<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

/**
 * A client-supplied wrapped Master Key copy (already ciphertext when it reaches us —
 * the client wrapped it with a PDK or RDK in-browser, docs/06_Cryptography.md §4).
 */
final readonly class WrappedKeyInput
{
    public function __construct(
        public string $ciphertextB64,
        public string $nonceB64,
        public string $kdfSaltB64,
    ) {
    }

    /** @param array{ciphertext: string, nonce: string, kdf_salt: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['ciphertext'], $data['nonce'], $data['kdf_salt']);
    }
}
