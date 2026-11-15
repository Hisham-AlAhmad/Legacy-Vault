<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * An attachment is accessible only to its owner, and only while the whole chain above it
 * is the owner's and live: the item it belongs to, and that item's recipient
 * (docs/09_Backend_Architecture.md §4, VAULT-5). Route binding scopes `{attachment}` the
 * same way and turns a miss into a 404; this is the second, independent layer.
 */
final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->owns($user, $attachment);
    }

    public function update(User $user, Attachment $attachment): bool
    {
        return $this->owns($user, $attachment);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->owns($user, $attachment);
    }

    /** Undoing a deletion — only into an item that is live. */
    public function restore(User $user, Attachment $attachment): bool
    {
        return $this->owns($user, $attachment);
    }

    /** "Erase now" — permanent. The step-up password is checked in the Action. */
    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return $this->owns($user, $attachment);
    }

    private function owns(User $user, Attachment $attachment): bool
    {
        return $attachment->user_id === $user->id
            && $user->vaultItems()->whereKey($attachment->vault_item_id)->whereHas('recipient')->exists();
    }
}
