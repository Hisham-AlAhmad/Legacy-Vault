<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * Response to POST /attachments/{uuid}/chunks (docs/08_API_Specification.md). The
 * attachment is included only when this chunk completed the upload.
 */
final readonly class ChunkReceiptDto
{
    public function __construct(
        public int $receivedChunk,
        public ?AttachmentDto $completedAttachment = null,
    ) {
    }
}
