<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\Crypto\ServerKeyWrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/**
 * THE Phase 1 exit criterion (docs/17_Roadmap.md):
 * "full registration → login → password-change → recovery round trip passes with real
 * crypto (no mocks) in the test suite."
 *
 * Every wrap/unwrap below is real Argon2id + real AES-256-GCM (docs/15_Testing.md §2);
 * FakeClientVault plays the browser's role in the key hierarchy.
 */
class FullKeyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_registration_login_password_change_recovery_round_trip(): void
    {
        $client = new FakeClientVault();
        $password1 = 'quiet lantern orbits 42 mango';
        $password2 = 'violet harbor sings 77 cedar';
        $password3 = 'amber falcon drifts 19 tundra';
        $recoveryKey1 = FakeClientVault::generateRecoveryKey();
        $recoveryKey2 = FakeClientVault::generateRecoveryKey();

        // ── 1. Register (Flow 1) ────────────────────────────────────────────────────
        $this->postJson('/api/v1/auth/register', [
            'email' => 'lifecycle@example.com',
            'password' => $password1,
            'locale' => 'en',
            'password_wrapped_master_key' => $client->wrapMasterKeyWith($password1),
            'recovery_wrapped_master_key' => $client->wrapMasterKeyWith($recoveryKey1),
            'server_wrap_master_key_plaintext' => base64_encode($client->masterKey),
        ])->assertCreated();

        $user = User::query()->where('email', 'lifecycle@example.com')->firstOrFail();
        $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/acknowledge')->assertOk();
        $this->resetAuthState();

        // ── 2. Login: unwrap Master Key with password-derived key ───────────────────
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'lifecycle@example.com',
            'password' => $password1,
        ])->assertOk();

        $this->assertSame(
            $client->masterKey,
            FakeClientVault::unwrap($login->json('password_wrapped_master_key'), $password1),
        );

        // ── 3. Change password (Flow 8): re-wrap Master Key with new PDK ────────────
        $user->refresh();
        $this->actingAs($user)->postJson('/api/v1/auth/password/change', [
            'current_password' => $password1,
            'new_password' => $password2,
            'password_wrapped_master_key' => $client->wrapMasterKeyWith($password2),
        ])->assertOk();
        $this->resetAuthState();

        $login2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'lifecycle@example.com',
            'password' => $password2,
        ])->assertOk();

        $this->assertSame(
            $client->masterKey,
            FakeClientVault::unwrap($login2->json('password_wrapped_master_key'), $password2),
        );

        // Old password no longer authenticates.
        $this->resetAuthState();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'lifecycle@example.com',
            'password' => $password1,
        ])->assertStatus(401);
        $this->app['cache']->clear();

        // ── 4. Password reset (Flow 6): account access restored, vault still locked ──
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'lifecycle@example.com'])->assertOk();

        // Grab the reset token by replacing the stored (hashed) one — the mail channel
        // is out of band for this test; token semantics are covered by using the API.
        $rawToken = 'test-reset-token-0123456789abcdef';
        DB::table('password_reset_tokens')->where('email', 'lifecycle@example.com')->update([
            'token' => Hash::make($rawToken),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'lifecycle@example.com',
            'token' => $rawToken,
            'new_password' => $password3,
        ])->assertOk();

        $login3 = $this->postJson('/api/v1/auth/login', [
            'email' => 'lifecycle@example.com',
            'password' => $password3,
        ])->assertOk();

        // AUTH-10: the new password authenticates but CANNOT decrypt the vault — the
        // stored wrap still uses the old PDK, so the client-side unwrap fails its GCM tag.
        $staleWrap = $login3->json('password_wrapped_master_key');
        $this->assertNull(FakeClientVault::unwrap($staleWrap, $password3));

        // ── 5. Recovery (Flow 7): unwrap via Recovery Key, re-wrap, rotate ──────────
        $user->refresh();
        $verify = $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/verify')->assertOk();

        $recovered = FakeClientVault::unwrap($verify->json('recovery_wrapped_master_key'), $recoveryKey1);
        $this->assertSame($client->masterKey, $recovered); // vault recovered with real crypto

        $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/rotate', [
            'password' => $password3,
            'recovery_wrapped_master_key' => $client->wrapMasterKeyWith($recoveryKey2),
            'password_wrapped_master_key' => $client->wrapMasterKeyWith($password3),
        ])->assertOk();
        $this->resetAuthState();

        // ── 6. Final state: everything consistent again ──────────────────────────────
        $login4 = $this->postJson('/api/v1/auth/login', [
            'email' => 'lifecycle@example.com',
            'password' => $password3,
        ])->assertOk();

        $this->assertSame(
            $client->masterKey,
            FakeClientVault::unwrap($login4->json('password_wrapped_master_key'), $password3),
        );

        // New Recovery Key works; the old one is invalidated (docs/06_Cryptography.md §11).
        $user->refresh();
        $verify2 = $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/verify')->assertOk();
        $this->assertSame($client->masterKey, FakeClientVault::unwrap($verify2->json('recovery_wrapped_master_key'), $recoveryKey2));
        $this->assertNull(FakeClientVault::unwrap($verify2->json('recovery_wrapped_master_key'), $recoveryKey1));

        // The SWK-wrapped copy still unwraps to the same Master Key — the zero-access
        // mechanism survived the entire credential lifecycle (docs/17_Roadmap.md Phase 1).
        $serverWrapped = $user->encryptedKeys()
            ->where('key_type', EncryptedKey::TYPE_SERVER_WRAPPED)->firstOrFail();

        $this->assertSame($client->masterKey, app(ServerKeyWrapService::class)->unwrap(
            $serverWrapped->wrapped_key,
            $serverWrapped->wrap_nonce,
            $user->id,
            $serverWrapped->wrap_version,
        ));

        // Audit trail covers the lifecycle (docs/05_Security_Architecture.md §17).
        foreach ([
            'user.registered', 'recovery_key.acknowledged', 'auth.login_success',
            'auth.password_changed', 'auth.password_reset_requested',
            'auth.password_reset_completed', 'recovery_key.recovery_started',
            'recovery_key.rotated',
        ] as $event) {
            $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => $event]);
        }
    }

    private function resetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
