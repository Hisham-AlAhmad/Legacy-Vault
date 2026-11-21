<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * One client-reported integrity failure (POST /vault/integrity-events,
 * docs/19_Decision_Log.md Decision #31). Every field is an enumerated code or an ID —
 * there is deliberately nowhere to put text, ciphertext or key material.
 */
final readonly class IntegrityEventData
{
    public const KINDS = ['item', 'attachment', 'sealed_field'];

    /** Which parts each kind may name — the same labels as the AAD parts (docs/06_Cryptography.md §9, §15). */
    public const PARTS = [
        'item' => ['item.key', 'item.title', 'item.content'],
        'attachment' => ['attachment.key', 'attachment.name', 'attachment.mime', 'attachment.chunk'],
        'sealed_field' => ['recipient.name', 'recipient.relationship', 'emergency_contact.name'],
    ];

    public const REASONS = ['authentication_failed', 'malformed', 'unknown_version', 'length_mismatch', 'unreadable'];

    public const SURFACES = ['preview', 'list', 'editor', 'download', 'trash', 'recipients'];

    public function __construct(
        public string $kind,
        public string $part,
        public string $reason,
        public string $surface,
        public ?string $itemId,
        public ?string $attachmentId,
        public ?int $recipientId,
    ) {
    }

    /** @return list<string> every part of every kind */
    public static function allParts(): array
    {
        return array_merge(...array_values(self::PARTS));
    }

    /**
     * Which IDs an event of this kind/part must carry; every other ID must be absent.
     *
     * @return list<'item_id'|'attachment_id'|'recipient_id'>
     */
    public static function requiredIds(string $kind, string $part): array
    {
        return match (true) {
            $kind === 'item' => ['item_id'],
            $kind === 'attachment' => ['item_id', 'attachment_id'],
            str_starts_with($part, 'recipient.') => ['recipient_id'],
            default => [],
        };
    }
}
