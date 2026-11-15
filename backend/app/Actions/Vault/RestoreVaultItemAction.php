<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\VaultItemDto;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use App\Services\Vault\AttachmentCascade;
use App\Services\Vault\UndoWindow;
use Illuminate\Support\Facades\DB;

/**
 * POST /trash/items/{uuid}/restore — undoes an item deletion inside the undo window
 * (docs/19_Decision_Log.md Decision #28). Only into a live recipient; the attachments
 * deleted with the item (identical `deleted_at`) come back with it.
 * Audit: vault_item.restored.
 */
final class RestoreVaultItemAction
{
    public function __construct(
        private readonly UndoWindow $window,
        private readonly AttachmentCascade $attachments,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(User $user, VaultItem $trashed, ?string $ipAddress): VaultItemDto
    {
        return DB::transaction(function () use ($user, $trashed, $ipAddress): VaultItemDto {
            $item = $user->vaultItems()->onlyTrashed()
                ->whereKey($trashed->id)
                ->where('deleted_at', '>', $this->window->cutoff())
                ->whereHas('recipient') // live recipients only
                ->lockForUpdate()
                ->firstOrFail();

            // The cap counts live items; the recipient may have filled up since.
            if ($item->recipient->vaultItems()->count() >= (int) config('legacyvault.vault_items.max_per_recipient')) {
                throw new ApiException('ITEM_LIMIT_REACHED', 'legacyvault.vault.item_limit_reached', 403);
            }

            $deletedAt = $item->deleted_at;
            $item->restore();
            $attachmentCount = $this->attachments->restoreWithItems([$item->id], $deletedAt);

            $this->audit->log($user->id, 'user', 'vault_item.restored', [
                'item_id' => $item->uuid,
                'recipient_id' => $item->recipient_id,
                'attachment_count' => $attachmentCount,
            ], $ipAddress);

            return VaultItemDto::summaryFromModel($item);
        });
    }
}
