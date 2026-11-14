<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Phone numbers are stored in E.164 (`+` and 7–15 digits) so SMS providers accept them. */
final class E164Phone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\+[1-9][0-9]{6,14}$/', $value) !== 1) {
            $fail(__('legacyvault.validation.invalid_phone'));
        }
    }
}
