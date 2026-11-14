<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Structural check for a client-sealed metadata value (docs/06_Cryptography.md §15):
 * base64 of version(1) ‖ nonce(12) ‖ ciphertext ‖ tag(16).
 *
 * The server can't read the content and doesn't try to — this only rejects malformed or
 * oversized envelopes (defense against abuse, docs/08_API_Specification.md §3).
 */
final class SealedField implements ValidationRule
{
    public const VERSION = 1;
    public const OVERHEAD_BYTES = 1 + 12 + 16;

    public function __construct(private readonly int $maxBytes)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $bytes = is_string($value) ? base64_decode($value, true) : false;

        if ($bytes === false
            || strlen($bytes) <= self::OVERHEAD_BYTES
            || strlen($bytes) > $this->maxBytes
            || ord($bytes[0]) !== self::VERSION) {
            $fail(__('legacyvault.validation.invalid_sealed_field'));
        }
    }
}
