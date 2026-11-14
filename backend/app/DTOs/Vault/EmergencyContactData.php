<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * POST /emergency-contact input. `linkedRecipientId` is set when the emergency contact is
 * also one of the user's recipients (EC-1: the roles may overlap); the client copies that
 * recipient's details into this record, which stays independent of it.
 */
final readonly class EmergencyContactData
{
    public function __construct(
        public string $nameEnc,
        public string $email,
        public ?string $phone,
        public ?int $linkedRecipientId,
    ) {
    }
}
