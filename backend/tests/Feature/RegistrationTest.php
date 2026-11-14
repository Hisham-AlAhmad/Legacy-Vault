<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\Crypto\ServerKeyWrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'quiet lantern orbits 42 mango';

    /** @return array<string, mixed> */
    private function registrationPayload(FakeClientVault $client, string $email): array
    {
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'locale' => 'en',
            'password_wrapped_master_key' => $client->wrapMasterKeyWith(self::PASSWORD),
            'recovery_wrapped_master_key' => $client->wrapMasterKeyWith(FakeClientVault::generateRecoveryKey()),
            'server_wrap_master_key_plaintext' => base64_encode($client->masterKey),
        ];
    }

    public function test_registration_creates_pending_account_with_full_key_hierarchy(): void
    {
        $client = new FakeClientVault();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload($client, 'reg@example.com'));

        $response->assertCreated()
            ->assertJsonPath('user.email', 'reg@example.com')
            ->assertJsonPath('user.status', 'pending')
            ->assertJsonPath('user.recovery_key_acknowledged', false);

        $user = User::query()->where('email', 'reg@example.com')->firstOrFail();

        // All three wrapped copies exist (docs/06_Cryptography.md §4).
        $this->assertSame(3, $user->encryptedKeys()->count());

        // The SWK wrap is correct end-to-end: unwrapping the stored server-wrapped copy
        // recovers the exact Master Key the client generated (docs/17_Roadmap.md Phase 1 —
        // this must be right from day one). Test-context-only unwrap (test SWK).
        $serverWrapped = $user->encryptedKeys()
            ->where('key_type', EncryptedKey::TYPE_SERVER_WRAPPED)->firstOrFail();

        $unwrapped = app(ServerKeyWrapService::class)->unwrap(
            $serverWrapped->wrapped_key,
            $serverWrapped->wrap_nonce,
            $user->id,
            $serverWrapped->wrap_version,
        );

        $this->assertSame($client->masterKey, $unwrapped);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event_type' => 'user.registered',
        ]);
    }

    public function test_acknowledging_recovery_key_activates_account(): void
    {
        $client = new FakeClientVault();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload($client, 'ack@example.com'))->assertCreated();

        $user = User::query()->where('email', 'ack@example.com')->firstOrFail();

        $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/acknowledge')->assertOk();

        $user->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->recovery_key_acknowledged_at);
    }

    public function test_weak_password_is_rejected_server_side(): void
    {
        $client = new FakeClientVault();
        $payload = $this->registrationPayload($client, 'weak@example.com');
        $payload['password'] = 'password12345'; // long but guessable — zxcvbn < 3 (AUTH-2)

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_active_email_collision_returns_conflict(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(new FakeClientVault(), 'taken@example.com'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_IN_USE');
    }

    public function test_stale_pending_registration_is_replaced(): void
    {
        User::factory()->pending()->create(['email' => 'retry@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(new FakeClientVault(), 'retry@example.com'))
            ->assertCreated();

        $this->assertSame(1, User::query()->where('email', 'retry@example.com')->count());
    }

    public function test_malformed_key_material_is_rejected(): void
    {
        $client = new FakeClientVault();
        $payload = $this->registrationPayload($client, 'bad@example.com');
        $payload['server_wrap_master_key_plaintext'] = base64_encode('not 32 bytes');

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'bad@example.com']);
    }
}
