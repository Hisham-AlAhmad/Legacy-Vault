<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use SensitiveParameter;

/**
 * Registration request DTO — docs/08_API_Specification.md POST /auth/register,
 * docs/02_User_Flows.md Flow 1 step 5.
 */
final readonly class RegisterUserData
{
    public function __construct(
        public string $email,
        #[SensitiveParameter] public string $password,
        public string $locale,
        public WrappedKeyInput $passwordWrappedMasterKey,
        public WrappedKeyInput $recoveryWrappedMasterKey,
        /**
         * The one moment the server sees the plaintext Master Key (TLS-protected,
         * registration only) — immediately SWK-wrapped and discarded
         * (docs/06_Cryptography.md §5 point 1).
         */
        #[SensitiveParameter] public string $serverWrapMasterKeyPlaintextB64,
    ) {
    }
}
