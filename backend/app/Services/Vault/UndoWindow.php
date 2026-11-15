<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Support\Carbon;

/**
 * The soft-delete undo window (docs/19_Decision_Log.md Decision #28), in one place so the
 * restore boundary and the purge boundary can never disagree:
 * restorable while `deleted_at > cutoff()`, purgeable once `deleted_at <= cutoff()`.
 */
final class UndoWindow
{
    public function days(): int
    {
        return (int) config('legacyvault.trash.undo_window_days');
    }

    /** Anything deleted at or before this moment is past the window. */
    public function cutoff(): Carbon
    {
        return now()->subDays($this->days());
    }

    /** When something deleted at `$deletedAt` becomes purgeable. */
    public function purgeAt(Carbon $deletedAt): Carbon
    {
        return $deletedAt->copy()->addDays($this->days());
    }
}
