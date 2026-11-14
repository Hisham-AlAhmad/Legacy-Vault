<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'quiet lantern orbits 42 mango';

    /** Factory users need wrapped-key rows for login to succeed (opaque bytes suffice here). */
    private function userWithKeys(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password_hash' => Hash::make(self::PASSWORD),
        ]);

        foreach (['password_wrapped_master', 'recovery_wrapped_master', 'server_wrapped_master'] as $type) {
            $user->encryptedKeys()->create([
                'key_type' => $type,
                'wrapped_key' => random_bytes(48),
                'wrap_nonce' => random_bytes(12),
                'kdf_salt' => $type === 'server_wrapped_master' ? null : random_bytes(16),
                'wrap_version' => 1,
            ]);
        }

        return $user;
    }

    public function test_sessions_are_listed_and_revocable(): void
    {
        $user = $this->userWithKeys('devices@example.com');

        // Two real logins → two session rows.
        $this->postJson('/api/v1/auth/login', ['email' => 'devices@example.com', 'password' => self::PASSWORD])->assertOk();
        $firstSessionId = $this->app['session']->getId();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->postJson('/api/v1/auth/login', ['email' => 'devices@example.com', 'password' => self::PASSWORD])->assertOk();
        $secondSessionId = $this->app['session']->getId();

        // The test client doesn't carry cookies automatically (and drops them entirely on
        // JSON requests unless withCredentials is set) — attach the session cookie
        // explicitly so the server sees the same session, as a browser would.
        $asSecondSession = fn () => $this->withCredentials()
            ->withCookie(config('session.cookie'), $secondSessionId);

        $list = $asSecondSession()->getJson('/api/v1/auth/sessions')->assertOk()->json('sessions');
        $this->assertCount(2, $list);

        // Raw session IDs are never exposed — only hashes.
        foreach ($list as $session) {
            $this->assertSame(64, strlen($session['id']));
        }

        // Revoke the non-current session (no re-auth needed, Flow 10).
        $other = collect($list)->firstWhere('is_current', false);
        $current = collect($list)->firstWhere('is_current', true);
        $this->assertNotNull($other);
        $this->assertNotNull($current);

        $asSecondSession()->deleteJson('/api/v1/auth/sessions/'.$other['id'])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $firstSessionId]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'session.revoked']);

        // Revoking the current session requires the password (Flow 10 step 3).
        $asSecondSession()->deleteJson('/api/v1/auth/sessions/'.$current['id'])->assertStatus(401);
        $asSecondSession()->deleteJson('/api/v1/auth/sessions/'.$current['id'], ['password' => self::PASSWORD])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => $secondSessionId]);
    }

    public function test_login_history_is_returned_from_audit_log(): void
    {
        $this->userWithKeys('history@example.com');

        $this->postJson('/api/v1/auth/login', ['email' => 'history@example.com', 'password' => 'wrong wrong wrong 1'])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'history@example.com', 'password' => self::PASSWORD])->assertOk();

        $entries = $this->getJson('/api/v1/auth/login-history')->assertOk()->json('entries');

        $types = array_column($entries, 'event_type');
        $this->assertContains('auth.login_success', $types);
        $this->assertContains('auth.login_failed', $types);
    }

    public function test_security_headers_present(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }
}
