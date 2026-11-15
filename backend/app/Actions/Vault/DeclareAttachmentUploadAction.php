<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\AttachmentDto;
use App\DTOs\Vault\NewAttachmentData;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\AttachmentStorage;
use App\Services\Vault\StorageQuota;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /vault-items/{uuid}/attachments — VAULT-6, VAULT-7, NFR-3. Declares an upload and
 * reserves its full ciphertext size against the quota *before* any content byte is
 * accepted (docs/19_Decision_Log.md Decisions #25, #26). The chunks then have to add up
 * to exactly this size, so the reservation can't be exceeded.
 *
 * No audit entry here: `attachment.uploaded` is written when the last chunk arrives.
 */
final class DeclareAttachmentUploadAction
{
    public function __construct(
        private readonly StorageQuota $quota,
        private readonly AttachmentStorage $storage,
    ) {
    }

    public function execute(User $user, VaultItem $item, NewAttachmentData $data): AttachmentDto
    {
        try {
            return DB::transaction(fn (): AttachmentDto => $this->declare($user, $item, $data));
        } catch (UniqueConstraintViolationException) {
            // Two requests raced past the check below with the same ID.
            throw self::idConflict();
        }
    }

    private function declare(User $user, VaultItem $item, NewAttachmentData $data): AttachmentDto
    {
        // Serialises this user's declarations, so two uploads can't both pass the quota
        // check and together exceed the limit.
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        // The ID is client-chosen and stays reserved while any row carries it, deleted
        // ones included (Decision #21).
        if ($user->attachments()->withTrashed()->where('uuid', $data->uuid)->exists()) {
            throw self::idConflict();
        }

        // Live rows, uploads in progress included — they will become attachments.
        if ($item->attachments()->count() >= (int) config('legacyvault.attachments.max_per_item')) {
            throw new ApiException('ATTACHMENT_LIMIT_REACHED', 'legacyvault.vault.attachment_limit_reached', 403);
        }

        if (! $this->quota->hasRoomFor($user, $data->sizeBytes)) {
            throw new ApiException('QUOTA_EXCEEDED', 'legacyvault.vault.quota_exceeded', 413);
        }

        $attachment = new Attachment([
            'uuid' => $data->uuid,
            'vault_item_id' => $item->id,
            'storage_path' => $this->storage->newPath($user),
            'filename_enc' => $data->filenameEnc,
            'mime_type_enc' => $data->mimeTypeEnc,
            'attachment_key_wrapped' => $data->attachmentKeyWrapped,
            'size_bytes' => $data->sizeBytes,
            'chunk_count' => $data->chunkCount,
            'received_chunks' => 0,
            'aad_version' => $data->aadVersion,
        ]);
        $user->attachments()->save($attachment);

        return AttachmentDto::fromModel($attachment, $item->uuid);
    }

    private static function idConflict(): ApiException
    {
        return new ApiException('ATTACHMENT_ID_CONFLICT', 'legacyvault.vault.attachment_id_conflict', 409);
    }
}
