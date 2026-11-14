<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\VaultItemDto;
use App\Models\VaultItem;

/**
 * GET /vault-items/{uuid} — the full item, body ciphertext included, for the owner to
 * decrypt in their own browser. Ownership is already established by the route binding
 * and the policy; nothing is decrypted here (AI_RULES.md #13).
 */
final class GetVaultItemAction
{
    public function execute(VaultItem $item): VaultItemDto
    {
        return VaultItemDto::fromModel($item);
    }
}
