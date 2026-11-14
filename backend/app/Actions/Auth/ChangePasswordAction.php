<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\WrappedKeyInput;
use App\Exceptions\ApiException;
use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Password change with the vault already unlocked — docs/02_User_Flows.md Flow 8.
 * The client re-wraps the Master Key with the new PDK; hash and wrapped copy are updated
 * atomically (step 3). The recovery-wrapped copy is untouched. Step-up: current password
 * is required even within an active session (docs/05_Security_Architecture.md §3).
 */
final class ChangePasswordAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(
        User $user,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
        WrappedKeyInput $newPasswordWrappedMasterKey,
        ?string $ipAddress,
    ): void {
        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        DB::transaction(function () use ($user, $newPassword, $newPasswordWrappedMasterKey, $ipAddress): void {
            $user->forceFill(['password_hash' => Hash::make($newPassword)])->save();

            $this->replaceWrappedKey($user, EncryptedKey::TYPE_PASSWORD_WRAPPED, $newPasswordWrappedMasterKey);

            $this->audit->log($user->id, 'user', 'auth.password_changed', [], $ipAddress);
        });
    }

    private function replaceWrappedKey(User $user, string $keyType, WrappedKeyInput $input): void
    {
        $ciphertext = base64_decode($input->ciphertextB64, true);
        $nonce = base64_decode($input->nonceB64, true);
        $salt = base64_decode($input->kdfSaltB64, true);

        if ($ciphertext === false || strlen($ciphertext) !== 48
            || $nonce === false || strlen($nonce) !== 12
            || $salt === false || strlen($salt) !== 16) {
            throw new ApiException('VALIDATION_FAILED', 'legacyvault.validation.invalid_key_material', 422);
        }

        $user->encryptedKeys()->updateOrCreate(
            ['key_type' => $keyType],
            [
                'wrapped_key' => $ciphertext,
                'wrap_nonce' => $nonce,
                'kdf_salt' => $salt,
                'wrap_version' => 1,
            ],
        );
    }
}
