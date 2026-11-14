<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\RecipientDto;
use App\Models\Recipient;
use App\Models\User;

/** GET /recipients — the user's own recipients only (docs/05_Security_Architecture.md §2). */
final class ListRecipientsAction
{
    /** @return list<RecipientDto> oldest first, so the order is stable across edits */
    public function execute(User $user): array
    {
        return $user->recipients()
            ->orderBy('id')
            ->get()
            ->map(fn (Recipient $recipient): RecipientDto => RecipientDto::fromModel($recipient))
            ->all();
    }
}
