<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\VaultItem;
use App\Services\Vault\TrashPurger;
use App\Services\Vault\UndoWindow;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permanently erases soft-deleted recipients, vault items and attachments once their
 * 30-day undo window has passed, and uploads that were started and abandoned
 * (docs/19_Decision_Log.md Decisions #25, #28, #29; docs/07_Database_Design.md §Deletion
 * strategy). Scheduled hourly via routes/console.php.
 *
 * Shared hosting has no workers (AI_RULES.md #16), so a run is deliberately short: small
 * batches, a hard time budget, and whatever is left waits for the next run. Each entry is
 * erased in its own transaction by TrashPurger, files first, with an audit entry
 * (actor `system`, IDs and counts only).
 */
class PurgeDeletedVaultData extends Command
{
    protected $signature = 'vault:purge-deleted';

    protected $description = 'Permanently erase soft-deleted vault data past the undo window, and abandoned uploads';

    private float $deadline = 0.0;

    public function handle(TrashPurger $purger, UndoWindow $window): int
    {
        $this->deadline = microtime(true) + (int) config('legacyvault.trash.purge_max_seconds');
        $cutoff = $window->cutoff();

        // 1. Uploads declared but never finished.
        $uploads = $this->sweep(
            fn (): Builder => Attachment::query()->whereNull('completed_at')->where('updated_at', '<=', $purger->abandonedCutoff()),
            fn (int $id): bool => $purger->abandonedUpload($id)?->complete ?? true,
        );

        // 2. Attachments deleted on their own. Those whose item is itself due go with
        //    the item in step 3 and are counted in its audit entry.
        $attachments = $this->sweep(
            fn (): Builder => Attachment::onlyTrashed()
                ->where('deleted_at', '<=', $cutoff)
                ->whereDoesntHave('vaultItem', fn (Builder $item) => $item->onlyTrashed()->where('deleted_at', '<=', $cutoff)),
            fn (int $id): bool => $purger->attachment($id, TrashPurger::REASON_WINDOW_EXPIRED)?->complete ?? true,
        );

        // 3. Items deleted on their own (or whose recipient is not due yet).
        $items = $this->sweep(
            fn (): Builder => VaultItem::onlyTrashed()
                ->where('deleted_at', '<=', $cutoff)
                ->whereDoesntHave('recipient', fn (Builder $recipient) => $recipient->onlyTrashed()->where('deleted_at', '<=', $cutoff)),
            fn (int $id): bool => $purger->item($id, TrashPurger::REASON_WINDOW_EXPIRED)?->complete ?? true,
        );

        // 4. Recipients, with everything under them.
        $recipients = $this->sweep(
            fn (): Builder => Recipient::onlyTrashed()->where('deleted_at', '<=', $cutoff),
            fn (int $id): bool => $purger->recipient($id, TrashPurger::REASON_WINDOW_EXPIRED)?->complete ?? true,
        );

        $this->info(sprintf(
            'Purged %d recipient(s), %d item(s), %d attachment(s) and %d abandoned upload(s).%s',
            $recipients,
            $items,
            $attachments,
            $uploads,
            $this->outOfTime() ? ' Time budget reached; the rest waits for the next run.' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Work through everything `$query` matches, one batch at a time, until nothing is
     * left or the time budget runs out. An entry that could not be fully erased is
     * skipped for the rest of this run and retried by the next one.
     *
     * @param Closure(): Builder $query
     * @param Closure(int): bool $purge returns false when the entry had to be kept
     */
    private function sweep(Closure $query, Closure $purge): int
    {
        $batchSize = (int) config('legacyvault.trash.purge_batch_size');
        $purged = 0;
        $kept = [];

        while (! $this->outOfTime()) {
            $ids = $query()->whereKeyNot($kept)->orderBy('id')->limit($batchSize)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                if ($this->outOfTime()) {
                    break;
                }

                if ($purge((int) $id)) {
                    $purged++;
                } else {
                    $kept[] = (int) $id;
                }
            }
        }

        return $purged;
    }

    private function outOfTime(): bool
    {
        return microtime(true) >= $this->deadline;
    }
}
