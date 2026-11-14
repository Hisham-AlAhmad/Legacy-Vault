<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\RecipientData;
use App\Models\Recipient;
use App\Rules\E164Phone;
use App\Rules\SealedField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /recipients — docs/08_API_Specification.md. Field-level rules here; the
 * cross-field "can this recipient actually be reached" invariant runs in the Action, since
 * PATCH needs it against the merged state (docs/09_Backend_Architecture.md §8).
 */
class StoreRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $max = (int) config('legacyvault.max_sealed_field_bytes');

        return [
            'name_enc' => ['required', 'string', new SealedField($max)],
            'relationship_enc' => ['required', 'string', new SealedField($max)],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', new E164Phone()],
            'preferred_methods' => ['required', 'array', 'min:1', 'max:'.count(Recipient::METHODS)],
            'preferred_methods.*' => ['required', 'string', 'distinct', 'in:'.implode(',', Recipient::METHODS)],
        ];
    }

    public function toData(): RecipientData
    {
        $validated = $this->validated();

        return new RecipientData(
            nameEnc: base64_decode($validated['name_enc'], true),
            relationshipEnc: base64_decode($validated['relationship_enc'], true),
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            preferredMethods: array_values($validated['preferred_methods']),
        );
    }
}
