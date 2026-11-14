<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\WrappedKeyDto;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Login — docs/08_API_Specification.md POST /auth/login,
 * docs/05_Security_Architecture.md §1 (backoff, timing normalization), §3 (sessions).
 *
 * Returns the password-wrapped Master Key copy so the client can unwrap the Master Key
 * locally; the server never learns it (docs/06_Cryptography.md §4).
 */
final class AttemptLoginAction
{
    // Fixed Argon2id hash of a random string — verified on nonexistent-user attempts so
    // response timing doesn't reveal whether the email exists (§13 timing attacks).
    private const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$WGRQd1lYZGxjbTF2Y2c$L7yCkkLB5AqhoOFR4B7pFRsUu86zQt3TrDeXZ0y+vhM';

    public function __construct(
        private readonly TotpService $totp,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{user: User, password_wrapped_master_key: WrappedKeyDto}
     */
    public function execute(
        string $email,
        #[SensitiveParameter] string $password,
        ?string $totpCode,
        bool $remember,
        Request $request,
    ): array {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH); // timing normalization
            throw ApiException::invalidCredentials();
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            throw ApiException::accountLocked();
        }

        if (! Hash::check($password, $user->password_hash)) {
            $this->recordFailure($user, $request->ip());
            throw ApiException::invalidCredentials();
        }

        if ($user->status === User::STATUS_SUSPENDED) {
            throw new ApiException('ACCOUNT_SUSPENDED', 'legacyvault.auth.account_suspended', 403);
        }

        if ($user->status === User::STATUS_PENDING) {
            // Recovery Key was displayed once and never acknowledged — the account was
            // never finalized; the user must register again (Flow 1 edge case).
            throw new ApiException('ACCOUNT_NOT_FINALIZED', 'legacyvault.auth.account_not_finalized', 403);
        }

        if ($user->two_factor_enabled) {
            $this->verifySecondFactor($user, $totpCode);
        }

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $device = $this->touchDevice($user, $request, $remember);

        $this->audit->log($user->id, 'user', 'auth.login_success', [
            'device_id' => $device->id,
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ], $request->ip());

        $passwordWrapped = $user->encryptedKeys()
            ->where('key_type', EncryptedKey::TYPE_PASSWORD_WRAPPED)
            ->firstOrFail();

        return [
            'user' => $user,
            'password_wrapped_master_key' => WrappedKeyDto::fromModel($passwordWrapped),
        ];
    }

    private function verifySecondFactor(User $user, ?string $totpCode): void
    {
        if ($totpCode === null || $totpCode === '') {
            throw ApiException::totpRequired();
        }

        $secret = $user->two_factor_secret_enc;

        if ($secret !== null && $this->totp->verify($secret, $totpCode)) {
            return;
        }

        if ($this->consumeBackupCode($user, $totpCode)) {
            return;
        }

        $this->recordFailure($user, request()->ip());
        throw ApiException::totpInvalid();
    }

    /** Backup codes are single-use and hashed at rest (docs/05_Security_Architecture.md §1). */
    private function consumeBackupCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_backup_codes_hash ?? [];

        foreach ($hashes as $index => $hash) {
            if (password_verify(strtoupper($code), $hash)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_backup_codes_hash' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Exponential backoff per account: after the threshold, lock for 2^(n-threshold)
     * seconds, capped (docs/05_Security_Architecture.md §1). The per-IP limit is enforced
     * independently by the route rate limiter.
     */
    private function recordFailure(User $user, ?string $ip): void
    {
        $failures = $user->failed_login_count + 1;
        $config = config('legacyvault.lockout');

        $lockedUntil = null;
        if ($failures >= $config['threshold']) {
            $seconds = min(2 ** ($failures - $config['threshold']), $config['max_backoff_seconds']);
            $lockedUntil = now()->addSeconds($seconds);
        }

        $user->forceFill([
            'failed_login_count' => $failures,
            'locked_until' => $lockedUntil,
        ])->save();

        $this->audit->log($user->id, 'user', 'auth.login_failed', [
            'failed_count' => $failures,
        ], $ip);
    }

    private function touchDevice(User $user, Request $request, bool $remember): Device
    {
        // UA + coarse network info only — deliberately not invasive fingerprinting
        // (docs/07_Database_Design.md §sessions/devices).
        $fingerprint = hash('sha256', implode('|', [
            (string) $request->userAgent(),
            // /24 (or /64 for IPv6) network prefix, not the full IP
            preg_replace('/(\.\d+|:[0-9a-f]*)$/i', '', (string) $request->ip()),
        ]));

        $device = Device::query()->firstOrNew([
            'user_id' => $user->id,
            'fingerprint_hash' => $fingerprint,
        ]);

        $device->fill([
            'trusted' => $remember || $device->trusted,
            'last_seen_at' => now(),
        ]);
        $device->user_id = $user->id;
        $device->save();

        // Link the database session row to the device for the trusted-devices UI.
        $request->session()->put('device_id', $device->id);

        return $device;
    }
}
