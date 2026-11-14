<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

use App\Models\Recipient;

/**
 * Recipient as returned by the API (AI_RULES.md #4). Sealed fields go back as base64 so
 * the client can open them with its Metadata Key.
 */
final readonly class RecipientDto
{
    /**
     * @param list<string> $preferredMethods
     */
    public function __construct(
        public int $id,
        public string $nameEncB64,
        public string $relationshipEncB64,
        public ?string $email,
        public ?string $phone,
        public array $preferredMethods,
        public bool $isEmergencyContact,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromModel(Recipient $recipient): self
    {
        return new self(
            id: $recipient->id,
            nameEncB64: base64_encode($recipient->name_enc),
            relationshipEncB64: base64_encode($recipient->relationship_label_enc),
            email: $recipient->email,
            phone: $recipient->phone,
            preferredMethods: array_values($recipient->preferred_methods),
            isEmergencyContact: $recipient->is_emergency_contact,
            createdAt: $recipient->created_at->toIso8601String(),
            updatedAt: $recipient->updated_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name_enc' => $this->nameEncB64,
            'relationship_enc' => $this->relationshipEncB64,
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_methods' => $this->preferredMethods,
            'is_emergency_contact' => $this->isEmergencyContact,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
