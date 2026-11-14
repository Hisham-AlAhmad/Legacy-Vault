<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** DELETE /auth/sessions/{id} — password required only when revoking the current session. */
class RevokeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
