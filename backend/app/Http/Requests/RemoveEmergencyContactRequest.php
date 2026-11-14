<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** DELETE /emergency-contact — step-up: with no emergency contact, a missed grace period goes straight to delivery (docs/11_CheckIn_Engine.md §6). */
class RemoveEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
