<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\NewVaultItemData;
use App\Http\Requests\Concerns\ValidatesItemCiphertext;
use App\Models\VaultItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /recipients/{id}/items — docs/08_API_Specification.md. The client picks the item
 * ID (docs/19_Decision_Log.md Decision #15), so it is validated like any other untrusted
 * input: canonical lowercase UUIDv4 only. Uniqueness and the per-recipient cap need the
 * database and live in the Action (docs/09_Backend_Architecture.md §8).
 */
class StoreVaultItemRequest extends FormRequest
{
    use ValidatesItemCiphertext;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'regex:'.VaultItem::UUID_PATTERN],
            'item_type' => ['required', 'string', 'in:'.implode(',', VaultItem::CREATABLE_TYPES)],
            ...$this->ciphertextRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'id.regex' => __('legacyvault.validation.invalid_item_id'),
        ];
    }

    public function toData(): NewVaultItemData
    {
        $validated = $this->validated();

        return new NewVaultItemData(
            uuid: $validated['id'],
            itemType: $validated['item_type'],
            ciphertext: $this->toCiphertext(),
        );
    }
}
