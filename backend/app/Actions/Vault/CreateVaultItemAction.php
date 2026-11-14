<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\NewVaultItemData;
use App\DTOs\Vault\VaultItemDto;
use App\Exceptions\ApiException;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /recipients/{id}/items — VAULT-5, VAULT-6 (text/links). Stores ciphertext exactly
 * as received; the server never opens it (AI_RULES.md #13). Audit: vault_item.created.
 */
final class CreateVaultItemAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, Recipient $recipient, NewVaultItemData $data, ?string $ipAddress): VaultItemDto
    {
        try {
            return DB::transaction(fn (): VaultItemDto => $this->create($user, $recipient, $data, $ipAddress));
        } catch (UniqueConstraintViolationException) {
            // Two requests raced past the check below with the same ID.
            throw self::idConflict();
        }
    }

    private function create(User $user, Recipient $recipient, NewVaultItemData $data, ?string $ipAddress): VaultItemDto
    {
        // Message text isn't counted against the attachment quota, so this cap is what
        // bounds it (docs/19_Decision_Log.md Decision #17).
        if ($recipient->vaultItems()->count() >= (int) config('legacyvault.vault_items.max_per_recipient')) {
            throw new ApiException('ITEM_LIMIT_REACHED', 'legacyvault.vault.item_limit_reached', 403);
        }

        // The ID is client-chosen. A soft-deleted item keeps its ID reserved, so an ID is
        // never reused for different content (Decision #15).
        if ($user->vaultItems()->withTrashed()->where('uuid', $data->uuid)->exists()) {
            throw self::idConflict();
        }

        $item = new VaultItem([
            'uuid' => $data->uuid,
            'recipient_id' => $recipient->id,
            'item_type' => $data->itemType,
            'title_enc' => $data->ciphertext->titleEnc,
            'content_enc' => $data->ciphertext->contentEnc,
            'content_key_wrapped' => $data->ciphertext->contentKeyWrapped,
            'aad_version' => $data->ciphertext->aadVersion,
            'position' => (int) $recipient->vaultItems()->max('position') + 1, // append
        ]);
        $user->vaultItems()->save($item);

        // IDs and type only — never titles, content or ciphertext (docs/05_Security_Architecture.md §16–17).
        $this->audit->log($user->id, 'user', 'vault_item.created', [
            'item_id' => $item->uuid,
            'recipient_id' => $recipient->id,
            'item_type' => $item->item_type,
        ], $ipAddress);

        return VaultItemDto::fromModel($item);
    }

    private static function idConflict(): ApiException
    {
        return new ApiException('ITEM_ID_CONFLICT', 'legacyvault.vault.item_id_conflict', 409);
    }
}
