<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VaultItem;

/**
 * An item is accessible only to its owner, and only while the recipient it is addressed
 * to is also one of that owner's live recipients (docs/09_Backend_Architecture.md §4,
 * VAULT-5). Route binding already scopes `{vaultItem}` the same way and turns a miss into
 * a 404; this is the second, independent layer.
 */
final class VaultItemPolicy
{
    public function view(User $user, VaultItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function update(User $user, VaultItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, VaultItem $item): bool
    {
        return $this->owns($user, $item);
    }

    /** Undoing a deletion — only into a recipient that is live. */
    public function restore(User $user, VaultItem $item): bool
    {
        return $this->owns($user, $item);
    }

    /** "Erase now" — permanent. The step-up password is checked in the Action. */
    public function forceDelete(User $user, VaultItem $item): bool
    {
        return $this->owns($user, $item);
    }

    private function owns(User $user, VaultItem $item): bool
    {
        return $item->user_id === $user->id
            && $user->recipients()->whereKey($item->recipient_id)->exists();
    }
}
