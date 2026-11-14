<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesItemCiphertext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /vault-items/{uuid} — a full replacement of key, title and body. There is no
 * partial content update: every edit rotates the content key (docs/06_Cryptography.md
 * §10), which re-encrypts everything, so all three envelopes are required together.
 * The ID, type and recipient are not updatable — they're bound into the AAD.
 */
class UpdateVaultItemRequest extends FormRequest
{
    use ValidatesItemCiphertext;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->ciphertextRules();
    }
}
