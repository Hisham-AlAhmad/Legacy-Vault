<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\EraseTrashedAction;
use App\Actions\Vault\ListTrashAction;
use App\Actions\Vault\RestoreAttachmentAction;
use App\Actions\Vault\RestoreRecipientAction;
use App\Actions\Vault\RestoreVaultItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\EraseTrashedRequest;
use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\VaultItem;
use App\Services\Vault\StorageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recently deleted: list, restore, erase now — docs/08_API_Specification.md, Decision #28.
 * `{trashedRecipient}`, `{trashedItem}` and `{trashedAttachment}` resolve only to the
 * caller's own soft-deleted rows that are still inside the undo window and whose parent
 * is live (AppServiceProvider); anything else is a 404.
 */
class TrashController extends Controller
{
    public function __construct(private readonly StorageQuota $quota)
    {
    }

    /** GET /api/v1/trash */
    public function index(Request $request, ListTrashAction $action): JsonResponse
    {
        return response()->json([
            ...$action->execute($request->user())->toArray(),
            'storage' => $this->quota->usageFor($request->user())->toArray(),
        ]);
    }

    /** POST /api/v1/trash/recipients/{id}/restore — Audit: recipient.restored. */
    public function restoreRecipient(Request $request, Recipient $trashedRecipient, RestoreRecipientAction $action): JsonResponse
    {
        Gate::authorize('restore', $trashedRecipient);

        return response()->json([
            'recipient' => $action->execute($request->user(), $trashedRecipient, $request->ip())->toArray(),
        ]);
    }

    /** POST /api/v1/trash/items/{uuid}/restore — Audit: vault_item.restored. */
    public function restoreItem(Request $request, VaultItem $trashedItem, RestoreVaultItemAction $action): JsonResponse
    {
        Gate::authorize('restore', $trashedItem);

        return response()->json([
            'item' => $action->execute($request->user(), $trashedItem, $request->ip())->toArray(),
        ]);
    }

    /** POST /api/v1/trash/attachments/{uuid}/restore — Audit: attachment.restored. */
    public function restoreAttachment(Request $request, Attachment $trashedAttachment, RestoreAttachmentAction $action): JsonResponse
    {
        Gate::authorize('restore', $trashedAttachment);

        return response()->json([
            'attachment' => $action->execute($request->user(), $trashedAttachment, $request->ip())->toArray(),
        ]);
    }

    /** DELETE /api/v1/trash/recipients/{id} — step-up password. Audit: recipient.purged. */
    public function eraseRecipient(EraseTrashedRequest $request, Recipient $trashedRecipient, EraseTrashedAction $action): JsonResponse
    {
        Gate::authorize('forceDelete', $trashedRecipient);

        $action->recipient($request->user(), $trashedRecipient, $request->validated('password'), $request->ip());

        return $this->erased($request);
    }

    /** DELETE /api/v1/trash/items/{uuid} — step-up password. Audit: vault_item.purged. */
    public function eraseItem(EraseTrashedRequest $request, VaultItem $trashedItem, EraseTrashedAction $action): JsonResponse
    {
        Gate::authorize('forceDelete', $trashedItem);

        $action->item($request->user(), $trashedItem, $request->validated('password'), $request->ip());

        return $this->erased($request);
    }

    /** DELETE /api/v1/trash/attachments/{uuid} — step-up password. Audit: attachment.purged. */
    public function eraseAttachment(EraseTrashedRequest $request, Attachment $trashedAttachment, EraseTrashedAction $action): JsonResponse
    {
        Gate::authorize('forceDelete', $trashedAttachment);

        $action->attachment($request->user(), $trashedAttachment, $request->validated('password'), $request->ip());

        return $this->erased($request);
    }

    private function erased(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'storage' => $this->quota->usageFor($request->user())->toArray()]);
    }
}
