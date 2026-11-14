<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'quiet lantern orbits 42 mango';

    private function registeredUser(string $email = 'user@example.com'): array
    {
        $client = new FakeClientVault();

        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => self::PASSWORD,
            'locale' => 'en',
            'password_wrapped_master_key' => $client->wrapMasterKeyWith(self::PASSWORD),
            'recovery_wrapped_master_key' => $client->wrapMasterKeyWith(FakeClientVault::generateRecoveryKey()),
            'server_wrap_master_key_plaintext' => base64_encode($client->masterKey),
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($user)->postJson('/api/v1/vault/recovery-key/acknowledge')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [$user->fresh(), $client];
    }

    public function test_login_returns_password_wrapped_master_key_unwrappable_by_client(): void
    {
        [, $client] = $this->registeredUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
        ])->assertOk();

        $wrapped = $response->json('password_wrapped_master_key');

        // Real crypto: the returned wrap must decrypt to the original Master Key.
        $this->assertSame($client->masterKey, FakeClientVault::unwrap($wrapped, self::PASSWORD));

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'auth.login_success']);
        $this->assertDatabaseHas('devices', ['user_id' => $response->json('user.id')]);
    }

    public function test_wrong_password_is_generic_and_audited(): void
    {
        $this->registeredUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'not the password 123',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        // Identical error for a nonexistent account (no enumeration, AUTH-9 spirit).
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'whatever whatever 123',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertDatabaseHas('audit_logs', ['event_type' => 'auth.login_failed']);
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        [$user] = $this->registeredUser();

        for ($i = 0; $i < 5; $i++) {
            // Bypass the route rate limiter by calling the action through HTTP would 429;
            // simulate accumulated failures directly, then verify the lock response.
            $user->forceFill(['failed_login_count' => $i])->save();
            $this->postJson('/api/v1/auth/login', [
                'email' => 'user@example.com',
                'password' => 'wrong password here 1',
            ]);
            $this->app['cache']->clear(); // reset the per-IP throttle between attempts
        }

        $user->refresh();
        $this->assertNotNull($user->locked_until);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
        ])->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
    }

    public function test_pending_account_cannot_login(): void
    {
        $pending = User::factory()->pending()->create([
            'email' => 'pending@example.com',
            'password_hash' => Hash::make(self::PASSWORD),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@example.com',
            'password' => self::PASSWORD,
        ])->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_NOT_FINALIZED');
    }

    public function test_two_factor_flow_end_to_end(): void
    {
        [$user] = $this->registeredUser();

        // Enroll: enable (step-up with password) → confirm with a real TOTP code.
        $enable = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enable', [
            'password' => self::PASSWORD,
        ])->assertOk();

        $secret = $enable->json('secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $confirm = $this->actingAs($user)->postJson('/api/v1/auth/2fa/confirm', [
            'code' => $code,
        ])->assertOk();

        $backupCodes = $confirm->json('backup_codes');
        $this->assertCount(10, $backupCodes);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // Login without code → TOTP_REQUIRED.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
        ])->assertStatus(401)->assertJsonPath('error.code', 'TOTP_REQUIRED');

        // Login with a valid TOTP code → success.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
            'totp_code' => (new Google2FA())->getCurrentOtp($secret),
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // A backup code works exactly once.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
            'totp_code' => $backupCodes[0],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->app['cache']->clear();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => self::PASSWORD,
            'totp_code' => $backupCodes[0],
        ])->assertStatus(401)->assertJsonPath('error.code', 'TOTP_INVALID');
    }
}
