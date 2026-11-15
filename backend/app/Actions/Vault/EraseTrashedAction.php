<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\PurgeResult;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\TrashPurger;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DELETE /trash/{recipients|items|attachments}/{id} — "erase now": permanently removes a
 * soft-deleted entry and everything under it before its undo window ends, which is what
 * frees quota at once (docs/19_Decision_Log.md Decisions #26, #28).
 *
 * Irreversible, so the password is re-verified (docs/05_Security_Architecture.md §3):
 * without this a stolen session could only delete attachments reversibly. The erasing
 * itself is TrashPurger — the same code path as the scheduled purge.
 * Audit: recipient.purged / vault_item.purged / attachment.purged, reason user_requested.
 */
final class EraseTrashedAction
{
    public function __construct(private readonly TrashPurger $purger)
    {
    }

    public function recipient(User $user, Recipient $trashed, #[SensitiveParameter] string $password, ?string $ipAddress): void
    {
        $this->verify($user, $password);
        $this->finish($this->purger->recipient($trashed->id, TrashPurger::REASON_USER_REQUESTED, $ipAddress));
    }

    public function item(User $user, VaultItem $trashed, #[SensitiveParameter] string $password, ?string $ipAddress): void
    {
        $this->verify($user, $password);
        $this->finish($this->purger->item($trashed->id, TrashPurger::REASON_USER_REQUESTED, $ipAddress));
    }

    public function attachment(User $user, Attachment $trashed, #[SensitiveParameter] string $password, ?string $ipAddress): void
    {
        $this->verify($user, $password);
        $this->finish($this->purger->attachment($trashed->id, TrashPurger::REASON_USER_REQUESTED, $ipAddress));
    }

    private function verify(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }
    }

    private function finish(?PurgeResult $result): void
    {
        // Restored or already purged between the route binding and the lock.
        if ($result === null) {
            throw new NotFoundHttpException();
        }

        // A file could not be removed. Nothing is half-reported as erased: the entry is
        // still in the recently-deleted list and the scheduled purge will retry it.
        if (! $result->complete) {
            throw new ApiException('ERASE_INCOMPLETE', 'legacyvault.vault.erase_incomplete', 500);
        }
    }
}
