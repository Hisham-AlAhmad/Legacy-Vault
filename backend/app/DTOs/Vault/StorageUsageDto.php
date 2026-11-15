<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * Attachment quota as reported to the client (docs/08_API_Specification.md §Attachments),
 * so the UI never hard-codes the limit (docs/19_Decision_Log.md Decision #19). All values
 * are ciphertext bytes; `trashedBytes` is the part of `usedBytes` held by deleted files
 * that have not been purged yet (Decision #26).
 */
final readonly class StorageUsageDto
{
    public function __construct(
        public int $limitBytes,
        public int $usedBytes,
        public int $trashedBytes,
    ) {
    }

    /** @return array{limit_bytes: int, used_bytes: int, trashed_bytes: int} */
    public function toArray(): array
    {
        return [
            'limit_bytes' => $this->limitBytes,
            'used_bytes' => $this->usedBytes,
            'trashed_bytes' => $this->trashedBytes,
        ];
    }
}
