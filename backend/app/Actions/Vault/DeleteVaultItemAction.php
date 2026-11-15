<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use App\Services\Vault\AttachmentCascade;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /vault-items/{uuid} — VAULT-9. Soft delete: the item vanishes from every API
 * response and can't be delivered, and its ID stays reserved. Its completed attachments
 * are soft-deleted with it, with the same timestamp, so a restore brings them back
 * together; unfinished uploads on it are discarded (docs/19_Decision_Log.md Decision
 * #27). Restorable for the undo window, then purged (Decisions #28, #29).
 * No step-up password — Decision #17. Audit: vault_item.deleted.
 */
final class DeleteVaultItemAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentCascade $attachments,
    ) {
    }

    public function execute(User $user, VaultItem $item, ?string $ipAddress): void
    {
        DB::transaction(function () use ($user, $item, $ipAddress): void {
            $item->delete();
            $attachmentCount = $this->attachments->trashWithItems([$item->id], $item->deleted_at);

            $this->audit->log($user->id, 'user', 'vault_item.deleted', [
                'item_id' => $item->uuid,
                'recipient_id' => $item->recipient_id,
                'attachment_count' => $attachmentCount,
            ], $ipAddress);
        });
    }
}
