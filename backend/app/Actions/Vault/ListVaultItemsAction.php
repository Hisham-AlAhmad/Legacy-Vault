<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\VaultItemDto;
use App\Models\Recipient;
use App\Models\VaultItem;

/**
 * GET /recipients/{id}/items — one recipient's live items, in the owner's chosen order.
 * Titles and wrapped keys only: the body column is not even selected, so a long list
 * never drags megabytes of ciphertext out of the database (docs/08_API_Specification.md).
 */
final class ListVaultItemsAction
{
    private const SUMMARY_COLUMNS = [
        'id', 'uuid', 'user_id', 'recipient_id', 'item_type', 'title_enc',
        'content_key_wrapped', 'aad_version', 'position', 'created_at', 'updated_at',
    ];

    /** @return list<VaultItemDto> */
    public function execute(Recipient $recipient): array
    {
        return $recipient->vaultItems()
            ->select(self::SUMMARY_COLUMNS)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (VaultItem $item): VaultItemDto => VaultItemDto::summaryFromModel($item))
            ->all();
    }
}
