<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Exceptions\ApiException;
use App\Models\Recipient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * DELETE /recipients/{id} — step-up re-auth required (docs/05_Security_Architecture.md §3).
 * Soft delete (docs/07_Database_Design.md §Deletion strategy); the undo window and the
 * scheduled hard purge arrive with Phase 2c. Audit: recipient.deleted.
 */
final class DeleteRecipientAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(
        User $user,
        Recipient $recipient,
        #[SensitiveParameter] string $password,
        ?string $ipAddress,
    ): void {
        if (! Hash::check($password, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        DB::transaction(function () use ($user, $recipient, $ipAddress): void {
            $recipient->delete();

            $this->audit->log($user->id, 'user', 'recipient.deleted', [
                'recipient_id' => $recipient->id,
            ], $ipAddress);
        });
    }
}
