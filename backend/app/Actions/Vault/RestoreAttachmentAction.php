<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\AttachmentDto;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Vault\UndoWindow;
use Illuminate\Support\Facades\DB;

/**
 * POST /trash/attachments/{uuid}/restore — undoes an attachment deletion inside the undo
 * window (docs/19_Decision_Log.md Decision #28). Only into a live item of a live
 * recipient. The file never left the disk or the quota, so no quota check is needed.
 * Audit: attachment.restored.
 */
final class RestoreAttachmentAction
{
    public function __construct(
        private readonly UndoWindow $window,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(User $user, Attachment $trashed, ?string $ipAddress): AttachmentDto
    {
        return DB::transaction(function () use ($user, $trashed, $ipAddress): AttachmentDto {
            $attachment = $user->attachments()->onlyTrashed()
                ->whereKey($trashed->id)
                ->where('deleted_at', '>', $this->window->cutoff())
                ->whereHas('vaultItem', fn ($item) => $item->whereHas('recipient'))
                ->lockForUpdate()
                ->firstOrFail();

            $item = $attachment->vaultItem;

            if ($item->attachments()->count() >= (int) config('legacyvault.attachments.max_per_item')) {
                throw new ApiException('ATTACHMENT_LIMIT_REACHED', 'legacyvault.vault.attachment_limit_reached', 403);
            }

            $attachment->restore();

            $this->audit->log($user->id, 'user', 'attachment.restored', [
                'attachment_id' => $attachment->uuid,
                'item_id' => $item->uuid,
            ], $ipAddress);

            return AttachmentDto::fromModel($attachment, $item->uuid);
        });
    }
}
