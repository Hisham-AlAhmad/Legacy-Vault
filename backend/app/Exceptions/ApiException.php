<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Domain exception rendered as the standard error envelope
 * (docs/08_API_Specification.md §1): { "error": { "code", "message", "fields" } }.
 * Messages are localized keys resolved at render time (docs/09_Backend_Architecture.md §9).
 */
class ApiException extends Exception
{
    /**
     * @param array<string, list<string>> $fields
     */
    public function __construct(
        public readonly string $errorCode,
        string $messageKey,
        public readonly int $status,
        public readonly array $fields = [],
    ) {
        parent::__construct($messageKey);
    }

    public static function invalidCredentials(): self
    {
        return new self('INVALID_CREDENTIALS', 'legacyvault.auth.invalid_credentials', 401);
    }

    public static function accountLocked(): self
    {
        return new self('ACCOUNT_LOCKED', 'legacyvault.auth.account_locked', 423);
    }

    public static function totpRequired(): self
    {
        return new self('TOTP_REQUIRED', 'legacyvault.auth.totp_required', 401);
    }

    public static function totpInvalid(): self
    {
        return new self('TOTP_INVALID', 'legacyvault.auth.totp_invalid', 401);
    }
}
