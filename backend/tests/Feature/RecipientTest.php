<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * Recipients CRUD — Phase 2a (VAULT-4, docs/02_User_Flows.md Flow 2 steps 1–3). Sealed
 * fields are produced with real HKDF + AES-256-GCM by the test client, never stubbed
 * (AI_RULES.md #6).
 */
class RecipientTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple 9!'; // UserFactory default

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
    }

    /** @return array<string, mixed> */
    private function payload(User $user, array $overrides = []): array
    {
        return [
            'name_enc' => $this->vault->sealField('Layla Haddad', 'recipient.name', $user->id),
            'relationship_enc' => $this->vault->sealField('Daughter', 'recipient.relationship', $user->id),
            'email' => 'layla@example.com',
            'phone' => '+962791234567',
            'preferred_methods' => ['email', 'sms'],
            ...$overrides,
        ];
    }

    public function test_create_stores_only_ciphertext_and_round_trips(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/recipients', $this->payload($user))->assertCreated();

        $recipient = $response->json('recipient');
        $this->assertSame('layla@example.com', $recipient['email']);
        $this->assertSame(['email', 'sms'], $recipient['preferred_methods']);
        $this->assertFalse($recipient['is_emergency_contact']);
        $this->assertSame('Layla Haddad', $this->vault->openField($recipient['name_enc'], 'recipient.name', $user->id));
        $this->assertSame('Daughter', $this->vault->openField($recipient['relationship_enc'], 'recipient.relationship', $user->id));

        // A raw database read reveals neither the name nor the relationship (docs/18_Threat_Model.md scenario 1).
        $row = (array) DB::table('recipients')->where('id', $recipient['id'])->first();
        $dump = implode('|', array_map('strval', $row));
        $this->assertStringNotContainsString('Layla', $dump);
        $this->assertStringNotContainsString('Daughter', $dump);

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'recipient.created']);
        $audit = DB::table('audit_logs')->where('event_type', 'recipient.created')->value('metadata');
        $this->assertStringNotContainsString('layla@example.com', $audit);
    }

    public function test_list_returns_only_own_recipients(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson('/api/v1/recipients', $this->payload($alice))->assertCreated();
        $this->actingAs($bob)->postJson('/api/v1/recipients', $this->payload($bob, ['email' => 'other@example.com']))->assertCreated();

        $list = $this->actingAs($alice)->getJson('/api/v1/recipients')->assertOk()->json('recipients');

        $this->assertCount(1, $list);
        $this->assertSame('layla@example.com', $list[0]['email']);
    }

    public function test_delivery_methods_must_be_backed_by_contact_details(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/v1/recipients', $this->payload($user, ['phone' => null, 'preferred_methods' => ['sms']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['phone']]]);

        $this->postJson('/api/v1/recipients', $this->payload($user, ['email' => null, 'preferred_methods' => ['email']]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);

        // "link" alone still needs some way to reach the person.
        $this->postJson('/api/v1/recipients', $this->payload($user, ['email' => null, 'phone' => null, 'preferred_methods' => ['link']]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);

        $this->postJson('/api/v1/recipients', $this->payload($user, ['preferred_methods' => []]))->assertStatus(422);
        $this->postJson('/api/v1/recipients', $this->payload($user, ['preferred_methods' => ['push']]))->assertStatus(422);
        $this->postJson('/api/v1/recipients', $this->payload($user, ['phone' => '0791234567']))->assertStatus(422);

        $this->assertDatabaseCount('recipients', 0);
    }

    public function test_malformed_or_oversized_sealed_fields_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $wrongVersion = base64_decode($this->vault->sealField('Omar', 'recipient.name', $user->id));
        $wrongVersion[0] = "\x02";

        foreach ([
            'not-base64!!',
            base64_encode(random_bytes(20)), // shorter than the envelope
            base64_encode($wrongVersion),
            base64_encode("\x01".random_bytes(600)), // over the 512-byte cap
        ] as $bad) {
            $this->postJson('/api/v1/recipients', $this->payload($user, ['name_enc' => $bad]))
                ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name_enc']]]);
        }
    }

    public function test_partial_update_rechecks_reachability_and_audits_field_names(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/recipients', $this->payload($user))->json('recipient.id');

        // Removing the phone while SMS is still selected is rejected against the merged state.
        $this->patchJson("/api/v1/recipients/{$id}", ['phone' => null])->assertStatus(422);

        $renamed = $this->vault->sealField('Layla H.', 'recipient.name', $user->id);
        $updated = $this->patchJson("/api/v1/recipients/{$id}", [
            'name_enc' => $renamed,
            'phone' => null,
            'preferred_methods' => ['email'],
        ])->assertOk()->json('recipient');

        $this->assertNull($updated['phone']);
        $this->assertSame('layla@example.com', $updated['email']); // untouched field kept
        $this->assertSame('Layla H.', $this->vault->openField($updated['name_enc'], 'recipient.name', $user->id));

        $metadata = json_decode(
            DB::table('audit_logs')->where('event_type', 'recipient.updated')->value('metadata'),
            true,
        );
        $this->assertEqualsCanonicalizing(['name_enc', 'phone', 'preferred_methods'], $metadata['changed_fields']);
    }

    public function test_another_users_recipient_is_not_found(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $id = $this->actingAs($owner)->postJson('/api/v1/recipients', $this->payload($owner))->json('recipient.id');

        $this->actingAs($intruder);
        $this->patchJson("/api/v1/recipients/{$id}", ['email' => 'x@example.com'])
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->deleteJson("/api/v1/recipients/{$id}", ['password' => self::PASSWORD])->assertNotFound();
        $this->patchJson('/api/v1/recipients/abc', ['email' => 'x@example.com'])->assertNotFound();

        $this->assertDatabaseHas('recipients', ['id' => $id, 'email' => 'layla@example.com', 'deleted_at' => null]);
    }

    public function test_delete_requires_password_and_soft_deletes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/recipients', $this->payload($user))->json('recipient.id');

        $this->deleteJson("/api/v1/recipients/{$id}")->assertStatus(422);
        $this->deleteJson("/api/v1/recipients/{$id}", ['password' => 'not my password at all'])
            ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertNotSoftDeleted('recipients', ['id' => $id]);

        $this->deleteJson("/api/v1/recipients/{$id}", ['password' => self::PASSWORD])->assertOk();

        $this->assertSoftDeleted('recipients', ['id' => $id]);
        $this->assertCount(0, $this->getJson('/api/v1/recipients')->json('recipients'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'recipient.deleted']);
    }

    public function test_pending_and_anonymous_users_are_rejected(): void
    {
        $this->getJson('/api/v1/recipients')->assertStatus(401);

        $pending = User::factory()->pending()->create();
        $this->actingAs($pending)->postJson('/api/v1/recipients', $this->payload($pending))
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_ACTIVE');

        $this->assertSame(0, Recipient::query()->count());
    }
}
