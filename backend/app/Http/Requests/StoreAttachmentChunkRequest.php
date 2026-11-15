<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\VaultItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /attachments/{uuid}/chunks — docs/08_API_Specification.md §Attachments. The body is
 * raw ciphertext (`application/octet-stream`), so what gets validated here are the three
 * headers; the body's exact size and version byte depend on the upload's state and are
 * checked in the Action under a row lock.
 */
class StoreAttachmentChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * No chunk is ever larger than one full chunk envelope. Rejected on the declared
     * length before anything else looks at the body.
     */
    protected function prepareForValidation(): void
    {
        $declared = $this->headers->get('Content-Length');

        if ((is_numeric($declared) && (int) $declared > Attachment::CHUNK_BYTES)
            || strlen($this->getContent()) > Attachment::CHUNK_BYTES) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'legacyvault.vault.chunk_too_large', 413);
        }
    }

    /** The headers are the input; the octet-stream body is not form data. */
    public function validationData(): array
    {
        return [
            'chunk_index' => $this->headers->get('X-Chunk-Index'),
            'chunk_total' => $this->headers->get('X-Chunk-Total'),
            'upload_id' => $this->headers->get('X-Upload-Id'),
        ];
    }

    public function rules(): array
    {
        return [
            'chunk_index' => ['required', 'regex:/\\A(0|[1-9][0-9]{0,4})\\z/'],
            'chunk_total' => ['required', 'regex:/\\A[1-9][0-9]{0,4}\\z/'],
            'upload_id' => ['required', 'string', 'regex:'.VaultItem::UUID_PATTERN],
        ];
    }

    public function chunkIndex(): int
    {
        return (int) $this->validated('chunk_index');
    }

    public function chunkTotal(): int
    {
        return (int) $this->validated('chunk_total');
    }

    public function uploadId(): string
    {
        return (string) $this->validated('upload_id');
    }

    /** The raw chunk envelope. Opaque ciphertext — never logged, never inspected beyond its size and version byte. */
    public function chunkBytes(): string
    {
        return $this->getContent();
    }
}
