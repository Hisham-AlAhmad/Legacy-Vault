<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\AttachmentDto;
use App\DTOs\Vault\RecipientDto;
use App\DTOs\Vault\TrashListingDto;
use App\DTOs\Vault\VaultItemDto;
use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\UndoWindow;

/**
 * GET /trash — what the user can still restore (docs/19_Decision_Log.md Decision #28).
 * Only the top of each deleted tree is listed: deleted recipients; deleted items whose
 * recipient is live; deleted attachments whose item (and its recipient) is live.
 * Children that went down with their parent come back with it and are not listed.
 */
final class ListTrashAction
{
    public function __construct(private readonly UndoWindow $window)
    {
    }

    public function execute(User $user): TrashListingDto
    {
        $cutoff = $this->window->cutoff();

        $recipients = $user->recipients()->onlyTrashed()
            ->where('deleted_at', '>', $cutoff)
            ->withCount(['vaultItems as restorable_item_count' => fn ($items) => $items->onlyTrashed()
                ->whereColumn('vault_items.deleted_at', 'recipients.deleted_at')])
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->get()
            ->map(fn (Recipient $recipient): array => [
                ...RecipientDto::fromModel($recipient)->toArray(),
                ...$this->trashFields($recipient->deleted_at),
                'item_count' => (int) $recipient->restorable_item_count,
            ])
            ->all();

        $items = $user->vaultItems()->onlyTrashed()
            ->where('deleted_at', '>', $cutoff)
            ->whereHas('recipient')
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->get()
            ->map(fn (VaultItem $item): array => [
                ...VaultItemDto::summaryFromModel($item)->toArray(),
                ...$this->trashFields($item->deleted_at),
            ])
            ->all();

        $attachments = $user->attachments()->onlyTrashed()
            ->where('deleted_at', '>', $cutoff)
            ->whereHas('vaultItem', fn ($item) => $item->whereHas('recipient'))
            ->with('vaultItem:id,uuid,recipient_id')
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->get()
            ->map(fn (Attachment $attachment): array => [
                ...AttachmentDto::fromModel($attachment, $attachment->vaultItem->uuid)->toArray(),
                // The client needs the whole chain to rebuild the AAD (Decision #21). A wrong
                // value can only make decryption fail, never succeed.
                'recipient_id' => $attachment->vaultItem->recipient_id,
            ])
            ->all();

        return new TrashListingDto($this->window->days(), $recipients, $items, $attachments);
    }

    /** @return array{deleted_at: string, purge_at: string} */
    private function trashFields(\Illuminate\Support\Carbon $deletedAt): array
    {
        return [
            'deleted_at' => $deletedAt->toIso8601String(),
            'purge_at' => $this->window->purgeAt($deletedAt)->toIso8601String(),
        ];
    }
}
