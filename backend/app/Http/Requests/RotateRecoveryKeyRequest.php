<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /vault/recovery-key/rotate — docs/02_User_Flows.md Flow 7. */
class RotateRecoveryKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $keyLen = (int) config('legacyvault.max_wrapped_key_b64_length');

        return [
            'password' => ['required', 'string', 'max:1024'],
            'recovery_wrapped_master_key' => ['required', 'array'],
            'recovery_wrapped_master_key.ciphertext' => ['required', 'string', "max:{$keyLen}"],
            'recovery_wrapped_master_key.nonce' => ['required', 'string', 'max:64'],
            'recovery_wrapped_master_key.kdf_salt' => ['required', 'string', 'max:64'],
            'password_wrapped_master_key' => ['sometimes', 'array'],
            'password_wrapped_master_key.ciphertext' => ['required_with:password_wrapped_master_key', 'string', "max:{$keyLen}"],
            'password_wrapped_master_key.nonce' => ['required_with:password_wrapped_master_key', 'string', 'max:64'],
            'password_wrapped_master_key.kdf_salt' => ['required_with:password_wrapped_master_key', 'string', 'max:64'],
        ];
    }
}
