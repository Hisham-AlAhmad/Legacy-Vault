<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

use App\Models\EmergencyContact;

/** Emergency contact as returned by the API (AI_RULES.md #4). */
final readonly class EmergencyContactDto
{
    public function __construct(
        public string $nameEncB64,
        public string $email,
        public ?string $phone,
        public ?int $linkedRecipientId,
        public string $updatedAt,
    ) {
    }

    public static function fromModel(EmergencyContact $contact, ?int $linkedRecipientId): self
    {
        return new self(
            nameEncB64: base64_encode($contact->name_enc),
            email: $contact->email,
            phone: $contact->phone,
            linkedRecipientId: $linkedRecipientId,
            updatedAt: $contact->updated_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name_enc' => $this->nameEncB64,
            'email' => $this->email,
            'phone' => $this->phone,
            'linked_recipient_id' => $this->linkedRecipientId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
