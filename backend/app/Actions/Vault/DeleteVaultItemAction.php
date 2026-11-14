<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /vault-items/{uuid} — VAULT-9. Soft delete: the item vanishes from every API
 * response and can't be delivered, its ID stays reserved, and its ciphertext row remains
 * until Phase 2c adds the undo window and the scheduled hard purge
 * (docs/19_Decision_Log.md Decision #18). No step-up password — Decision #17.
 * Audit: vault_item.deleted.
 */
final class DeleteVaultItemAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, VaultItem $item, ?string $ipAddress): void
    {
        DB::transaction(function () use ($user, $item, $ipAddress): void {
            $item->delete();

            $this->audit->log($user->id, 'user', 'vault_item.deleted', [
                'item_id' => $item->uuid,
                'recipient_id' => $item->recipient_id,
            ], $ipAddress);
        });
    }
}
