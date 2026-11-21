<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\IntegrityEventData;
use App\Models\VaultItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /vault/integrity-events — docs/08_API_Specification.md, docs/19_Decision_Log.md
 * Decision #31. Shape only: enumerated codes, a part that belongs to its kind, and exactly
 * the IDs that kind needs. That the IDs are the caller's own rows is checked in the
 * Action, against the database. Unknown keys never leave this class (toEvents() reads
 * validated fields only), so nothing the client adds can reach the audit log.
 */
class ReportIntegrityEventsRequest extends FormRequest
{
    private const ID_FIELDS = ['item_id', 'attachment_id', 'recipient_id'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('legacyvault.integrity_events.max_per_request')],
            'events.*' => ['required', 'array'],
            'events.*.kind' => ['required', 'string', Rule::in(IntegrityEventData::KINDS)],
            'events.*.part' => ['required', 'string', Rule::in(IntegrityEventData::allParts())],
            'events.*.reason' => ['required', 'string', Rule::in(IntegrityEventData::REASONS)],
            'events.*.surface' => ['required', 'string', Rule::in(IntegrityEventData::SURFACES)],
            'events.*.item_id' => ['nullable', 'string', 'regex:'.VaultItem::UUID_PATTERN],
            'events.*.attachment_id' => ['nullable', 'string', 'regex:'.VaultItem::UUID_PATTERN],
            'events.*.recipient_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** The part must belong to the kind, and the event must carry exactly the IDs its kind needs. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach ($this->input('events', []) as $index => $event) {
                    $kind = $event['kind'];
                    $part = $event['part'];

                    if (! in_array($part, IntegrityEventData::PARTS[$kind], true)) {
                        $validator->errors()->add("events.$index.part", __('legacyvault.validation.integrity_part_mismatch'));
                        continue;
                    }

                    $required = IntegrityEventData::requiredIds($kind, $part);
                    foreach (self::ID_FIELDS as $field) {
                        $present = isset($event[$field]);
                        if ($present !== in_array($field, $required, true)) {
                            $validator->errors()->add("events.$index.$field", __('legacyvault.validation.integrity_ids'));
                        }
                    }
                }
            },
        ];
    }

    /** @return list<IntegrityEventData> */
    public function toEvents(): array
    {
        return array_map(
            static fn (array $event): IntegrityEventData => new IntegrityEventData(
                kind: $event['kind'],
                part: $event['part'],
                reason: $event['reason'],
                surface: $event['surface'],
                itemId: $event['item_id'] ?? null,
                attachmentId: $event['attachment_id'] ?? null,
                recipientId: isset($event['recipient_id']) ? (int) $event['recipient_id'] : null,
            ),
            array_values($this->validated('events')),
        );
    }
}
