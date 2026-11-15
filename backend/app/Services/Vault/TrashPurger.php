<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\DTOs\Vault\PurgeResult;
use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently erases soft-deleted vault data, one entry per transaction — shared by the
 * scheduled purge (`vault:purge-deleted`, reason `window_expired`, actor `system`) and
 * "erase now" (reason `user_requested`, actor `user`) so there is exactly one way data
 * gets destroyed (docs/19_Decision_Log.md Decisions #28, #29).
 *
 * Every method locks the row and re-checks that it is still deleted — and, for the
 * scheduled purge, still past the undo window — before touching anything, so a restore
 * that commits first always wins. Each returns null when the row no longer qualifies.
 *
 * This is the system-level path: rows are addressed by primary key, not through a user
 * relation, because the scheduled job has no user. Callers on request paths must have
 * resolved the ID through the owner-scoped route bindings first.
 */
final class TrashPurger
{
    public const REASON_WINDOW_EXPIRED = 'window_expired';
    public const REASON_USER_REQUESTED = 'user_requested';

    public function __construct(
        private readonly VaultPurger $purger,
        private readonly UndoWindow $window,
        private readonly AuditLogger $audit,
    ) {
    }

    public function recipient(int $id, string $reason, ?string $ipAddress = null): ?PurgeResult
    {
        return DB::transaction(function () use ($id, $reason, $ipAddress): ?PurgeResult {
            $recipient = $this->qualifying(Recipient::onlyTrashed()->whereKey($id), 'deleted_at', $reason)->first();

            if ($recipient === null) {
                return null;
            }

            $result = $this->purger->purgeRecipient($recipient);

            if ($result->complete) {
                $this->audit->log($recipient->user_id, self::actor($reason), 'recipient.purged', [
                    'recipient_id' => $recipient->id,
                    'item_count' => $result->items,
                    'attachment_count' => $result->attachments,
                    'reason' => $reason,
                ], $ipAddress);
            } else {
                self::reportIncomplete('recipient', $recipient->id);
            }

            return $result;
        });
    }

    public function item(int $id, string $reason, ?string $ipAddress = null): ?PurgeResult
    {
        return DB::transaction(function () use ($id, $reason, $ipAddress): ?PurgeResult {
            $item = $this->qualifying(VaultItem::onlyTrashed()->whereKey($id), 'deleted_at', $reason)->first();

            if ($item === null) {
                return null;
            }

            $result = $this->purger->purgeItem($item);

            if ($result->complete) {
                $this->audit->log($item->user_id, self::actor($reason), 'vault_item.purged', [
                    'item_id' => $item->uuid,
                    'recipient_id' => $item->recipient_id,
                    'attachment_count' => $result->attachments,
                    'reason' => $reason,
                ], $ipAddress);
            } else {
                self::reportIncomplete('vault item', $item->id);
            }

            return $result;
        });
    }

    public function attachment(int $id, string $reason, ?string $ipAddress = null): ?PurgeResult
    {
        return DB::transaction(function () use ($id, $reason, $ipAddress): ?PurgeResult {
            $attachment = $this->qualifying(Attachment::onlyTrashed()->whereKey($id), 'deleted_at', $reason)->first();

            return $attachment === null ? null : $this->eraseAttachment($attachment, 'attachment.purged', $reason, $ipAddress);
        });
    }

    /** An upload that was declared but never finished and has not been touched since `updated_at <= cutoff`. */
    public function abandonedUpload(int $id): ?PurgeResult
    {
        return DB::transaction(function () use ($id): ?PurgeResult {
            $upload = Attachment::query()
                ->whereKey($id)
                ->whereNull('completed_at')
                ->where('updated_at', '<=', $this->abandonedCutoff())
                ->lockForUpdate()
                ->first();

            return $upload === null
                ? null
                : $this->eraseAttachment($upload, 'attachment.upload_expired', self::REASON_WINDOW_EXPIRED, null);
        });
    }

    /** Uploads untouched since this moment are abandoned (Decision #25). */
    public function abandonedCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subHours((int) config('legacyvault.attachments.abandoned_upload_ttl_hours'));
    }

    private function eraseAttachment(Attachment $attachment, string $event, string $reason, ?string $ipAddress): PurgeResult
    {
        // The item may itself be soft-deleted by now; its ID is still the right reference.
        $itemUuid = VaultItem::withTrashed()->whereKey($attachment->vault_item_id)->value('uuid');

        if (! $this->purger->purgeAttachment($attachment)) {
            self::reportIncomplete('attachment', $attachment->id);

            return new PurgeResult(complete: false);
        }

        // IDs and sizes only — never a filename (docs/05_Security_Architecture.md §17).
        $this->audit->log($attachment->user_id, self::actor($reason), $event, [
            'attachment_id' => $attachment->uuid,
            'item_id' => $itemUuid,
            'size_bytes' => $attachment->size_bytes,
            'reason' => $reason,
        ], $ipAddress);

        return new PurgeResult(complete: true, attachments: 1);
    }

    /** Lock the row; the scheduled purge additionally requires it to be past the undo window. */
    private function qualifying(Builder $query, string $column, string $reason): Builder
    {
        if ($reason === self::REASON_WINDOW_EXPIRED) {
            $query->where($column, '<=', $this->window->cutoff());
        }

        return $query->lockForUpdate();
    }

    private static function actor(string $reason): string
    {
        return $reason === self::REASON_USER_REQUESTED ? 'user' : 'system';
    }

    /** A file could not be deleted: the row is kept and retried. Operational, so app log, IDs only. */
    private static function reportIncomplete(string $kind, int $id): void
    {
        Log::warning("Purge incomplete: a file under {$kind} #{$id} could not be deleted; it will be retried.");
    }
}
