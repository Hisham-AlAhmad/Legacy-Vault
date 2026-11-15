<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\AttachmentDto;
use App\DTOs\Vault\ChunkReceiptDto;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Vault\AttachmentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /attachments/{uuid}/chunks — stores the next encrypted chunk
 * (docs/19_Decision_Log.md Decisions #22, #25). Chunks are accepted strictly in order,
 * each exactly once and each exactly the size its position requires; the server checks
 * the container, never the content (AI_RULES.md #13). Audit: attachment.uploaded, once,
 * when the last chunk arrives.
 */
final class StoreAttachmentChunkAction
{
    public function __construct(
        private readonly AttachmentStorage $storage,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(
        User $user,
        Attachment $attachment,
        int $index,
        int $total,
        string $uploadId,
        string $bytes,
        ?string $ipAddress,
    ): ChunkReceiptDto {
        if ($uploadId !== $attachment->uuid) {
            throw ValidationException::withMessages(['upload_id' => [__('legacyvault.validation.chunk_upload_mismatch')]]);
        }
        if ($total !== $attachment->chunk_count) {
            throw ValidationException::withMessages(['chunk_total' => [__('legacyvault.validation.chunk_upload_mismatch')]]);
        }

        return DB::transaction(function () use ($user, $attachment, $index, $bytes, $ipAddress): ChunkReceiptDto {
            // Re-read under a row lock: two requests for the same upload can't both
            // believe they are the next chunk.
            $upload = $user->attachments()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();

            if ($upload->isComplete() || $index !== $upload->received_chunks) {
                throw self::sequenceError();
            }

            // Container check only: exact size for this position, and the envelope
            // version byte. Accepted bytes can therefore never exceed what was reserved.
            if (strlen($bytes) !== $upload->expectedChunkBytes($index) || ord($bytes[0]) !== Attachment::AAD_VERSION) {
                throw ValidationException::withMessages(['chunk' => [__('legacyvault.validation.invalid_chunk')]]);
            }

            // False = the file is shorter than the chunks already recorded; nothing can
            // be appended to it in order any more.
            if (! $this->storage->writeChunk($upload, $index, $bytes)) {
                throw self::sequenceError();
            }
            $upload->received_chunks = $index + 1;

            if ($upload->received_chunks < $upload->chunk_count) {
                $upload->save();

                return new ChunkReceiptDto(receivedChunk: $index);
            }

            // Complete only if the file is exactly what was declared and reserved.
            if ($this->storage->storedBytes($upload) !== $upload->size_bytes) {
                throw self::sequenceError();
            }
            $upload->completed_at = now();
            $upload->save();

            $item = $upload->vaultItem;

            // IDs, size and count only — never a filename (docs/05_Security_Architecture.md §17).
            $this->audit->log($user->id, 'user', 'attachment.uploaded', [
                'attachment_id' => $upload->uuid,
                'item_id' => $item->uuid,
                'recipient_id' => $item->recipient_id,
                'size_bytes' => $upload->size_bytes,
                'chunk_count' => $upload->chunk_count,
            ], $ipAddress);

            return new ChunkReceiptDto(
                receivedChunk: $index,
                completedAttachment: AttachmentDto::fromModel($upload, $item->uuid),
            );
        });
    }

    private static function sequenceError(): ApiException
    {
        return new ApiException('CHUNK_SEQUENCE_ERROR', 'legacyvault.vault.chunk_sequence_error', 409);
    }
}
