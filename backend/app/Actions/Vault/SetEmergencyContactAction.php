<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\EmergencyContactData;
use App\DTOs\Vault\EmergencyContactDto;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * POST /emergency-contact — set or replace (EC-4; one per user in v1). When the contact is
 * also a recipient, that recipient's `is_emergency_contact` flag is set and every other
 * flag cleared, so at most one recipient carries it. Audit: emergency_contact.set.
 */
final class SetEmergencyContactAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, EmergencyContactData $data, ?string $ipAddress): EmergencyContactDto
    {
        return DB::transaction(function () use ($user, $data, $ipAddress): EmergencyContactDto {
            $linked = null;

            if ($data->linkedRecipientId !== null) {
                // Scoped lookup — another user's recipient is indistinguishable from a missing one.
                $linked = $user->recipients()->find($data->linkedRecipientId);

                if ($linked === null) {
                    throw new ApiException('VALIDATION_FAILED', 'legacyvault.validation.failed', 422, [
                        'recipient_id' => [__('legacyvault.validation.recipient_not_found')],
                    ]);
                }
            }

            $existed = $user->emergencyContact()->exists();

            $contact = $user->emergencyContact()->updateOrCreate([], [
                'name_enc' => $data->nameEnc,
                'email' => $data->email,
                'phone' => $data->phone,
            ]);

            $user->recipients()->where('is_emergency_contact', true)->update(['is_emergency_contact' => false]);
            $linked?->forceFill(['is_emergency_contact' => true])->save();

            $this->audit->log($user->id, 'user', 'emergency_contact.set', [
                'reason' => $existed ? 'replaced' : 'created',
                'linked_recipient_id' => $linked?->id,
            ], $ipAddress);

            return EmergencyContactDto::fromModel($contact, $linked?->id);
        });
    }
}
