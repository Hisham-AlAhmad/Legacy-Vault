<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\Vault\NewAttachmentData;
use App\Models\Attachment;
use App\Models\VaultItem;
use App\Rules\SealedField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /vault-items/{uuid}/attachments — docs/08_API_Specification.md §Attachments.
 * Structure only: the server can't read the filename, the type or the key. The declared
 * size must be one the declared number of chunks can actually add up to
 * (docs/19_Decision_Log.md Decision #22). ID uniqueness, the per-item cap and the quota
 * need the database and live in the Action.
 */
class DeclareAttachmentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'regex:'.VaultItem::UUID_PATTERN],
            'filename_enc' => ['required', 'string', new SealedField((int) config('legacyvault.attachments.max_filename_bytes'))],
            'mime_type_enc' => ['required', 'string', SealedField::exactly(Attachment::MIME_ENVELOPE_BYTES)],
            'attachment_key_wrapped' => ['required', 'string', SealedField::exactly(Attachment::WRAPPED_KEY_BYTES)],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'chunk_count' => ['required', 'integer', 'min:1', 'max:65535'],
            'aad_version' => ['required', 'integer', 'in:'.Attachment::AAD_VERSION],
        ];
    }

    public function messages(): array
    {
        return [
            'id.regex' => __('legacyvault.validation.invalid_item_id'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['size_bytes', 'chunk_count'])) {
                    return;
                }

                if (! Attachment::isPossibleSize((int) $this->input('size_bytes'), (int) $this->input('chunk_count'))) {
                    $validator->errors()->add('size_bytes', __('legacyvault.validation.attachment_size_mismatch'));
                }
            },
        ];
    }

    public function toData(): NewAttachmentData
    {
        $validated = $this->validated();

        return new NewAttachmentData(
            uuid: $validated['id'],
            filenameEnc: base64_decode($validated['filename_enc'], true),
            mimeTypeEnc: base64_decode($validated['mime_type_enc'], true),
            attachmentKeyWrapped: base64_decode($validated['attachment_key_wrapped'], true),
            sizeBytes: (int) $validated['size_bytes'],
            chunkCount: (int) $validated['chunk_count'],
            aadVersion: (int) $validated['aad_version'],
        );
    }
}
