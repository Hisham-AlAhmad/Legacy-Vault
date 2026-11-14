<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recipient;
use App\Models\User;

/**
 * Owner-only access (docs/09_Backend_Architecture.md §4). Route binding already resolves
 * recipients through `$user->recipients()` (404 for anyone else's); this is the second,
 * independent layer.
 */
final class RecipientPolicy
{
    public function update(User $user, Recipient $recipient): bool
    {
        return $recipient->user_id === $user->id;
    }

    public function delete(User $user, Recipient $recipient): bool
    {
        return $recipient->user_id === $user->id;
    }
}
