<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\TotpService;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * TOTP 2FA enrollment/teardown — AUTH-4, docs/05_Security_Architecture.md §1.
 * Grouped here as three closely-coupled steps of one use-case (enable → confirm → disable);
 * each public method is a single use-case entry point.
 */
final class TwoFactorActions
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Begin enrollment: generate a pending secret. Not active until confirmed with a
     * valid code. Step-up: password required.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function enable(User $user, #[SensitiveParameter] string $password): array
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        if ($user->two_factor_enabled) {
            throw new ApiException('TOTP_ALREADY_ENABLED', 'legacyvault.auth.totp_already_enabled', 409);
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill(['two_factor_pending_secret_enc' => $secret])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri($user->email, $secret),
        ];
    }

    /**
     * Confirm enrollment with a valid code; activates 2FA and issues the 10 single-use
     * backup codes (returned exactly once, hashed at rest).
     *
     * @return array{backup_codes: list<string>}
     */
    public function confirm(User $user, string $code, ?string $ipAddress): array
    {
        $pending = $user->two_factor_pending_secret_enc;

        if ($pending === null) {
            throw new ApiException('TOTP_ENROLLMENT_NOT_STARTED', 'legacyvault.auth.totp_enrollment_not_started', 409);
        }

        if (! $this->totp->verify($pending, $code)) {
            throw ApiException::totpInvalid();
        }

        $backup = $this->totp->generateBackupCodes();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret_enc' => $pending,
            'two_factor_pending_secret_enc' => null,
            'two_factor_backup_codes_hash' => $backup['hashes'],
        ])->save();

        $this->audit->log($user->id, 'user', 'auth.2fa_enabled', [], $ipAddress);

        return ['backup_codes' => $backup['codes']];
    }

    /** Disable 2FA. Step-up: password AND a current valid code (or backup code). */
    public function disable(
        User $user,
        #[SensitiveParameter] string $password,
        string $code,
        ?string $ipAddress,
    ): void {
        if (! Hash::check($password, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        if (! $user->two_factor_enabled) {
            throw new ApiException('TOTP_NOT_ENABLED', 'legacyvault.auth.totp_not_enabled', 409);
        }

        $secret = $user->two_factor_secret_enc;

        if ($secret === null || ! $this->totp->verify($secret, $code)) {
            throw ApiException::totpInvalid();
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret_enc' => null,
            'two_factor_backup_codes_hash' => null,
        ])->save();

        $this->audit->log($user->id, 'user', 'auth.2fa_disabled', [], $ipAddress);
    }
}
