<?php

declare(strict_types=1);

namespace App\Services\Crypto;

/**
 * Immutable result of an SWK wrap operation — raw bytes, never persisted or logged directly.
 */
final readonly class WrappedKey
{
    public function __construct(
        public string $ciphertext, // AES-256-GCM ciphertext with 16-byte tag appended
        public string $nonce,      // 12-byte GCM nonce
        public int $version,
    ) {
    }
}
