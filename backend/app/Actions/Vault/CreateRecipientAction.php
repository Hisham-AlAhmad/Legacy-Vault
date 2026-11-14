<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\RecipientData;
use App\DTOs\Vault\RecipientDto;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** POST /recipients — docs/02_User_Flows.md Flow 2 steps 1–3, VAULT-4. Audit: recipient.created. */
final class CreateRecipientAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, RecipientData $data, ?string $ipAddress): RecipientDto
    {
        RecipientReachability::assert($data);

        return DB::transaction(function () use ($user, $data, $ipAddress): RecipientDto {
            $recipient = $user->recipients()->create([
                'name_enc' => $data->nameEnc,
                'relationship_label_enc' => $data->relationshipEnc,
                'email' => $data->email,
                'phone' => $data->phone,
                'preferred_methods' => $data->preferredMethods,
            ]);

            // IDs and method names only — never names or contact details (docs/05_Security_Architecture.md §16–17).
            $this->audit->log($user->id, 'user', 'recipient.created', [
                'recipient_id' => $recipient->id,
                'preferred_methods' => $data->preferredMethods,
            ], $ipAddress);

            return RecipientDto::fromModel($recipient);
        });
    }
}
