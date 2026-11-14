<?php

declare(strict_types=1);

namespace App\DTOs\Vault;

/**
 * Recipient input — POST /recipients and (merged over current values) PATCH
 * /recipients/{id} (docs/08_API_Specification.md). Sealed fields are already-decoded
 * ciphertext bytes; the server never sees the plaintext name/relationship.
 */
final readonly class RecipientData
{
    /**
     * @param list<string> $preferredMethods subset of email|sms|link
     */
    public function __construct(
        public string $nameEnc,
        public string $relationshipEnc,
        public ?string $email,
        public ?string $phone,
        public array $preferredMethods,
    ) {
    }
}
