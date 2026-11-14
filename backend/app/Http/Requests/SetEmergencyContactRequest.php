<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\EmergencyContactData;
use App\Rules\E164Phone;
use App\Rules\SealedField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /emergency-contact — set or replace (EC-4). Email is required: the Phase 3
 * confirmation link is sent there (EC-3).
 */
class SetEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name_enc' => ['required', 'string', new SealedField((int) config('legacyvault.max_sealed_field_bytes'))],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', new E164Phone()],
            'recipient_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toData(): EmergencyContactData
    {
        $validated = $this->validated();

        return new EmergencyContactData(
            nameEnc: base64_decode($validated['name_enc'], true),
            email: $validated['email'],
            phone: $validated['phone'] ?? null,
            linkedRecipientId: isset($validated['recipient_id']) ? (int) $validated['recipient_id'] : null,
        );
    }
}
