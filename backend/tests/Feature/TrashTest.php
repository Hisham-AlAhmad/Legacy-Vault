<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsVault;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * Soft-delete undo window, restore, erase-now and the scheduled purge — Phase 2c
 * (docs/19_Decision_Log.md Decisions #18, #27–#29; docs/07_Database_Design.md §Deletion
 * strategy). The clock is frozen wherever the 30-day boundary matters.
 */
class TrashTest extends TestCase
{
    use BuildsVault;
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple 9!'; // UserFactory default

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
        Storage::fake('attachments');
    }

    private function fileCount(): int
    {
        return count(Storage::disk('attachments')->allFiles());
    }

    /** @return array<string, mixed> */
    private function trash(): array
    {
        return $this->getJson('/api/v1/trash')->assertOk()->json();
    }

    /** @return list<array<string, mixed>> */
    private function audits(string $event): array
    {
        return DB::table('audit_logs')->where('event_type', $event)->orderBy('id')->get()
            ->map(fn ($row): array => ['user_id' => $row->user_id, 'actor_type' => $row->actor_type, ...json_decode($row->metadata, true)])
            ->all();
    }

    public function test_deleting_an_item_takes_its_attachments_and_restoring_brings_them_back(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $bundle = $this->createBundle($user, $recipient);
        $early = $this->upload($user, $bundle, 'deleted on its own');
        $a = $this->upload($user, $bundle, 'first');
        $b = $this->upload($user, $bundle, 'second');

        // An unfinished upload on the item: discarded with it, not kept in the trash.
        $unfinished = $this->encryptFile($user, $bundle, random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 3));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $unfinished['declare'])->assertCreated();
        $this->sendChunk($unfinished['declare']['id'], 0, 2, $unfinished['chunks'][0])->assertOk();
        $this->assertSame(4, $this->fileCount());

        // One attachment is deleted by itself, a minute before the item.
        $this->deleteJson("/api/v1/attachments/{$early['id']}")->assertOk();
        Carbon::setTestNow('2026-11-01 10:01:00');
        $updatedAtBefore = DB::table('attachments')->where('uuid', $a['id'])->value('updated_at');

        $this->deleteJson("/api/v1/vault-items/{$bundle['id']}")->assertOk();

        // Same deleted_at as the item; the earlier one keeps its own; updated_at untouched.
        $itemDeletedAt = DB::table('vault_items')->where('uuid', $bundle['id'])->value('deleted_at');
        $this->assertSame('2026-11-01 10:01:00', $itemDeletedAt);
        $this->assertSame($itemDeletedAt, DB::table('attachments')->where('uuid', $a['id'])->value('deleted_at'));
        $this->assertSame($itemDeletedAt, DB::table('attachments')->where('uuid', $b['id'])->value('deleted_at'));
        $this->assertSame('2026-11-01 10:00:00', DB::table('attachments')->where('uuid', $early['id'])->value('deleted_at'));
        $this->assertSame($updatedAtBefore, DB::table('attachments')->where('uuid', $a['id'])->value('updated_at'));
        $this->assertDatabaseMissing('attachments', ['uuid' => $unfinished['declare']['id']]);
        $this->assertSame(3, $this->fileCount());
        $this->assertSame(2, $this->audits('vault_item.deleted')[0]['attachment_count']);

        // Nothing under the deleted item is reachable.
        $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->assertNotFound();
        $this->get("/api/v1/attachments/{$a['id']}/content")->assertNotFound();

        // The trash lists the item only — its attachments come back with it. They can't
        // be restored separately while their item is deleted.
        $trash = $this->trash();
        $this->assertSame(30, $trash['undo_window_days']);
        $this->assertSame([$bundle['id']], array_column($trash['items'], 'id'));
        $this->assertSame([], $trash['attachments']);
        $this->assertSame([], $trash['recipients']);
        $this->assertSame('2026-11-01T10:01:00+00:00', $trash['items'][0]['deleted_at']);
        $this->assertSame('2026-12-01T10:01:00+00:00', $trash['items'][0]['purge_at']);
        $this->assertSame('Documents', $this->vault->decryptItem($trash['items'][0], $user->id, $recipient->id, $bundle['id'])['title']);
        $this->postJson("/api/v1/trash/attachments/{$a['id']}/restore")->assertNotFound();

        $this->postJson("/api/v1/trash/items/{$bundle['id']}/restore")->assertOk()->assertJsonPath('item.id', $bundle['id']);

        // The item and the two attachments deleted with it are live again…
        $this->assertNotSoftDeleted('vault_items', ['uuid' => $bundle['id']]);
        $listed = array_column($this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->json('attachments'), 'id');
        $this->assertSame([$a['id'], $b['id']], $listed);
        $this->assertSame(
            'first',
            $this->vault->decryptAttachment($a, $this->download($a['id']), $user->id, $recipient->id, $bundle['id'], $a['id'])['content'],
        );

        // …the one deleted on its own is still deleted, and now listed in its own right.
        $this->assertSoftDeleted('attachments', ['uuid' => $early['id']]);
        $trash = $this->trash();
        $this->assertSame([], $trash['items']);
        $this->assertSame([$early['id']], array_column($trash['attachments'], 'id'));
        $this->assertSame($bundle['id'], $trash['attachments'][0]['item_id']);

        $this->postJson("/api/v1/trash/attachments/{$early['id']}/restore")->assertOk()->assertJsonPath('attachment.status', 'complete');
        $this->assertCount(3, $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->json('attachments'));

        $this->assertEqualsCanonicalizing(
            ['user_id' => $user->id, 'actor_type' => 'user', 'item_id' => $bundle['id'], 'recipient_id' => $recipient->id, 'attachment_count' => 2],
            $this->audits('vault_item.restored')[0],
        );
        $this->assertEqualsCanonicalizing(
            ['user_id' => $user->id, 'actor_type' => 'user', 'attachment_id' => $early['id'], 'item_id' => $bundle['id']],
            $this->audits('attachment.restored')[0],
        );

        // Restoring something that isn't deleted is a 404.
        $this->postJson("/api/v1/trash/items/{$bundle['id']}/restore")->assertNotFound();
    }

    public function test_restoring_a_recipient_restores_what_was_deleted_with_it(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $message = $this->createMessage($user, $layla, 'Message');
        $bundle = $this->createBundle($user, $layla);
        $file = $this->upload($user, $bundle, 'attached');
        $deletedEarlier = $this->createMessage($user, $layla, 'Deleted before the recipient');
        $omars = $this->createMessage($user, $omar, 'For Omar');

        $this->deleteJson("/api/v1/vault-items/{$deletedEarlier['id']}")->assertOk();
        // Layla is also the emergency contact; that role moves to Omar while she is deleted.
        $layla->forceFill(['is_emergency_contact' => true])->save();

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->deleteJson("/api/v1/recipients/{$layla->id}", ['password' => self::PASSWORD])->assertOk();
        $omar->forceFill(['is_emergency_contact' => true])->save();

        $this->assertSame(
            ['2026-11-02 09:00:00'],
            DB::table('attachments')->where('uuid', $file['id'])->pluck('deleted_at')->all(),
        );

        // Only the recipient is listed; the item deleted earlier is hidden behind it.
        $trash = $this->trash();
        $this->assertSame([$layla->id], array_column($trash['recipients'], 'id'));
        $this->assertSame(2, $trash['recipients'][0]['item_count']); // not the one deleted earlier
        $this->assertSame('Layla Haddad', $this->vault->openField($trash['recipients'][0]['name_enc'], 'recipient.name', $user->id));
        $this->assertSame([], $trash['items']);
        $this->assertSame([], $trash['attachments']);

        // An item can't be restored into a deleted recipient.
        $this->postJson("/api/v1/trash/items/{$message['id']}/restore")->assertNotFound();

        $this->postJson("/api/v1/trash/recipients/{$layla->id}/restore")->assertOk()
            ->assertJsonPath('recipient.id', $layla->id)
            ->assertJsonPath('recipient.is_emergency_contact', false); // Omar holds the role now

        $live = array_column($this->getJson("/api/v1/recipients/{$layla->id}/items")->assertOk()->json('items'), 'id');
        $this->assertSame([$message['id'], $bundle['id']], $live);
        $this->assertSame([$file['id']], array_column($this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->json('attachments'), 'id'));
        $this->assertTrue((bool) DB::table('recipients')->where('id', $omar->id)->value('is_emergency_contact'));
        $this->getJson("/api/v1/vault-items/{$omars['id']}")->assertOk();

        // The message deleted before the recipient stays deleted, now restorable by itself.
        $this->assertSoftDeleted('vault_items', ['uuid' => $deletedEarlier['id']]);
        $this->assertSame([$deletedEarlier['id']], array_column($this->trash()['items'], 'id'));

        $this->assertEqualsCanonicalizing(
            ['user_id' => $user->id, 'actor_type' => 'user', 'recipient_id' => $layla->id, 'item_count' => 2],
            $this->audits('recipient.restored')[0],
        );
    }

    public function test_the_trash_is_private_and_restore_respects_the_caps(): void
    {
        config(['legacyvault.vault_items.max_per_recipient' => 1]);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $recipient = $this->recipient($owner);
        $item = $this->createMessage($owner, $recipient);
        $this->deleteJson("/api/v1/vault-items/{$item['id']}")->assertOk();
        $this->deleteJson("/api/v1/recipients/{$this->recipient($owner, 'x@example.com')->id}", ['password' => self::PASSWORD])->assertOk();
        $deletedRecipientId = DB::table('recipients')->whereNotNull('deleted_at')->value('id');

        $this->actingAs($intruder);
        $this->assertSame([[], [], []], [$this->trash()['recipients'], $this->trash()['items'], $this->trash()['attachments']]);
        $this->postJson("/api/v1/trash/items/{$item['id']}/restore")->assertNotFound();
        $this->postJson("/api/v1/trash/recipients/{$deletedRecipientId}/restore")->assertNotFound();
        $this->deleteJson("/api/v1/trash/items/{$item['id']}", ['password' => self::PASSWORD])->assertNotFound();
        $this->deleteJson("/api/v1/trash/recipients/{$deletedRecipientId}", ['password' => self::PASSWORD])->assertNotFound();
        $this->postJson('/api/v1/trash/recipients/abc/restore')->assertNotFound();
        $this->postJson('/api/v1/trash/items/123/restore')->assertNotFound();
        $this->assertSoftDeleted('vault_items', ['uuid' => $item['id']]);

        // The recipient has since been filled to its cap, so the deleted item can't return.
        $this->actingAs($owner);
        $this->createMessage($owner, $recipient);
        $this->postJson("/api/v1/trash/items/{$item['id']}/restore")
            ->assertStatus(403)->assertJsonPath('error.code', 'ITEM_LIMIT_REACHED');
        $this->assertSoftDeleted('vault_items', ['uuid' => $item['id']]);
    }

    public function test_the_undo_window_is_thirty_days_and_purge_erases_only_what_is_past_it(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);

        // Deleted 2026-10-01 12:00:00 — the 30-day window ends 2026-10-31 12:00:00.
        Carbon::setTestNow('2026-10-01 12:00:00');
        $oldBundle = $this->createBundle($user, $recipient, 'Old bundle');
        $oldFile = $this->upload($user, $oldBundle, 'old file');
        $keptBundle = $this->createBundle($user, $recipient, 'Kept bundle');
        $oldLoose = $this->upload($user, $keptBundle, 'old loose file');
        $this->deleteJson("/api/v1/vault-items/{$oldBundle['id']}")->assertOk();
        $this->deleteJson("/api/v1/attachments/{$oldLoose['id']}")->assertOk();
        $oldPath = DB::table('attachments')->where('uuid', $oldFile['id'])->value('storage_path');

        // Deleted "yesterday" relative to the purge below.
        Carbon::setTestNow('2026-10-30 12:00:00');
        $recentBundle = $this->createBundle($user, $recipient, 'Recent bundle');
        $recentFile = $this->upload($user, $recentBundle, 'recent file');
        $this->deleteJson("/api/v1/vault-items/{$recentBundle['id']}")->assertOk();
        $this->assertSame(3, $this->fileCount());

        // One second before the window ends: still listed, purge leaves it alone.
        Carbon::setTestNow('2026-10-31 11:59:59');
        $this->assertContains($oldBundle['id'], array_column($this->trash()['items'], 'id'));
        $this->artisan('vault:purge-deleted')->assertSuccessful()
            ->expectsOutputToContain('Purged 0 recipient(s), 0 item(s), 0 attachment(s) and 0 abandoned upload(s).');
        $this->assertDatabaseHas('vault_items', ['uuid' => $oldBundle['id']]);
        $this->assertSame(3, $this->fileCount());

        // Exactly 30 days: no longer restorable, even before the purge has run…
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->assertNotContains($oldBundle['id'], array_column($this->trash()['items'], 'id'));
        $this->postJson("/api/v1/trash/items/{$oldBundle['id']}/restore")->assertNotFound();
        $this->postJson("/api/v1/trash/attachments/{$oldLoose['id']}/restore")->assertNotFound();
        $this->getJson('/api/v1/vault/storage')->assertJsonPath('storage.trashed_bytes', (8 + 29) + (14 + 29) + (11 + 29));

        // …and the purge removes it from the database and the disk.
        $this->artisan('vault:purge-deleted')->assertSuccessful()
            ->expectsOutputToContain('Purged 0 recipient(s), 1 item(s), 1 attachment(s) and 0 abandoned upload(s).');

        $this->assertDatabaseMissing('vault_items', ['uuid' => $oldBundle['id']]);
        $this->assertDatabaseMissing('attachments', ['uuid' => $oldFile['id']]);
        $this->assertDatabaseMissing('attachments', ['uuid' => $oldLoose['id']]);
        $this->assertFalse(Storage::disk('attachments')->exists($oldPath));
        $this->assertSame(1, $this->fileCount());

        // The space is free again, and the purged ID is no longer reserved.
        $this->getJson('/api/v1/vault/storage')->assertJsonPath('storage.used_bytes', 11 + 29);

        // The item deleted yesterday is untouched and can still be restored, file and all.
        $this->assertSame([$recentBundle['id']], array_column($this->trash()['items'], 'id'));
        $this->postJson("/api/v1/trash/items/{$recentBundle['id']}/restore")->assertOk();
        $this->assertSame(
            'recent file',
            $this->vault->decryptAttachment($recentFile, $this->download($recentFile['id']), $user->id, $recipient->id, $recentBundle['id'], $recentFile['id'])['content'],
        );

        // Audit: system actor, IDs and counts only. The item's file is counted in the
        // item's entry; the loose attachment has its own.
        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'system', 'item_id' => $oldBundle['id'],
            'recipient_id' => $recipient->id, 'attachment_count' => 1, 'reason' => 'window_expired',
        ]], $this->audits('vault_item.purged'));
        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'system', 'attachment_id' => $oldLoose['id'],
            'item_id' => $keptBundle['id'],
            'size_bytes' => 14 + 29, 'reason' => 'window_expired',
        ]], $this->audits('attachment.purged'));

        // Running it again finds nothing.
        $this->artisan('vault:purge-deleted')->assertSuccessful();
        $this->assertSame(1, $this->fileCount());
    }

    public function test_purge_erases_an_expired_recipient_with_everything_under_it(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Carbon::setTestNow('2026-09-01 08:00:00');
        $layla = $this->recipient($user);
        $kept = $this->recipient($user, 'kept@example.com');
        $bundle = $this->createBundle($user, $layla);
        $this->upload($user, $bundle, 'one');
        $this->upload($user, $bundle, 'two');
        $this->createMessage($user, $layla);
        $keptItem = $this->createMessage($user, $kept);
        $othersBundle = $this->createBundle($other, $this->recipient($other, 'o@example.com'));
        $othersFile = $this->upload($other, $othersBundle, 'theirs');

        $this->actingAs($user)->deleteJson("/api/v1/recipients/{$layla->id}", ['password' => self::PASSWORD])->assertOk();

        Carbon::setTestNow('2026-10-01 08:00:00'); // exactly 30 days later
        $this->artisan('vault:purge-deleted')->assertSuccessful()
            ->expectsOutputToContain('Purged 1 recipient(s), 0 item(s), 0 attachment(s)');

        $this->assertDatabaseMissing('recipients', ['id' => $layla->id]);
        $this->assertSame(0, DB::table('vault_items')->where('recipient_id', $layla->id)->count());
        $this->assertSame(0, DB::table('attachments')->where('user_id', $user->id)->count());
        $this->assertSame([], $this->trash()['recipients']);

        // Live data, and other users' data, is untouched.
        $this->assertDatabaseHas('recipients', ['id' => $kept->id, 'deleted_at' => null]);
        $this->getJson("/api/v1/vault-items/{$keptItem['id']}")->assertOk();
        $this->assertSame(1, $this->fileCount());
        $this->actingAs($other)->get("/api/v1/attachments/{$othersFile['id']}/content")->assertOk();

        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'system', 'recipient_id' => $layla->id,
            'item_count' => 2, 'attachment_count' => 2, 'reason' => 'window_expired',
        ]], $this->audits('recipient.purged'));
        // Children are counted in the parent's entry, not logged one by one.
        $this->assertSame([], $this->audits('vault_item.purged'));
        $this->assertSame([], $this->audits('attachment.purged'));
    }

    public function test_abandoned_uploads_are_discarded_after_a_day(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $finished = $this->upload($user, $bundle, 'finished');

        $stale = $this->encryptFile($user, $bundle, random_bytes(FakeClientVault::CHUNK_PLAINTEXT_BYTES + 3));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $stale['declare'])->assertCreated();
        $this->sendChunk($stale['declare']['id'], 0, 2, $stale['chunks'][0])->assertOk();

        // A second upload that is still making progress later on.
        Carbon::setTestNow('2026-11-02 09:00:00');
        $active = $this->encryptFile($user, $bundle, 'still going');
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $active['declare'])->assertCreated();

        // 23h59m59s after the stale upload's last chunk: kept.
        Carbon::setTestNow('2026-11-02 09:59:59');
        $this->artisan('vault:purge-deleted')->assertSuccessful();
        $this->assertDatabaseHas('attachments', ['uuid' => $stale['declare']['id']]);

        // 24 hours: gone, with its partial file and its quota reservation.
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->artisan('vault:purge-deleted')->assertSuccessful()
            ->expectsOutputToContain('1 abandoned upload(s)');

        $this->assertDatabaseMissing('attachments', ['uuid' => $stale['declare']['id']]);
        $this->assertDatabaseHas('attachments', ['uuid' => $active['declare']['id']]);
        $this->assertDatabaseHas('attachments', ['uuid' => $finished['id'], 'deleted_at' => null]);
        $this->assertSame(1, $this->fileCount());
        $this->getJson('/api/v1/vault/storage')
            ->assertJsonPath('storage.used_bytes', $finished['size_bytes'] + $active['declare']['size_bytes']);

        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'system', 'attachment_id' => $stale['declare']['id'],
            'item_id' => $bundle['id'], 'size_bytes' => $stale['declare']['size_bytes'], 'reason' => 'window_expired',
        ]], $this->audits('attachment.upload_expired'));
    }

    public function test_erase_now_needs_the_password_and_is_permanent(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $doomed = $this->recipient($user, 'doomed@example.com');
        $bundle = $this->createBundle($user, $recipient);
        $file = $this->upload($user, $bundle, 'erase me');
        $loose = $this->upload($user, $this->createBundle($user, $recipient, 'Other'), 'loose');
        $doomedBundle = $this->createBundle($user, $doomed);
        $this->upload($user, $doomedBundle, 'theirs');
        $path = DB::table('attachments')->where('uuid', $file['id'])->value('storage_path');

        // A live entry can't be erased — it has to be deleted first.
        $this->deleteJson("/api/v1/trash/items/{$bundle['id']}", ['password' => self::PASSWORD])->assertNotFound();

        $this->deleteJson("/api/v1/vault-items/{$bundle['id']}")->assertOk();
        $this->deleteJson("/api/v1/attachments/{$loose['id']}")->assertOk();
        $this->deleteJson("/api/v1/recipients/{$doomed->id}", ['password' => self::PASSWORD])->assertOk();

        // Step-up: no password, or the wrong one, erases nothing.
        $this->deleteJson("/api/v1/trash/items/{$bundle['id']}")->assertStatus(422);
        $this->deleteJson("/api/v1/trash/items/{$bundle['id']}", ['password' => 'wrong password'])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertDatabaseHas('vault_items', ['uuid' => $bundle['id']]);
        $this->assertSame(3, $this->fileCount());

        $this->deleteJson("/api/v1/trash/items/{$bundle['id']}", ['password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('storage.used_bytes', (5 + 29) + (6 + 29));

        $this->assertDatabaseMissing('vault_items', ['uuid' => $bundle['id']]);
        $this->assertDatabaseMissing('attachments', ['uuid' => $file['id']]);
        $this->assertFalse(Storage::disk('attachments')->exists($path));
        $this->postJson("/api/v1/trash/items/{$bundle['id']}/restore")->assertNotFound();
        $this->deleteJson("/api/v1/trash/items/{$bundle['id']}", ['password' => self::PASSWORD])->assertNotFound();

        $this->deleteJson("/api/v1/trash/attachments/{$loose['id']}", ['password' => self::PASSWORD])->assertOk();
        $this->deleteJson("/api/v1/trash/recipients/{$doomed->id}", ['password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('storage.used_bytes', 0);

        $this->assertDatabaseMissing('recipients', ['id' => $doomed->id]);
        $this->assertSame(0, DB::table('attachments')->count());
        $this->assertSame(0, $this->fileCount());
        $this->assertDatabaseHas('recipients', ['id' => $recipient->id, 'deleted_at' => null]);

        // Audited as the user's own action.
        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'user', 'item_id' => $bundle['id'],
            'recipient_id' => $recipient->id, 'attachment_count' => 1, 'reason' => 'user_requested',
        ]], $this->audits('vault_item.purged'));
        $this->assertSame('user_requested', $this->audits('attachment.purged')[0]['reason']);
        $this->assertEqualsCanonicalizing([[
            'user_id' => $user->id, 'actor_type' => 'user', 'recipient_id' => $doomed->id,
            'item_count' => 1, 'attachment_count' => 1, 'reason' => 'user_requested',
        ]], $this->audits('recipient.purged'));
    }

    public function test_a_parent_cannot_be_removed_from_under_its_attachment_rows(): void
    {
        $user = User::factory()->create();
        $bundle = $this->createBundle($user, $this->recipient($user));
        $this->upload($user, $bundle);

        // RESTRICT, not CASCADE: a plain database delete of the item is refused, so rows
        // can never vanish and leave their files behind (Decision #23).
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('vault_items')->where('uuid', $bundle['id'])->delete();
    }
}
