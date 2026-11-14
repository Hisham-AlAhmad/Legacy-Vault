<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

use App\Models\VaultItem;

/**
 * Vault item as returned by the API (AI_RULES.md #4). Ciphertext goes back as base64 for
 * the client to open; `id` is the client-generated UUID — the numeric primary key is
 * never exposed. The list shape (summary) leaves the body ciphertext out
 * (docs/08_API_Specification.md).
 */
final readonly class VaultItemDto
{
    public function __construct(
        public string $id,
        public int $recipientId,
        public string $itemType,
        public string $titleEncB64,
        public string $contentKeyWrappedB64,
        public ?string $contentEncB64,
        public bool $includesContent,
        public int $aadVersion,
        public int $position,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** Full item, including the body ciphertext. */
    public static function fromModel(VaultItem $item): self
    {
        return self::build($item, includesContent: true);
    }

    /** List entry: everything needed to show the title, without the body. */
    public static function summaryFromModel(VaultItem $item): self
    {
        return self::build($item, includesContent: false);
    }

    private static function build(VaultItem $item, bool $includesContent): self
    {
        return new self(
            id: $item->uuid,
            recipientId: $item->recipient_id,
            itemType: $item->item_type,
            titleEncB64: base64_encode($item->title_enc),
            contentKeyWrappedB64: base64_encode($item->content_key_wrapped),
            contentEncB64: $includesContent && $item->content_enc !== null ? base64_encode($item->content_enc) : null,
            includesContent: $includesContent,
            aadVersion: $item->aad_version,
            position: $item->position,
            createdAt: $item->created_at->toIso8601String(),
            updatedAt: $item->updated_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'recipient_id' => $this->recipientId,
            'item_type' => $this->itemType,
            'title_enc' => $this->titleEncB64,
            'content_key_wrapped' => $this->contentKeyWrappedB64,
            'aad_version' => $this->aadVersion,
            'position' => $this->position,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];

        if ($this->includesContent) {
            $data['content_enc'] = $this->contentEncB64;
        }

        return $data;
    }
}
