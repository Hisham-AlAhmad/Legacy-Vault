<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\RecipientData;
use App\Models\Recipient;
use App\Rules\E164Phone;
use App\Rules\SealedField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /recipients/{id} — partial update. Omitted fields keep their stored value;
 * email/phone may be explicitly nulled.
 */
class UpdateRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $max = (int) config('legacyvault.max_sealed_field_bytes');

        return [
            'name_enc' => ['sometimes', 'required', 'string', new SealedField($max)],
            'relationship_enc' => ['sometimes', 'required', 'string', new SealedField($max)],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', new E164Phone()],
            'preferred_methods' => ['sometimes', 'required', 'array', 'min:1', 'max:'.count(Recipient::METHODS)],
            'preferred_methods.*' => ['required', 'string', 'distinct', 'in:'.implode(',', Recipient::METHODS)],
        ];
    }

    /** The full resulting state: validated input merged over the recipient's current values. */
    public function toData(Recipient $current): RecipientData
    {
        $validated = $this->validated();

        return new RecipientData(
            nameEnc: array_key_exists('name_enc', $validated)
                ? base64_decode($validated['name_enc'], true)
                : $current->name_enc,
            relationshipEnc: array_key_exists('relationship_enc', $validated)
                ? base64_decode($validated['relationship_enc'], true)
                : $current->relationship_label_enc,
            email: array_key_exists('email', $validated) ? $validated['email'] : $current->email,
            phone: array_key_exists('phone', $validated) ? $validated['phone'] : $current->phone,
            preferredMethods: array_values($validated['preferred_methods'] ?? $current->preferred_methods),
        );
    }
}
