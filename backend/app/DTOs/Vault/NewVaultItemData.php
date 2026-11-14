<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * Input for POST /recipients/{id}/items (docs/08_API_Specification.md). `uuid` is chosen
 * by the client so it can be bound into the AAD before the first encryption
 * (docs/19_Decision_Log.md Decision #15) — it is untrusted input, validated as a
 * canonical UUIDv4 and checked for uniqueness in the Action.
 */
final readonly class NewVaultItemData
{
    public function __construct(
        public string $uuid,
        public string $itemType,
        public VaultItemCiphertext $ciphertext,
    ) {
    }
}
