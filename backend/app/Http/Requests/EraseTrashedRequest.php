<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /trash/… — permanent erasure needs step-up re-authentication
 * (docs/05_Security_Architecture.md §3, docs/19_Decision_Log.md Decision #28).
 */
class EraseTrashedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
