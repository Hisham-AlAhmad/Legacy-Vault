<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use ZxcvbnPhp\Zxcvbn;

/** POST /auth/password/reset — docs/02_User_Flows.md Flow 6. */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint (token-authenticated)
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'new_password' => ['required', 'string', 'min:12', 'max:1024'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $password = (string) $this->input('new_password', '');

                if ($password === '' || $validator->errors()->has('new_password')) {
                    return;
                }

                if ((new Zxcvbn())->passwordStrength($password)['score'] < 3) {
                    $validator->errors()->add('new_password', __('legacyvault.validation.password_too_weak'));
                }
            },
        ];
    }
}
