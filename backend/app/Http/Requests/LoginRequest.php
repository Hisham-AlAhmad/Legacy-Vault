<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/login — docs/08_API_Specification.md. */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'totp_code' => ['nullable', 'string', 'max:16'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
