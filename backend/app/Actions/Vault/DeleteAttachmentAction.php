<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Models\Attachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Vault\VaultPurger;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /attachments/{uuid} — VAULT-9.
 *
 * A completed attachment is soft-deleted: restorable for the undo window, and still
 * counted against the quota until it is purged or erased (docs/19_Decision_Log.md
 * Decisions #26, #28). An unfinished upload is an abort: row and partial file go at
 * once, releasing the reservation — there is nothing worth undoing (Decision #25).
 * No step-up password, as for items (Decision #17).
 * Audit: attachment.deleted / attachment.upload_aborted.
 */
final class DeleteAttachmentAction
{
    public function __construct(
        private readonly VaultPurger $purger,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(User $user, Attachment $attachment, ?string $ipAddress): void
    {
        DB::transaction(function () use ($user, $attachment, $ipAddress): void {
            // Same row lock a chunk request takes, so an abort can't interleave with a
            // chunk that is still being written (Decision #25).
            $attachment = $user->attachments()->whereKey($attachment->id)->lockForUpdate()->firstOrFail();

            $metadata = [
                'attachment_id' => $attachment->uuid,
                'item_id' => $attachment->vaultItem->uuid,
                'size_bytes' => $attachment->size_bytes,
            ];

            if ($attachment->isComplete()) {
                $attachment->delete();
                $this->audit->log($user->id, 'user', 'attachment.deleted', $metadata, $ipAddress);

                return;
            }

            $this->purger->purgeAttachment($attachment);
            $this->audit->log($user->id, 'user', 'attachment.upload_aborted', $metadata, $ipAddress);
        });
    }
}
