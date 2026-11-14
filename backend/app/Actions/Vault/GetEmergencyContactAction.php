<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\EmergencyContactDto;
use App\Models\User;

/** GET /emergency-contact — null when none is configured yet. */
final class GetEmergencyContactAction
{
    public function execute(User $user): ?EmergencyContactDto
    {
        $contact = $user->emergencyContact()->first();

        if ($contact === null) {
            return null;
        }

        $linkedRecipientId = $user->recipients()->where('is_emergency_contact', true)->value('id');

        return EmergencyContactDto::fromModel($contact, $linkedRecipientId);
    }
}
