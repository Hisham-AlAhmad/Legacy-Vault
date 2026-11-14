<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Exceptions\ApiException;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * DELETE /recipients/{id} — step-up re-auth required (docs/05_Security_Architecture.md §3).
 * Soft delete (docs/07_Database_Design.md §Deletion strategy); the undo window and the
 * scheduled hard purge arrive with Phase 2c. The recipient's vault items are soft-deleted
 * with it, in the same transaction and with the same timestamp, so nothing addressed to a
 * deleted recipient is left behind as live content and 2c can restore the two together
 * (docs/19_Decision_Log.md Decision #18). Audit: recipient.deleted.
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

            // Same deleted_at as the recipient; updated_at is left alone — nothing was edited.
            $itemCount = VaultItem::withoutTimestamps(
                fn (): int => $recipient->vaultItems()->update(['deleted_at' => $recipient->deleted_at]),
            );

            $this->audit->log($user->id, 'user', 'recipient.deleted', [
                'recipient_id' => $recipient->id,
                'item_count' => $itemCount,
            ], $ipAddress);
        });
    }
}
