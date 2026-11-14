<?php

declare(strict_types=1);

namespace App\Services\Auth;

use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) enrollment/verification — docs/05_Security_Architecture.md §1.
 * Secrets are stored encrypted at the app level (users.two_factor_secret_enc); backup codes
 * are hashed at rest like passwords.
 */
final class TotpService
{
    public const BACKUP_CODE_COUNT = 10;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    /** Generate a fresh base32 TOTP secret for enrollment. */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** Verify a 6-digit code against a secret (±1 time-step window). */
    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    /** otpauth:// provisioning URI for authenticator apps. */
    public function provisioningUri(string $email, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('Legacy Vault', $email, $secret);
    }

    /**
     * Generate 10 single-use backup codes. Returns [plaintext codes, argon2id hashes];
     * plaintext is shown to the user exactly once and never stored.
     *
     * @return array{codes: list<string>, hashes: list<string>}
     */
    public function generateBackupCodes(): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            // 10 hex chars, grouped for readability: XXXXX-XXXXX
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $code = substr($raw, 0, 5).'-'.substr($raw, 5);
            $codes[] = $code;
            $hashes[] = password_hash($code, PASSWORD_ARGON2ID, [
                'memory_cost' => 19456,
                'time_cost' => 2,
                'threads' => 1,
            ]);
        }

        return ['codes' => $codes, 'hashes' => $hashes];
    }
}
