<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * The encrypted parts of a vault item, as already-decoded envelope bytes
 * (docs/06_Cryptography.md §13). Opaque to the server: it stores them and hands them
 * back, nothing else (AI_RULES.md #13). An edit always supplies all three together,
 * because every edit rotates the content key (§10). `contentEnc` is null for a file
 * bundle, which has a title and attachments but no body (Decision #27).
 */
final readonly class VaultItemCiphertext
{
    public function __construct(
        public string $titleEnc,
        public ?string $contentEnc,
        public string $contentKeyWrapped,
        public int $aadVersion,
    ) {
    }
}
