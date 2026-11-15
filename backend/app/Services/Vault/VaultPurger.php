<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\DTOs\Vault\PurgeResult;
use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\VaultItem;

/**
 * Hard deletion of vault data — the one code path behind both the scheduled purge
 * (`vault:purge-deleted`) and "erase now" in the recently-deleted list
 * (docs/19_Decision_Log.md Decisions #28, #29).
 *
 * Order is always file first, row second: if a file can't be removed the row stays and
 * the purge is retried, so a row never vanishes while its bytes remain on disk
 * uncounted. Callers decide *what* may be purged and write the audit entry; this class
 * only knows *how*.
 */
final class VaultPurger
{
    public function __construct(private readonly AttachmentStorage $storage)
    {
    }

    /** Remove one attachment's file and row. False if the file could not be deleted. */
    public function purgeAttachment(Attachment $attachment): bool
    {
        if (! $this->storage->delete($attachment)) {
            return false;
        }

        $attachment->forceDelete();

        return true;
    }

    /** Remove an item with every attachment under it, whatever state they are in. */
    public function purgeItem(VaultItem $item): PurgeResult
    {
        $attachments = 0;

        foreach ($item->attachments()->withTrashed()->get() as $attachment) {
            if (! $this->purgeAttachment($attachment)) {
                return new PurgeResult(complete: false, attachments: $attachments);
            }
            $attachments++;
        }

        $item->forceDelete();

        return new PurgeResult(complete: true, items: 1, attachments: $attachments);
    }

    /** Remove a recipient with every item and attachment under it. */
    public function purgeRecipient(Recipient $recipient): PurgeResult
    {
        $items = 0;
        $attachments = 0;

        foreach ($recipient->vaultItems()->withTrashed()->get() as $item) {
            $result = $this->purgeItem($item);
            $attachments += $result->attachments;

            if (! $result->complete) {
                return new PurgeResult(complete: false, items: $items, attachments: $attachments);
            }
            $items++;
        }

        $recipient->forceDelete();

        return new PurgeResult(complete: true, items: $items, attachments: $attachments);
    }
}
