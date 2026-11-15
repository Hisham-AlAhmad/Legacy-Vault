<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\AttachmentDto;
use App\Models\Attachment;
use App\Models\VaultItem;

/**
 * GET /vault-items/{uuid}/attachments — the item's completed attachments, oldest first.
 * Uploads still in progress are not attachments yet and are not listed.
 */
final class ListAttachmentsAction
{
    /** @return list<AttachmentDto> */
    public function execute(VaultItem $item): array
    {
        return $item->attachments()
            ->whereNotNull('completed_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Attachment $attachment): AttachmentDto => AttachmentDto::fromModel($attachment, $item->uuid))
            ->all();
    }
}
