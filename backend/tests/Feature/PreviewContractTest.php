<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use App\Models\VaultItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsVault;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * "Preview as recipient" — server side of the contract (VAULT-8, docs/13_Delivery_Engine.md
 * §8, docs/19_Decision_Log.md Decisions #30–#31). The preview reads exactly what the
 * delivery trigger will read — live items in order, completed files — through the
 * ordinary endpoints, and decrypts the *stored* ciphertext in the browser. Here the
 * browser is FakeClientVault (real HKDF + AES-256-GCM, AI_RULES.md #6): damage done to the
 * stored ciphertext must make it fail, and the failure it then reports must land in the
 * audit log as exactly one well-formed row.
 */
class PreviewContractTest extends TestCase
{
    use BuildsVault;
    use RefreshDatabase;

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
        Storage::fake('attachments');
    }

    /**
     * What the preview does for one recipient: list → fetch each item → decrypt with the
     * context of the recipient being previewed (not the IDs the server reports).
     *
     * @return array<string, array{title: string, body: ?string}|null> item ID → plaintext, null = failed
     */
    private function previewItems(User $user, int $recipientId): array
    {
        $result = [];
        foreach ($this->actingAs($user)->getJson("/api/v1/recipients/{$recipientId}/items")->assertOk()->json('items') as $summary) {
            $item = $this->getJson("/api/v1/vault-items/{$summary['id']}")->assertOk()->json('item');
            $result[$summary['id']] = $this->vault->decryptItem($item, $user->id, $recipientId, $summary['id']);
        }

        return $result;
    }

    private function reportItemFailure(string $itemId): void
    {
        $this->postJson('/api/v1/vault/integrity-events', ['events' => [[
            'kind' => 'item', 'part' => 'item.content', 'reason' => 'authentication_failed', 'surface' => 'preview', 'item_id' => $itemId,
        ]]])->assertStatus(202)->assertJsonPath('recorded', 1);
    }

    /** @return array<string, mixed> */
    private function onlyIntegrityRow(): array
    {
        $rows = DB::table('audit_logs')->where('event_type', 'integrity.check_failed')->get();
        $this->assertCount(1, $rows);

        return json_decode($rows[0]->metadata, true);
    }

    public function test_preview_reads_live_items_in_order_and_decrypts_the_stored_ciphertext(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $first = $this->createMessage($user, $recipient, 'First');
        $second = $this->createMessage($user, $recipient, 'Second');
        $gone = $this->createMessage($user, $recipient, 'Deleted');
        $this->deleteJson("/api/v1/vault-items/{$gone['id']}")->assertOk();
        $this->postJson("/api/v1/recipients/{$recipient->id}/items/reorder", ['order' => [$second['id'], $first['id']]])->assertOk();

        $preview = $this->previewItems($user, $recipient->id);

        $this->assertSame([$second['id'], $first['id']], array_keys($preview)); // position order, deleted item absent
        $this->assertSame('Second', $preview[$second['id']]['title']);
        $this->assertSame('<p dir="auto">Body</p>', $preview[$first['id']]['body']);
    }

    public function test_tampered_body_fails_and_its_report_is_audited(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $item = $this->createMessage($user, $recipient);

        $row = VaultItem::query()->where('uuid', $item['id'])->firstOrFail();
        $stored = $row->content_enc;
        $stored[strlen($stored) - 1] = chr(ord($stored[strlen($stored) - 1]) ^ 0x01); // flip one tag bit
        DB::table('vault_items')->where('id', $row->id)->update(['content_enc' => $stored]);

        $this->assertNull($this->previewItems($user, $recipient->id)[$item['id']]);

        $this->reportItemFailure($item['id']);
        $this->assertSame([
            'kind' => 'item', 'part' => 'item.content', 'reason' => 'authentication_failed', 'surface' => 'preview',
            'item_id' => $item['id'], 'recipient_id' => $recipient->id, 'event_source' => 'client',
        ], $this->onlyIntegrityRow());
    }

    public function test_ciphertext_swapped_between_two_items_fails_for_both(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $a = $this->createMessage($user, $recipient, 'A');
        $b = $this->createMessage($user, $recipient, 'B');

        // Swap every encrypted column of the two rows: each is a perfectly valid ciphertext,
        // just bound (AAD) to the other item's ID.
        $rowA = VaultItem::query()->where('uuid', $a['id'])->firstOrFail();
        $rowB = VaultItem::query()->where('uuid', $b['id'])->firstOrFail();
        $columns = ['content_key_wrapped', 'title_enc', 'content_enc'];
        DB::table('vault_items')->where('id', $rowA->id)->update($rowB->only($columns));
        DB::table('vault_items')->where('id', $rowB->id)->update($rowA->only($columns));

        $preview = $this->previewItems($user, $recipient->id);
        $this->assertNull($preview[$a['id']]);
        $this->assertNull($preview[$b['id']]);
    }

    public function test_item_re_pointed_at_another_recipient_does_not_open_as_theirs(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $item = $this->createMessage($user, $layla, 'For Layla only');

        DB::table('vault_items')->where('uuid', $item['id'])->update(['recipient_id' => $omar->id]);

        // The server now lists it under Omar — and Omar's preview cannot open it.
        $preview = $this->previewItems($user, $omar->id);
        $this->assertArrayHasKey($item['id'], $preview);
        $this->assertNull($preview[$item['id']]);
        $this->assertSame([], $this->previewItems($user, $layla->id));

        // Reported as Omar's item: the server records the recipient the row now names.
        $this->reportItemFailure($item['id']);
        $this->assertSame($omar->id, $this->onlyIntegrityRow()['recipient_id']);
    }

    public function test_truncated_file_fails_and_its_report_is_audited(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $bundle = $this->createBundle($user, $recipient);
        $attachment = $this->upload($user, $bundle, str_repeat('x', FakeClientVault::CHUNK_PLAINTEXT_BYTES + 10));
        $this->assertSame(2, $attachment['chunk_count']);

        $path = Attachment::query()->where('uuid', $attachment['id'])->value('storage_path');
        $disk = Storage::disk('attachments');
        $disk->put($path, substr($disk->get($path), 0, FakeClientVault::CHUNK_BYTES)); // drop the last chunk

        $listed = $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->assertOk()->json('attachments.0');
        $opened = $this->vault->decryptAttachment($listed, $this->download($attachment['id']), $user->id, $recipient->id, $bundle['id'], $attachment['id']);
        $this->assertNull($opened);

        $this->postJson('/api/v1/vault/integrity-events', ['events' => [[
            'kind' => 'attachment', 'part' => 'attachment.chunk', 'reason' => 'length_mismatch', 'surface' => 'preview',
            'item_id' => $bundle['id'], 'attachment_id' => $attachment['id'],
        ]]])->assertStatus(202);
        $this->assertSame([
            'kind' => 'attachment', 'part' => 'attachment.chunk', 'reason' => 'length_mismatch', 'surface' => 'preview',
            'item_id' => $bundle['id'], 'attachment_id' => $attachment['id'], 'recipient_id' => $recipient->id, 'event_source' => 'client',
        ], $this->onlyIntegrityRow());
    }

    public function test_cancelled_and_unfinished_uploads_never_appear_in_the_preview(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $bundle = $this->createBundle($user, $recipient);
        $kept = $this->upload($user, $bundle, 'complete file');

        // Declared, first of two chunks sent, then left hanging.
        $hanging = $this->encryptFile($user, $bundle, str_repeat('y', FakeClientVault::CHUNK_PLAINTEXT_BYTES + 1));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $hanging['declare'])->assertCreated();
        $this->sendChunk($hanging['declare']['id'], 0, 2, $hanging['chunks'][0])->assertOk();

        // Declared, one chunk sent, then cancelled (abort deletes it outright).
        $cancelled = $this->encryptFile($user, $bundle, str_repeat('z', FakeClientVault::CHUNK_PLAINTEXT_BYTES + 1));
        $this->postJson("/api/v1/vault-items/{$bundle['id']}/attachments", $cancelled['declare'])->assertCreated();
        $this->sendChunk($cancelled['declare']['id'], 0, 2, $cancelled['chunks'][0])->assertOk();
        $this->deleteJson("/api/v1/attachments/{$cancelled['declare']['id']}")->assertOk();

        $listed = $this->getJson("/api/v1/vault-items/{$bundle['id']}/attachments")->assertOk()->json('attachments');
        $this->assertSame([$kept['id']], array_column($listed, 'id'));
        $this->get("/api/v1/attachments/{$hanging['declare']['id']}/content")->assertNotFound();
        $this->get("/api/v1/attachments/{$cancelled['declare']['id']}/content")->assertNotFound();
    }
}
