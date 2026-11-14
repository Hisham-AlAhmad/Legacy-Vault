<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\VaultItemCiphertext;
use App\DTOs\Vault\VaultItemDto;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PATCH /vault-items/{uuid} — VAULT-9. Replaces key, title and body together: every edit
 * rotates the content key (docs/06_Cryptography.md §10). Audit: vault_item.updated.
 */
final class UpdateVaultItemAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, VaultItem $item, VaultItemCiphertext $ciphertext, ?string $ipAddress): VaultItemDto
    {
        // The server can't see whether the key inside the wrap is new, but a wrap that is
        // byte-for-byte the stored one certainly isn't. Envelopes are not secrets, so a
        // plain comparison is fine.
        if ($ciphertext->contentKeyWrapped === $item->content_key_wrapped) {
            throw ValidationException::withMessages([
                'content_key_wrapped' => [__('legacyvault.validation.content_key_not_rotated')],
            ]);
        }

        return DB::transaction(function () use ($user, $item, $ciphertext, $ipAddress): VaultItemDto {
            $item->update([
                'title_enc' => $ciphertext->titleEnc,
                'content_enc' => $ciphertext->contentEnc,
                'content_key_wrapped' => $ciphertext->contentKeyWrapped,
                'aad_version' => $ciphertext->aadVersion,
            ]);

            $this->audit->log($user->id, 'user', 'vault_item.updated', [
                'item_id' => $item->uuid,
                'recipient_id' => $item->recipient_id,
            ], $ipAddress);

            return VaultItemDto::fromModel($item);
        });
    }
}
