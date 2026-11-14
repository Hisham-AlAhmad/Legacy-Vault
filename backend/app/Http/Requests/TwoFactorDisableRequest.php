<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /auth/2fa/disable — step-up: password + current code. */
class TwoFactorDisableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['required', 'string', 'max:16'],
        ];
    }
}
