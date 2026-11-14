<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A PHP stand-in for the browser's crypto module (frontend/src/crypto) so feature tests can
 * exercise the FULL key hierarchy with real crypto — real Argon2id (libsodium), real
 * AES-256-GCM — never a disabled-crypto path (AI_RULES.md #6, docs/15_Testing.md §2).
 *
 * Mirrors the client contract: Master Key (32 random bytes) wrapped by an Argon2id-derived
 * key (docs/06_Cryptography.md §4, §6 — 64 MiB / 3 iterations; libsodium's argon2id fixes
 * parallelism at 1, an accepted test-client difference from the browser's p=4).
 */
final class FakeClientVault
{
    public string $masterKey;

    public function __construct()
    {
        $this->masterKey = random_bytes(32);
    }

    /** @return array{ciphertext: string, nonce: string, kdf_salt: string} base64 fields */
    public function wrapMasterKeyWith(string $secret): array
    {
        $salt = random_bytes(16); // docs/06_Cryptography.md §7
        $wrappingKey = self::deriveKey($secret, $salt);
        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $this->masterKey,
            'aes-256-gcm',
            $wrappingKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Test client wrap failed.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext.$tag),
            'nonce' => base64_encode($nonce),
            'kdf_salt' => base64_encode($salt),
        ];
    }

    /**
     * Unwrap a wrapped-key payload as returned by the API. Returns null when the secret is
     * wrong (GCM tag failure) — exactly how the real client detects a stale wrap after a
     * password reset (docs/02_User_Flows.md Flow 6).
     *
     * @param array{ciphertext: string, nonce: string, kdf_salt: ?string} $payload
     */
    public static function unwrap(array $payload, string $secret): ?string
    {
        $blob = base64_decode($payload['ciphertext'], true);
        $nonce = base64_decode($payload['nonce'], true);
        $salt = base64_decode((string) $payload['kdf_salt'], true);

        if ($blob === false || $nonce === false || $salt === false) {
            throw new RuntimeException('Malformed wrapped-key payload.');
        }

        $wrappingKey = self::deriveKey($secret, $salt);
        $ciphertext = substr($blob, 0, -16);
        $tag = substr($blob, -16);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $wrappingKey, OPENSSL_RAW_DATA, $nonce, $tag, '');

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Seal a metadata field exactly as the browser does (frontend/src/crypto sealField):
     * Metadata Key = HKDF-SHA256(Master Key, info "legacy-vault/v1/metadata-key"),
     * AAD = "legacy-vault|v1|{field}|user:{id}", output base64(0x01 ‖ nonce ‖ ct ‖ tag)
     * (docs/06_Cryptography.md §15). `$nonce` is only fixed by known-answer tests.
     */
    public function sealField(string $plaintext, string $field, int $userId, ?string $nonce = null): string
    {
        $nonce ??= random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->metadataKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::metadataAad($field, $userId),
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Test client seal failed.');
        }

        return base64_encode("\x01".$nonce.$ciphertext.$tag);
    }

    /** Open a sealed field; null on any integrity failure (wrong key, AAD, tampering). */
    public function openField(string $sealedB64, string $field, int $userId): ?string
    {
        $sealed = base64_decode($sealedB64, true);

        if ($sealed === false || strlen($sealed) < 29 || $sealed[0] !== "\x01") {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($sealed, 13, -16),
            'aes-256-gcm',
            $this->metadataKey(),
            OPENSSL_RAW_DATA,
            substr($sealed, 1, 12),
            substr($sealed, -16),
            self::metadataAad($field, $userId),
        );

        return $plaintext === false ? null : $plaintext;
    }

    public function metadataKey(): string
    {
        return hash_hkdf('sha256', $this->masterKey, 32, 'legacy-vault/v1/metadata-key', '');
    }

    private static function metadataAad(string $field, int $userId): string
    {
        return "legacy-vault|v1|{$field}|user:{$userId}";
    }

    public static function generateRecoveryKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function deriveKey(string $secret, string $salt): string
    {
        // Real Argon2id — 64 MiB memory, 3 iterations (docs/06_Cryptography.md §6).
        return sodium_crypto_pwhash(
            32,
            $secret,
            $salt,
            3,
            64 * 1024 * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
