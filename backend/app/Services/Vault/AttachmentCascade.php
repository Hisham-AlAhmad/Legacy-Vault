<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Attachment;
use Illuminate\Support\Carbon;

/**
 * What happens to attachments when the items above them are deleted or restored
 * (docs/19_Decision_Log.md Decision #27). Children always carry their parent's exact
 * `deleted_at`, which is what lets a restore bring back precisely what went down
 * together and nothing that had been deleted on its own before.
 *
 * Call inside the transaction that deletes or restores the items.
 */
final class AttachmentCascade
{
    public function __construct(private readonly VaultPurger $purger)
    {
    }

    /**
     * Soft-delete the completed attachments of these items with the given timestamp, and
     * discard their unfinished uploads outright. Returns how many were soft-deleted.
     *
     * @param list<int> $itemIds
     */
    public function trashWithItems(array $itemIds, Carbon $deletedAt): int
    {
        if ($itemIds === []) {
            return 0;
        }

        // Locked like a chunk request locks them, so an upload still in flight can't
        // write to a file whose row is being removed.
        $unfinished = Attachment::query()
            ->whereIn('vault_item_id', $itemIds)
            ->whereNull('completed_at')
            ->lockForUpdate()
            ->get();

        foreach ($unfinished as $upload) {
            $this->purger->purgeAttachment($upload);
        }

        // updated_at is left alone — nothing was edited.
        return Attachment::withoutTimestamps(
            fn (): int => Attachment::query()->whereIn('vault_item_id', $itemIds)->update(['deleted_at' => $deletedAt]),
        );
    }

    /**
     * Restore the attachments of these items that were deleted at exactly `$deletedAt`.
     *
     * @param list<int> $itemIds
     */
    public function restoreWithItems(array $itemIds, Carbon $deletedAt): int
    {
        if ($itemIds === []) {
            return 0;
        }

        return Attachment::withoutTimestamps(
            fn (): int => Attachment::onlyTrashed()
                ->whereIn('vault_item_id', $itemIds)
                ->where('deleted_at', $deletedAt)
                ->update(['deleted_at' => null]),
        );
    }
}
