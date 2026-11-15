<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * What a hard delete removed (App\Services\Vault\VaultPurger). `complete` is false when a
 * file could not be deleted: the entry itself was kept and will be retried, though
 * children counted here are already gone.
 */
final readonly class PurgeResult
{
    public function __construct(
        public bool $complete,
        public int $items = 0,
        public int $attachments = 0,
    ) {
    }
}
