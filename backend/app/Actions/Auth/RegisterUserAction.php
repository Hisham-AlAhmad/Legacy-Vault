<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\RegisterUserData;
use App\DTOs\Auth\WrappedKeyInput;
use App\Exceptions\ApiException;
use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Crypto\ServerKeyWrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an account and establishes the full key hierarchy — docs/02_User_Flows.md Flow 1
 * step 5, docs/06_Cryptography.md §4–5. The account stays `pending` until the Recovery Key
 * is acknowledged (Flow 1 edge case); unacknowledged accounts are purged after 24h.
 */
final class RegisterUserAction
{
    public function __construct(
        private readonly ServerKeyWrapService $serverKeyWrap,
        private readonly AuditLogger $audit,
    ) {
    }

    public function execute(RegisterUserData $data, ?string $ipAddress): User
    {
        return DB::transaction(function () use ($data, $ipAddress): User {
            // A stale pending registration (user closed the tab before acknowledging the
            // Recovery Key) may be replaced — the shown-once Recovery Key is gone, so the
            // only sane path is a fresh registration (Flow 1 edge case).
            $existing = User::query()->where('email', $data->email)->first();

            if ($existing !== null) {
                if ($existing->status !== User::STATUS_PENDING) {
                    // Enumeration trade-off accepted per docs/19_Decision_Log.md #10 —
                    // blunted by the 3/hour/IP registration rate limit.
                    throw new ApiException('EMAIL_IN_USE', 'legacyvault.auth.email_in_use', 409);
                }

                $existing->forceDelete();
            }

            $user = User::query()->create([
                'email' => $data->email,
                'password_hash' => Hash::make($data->password),
                'status' => User::STATUS_PENDING,
                'locale' => $data->locale,
            ]);

            $this->storeClientWrappedKey($user, EncryptedKey::TYPE_PASSWORD_WRAPPED, $data->passwordWrappedMasterKey);
            $this->storeClientWrappedKey($user, EncryptedKey::TYPE_RECOVERY_WRAPPED, $data->recoveryWrappedMasterKey);

            // The zero-access mechanism (docs/06_Cryptography.md §5): wrap the plaintext
            // Master Key with the SWK immediately; the plaintext is never persisted or logged.
            $masterKeyRaw = base64_decode($data->serverWrapMasterKeyPlaintextB64, true);

            if ($masterKeyRaw === false || strlen($masterKeyRaw) !== 32) {
                throw new ApiException('VALIDATION_FAILED', 'legacyvault.validation.invalid_key_material', 422);
            }

            $wrapped = $this->serverKeyWrap->wrap($masterKeyRaw, $user->id);
            sodium_memzero($masterKeyRaw);

            $user->encryptedKeys()->create([
                'key_type' => EncryptedKey::TYPE_SERVER_WRAPPED,
                'wrapped_key' => $wrapped->ciphertext,
                'wrap_nonce' => $wrapped->nonce,
                'kdf_salt' => null,
                'wrap_version' => $wrapped->version,
            ]);

            $this->audit->log($user->id, 'user', 'user.registered', [
                'locale' => $data->locale,
            ], $ipAddress);

            return $user;
        });
    }

    private function storeClientWrappedKey(User $user, string $keyType, WrappedKeyInput $input): void
    {
        $ciphertext = base64_decode($input->ciphertextB64, true);
        $nonce = base64_decode($input->nonceB64, true);
        $salt = base64_decode($input->kdfSaltB64, true);

        // 32-byte key + 16-byte GCM tag = 48-byte ciphertext; 12-byte nonce; 16-byte salt
        // (docs/06_Cryptography.md §7–9).
        if ($ciphertext === false || strlen($ciphertext) !== 48
            || $nonce === false || strlen($nonce) !== 12
            || $salt === false || strlen($salt) !== 16) {
            throw new ApiException('VALIDATION_FAILED', 'legacyvault.validation.invalid_key_material', 422);
        }

        $user->encryptedKeys()->create([
            'key_type' => $keyType,
            'wrapped_key' => $ciphertext,
            'wrap_nonce' => $nonce,
            'kdf_salt' => $salt,
            'wrap_version' => 1,
        ]);
    }
}
