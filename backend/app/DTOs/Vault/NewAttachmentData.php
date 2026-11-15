<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * Input for POST /vault-items/{uuid}/attachments — the declaration that precedes the
 * chunks (docs/19_Decision_Log.md Decision #25). `uuid` is client-chosen so it can be
 * bound into the AAD before the first byte is encrypted (Decision #21); the three
 * envelopes are already-decoded ciphertext bytes the server never opens.
 */
final readonly class NewAttachmentData
{
    public function __construct(
        public string $uuid,
        public string $filenameEnc,
        public string $mimeTypeEnc,
        public string $attachmentKeyWrapped,
        public int $sizeBytes,
        public int $chunkCount,
        public int $aadVersion,
    ) {
    }
}
