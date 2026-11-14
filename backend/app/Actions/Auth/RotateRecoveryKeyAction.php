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
 * Issues a new Recovery Key — docs/02_User_Flows.md Flow 7 steps 3–4,
 * docs/06_Cryptography.md §11 (old Recovery Key invalidated by replacing its wrapped copy).
 *
 * The client has already: unwrapped the Master Key (via old Recovery Key or password),
 * generated a fresh Recovery Key, and re-wrapped the Master Key with the new RDK — and,
 * during recovery, with a fresh PDK too. The server just replaces rows atomically.
 * Step-up re-auth (current password) required (docs/05_Security_Architecture.md §3).
 */
final class RotateRecoveryKeyAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(
        User $user,
        #[SensitiveParameter] string $currentPassword,
        WrappedKeyInput $newRecoveryWrappedMasterKey,
        ?WrappedKeyInput $newPasswordWrappedMasterKey,
        ?string $ipAddress,
    ): void {
        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw ApiException::invalidCredentials();
        }

        DB::transaction(function () use ($user, $newRecoveryWrappedMasterKey, $newPasswordWrappedMasterKey, $ipAddress): void {
            $this->replace($user, EncryptedKey::TYPE_RECOVERY_WRAPPED, $newRecoveryWrappedMasterKey);

            // During Flow 7 the password-wrapped copy is stale (old PDK) and is replaced
            // in the same transaction; from security settings it's usually omitted.
            if ($newPasswordWrappedMasterKey !== null) {
                $this->replace($user, EncryptedKey::TYPE_PASSWORD_WRAPPED, $newPasswordWrappedMasterKey);
            }

            $this->audit->log($user->id, 'user', 'recovery_key.rotated', [], $ipAddress);
        });
    }

    private function replace(User $user, string $keyType, WrappedKeyInput $input): void
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
