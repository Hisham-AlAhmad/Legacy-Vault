<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * POST /vault/unlock — re-unlocking inside an existing session after a page reload
 * (docs/19_Decision_Log.md Decision #12). Real Argon2id + AES-256-GCM wrap.
 */
class VaultUnlockTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'quiet lantern orbits 42 mango';

    private function userWithRealWrap(FakeClientVault $vault): User
    {
        $user = User::factory()->create(['password_hash' => Hash::make(self::PASSWORD)]);
        $wrapped = $vault->wrapMasterKeyWith(self::PASSWORD);

        $user->encryptedKeys()->create([
            'key_type' => 'password_wrapped_master',
            'wrapped_key' => base64_decode($wrapped['ciphertext']),
            'wrap_nonce' => base64_decode($wrapped['nonce']),
            'kdf_salt' => base64_decode($wrapped['kdf_salt']),
            'wrap_version' => 1,
        ]);

        return $user;
    }

    public function test_correct_password_returns_a_wrap_the_client_can_open(): void
    {
        $vault = new FakeClientVault();
        $user = $this->userWithRealWrap($vault);

        $payload = $this->actingAs($user)
            ->postJson('/api/v1/vault/unlock', ['password' => self::PASSWORD])
            ->assertOk()
            ->json('password_wrapped_master_key');

        $this->assertSame('password_wrapped_master', $payload['key_type']);
        $this->assertSame($vault->masterKey, FakeClientVault::unwrap($payload, self::PASSWORD));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'vault.unlocked']);
    }

    public function test_wrong_password_returns_nothing_and_is_audited(): void
    {
        $user = $this->userWithRealWrap(new FakeClientVault());

        $this->actingAs($user)
            ->postJson('/api/v1/vault/unlock', ['password' => 'not the password'])
            ->assertStatus(401)
            ->assertJsonMissingPath('password_wrapped_master_key');

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'vault.unlock_failed']);
    }

    public function test_step_up_attempts_are_rate_limited(): void
    {
        $user = $this->userWithRealWrap(new FakeClientVault());
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/vault/unlock', ['password' => "guess {$i}"])->assertStatus(401);
        }

        $this->postJson('/api/v1/vault/unlock', ['password' => self::PASSWORD])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
