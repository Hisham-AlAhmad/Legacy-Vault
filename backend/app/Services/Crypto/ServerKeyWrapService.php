<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use RuntimeException;

/**
 * The zero-access mechanism — docs/06_Cryptography.md §5.
 *
 * At registration the client sends the plaintext Master Key once, over TLS. This service
 * immediately wraps it with the Server Wrap Key (SWK, from environment configuration) and
 * the plaintext is discarded — never persisted, logged, or cached (documented honestly as
 * the one moment the server sees the plaintext Master Key).
 *
 * RESTRICTION (AI_RULES.md #13, docs/06_Cryptography.md §25): `wrap()` runs at registration
 * and backup import only. `unwrap()` exists so the mechanism is verifiably correct end-to-end
 * from Phase 1 (docs/17_Roadmap.md Phase 1), but the ONLY production caller permitted is
 * DeliveryTriggerService::executeDelivery() (Phase 3). Any other caller is a security defect.
 * PRs touching this file require a second reviewer (docs/16_Coding_Standards.md §6).
 */
final class ServerKeyWrapService
{
    public const WRAP_VERSION = 1;

    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12; // 96-bit, docs/06_Cryptography.md §8
    private const TAG_BYTES = 16;   // 128-bit, docs/06_Cryptography.md §9
    private const KEY_BYTES = 32;

    private string $serverWrapKey;

    public function __construct(?string $serverWrapKeyBase64)
    {
        if ($serverWrapKeyBase64 === null || $serverWrapKeyBase64 === '') {
            throw new RuntimeException('SERVER_WRAP_KEY is not configured.');
        }

        $key = base64_decode($serverWrapKeyBase64, true);

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('SERVER_WRAP_KEY must be base64 of exactly 32 bytes.');
        }

        $this->serverWrapKey = $key;
    }

    /**
     * Wrap a plaintext Master Key with the SWK. AAD binds the wrap to a user and scheme
     * version so ciphertext cannot be replayed under another user's row
     * (docs/06_Cryptography.md §9, AAD rationale).
     */
    public function wrap(string $masterKeyRaw, int $userId): WrappedKey
    {
        if (strlen($masterKeyRaw) !== self::KEY_BYTES) {
            throw new RuntimeException('Master Key must be exactly 32 bytes.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $masterKeyRaw,
            self::CIPHER,
            $this->serverWrapKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($userId),
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('SWK wrap failed.');
        }

        return new WrappedKey($ciphertext.$tag, $nonce, self::WRAP_VERSION);
    }

    /**
     * Unwrap an SWK-wrapped Master Key. See the class-level restriction: production use is
     * exclusively the audited delivery-trigger path (docs/13_Delivery_Engine.md §2).
     * A failed GCM tag is tampering, never a silent fallback (docs/06_Cryptography.md §9).
     */
    public function unwrap(string $wrappedKey, string $nonce, int $userId, int $version): string
    {
        if ($version !== self::WRAP_VERSION) {
            throw new RuntimeException("Unsupported SWK wrap version: {$version}.");
        }

        if (strlen($wrappedKey) <= self::TAG_BYTES) {
            throw new RuntimeException('Wrapped key too short.');
        }

        $ciphertext = substr($wrappedKey, 0, -self::TAG_BYTES);
        $tag = substr($wrappedKey, -self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->serverWrapKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($userId),
        );

        if ($plaintext === false) {
            // Tag failure = tampering; the caller audit-logs this as an integrity event.
            throw new RuntimeException('SWK unwrap failed: authentication tag mismatch.');
        }

        return $plaintext;
    }

    private static function aad(int $userId): string
    {
        return 'legacyvault:swk:v'.self::WRAP_VERSION.':user:'.$userId;
    }
}
