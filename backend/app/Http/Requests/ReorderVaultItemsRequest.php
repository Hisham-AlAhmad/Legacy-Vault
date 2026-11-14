<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VaultItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /recipients/{id}/items/reorder — the complete ordered list of that recipient's
 * item IDs (docs/08_API_Specification.md). Shape is checked here; that the list matches
 * the recipient's live items exactly is checked in the Action, against the database.
 */
class ReorderVaultItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'order' => ['present', 'array', 'max:'.(int) config('legacyvault.vault_items.max_per_recipient')],
            'order.*' => ['required', 'string', 'distinct', 'regex:'.VaultItem::UUID_PATTERN],
        ];
    }

    public function messages(): array
    {
        return [
            'order.*.regex' => __('legacyvault.validation.invalid_item_id'),
        ];
    }

    /** @return list<string> */
    public function order(): array
    {
        return array_values($this->validated('order'));
    }
}
