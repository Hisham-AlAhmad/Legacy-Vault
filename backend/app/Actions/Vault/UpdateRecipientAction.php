<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Vault\RecipientData;
use App\DTOs\Vault\RecipientDto;
use App\Models\Recipient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** PATCH /recipients/{id} — VAULT-9. Audit: recipient.updated (changed field names, never values). */
final class UpdateRecipientAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, Recipient $recipient, RecipientData $data, ?string $ipAddress): RecipientDto
    {
        RecipientReachability::assert($data);

        return DB::transaction(function () use ($user, $recipient, $data, $ipAddress): RecipientDto {
            $recipient->fill([
                'name_enc' => $data->nameEnc,
                'relationship_label_enc' => $data->relationshipEnc,
                'email' => $data->email,
                'phone' => $data->phone,
                'preferred_methods' => $data->preferredMethods,
            ]);

            $changed = array_keys($recipient->getDirty());

            if ($changed !== []) {
                $recipient->save();

                $this->audit->log($user->id, 'user', 'recipient.updated', [
                    'recipient_id' => $recipient->id,
                    'changed_fields' => $changed,
                ], $ipAddress);
            }

            return RecipientDto::fromModel($recipient);
        });
    }
}
