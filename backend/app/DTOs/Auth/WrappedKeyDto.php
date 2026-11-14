<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\EncryptedKey;

/**
 * A wrapped key copy returned to the client (login response / recovery flow) so the
 * browser can unwrap the Master Key locally. Only ever built from password- or
 * recovery-wrapped rows — the server_wrapped_master row is never sent to any client
 * (docs/06_Cryptography.md §21).
 */
final readonly class WrappedKeyDto
{
    public function __construct(
        public string $keyType,
        public string $ciphertextB64,
        public string $nonceB64,
        public ?string $kdfSaltB64,
        public int $wrapVersion,
    ) {
    }

    public static function fromModel(EncryptedKey $key): self
    {
        if ($key->key_type === EncryptedKey::TYPE_SERVER_WRAPPED) {
            throw new \LogicException('The server-wrapped Master Key copy must never leave the server.');
        }

        return new self(
            keyType: $key->key_type,
            ciphertextB64: base64_encode($key->wrapped_key),
            nonceB64: base64_encode($key->wrap_nonce),
            kdfSaltB64: $key->kdf_salt !== null ? base64_encode($key->kdf_salt) : null,
            wrapVersion: $key->wrap_version,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key_type' => $this->keyType,
            'ciphertext' => $this->ciphertextB64,
            'nonce' => $this->nonceB64,
            'kdf_salt' => $this->kdfSaltB64,
            'wrap_version' => $this->wrapVersion,
        ];
    }
}
