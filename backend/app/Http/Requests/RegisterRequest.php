<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Auth\RegisterUserData;
use App\DTOs\Auth\WrappedKeyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use ZxcvbnPhp\Zxcvbn;

/**
 * POST /auth/register — docs/08_API_Specification.md. Server-side password strength
 * re-check (zxcvbn ≥ 3, AUTH-2) — never trust the client-side check alone.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    public function rules(): array
    {
        $keyLen = (int) config('legacyvault.max_wrapped_key_b64_length');

        $wrappedKeyRules = fn (string $field): array => [
            $field => ['required', 'array'],
            "{$field}.ciphertext" => ['required', 'string', "max:{$keyLen}"],
            "{$field}.nonce" => ['required', 'string', 'max:64'],
            "{$field}.kdf_salt" => ['required', 'string', 'max:64'],
        ];

        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
            'locale' => ['required', 'in:en,ar'],
            ...$wrappedKeyRules('password_wrapped_master_key'),
            ...$wrappedKeyRules('recovery_wrapped_master_key'),
            'server_wrap_master_key_plaintext' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $password = (string) $this->input('password', '');

                if ($password === '' || $validator->errors()->has('password')) {
                    return;
                }

                $score = (new Zxcvbn())->passwordStrength($password, [(string) $this->input('email', '')])['score'];

                if ($score < 3) {
                    $validator->errors()->add('password', __('legacyvault.validation.password_too_weak'));
                }
            },
        ];
    }

    public function toData(): RegisterUserData
    {
        $validated = $this->validated();

        return new RegisterUserData(
            email: strtolower($validated['email']),
            password: $validated['password'],
            locale: $validated['locale'],
            passwordWrappedMasterKey: WrappedKeyInput::fromArray($validated['password_wrapped_master_key']),
            recoveryWrappedMasterKey: WrappedKeyInput::fromArray($validated['recovery_wrapped_master_key']),
            serverWrapMasterKeyPlaintextB64: $validated['server_wrap_master_key_plaintext'],
        );
    }
}
