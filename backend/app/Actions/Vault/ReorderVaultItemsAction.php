<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\VaultItemDto;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /recipients/{id}/items/reorder — rewrites `position` 1…n. Ordering is plaintext
 * metadata, so no ciphertext changes and no key rotates. Audit: vault_item.reordered.
 */
final class ReorderVaultItemsAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ListVaultItemsAction $list,
    ) {
    }

    /**
     * @param list<string> $order the complete list of the recipient's live item UUIDs
     * @return list<VaultItemDto> the items in their new order
     */
    public function execute(User $user, Recipient $recipient, array $order, ?string $ipAddress): array
    {
        return DB::transaction(function () use ($user, $recipient, $order, $ipAddress): array {
            /** @var array<string, int> $idsByUuid */
            $idsByUuid = $recipient->vaultItems()->lockForUpdate()->pluck('id', 'uuid')->all();

            // The list must be exactly this recipient's live items. One generic error for
            // every mismatch — missing, extra, deleted, another recipient's or another
            // user's ID — so a stale client can't drop an item from the ordering and
            // nothing reveals whether a foreign ID exists.
            if (count($order) !== count($idsByUuid) || array_diff($order, array_keys($idsByUuid)) !== []) {
                throw ValidationException::withMessages([
                    'order' => [__('legacyvault.validation.reorder_mismatch')],
                ]);
            }

            // updated_at means "content last edited"; moving an item doesn't change it.
            VaultItem::withoutTimestamps(function () use ($recipient, $order, $idsByUuid): void {
                foreach ($order as $index => $uuid) {
                    $recipient->vaultItems()->whereKey($idsByUuid[$uuid])->update(['position' => $index + 1]);
                }
            });

            $this->audit->log($user->id, 'user', 'vault_item.reordered', [
                'recipient_id' => $recipient->id,
                'item_count' => count($order),
            ], $ipAddress);

            return $this->list->execute($recipient);
        });
    }
}
