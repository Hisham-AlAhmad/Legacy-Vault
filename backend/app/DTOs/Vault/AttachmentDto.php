<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

use App\Models\Attachment;

/**
 * Attachment as returned by the API (AI_RULES.md #4). `id` is the client-generated UUID
 * and `itemId` the owning item's UUID — numeric keys and the storage path never leave the
 * backend. The three envelopes go back as base64 for the client to open; the file itself
 * is fetched separately (docs/08_API_Specification.md §Attachments).
 */
final readonly class AttachmentDto
{
    public function __construct(
        public string $id,
        public string $itemId,
        public string $filenameEncB64,
        public string $mimeTypeEncB64,
        public string $attachmentKeyWrappedB64,
        public int $sizeBytes,
        public int $chunkCount,
        public int $receivedChunks,
        public string $status,
        public int $aadVersion,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
        public ?string $purgeAt = null,
    ) {
    }

    /** `$itemUuid` is passed in so a list doesn't load the item once per attachment. */
    public static function fromModel(Attachment $attachment, string $itemUuid): self
    {
        return new self(
            id: $attachment->uuid,
            itemId: $itemUuid,
            filenameEncB64: base64_encode($attachment->filename_enc),
            mimeTypeEncB64: base64_encode($attachment->mime_type_enc),
            attachmentKeyWrappedB64: base64_encode($attachment->attachment_key_wrapped),
            sizeBytes: $attachment->size_bytes,
            chunkCount: $attachment->chunk_count,
            receivedChunks: $attachment->received_chunks,
            status: $attachment->isComplete() ? Attachment::STATUS_COMPLETE : Attachment::STATUS_UPLOADING,
            aadVersion: $attachment->aad_version,
            createdAt: $attachment->created_at->toIso8601String(),
            updatedAt: $attachment->updated_at->toIso8601String(),
            deletedAt: $attachment->deleted_at?->toIso8601String(),
            purgeAt: $attachment->deleted_at?->copy()
                ->addDays((int) config('legacyvault.trash.undo_window_days'))->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'filename_enc' => $this->filenameEncB64,
            'mime_type_enc' => $this->mimeTypeEncB64,
            'attachment_key_wrapped' => $this->attachmentKeyWrappedB64,
            'size_bytes' => $this->sizeBytes,
            'chunk_count' => $this->chunkCount,
            'received_chunks' => $this->receivedChunks,
            'status' => $this->status,
            'aad_version' => $this->aadVersion,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];

        // Only present in the recently-deleted list (GET /trash).
        if ($this->deletedAt !== null) {
            $data['deleted_at'] = $this->deletedAt;
            $data['purge_at'] = $this->purgeAt;
        }

        return $data;
    }
}
