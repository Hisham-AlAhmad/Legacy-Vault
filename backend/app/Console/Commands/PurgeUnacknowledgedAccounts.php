<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Purges partial registrations where the Recovery Key was never acknowledged —
 * docs/02_User_Flows.md Flow 1 edge case (24h TTL, config legacyvault.pending_account_ttl_hours).
 * Scheduled hourly via routes/console.php (cron-driven, docs/04_System_Architecture.md §5).
 */
class PurgeUnacknowledgedAccounts extends Command
{
    protected $signature = 'accounts:purge-unacknowledged';

    protected $description = 'Hard-delete pending accounts whose Recovery Key was never acknowledged within the TTL';

    public function handle(AuditLogger $audit): int
    {
        $cutoff = now()->subHours((int) config('legacyvault.pending_account_ttl_hours'));

        $stale = User::query()
            ->where('status', User::STATUS_PENDING)
            ->whereNull('recovery_key_acknowledged_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $user) {
            // Hard delete cascades to encrypted_keys/devices. Audit rows persist by design
            // (docs/05_Security_Architecture.md §17) with the user reference kept as-is.
            $audit->log($user->id, 'system', 'user.pending_registration_purged');
            $user->forceDelete();
        }

        $this->info(sprintf('Purged %d unacknowledged pending account(s).', $stale->count()));

        return self::SUCCESS;
    }
}
