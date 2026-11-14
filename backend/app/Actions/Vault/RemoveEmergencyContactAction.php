<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * DELETE /emergency-contact — step-up re-auth: without an emergency contact, a missed grace
 * period goes straight to delivery (docs/11_CheckIn_Engine.md §6), so removing one is as
 * consequential as deleting a recipient. Audit: emergency_contact.removed.
 */
final class RemoveEmergencyContactAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, #[SensitiveParameter] string $password, ?string $ipAddress): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        $contact = $user->emergencyContact()->first();

        if ($contact === null) {
            throw new ApiException('EMERGENCY_CONTACT_NOT_FOUND', 'legacyvault.vault.emergency_contact_not_found', 404);
        }

        DB::transaction(function () use ($user, $contact, $ipAddress): void {
            $contact->delete();
            $user->recipients()->where('is_emergency_contact', true)->update(['is_emergency_contact' => false]);

            $this->audit->log($user->id, 'user', 'emergency_contact.removed', [], $ipAddress);
        });
    }
}
