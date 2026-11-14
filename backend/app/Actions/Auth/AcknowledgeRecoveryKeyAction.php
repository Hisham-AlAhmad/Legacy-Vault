<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditLogger;

/**
 * Finalizes registration: the user has confirmed saving their Recovery Key
 * (docs/02_User_Flows.md Flow 1 step 4, VAULT-2). Moves pending → active.
 */
final class AcknowledgeRecoveryKeyAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, ?string $ipAddress): void
    {
        if ($user->recovery_key_acknowledged_at !== null) {
            return; // idempotent
        }

        $user->forceFill([
            'recovery_key_acknowledged_at' => now(),
            'status' => $user->status === User::STATUS_PENDING ? User::STATUS_ACTIVE : $user->status,
        ])->save();

        $this->audit->log($user->id, 'user', 'recovery_key.acknowledged', [], $ipAddress);
    }
}
