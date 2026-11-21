<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsVault;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * POST /vault/integrity-events — docs/19_Decision_Log.md Decision #31. The endpoint must
 * record genuine client reports, and must not be usable to write text, to name another
 * user's rows, or to flood the audit log.
 */
class IntegrityEventTest extends TestCase
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

    private const URL = '/api/v1/vault/integrity-events';

    /** @return list<object> the integrity.check_failed rows, metadata decoded */
    private function integrityRows(): array
    {
        return DB::table('audit_logs')->where('event_type', 'integrity.check_failed')->orderBy('id')->get()
            ->map(function (object $row): object {
                $row->metadata = json_decode($row->metadata, true);

                return $row;
            })->all();
    }

    private static function itemEvent(string $itemId, array $overrides = []): array
    {
        return ['kind' => 'item', 'part' => 'item.content', 'reason' => 'authentication_failed', 'surface' => 'preview', 'item_id' => $itemId, ...$overrides];
    }

    public function test_records_item_attachment_and_sealed_field_events_with_server_side_recipient(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $item = $this->createMessage($user, $recipient);
        $attachment = $this->upload($user, $item);

        $this->actingAs($user)->postJson(self::URL, ['events' => [
            self::itemEvent($item['id']),
            ['kind' => 'attachment', 'part' => 'attachment.chunk', 'reason' => 'length_mismatch', 'surface' => 'download', 'item_id' => $item['id'], 'attachment_id' => $attachment['id']],
            ['kind' => 'sealed_field', 'part' => 'recipient.name', 'reason' => 'unreadable', 'surface' => 'recipients', 'recipient_id' => $recipient->id],
            ['kind' => 'sealed_field', 'part' => 'emergency_contact.name', 'reason' => 'unreadable', 'surface' => 'recipients'],
        ]])->assertStatus(202)->assertExactJson(['recorded' => 4]);

        $rows = $this->integrityRows();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame($user->id, $row->user_id);
            $this->assertSame('user', $row->actor_type);
            $this->assertSame('client', $row->metadata['event_source']);
        }

        $this->assertSame([
            'kind' => 'item', 'part' => 'item.content', 'reason' => 'authentication_failed', 'surface' => 'preview',
            'item_id' => $item['id'], 'recipient_id' => $recipient->id, 'event_source' => 'client',
        ], $rows[0]->metadata);
        $this->assertSame($attachment['id'], $rows[1]->metadata['attachment_id']);
        $this->assertSame($recipient->id, $rows[1]->metadata['recipient_id']);
        $this->assertSame($recipient->id, $rows[2]->metadata['recipient_id']);
        $this->assertArrayNotHasKey('recipient_id', $rows[3]->metadata);
    }

    public function test_recipient_comes_from_the_row_not_from_the_client(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $item = $this->createMessage($user, $layla);

        // An item event may not carry a recipient at all — it is derived.
        $this->actingAs($user)->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['recipient_id' => $omar->id])]])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame([], $this->integrityRows());
    }

    public function test_free_text_and_unknown_keys_never_reach_the_log(): void
    {
        $user = User::factory()->create();
        $item = $this->createMessage($user, $this->recipient($user));

        $this->actingAs($user)->postJson(self::URL, ['events' => [self::itemEvent($item['id'], [
            'message' => 'Vault item integrity check failed: My dearest Layla',
            'title' => 'For Layla',
            'ciphertext' => base64_encode(random_bytes(40)),
        ])], 'note' => 'anything'])->assertStatus(202);

        $raw = (string) DB::table('audit_logs')->where('event_type', 'integrity.check_failed')->value('metadata');
        $this->assertStringNotContainsString('Layla', $raw);
        $this->assertStringNotContainsString('message', $raw);
        $this->assertStringNotContainsString('ciphertext', $raw);

        // A free-text value in an enumerated field is refused outright.
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['reason' => 'My dearest Layla'])]])->assertStatus(422);
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['surface' => 'somewhere'])]])->assertStatus(422);
    }

    public function test_part_must_match_kind_and_ids_must_match_kind(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $item = $this->createMessage($user, $recipient);
        $this->actingAs($user);

        $invalid = [
            self::itemEvent($item['id'], ['part' => 'attachment.chunk']),                   // part of another kind
            self::itemEvent($item['id'], ['kind' => 'nonsense']),                          // unknown kind
            ['kind' => 'item', 'part' => 'item.title', 'reason' => 'malformed', 'surface' => 'list'], // missing item_id
            ['kind' => 'attachment', 'part' => 'attachment.name', 'reason' => 'malformed', 'surface' => 'list', 'item_id' => $item['id']], // missing attachment_id
            ['kind' => 'sealed_field', 'part' => 'recipient.name', 'reason' => 'unreadable', 'surface' => 'recipients'], // missing recipient_id
            ['kind' => 'sealed_field', 'part' => 'emergency_contact.name', 'reason' => 'unreadable', 'surface' => 'recipients', 'recipient_id' => $recipient->id], // extra id
            self::itemEvent('not-a-uuid'),
        ];

        foreach ($invalid as $event) {
            $this->postJson(self::URL, ['events' => [$event]])->assertStatus(422);
        }
        $this->postJson(self::URL, ['events' => []])->assertStatus(422);
        $this->postJson(self::URL, ['events' => array_fill(0, 11, self::itemEvent($item['id']))])->assertStatus(422);

        $this->assertSame([], $this->integrityRows());
    }

    public function test_another_users_rows_cannot_be_named(): void
    {
        $owner = User::factory()->create();
        $ownerRecipient = $this->recipient($owner);
        $ownerItem = $this->createMessage($owner, $ownerRecipient);
        $ownerFile = $this->upload($owner, $ownerItem);

        $intruder = User::factory()->create();
        $intruderItem = $this->createMessage($intruder, $this->recipient($intruder, 'x@example.com'));
        $this->actingAs($intruder);

        $this->postJson(self::URL, ['events' => [self::itemEvent($ownerItem['id'])]])->assertStatus(422);
        $this->postJson(self::URL, ['events' => [
            ['kind' => 'sealed_field', 'part' => 'recipient.name', 'reason' => 'unreadable', 'surface' => 'recipients', 'recipient_id' => $ownerRecipient->id],
        ]])->assertStatus(422);
        // The owner's attachment under the intruder's own item: the pair must match.
        $this->postJson(self::URL, ['events' => [
            ['kind' => 'attachment', 'part' => 'attachment.key', 'reason' => 'authentication_failed', 'surface' => 'preview', 'item_id' => $intruderItem['id'], 'attachment_id' => $ownerFile['id']],
        ]])->assertStatus(422);
        // One bad reference in a batch records nothing at all.
        $this->postJson(self::URL, ['events' => [self::itemEvent($intruderItem['id']), self::itemEvent($ownerItem['id'])]])->assertStatus(422);

        $this->assertSame([], $this->integrityRows());
        $this->assertSame(0, DB::table('audit_logs')->where('user_id', $owner->id)->where('event_type', 'integrity.check_failed')->count());
    }

    public function test_deleted_rows_can_be_reported(): void
    {
        $user = User::factory()->create();
        $item = $this->createMessage($user, $this->recipient($user));
        $this->actingAs($user)->deleteJson("/api/v1/vault-items/{$item['id']}")->assertOk();

        // The trash list decrypts titles too.
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['part' => 'item.title', 'surface' => 'trash'])]])
            ->assertStatus(202)->assertJsonPath('recorded', 1);
    }

    public function test_the_same_failure_is_recorded_once_per_window(): void
    {
        $user = User::factory()->create();
        $item = $this->createMessage($user, $this->recipient($user));
        $this->actingAs($user);

        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id']), self::itemEvent($item['id'])]])->assertJsonPath('recorded', 1);
        // Seen again elsewhere and for another reason: still the same broken ciphertext.
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['surface' => 'editor', 'reason' => 'malformed'])]])
            ->assertStatus(202)->assertJsonPath('recorded', 0);
        // A different part of the same item is a different failure.
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'], ['part' => 'item.title'])]])->assertJsonPath('recorded', 1);

        $this->assertCount(2, $this->integrityRows());

        $this->travel(25)->hours();
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'])]])->assertJsonPath('recorded', 1);
    }

    public function test_recorded_events_have_a_daily_budget(): void
    {
        config(['legacyvault.integrity_events.daily_budget' => 3]);
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $items = array_map(fn () => $this->createMessage($user, $recipient), range(1, 5));
        $this->actingAs($user);

        $this->postJson(self::URL, ['events' => [self::itemEvent($items[0]['id']), self::itemEvent($items[1]['id'])]])->assertJsonPath('recorded', 2);
        // Two more would exceed the budget of 3: refused whole, nothing written.
        $this->postJson(self::URL, ['events' => [self::itemEvent($items[2]['id']), self::itemEvent($items[3]['id'])]])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertCount(2, $this->integrityRows());
        $this->postJson(self::URL, ['events' => [self::itemEvent($items[2]['id'])]])->assertJsonPath('recorded', 1);
        $this->postJson(self::URL, ['events' => [self::itemEvent($items[4]['id'])]])->assertStatus(429);
        // A duplicate costs nothing and is still accepted.
        $this->postJson(self::URL, ['events' => [self::itemEvent($items[0]['id'])]])->assertStatus(202)->assertJsonPath('recorded', 0);

        $this->assertCount(3, $this->integrityRows());
    }

    public function test_requests_are_rate_limited(): void
    {
        config(['legacyvault.integrity_events.requests_per_hour' => 2]);
        $user = User::factory()->create();
        $item = $this->createMessage($user, $this->recipient($user));
        $this->actingAs($user);

        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'])]])->assertStatus(202);
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'])]])->assertStatus(202);
        $this->postJson(self::URL, ['events' => [self::itemEvent($item['id'])]])->assertStatus(429);
    }

    public function test_requires_an_authenticated_active_account(): void
    {
        $this->postJson(self::URL, ['events' => [self::itemEvent(FakeClientVault::newItemId())]])->assertStatus(401);

        $pending = User::factory()->create(['status' => 'pending']);
        $this->actingAs($pending)->postJson(self::URL, ['events' => [self::itemEvent(FakeClientVault::newItemId())]])->assertStatus(403);
        $this->assertSame([], $this->integrityRows());
    }
}
