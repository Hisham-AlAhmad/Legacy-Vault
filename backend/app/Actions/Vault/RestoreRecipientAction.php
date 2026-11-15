<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\RecipientDto;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use App\Services\Vault\AttachmentCascade;
use App\Services\Vault\UndoWindow;
use Illuminate\Support\Facades\DB;

/**
 * POST /trash/recipients/{id}/restore — undoes a recipient deletion inside the undo
 * window (docs/19_Decision_Log.md Decision #28). The recipient comes back with the items
 * that were deleted *with* it — those carrying the identical `deleted_at` — and those
 * items with their attachments. Anything deleted on its own earlier stays deleted.
 * Audit: recipient.restored.
 */
final class RestoreRecipientAction
{
    public function __construct(
        private readonly UndoWindow $window,
        private readonly AttachmentCascade $attachments,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(User $user, Recipient $trashed, ?string $ipAddress): RecipientDto
    {
        return DB::transaction(function () use ($user, $trashed, $ipAddress): RecipientDto {
            // Locked and re-checked: a purge that got here first leaves nothing to restore.
            $recipient = $user->recipients()->onlyTrashed()
                ->whereKey($trashed->id)
                ->where('deleted_at', '>', $this->window->cutoff())
                ->lockForUpdate()
                ->firstOrFail();

            $deletedAt = $recipient->deleted_at;
            $recipient->restore();

            $itemIds = $recipient->vaultItems()->onlyTrashed()->where('deleted_at', $deletedAt)->pluck('id')->all();

            if ($itemIds !== []) {
                VaultItem::withoutTimestamps(
                    fn (): int => VaultItem::onlyTrashed()->whereIn('id', $itemIds)->update(['deleted_at' => null]),
                );
                $this->attachments->restoreWithItems($itemIds, $deletedAt);
            }

            // The emergency-contact role may have moved on while this recipient was deleted.
            if ($recipient->is_emergency_contact
                && $user->recipients()->whereKeyNot($recipient->id)->where('is_emergency_contact', true)->exists()) {
                $recipient->forceFill(['is_emergency_contact' => false])->save();
            }

            $this->audit->log($user->id, 'user', 'recipient.restored', [
                'recipient_id' => $recipient->id,
                'item_count' => count($itemIds),
            ], $ipAddress);

            return RecipientDto::fromModel($recipient);
        });
    }
}
