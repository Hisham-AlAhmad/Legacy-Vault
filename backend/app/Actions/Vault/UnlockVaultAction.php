<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\DTOs\Auth\WrappedKeyDto;
use App\Exceptions\ApiException;
use App\Models\EncryptedKey;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * POST /vault/unlock — re-unlock the vault inside an existing session (the in-memory Master
 * Key doesn't survive a page reload, docs/10_Frontend_Architecture.md §3). The
 * password-wrapped Master Key is returned only after the password is re-verified, so a
 * stolen session cookie alone can't fetch material for an offline password-guessing attack
 * (docs/19_Decision_Log.md Decision #12). Audit: vault.unlocked / vault.unlock_failed.
 */
final class UnlockVaultAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(User $user, #[SensitiveParameter] string $password, ?string $ipAddress): WrappedKeyDto
    {
        if (! Hash::check($password, $user->password_hash)) {
            $this->audit->log($user->id, 'user', 'vault.unlock_failed', [], $ipAddress);
            throw ApiException::invalidCredentials();
        }

        $wrapped = $user->encryptedKeys()
            ->where('key_type', EncryptedKey::TYPE_PASSWORD_WRAPPED)
            ->firstOrFail();

        $this->audit->log($user->id, 'user', 'vault.unlocked', [], $ipAddress);

        return WrappedKeyDto::fromModel($wrapped);
    }
}
