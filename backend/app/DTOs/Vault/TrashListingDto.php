<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * GET /trash response (docs/08_API_Specification.md §Recently deleted). Each entry is the
 * resource's normal API shape plus `deleted_at` and `purge_at`; recipients also carry
 * `item_count`, the number of items that come back with them. Still ciphertext — the
 * client decrypts names and titles.
 */
final readonly class TrashListingDto
{
    /**
     * @param list<array<string, mixed>> $recipients
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $attachments
     */
    public function __construct(
        public int $undoWindowDays,
        public array $recipients,
        public array $items,
        public array $attachments,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'undo_window_days' => $this->undoWindowDays,
            'recipients' => $this->recipients,
            'items' => $this->items,
            'attachments' => $this->attachments,
        ];
    }
}
