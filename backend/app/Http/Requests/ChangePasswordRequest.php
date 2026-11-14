<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use ZxcvbnPhp\Zxcvbn;

/** POST /auth/password/change — docs/02_User_Flows.md Flow 8. */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $keyLen = (int) config('legacyvault.max_wrapped_key_b64_length');

        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'new_password' => ['required', 'string', 'min:12', 'max:1024'],
            'password_wrapped_master_key' => ['required', 'array'],
            'password_wrapped_master_key.ciphertext' => ['required', 'string', "max:{$keyLen}"],
            'password_wrapped_master_key.nonce' => ['required', 'string', 'max:64'],
            'password_wrapped_master_key.kdf_salt' => ['required', 'string', 'max:64'],
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
