<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * Vault items (messages) — Phase 2b (VAULT-5, VAULT-6 text/links, VAULT-9). Every item is
 * produced with real HKDF + AES-256-GCM by the test client, never stubbed
 * (AI_RULES.md #6); the server only ever stores and returns ciphertext.
 */
class VaultItemTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple 9!'; // UserFactory default
    private const TITLE = 'For Layla, when the time comes';
    private const BODY = '<p dir="auto">My dearest Layla — رسالتي الأخيرة. The house deed is with <a href="https://example.com/notary" rel="noopener noreferrer nofollow" target="_blank">the notary</a>.</p>';

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
    }

    private function recipient(User $user, string $email = 'layla@example.com'): Recipient
    {
        return $user->recipients()->create([
            'name_enc' => base64_decode($this->vault->sealField('Layla Haddad', 'recipient.name', $user->id)),
            'relationship_label_enc' => base64_decode($this->vault->sealField('Daughter', 'recipient.relationship', $user->id)),
            'email' => $email,
            'phone' => null,
            'preferred_methods' => ['email'],
        ]);
    }

    /** @return array<string, mixed> a create payload with a fresh ID and freshly encrypted content */
    private function payload(User $user, Recipient $recipient, array $overrides = [], string $title = self::TITLE, string $body = self::BODY): array
    {
        $id = $overrides['id'] ?? FakeClientVault::newItemId();

        return [
            'id' => $id,
            'item_type' => 'message',
            ...$this->vault->encryptItem($title, $body, $user->id, $recipient->id, (string) $id),
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> the created item as returned by the API */
    private function createItem(User $user, Recipient $recipient, string $title = self::TITLE, string $body = self::BODY): array
    {
        return $this->actingAs($user)
            ->postJson("/api/v1/recipients/{$recipient->id}/items", $this->payload($user, $recipient, [], $title, $body))
            ->assertCreated()
            ->json('item');
    }

    public function test_create_stores_only_ciphertext_and_round_trips(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);

        $payload = $this->payload($user, $recipient);
        $item = $this->actingAs($user)->postJson("/api/v1/recipients/{$recipient->id}/items", $payload)
            ->assertCreated()->json('item');

        $this->assertSame($payload['id'], $item['id']);
        $this->assertSame($recipient->id, $item['recipient_id']);
        $this->assertSame('message', $item['item_type']);
        $this->assertSame(1, $item['position']);
        $this->assertSame(1, $item['aad_version']);
        $this->assertSame(
            ['title' => self::TITLE, 'body' => self::BODY],
            $this->vault->decryptItem($item, $user->id, $recipient->id, $item['id']),
        );

        // The numeric primary key never leaves the backend.
        $this->assertArrayNotHasKey('uuid', $item);
        $this->assertFalse(is_int($item['id']));

        // A raw database read reveals neither the title nor the body (docs/18_Threat_Model.md scenario 1).
        $row = (array) DB::table('vault_items')->where('uuid', $item['id'])->first();
        $dump = implode('|', array_map('strval', $row));
        foreach (['Layla', 'time comes', 'dearest', 'رسالتي', 'notary', 'example.com', '<p'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $this->assertSame(61, strlen($row['content_key_wrapped']));

        // The audit entry carries IDs only — no title, content or ciphertext.
        $audit = DB::table('audit_logs')->where('event_type', 'vault_item.created')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertEqualsCanonicalizing(
            ['item_id' => $item['id'], 'recipient_id' => $recipient->id, 'item_type' => 'message'],
            json_decode($audit->metadata, true),
        );
    }

    public function test_list_is_per_recipient_ordered_and_omits_bodies(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');

        $first = $this->createItem($user, $layla, 'First');
        $second = $this->createItem($user, $layla, 'Second');
        $this->createItem($user, $omar, 'For Omar only');

        $items = $this->getJson("/api/v1/recipients/{$layla->id}/items")->assertOk()->json('items');

        $this->assertSame([$first['id'], $second['id']], array_column($items, 'id'));
        $this->assertSame([1, 2], array_column($items, 'position'));
        $this->assertArrayNotHasKey('content_enc', $items[0]);
        $this->assertSame('First', $this->vault->decryptItem($items[0], $user->id, $layla->id, $items[0]['id'])['title']);

        $omarsItems = $this->getJson("/api/v1/recipients/{$omar->id}/items")->json('items');
        $this->assertCount(1, $omarsItems);
        $this->assertSame(1, $omarsItems[0]['position']); // positions are per recipient
    }

    public function test_show_returns_the_body_ciphertext(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $created = $this->createItem($user, $recipient);

        $item = $this->getJson("/api/v1/vault-items/{$created['id']}")->assertOk()->json('item');

        $this->assertSame(self::BODY, $this->vault->decryptItem($item, $user->id, $recipient->id, $item['id'])['body']);
    }

    public function test_update_replaces_everything_and_requires_a_rotated_key(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $created = $this->createItem($user, $recipient);
        $url = "/api/v1/vault-items/{$created['id']}";

        $edited = $this->vault->encryptItem('Edited title', '<p dir="auto">Edited body</p>', $user->id, $recipient->id, $created['id']);

        // No partial content update — a new key means everything is re-encrypted.
        $this->patchJson($url, ['title_enc' => $edited['title_enc']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content_enc', 'content_key_wrapped', 'aad_version']]]);

        // New title/body under the *old* wrapped key is rejected: the key wasn't rotated.
        $this->patchJson($url, [...$edited, 'content_key_wrapped' => $created['content_key_wrapped']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['content_key_wrapped']]]);

        $updated = $this->patchJson($url, $edited)->assertOk()->json('item');

        $this->assertSame($created['id'], $updated['id']);
        $this->assertSame($created['position'], $updated['position']);
        $this->assertNotSame($created['content_key_wrapped'], $updated['content_key_wrapped']);
        $this->assertNotSame($created['title_enc'], $updated['title_enc']);
        $this->assertSame(
            ['title' => 'Edited title', 'body' => '<p dir="auto">Edited body</p>'],
            $this->vault->decryptItem($updated, $user->id, $recipient->id, $updated['id']),
        );

        $row = (array) DB::table('vault_items')->where('uuid', $created['id'])->first();
        $this->assertStringNotContainsString('Edited', implode('|', array_map('strval', $row)));

        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'vault_item.updated')->sole()->metadata, true);
        $this->assertEqualsCanonicalizing(['item_id' => $created['id'], 'recipient_id' => $recipient->id], $audit);

        // The ID, type and recipient are not updatable, even if sent.
        $again = $this->vault->encryptItem('Third', '<p dir="auto">x</p>', $user->id, $recipient->id, $created['id']);
        $this->patchJson($url, [...$again, 'id' => FakeClientVault::newItemId(), 'recipient_id' => 999, 'item_type' => 'file_bundle'])->assertOk();
        $this->assertDatabaseHas('vault_items', ['uuid' => $created['id'], 'recipient_id' => $recipient->id, 'item_type' => 'message']);
    }

    public function test_another_users_recipients_and_items_are_not_found(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $recipient = $this->recipient($owner);
        $item = $this->createItem($owner, $recipient);
        $intrudersRecipient = $this->recipient($intruder, 'mine@example.com');

        $this->actingAs($intruder);

        // The owner's recipient doesn't exist as far as the intruder can tell.
        $this->getJson("/api/v1/recipients/{$recipient->id}/items")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson("/api/v1/recipients/{$recipient->id}/items", $this->payload($intruder, $recipient))->assertNotFound();
        $this->postJson("/api/v1/recipients/{$recipient->id}/items/reorder", ['order' => [$item['id']]])->assertNotFound();

        // Nor does the item.
        $replacement = $this->vault->encryptItem('x', '<p>x</p>', $intruder->id, $recipient->id, $item['id']);
        $this->getJson("/api/v1/vault-items/{$item['id']}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->patchJson("/api/v1/vault-items/{$item['id']}", $replacement)->assertNotFound();
        $this->deleteJson("/api/v1/vault-items/{$item['id']}")->assertNotFound();

        // And it can't be pulled into the intruder's own recipient's ordering.
        $this->postJson("/api/v1/recipients/{$intrudersRecipient->id}/items/reorder", ['order' => [$item['id']]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['order']]]);

        // Malformed IDs are the same plain 404.
        $this->getJson('/api/v1/vault-items/123')->assertNotFound();
        $this->getJson('/api/v1/vault-items/'.strtoupper($item['id']))->assertNotFound();

        $this->assertDatabaseHas('vault_items', ['uuid' => $item['id'], 'user_id' => $owner->id, 'deleted_at' => null]);
        $this->assertSame($item['title_enc'], base64_encode(DB::table('vault_items')->where('uuid', $item['id'])->value('title_enc')));
    }

    public function test_one_recipients_item_cannot_be_used_or_decrypted_in_anothers_context(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $item = $this->createItem($user, $layla);
        $this->actingAs($user);

        // API: Layla's item is not part of Omar's list, and can't be reordered into it.
        $this->assertSame([], $this->getJson("/api/v1/recipients/{$omar->id}/items")->json('items'));
        $this->postJson("/api/v1/recipients/{$omar->id}/items/reorder", ['order' => [$item['id']]])->assertStatus(422);

        // Crypto: the same ciphertext opens for Layla and fails for Omar, another user,
        // or another item ID (AAD binding, docs/06_Cryptography.md §9).
        $this->assertNotNull($this->vault->decryptItem($item, $user->id, $layla->id, $item['id']));
        $this->assertNull($this->vault->decryptItem($item, $user->id, $omar->id, $item['id']));
        $this->assertNull($this->vault->decryptItem($item, $user->id + 1, $layla->id, $item['id']));
        $this->assertNull($this->vault->decryptItem($item, $user->id, $layla->id, FakeClientVault::newItemId()));

        // Even a database-level re-pointing of the row to Omar doesn't make it readable
        // as Omar's: the server would serve it under Omar, and decryption fails there.
        DB::table('vault_items')->where('uuid', $item['id'])->update(['recipient_id' => $omar->id]);
        $moved = $this->getJson("/api/v1/recipients/{$omar->id}/items")->json('items.0');
        $this->assertSame($item['id'], $moved['id']);
        $this->assertNull($this->vault->decryptItem($moved, $user->id, $omar->id, $moved['id']));
    }

    public function test_malformed_input_is_rejected(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $this->actingAs($user);
        $url = "/api/v1/recipients/{$recipient->id}/items";

        $rejects = function (array $overrides, string $field) use ($user, $recipient, $url): void {
            $this->postJson($url, $this->payload($user, $recipient, $overrides))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['fields' => [$field]]]);
        };

        $valid = $this->payload($user, $recipient);
        $wrongVersion = base64_decode($valid['title_enc']);
        $wrongVersion[0] = "\x02";

        // The client-chosen ID must be a canonical lowercase UUIDv4.
        $rejects(['id' => 'not-a-uuid'], 'id');
        $rejects(['id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'], 'id'); // v1
        $rejects(['id' => strtoupper(FakeClientVault::newItemId())], 'id');
        $rejects(['id' => 42], 'id');

        $rejects(['item_type' => 'file_bundle'], 'item_type'); // arrives with Phase 2c
        $rejects(['item_type' => 'note'], 'item_type');
        $rejects(['aad_version' => 2], 'aad_version');

        $rejects(['title_enc' => 'not-base64!!'], 'title_enc');
        $rejects(['title_enc' => base64_encode($wrongVersion)], 'title_enc');
        $rejects(['title_enc' => base64_encode("\x01".random_bytes(1100))], 'title_enc'); // over 1024 bytes
        $rejects(['content_enc' => null], 'content_enc');
        $rejects(['content_enc' => base64_encode(random_bytes(20))], 'content_enc'); // shorter than an envelope

        // A wrapped content key is exactly 61 bytes — nothing shorter or longer.
        $rejects(['content_key_wrapped' => base64_encode("\x01".random_bytes(59))], 'content_key_wrapped');
        $rejects(['content_key_wrapped' => base64_encode("\x01".random_bytes(61))], 'content_key_wrapped');

        $this->postJson($url, [])->assertStatus(422);
        $this->assertDatabaseCount('vault_items', 0);
    }

    public function test_an_oversized_body_is_413(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $this->actingAs($user);

        $max = config('legacyvault.vault_items.max_content_bytes');
        $atLimit = base64_encode("\x01".random_bytes($max - 1));
        $overLimit = base64_encode("\x01".random_bytes($max + 3));

        $this->postJson("/api/v1/recipients/{$recipient->id}/items", $this->payload($user, $recipient, ['content_enc' => $overLimit]))
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
        $this->assertDatabaseCount('vault_items', 0);

        // Exactly at the cap is accepted (structure is all the server can check).
        $created = $this->postJson("/api/v1/recipients/{$recipient->id}/items", $this->payload($user, $recipient, ['content_enc' => $atLimit]))
            ->assertCreated()->json('item');

        $edit = $this->vault->encryptItem('t', 'b', $user->id, $recipient->id, $created['id']);
        $this->patchJson("/api/v1/vault-items/{$created['id']}", [...$edit, 'content_enc' => $overLimit])
            ->assertStatus(413)->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_an_item_id_can_never_be_reused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $recipient = $this->recipient($user);
        $item = $this->createItem($user, $recipient);
        $url = "/api/v1/recipients/{$recipient->id}/items";

        $this->postJson($url, $this->payload($user, $recipient, ['id' => $item['id']]))
            ->assertStatus(409)->assertJsonPath('error.code', 'ITEM_ID_CONFLICT');

        // Still reserved after the item is deleted.
        $this->deleteJson("/api/v1/vault-items/{$item['id']}")->assertOk();
        $this->postJson($url, $this->payload($user, $recipient, ['id' => $item['id']]))->assertStatus(409);

        // IDs are scoped per user: someone else using the same UUID learns nothing.
        $othersRecipient = $this->recipient($other, 'x@example.com');
        $this->actingAs($other)
            ->postJson("/api/v1/recipients/{$othersRecipient->id}/items", $this->payload($other, $othersRecipient, ['id' => $item['id']]))
            ->assertCreated();
    }

    public function test_items_per_recipient_are_capped(): void
    {
        config(['legacyvault.vault_items.max_per_recipient' => 2]);

        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $first = $this->createItem($user, $recipient);
        $this->createItem($user, $recipient);
        $url = "/api/v1/recipients/{$recipient->id}/items";

        $this->postJson($url, $this->payload($user, $recipient))
            ->assertStatus(403)->assertJsonPath('error.code', 'ITEM_LIMIT_REACHED');

        // Deleting one frees a slot — only live items count.
        $this->deleteJson("/api/v1/vault-items/{$first['id']}")->assertOk();
        $this->postJson($url, $this->payload($user, $recipient))->assertCreated();
    }

    public function test_reorder_rewrites_positions_without_touching_ciphertext(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $a = $this->createItem($user, $recipient, 'A');
        $b = $this->createItem($user, $recipient, 'B');
        $c = $this->createItem($user, $recipient, 'C');
        $url = "/api/v1/recipients/{$recipient->id}/items/reorder";

        // The list must be complete and contain nothing foreign or repeated.
        $this->postJson($url, ['order' => [$c['id'], $a['id']]])->assertStatus(422);
        $this->postJson($url, ['order' => [$c['id'], $a['id'], $b['id'], FakeClientVault::newItemId()]])->assertStatus(422);
        $this->postJson($url, ['order' => [$c['id'], $a['id'], $a['id']]])->assertStatus(422);
        $this->postJson($url, ['order' => 'nope'])->assertStatus(422);

        $items = $this->postJson($url, ['order' => [$c['id'], $a['id'], $b['id']]])->assertOk()->json('items');

        $this->assertSame([$c['id'], $a['id'], $b['id']], array_column($items, 'id'));
        $this->assertSame([1, 2, 3], array_column($items, 'position'));
        $this->assertSame($items, $this->getJson("/api/v1/recipients/{$recipient->id}/items")->json('items'));
        $this->assertSame($a['title_enc'], $items[1]['title_enc']);
        $this->assertSame($a['content_key_wrapped'], $items[1]['content_key_wrapped']);

        // A new item is appended after the reordered ones.
        $this->assertSame(4, $this->createItem($user, $recipient, 'D')['position']);

        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'vault_item.reordered')->sole()->metadata, true);
        $this->assertEqualsCanonicalizing(['recipient_id' => $recipient->id, 'item_count' => 3], $audit);
    }

    public function test_delete_soft_deletes_and_hides_the_item(): void
    {
        $user = User::factory()->create();
        $recipient = $this->recipient($user);
        $keep = $this->createItem($user, $recipient, 'Keep');
        $gone = $this->createItem($user, $recipient, 'Gone');

        // No step-up password is required (docs/19_Decision_Log.md Decision #17).
        $this->deleteJson("/api/v1/vault-items/{$gone['id']}")->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSoftDeleted('vault_items', ['uuid' => $gone['id']]);
        $this->assertNotSoftDeleted('vault_items', ['uuid' => $keep['id']]);
        $this->assertSame([$keep['id']], array_column($this->getJson("/api/v1/recipients/{$recipient->id}/items")->json('items'), 'id'));

        $this->getJson("/api/v1/vault-items/{$gone['id']}")->assertNotFound();
        $this->deleteJson("/api/v1/vault-items/{$gone['id']}")->assertNotFound();
        $edit = $this->vault->encryptItem('t', 'b', $user->id, $recipient->id, $gone['id']);
        $this->patchJson("/api/v1/vault-items/{$gone['id']}", $edit)->assertNotFound();

        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'vault_item.deleted')->sole()->metadata, true);
        $this->assertEqualsCanonicalizing(['item_id' => $gone['id'], 'recipient_id' => $recipient->id], $audit);
    }

    public function test_deleting_a_recipient_soft_deletes_their_items_with_the_same_timestamp(): void
    {
        $user = User::factory()->create();
        $layla = $this->recipient($user);
        $omar = $this->recipient($user, 'omar@example.com');
        $one = $this->createItem($user, $layla, 'One');
        $two = $this->createItem($user, $layla, 'Two');
        $omars = $this->createItem($user, $omar, 'Omar');
        $updatedAtBefore = DB::table('vault_items')->where('uuid', $one['id'])->value('updated_at');

        $this->deleteJson("/api/v1/recipients/{$layla->id}", ['password' => self::PASSWORD])->assertOk();

        $this->assertSoftDeleted('vault_items', ['uuid' => $one['id']]);
        $this->assertSoftDeleted('vault_items', ['uuid' => $two['id']]);
        $this->assertNotSoftDeleted('vault_items', ['uuid' => $omars['id']]);

        // Identical timestamps are what lets Phase 2c restore a recipient with its items.
        $recipientDeletedAt = DB::table('recipients')->where('id', $layla->id)->value('deleted_at');
        $this->assertNotNull($recipientDeletedAt);
        $this->assertSame(
            [$recipientDeletedAt, $recipientDeletedAt],
            DB::table('vault_items')->where('recipient_id', $layla->id)->pluck('deleted_at')->all(),
        );
        $this->assertSame($updatedAtBefore, DB::table('vault_items')->where('uuid', $one['id'])->value('updated_at'));

        // Nothing addressed to the deleted recipient is reachable any more.
        $this->getJson("/api/v1/recipients/{$layla->id}/items")->assertNotFound();
        $this->getJson("/api/v1/vault-items/{$one['id']}")->assertNotFound();
        $this->getJson("/api/v1/vault-items/{$omars['id']}")->assertOk();

        $audit = json_decode(DB::table('audit_logs')->where('event_type', 'recipient.deleted')->sole()->metadata, true);
        $this->assertSame(2, $audit['item_count']);
    }

    public function test_pending_and_anonymous_users_are_rejected(): void
    {
        $id = FakeClientVault::newItemId();

        $this->getJson('/api/v1/recipients/1/items')->assertStatus(401);
        $this->postJson('/api/v1/recipients/1/items', [])->assertStatus(401);
        $this->postJson('/api/v1/recipients/1/items/reorder', ['order' => []])->assertStatus(401);
        $this->getJson("/api/v1/vault-items/{$id}")->assertStatus(401);
        $this->patchJson("/api/v1/vault-items/{$id}", [])->assertStatus(401);
        $this->deleteJson("/api/v1/vault-items/{$id}")->assertStatus(401);

        // A pending registration (Recovery Key not yet acknowledged) can't build a vault.
        $pending = User::factory()->pending()->create();
        $recipient = $this->recipient($pending, 'p@example.com');
        $this->actingAs($pending);

        $this->getJson("/api/v1/recipients/{$recipient->id}/items")
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_ACTIVE');
        $this->postJson("/api/v1/recipients/{$recipient->id}/items", $this->payload($pending, $recipient))
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_ACTIVE');
        $this->postJson("/api/v1/recipients/{$recipient->id}/items/reorder", ['order' => []])
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_ACTIVE');

        $this->assertSame(0, VaultItem::query()->count());
    }
}
