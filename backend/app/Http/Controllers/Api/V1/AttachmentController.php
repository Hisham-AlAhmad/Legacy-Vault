<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\DeclareAttachmentUploadAction;
use App\Actions\Vault\DeleteAttachmentAction;
use App\Actions\Vault\ListAttachmentsAction;
use App\Actions\Vault\StoreAttachmentChunkAction;
use App\DTOs\Vault\AttachmentDto;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeclareAttachmentUploadRequest;
use App\Http\Requests\StoreAttachmentChunkRequest;
use App\Models\Attachment;
use App\Models\VaultItem;
use App\Services\Vault\AttachmentStorage;
use App\Services\Vault\StorageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Attachments — docs/08_API_Specification.md §Attachments. `{vaultItem}` and
 * `{attachment}` resolve only within the authenticated user's own live rows
 * (AppServiceProvider route bindings), so anything else is a 404. Every payload is
 * ciphertext the server stores and returns without opening (AI_RULES.md #13).
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly StorageQuota $quota)
    {
    }

    /** GET /api/v1/vault/storage — the quota and current usage (Decision #19). */
    public function storage(Request $request): JsonResponse
    {
        return response()->json(['storage' => $this->quota->usageFor($request->user())->toArray()]);
    }

    /** GET /api/v1/vault-items/{uuid}/attachments */
    public function index(Request $request, VaultItem $vaultItem, ListAttachmentsAction $action): JsonResponse
    {
        Gate::authorize('view', $vaultItem);

        return response()->json([
            'attachments' => array_map(fn (AttachmentDto $dto): array => $dto->toArray(), $action->execute($vaultItem)),
            'storage' => $this->quota->usageFor($request->user())->toArray(),
        ]);
    }

    /** POST /api/v1/vault-items/{uuid}/attachments — declares an upload and reserves quota. */
    public function store(DeclareAttachmentUploadRequest $request, VaultItem $vaultItem, DeclareAttachmentUploadAction $action): JsonResponse
    {
        Gate::authorize('update', $vaultItem);

        $dto = $action->execute($request->user(), $vaultItem, $request->toData());

        return response()->json([
            'attachment' => $dto->toArray(),
            'storage' => $this->quota->usageFor($request->user())->toArray(),
        ], 201);
    }

    /** GET /api/v1/attachments/{uuid} — includes upload progress, for resuming. */
    public function show(Attachment $attachment): JsonResponse
    {
        Gate::authorize('view', $attachment);

        return response()->json([
            'attachment' => AttachmentDto::fromModel($attachment, $attachment->vaultItem->uuid)->toArray(),
        ]);
    }

    /** POST /api/v1/attachments/{uuid}/chunks — Audit: attachment.uploaded on completion. */
    public function storeChunk(StoreAttachmentChunkRequest $request, Attachment $attachment, StoreAttachmentChunkAction $action): JsonResponse
    {
        Gate::authorize('update', $attachment);

        $receipt = $action->execute(
            $request->user(),
            $attachment,
            $request->chunkIndex(),
            $request->chunkTotal(),
            $request->uploadId(),
            $request->chunkBytes(),
            $request->ip(),
        );

        $body = ['received_chunk' => $receipt->receivedChunk];

        if ($receipt->completedAttachment !== null) {
            $body['attachment'] = $receipt->completedAttachment->toArray();
            $body['storage'] = $this->quota->usageFor($request->user())->toArray();
        }

        return response()->json($body);
    }

    /**
     * GET /api/v1/attachments/{uuid}/content — the ciphertext file, as an opaque download.
     * The name is the attachment ID: the server can't know the real filename, and the
     * storage path is never exposed (docs/19_Decision_Log.md Decision #23).
     */
    public function download(Attachment $attachment, AttachmentStorage $storage): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);

        $path = $attachment->isComplete() ? $storage->filePath($attachment) : null;

        if ($path === null) {
            throw new NotFoundHttpException();
        }

        return response()->download($path, $attachment->uuid.'.bin', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** DELETE /api/v1/attachments/{uuid} — Audit: attachment.deleted / attachment.upload_aborted. */
    public function destroy(Request $request, Attachment $attachment, DeleteAttachmentAction $action): JsonResponse
    {
        Gate::authorize('delete', $attachment);

        $action->execute($request->user(), $attachment, $request->ip());

        return response()->json(['ok' => true, 'storage' => $this->quota->usageFor($request->user())->toArray()]);
    }
}
