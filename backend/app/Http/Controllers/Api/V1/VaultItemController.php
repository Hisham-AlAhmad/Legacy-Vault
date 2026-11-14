<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\CreateVaultItemAction;
use App\Actions\Vault\DeleteVaultItemAction;
use App\Actions\Vault\GetVaultItemAction;
use App\Actions\Vault\ListVaultItemsAction;
use App\Actions\Vault\ReorderVaultItemsAction;
use App\Actions\Vault\UpdateVaultItemAction;
use App\DTOs\Vault\VaultItemDto;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderVaultItemsRequest;
use App\Http\Requests\StoreVaultItemRequest;
use App\Http\Requests\UpdateVaultItemRequest;
use App\Models\Recipient;
use App\Models\VaultItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Vault items — docs/08_API_Specification.md. `{recipient}` and `{vaultItem}` resolve
 * only within the authenticated user's own rows (AppServiceProvider route bindings), so
 * anyone else's ID is a 404, never a 403 that would confirm it exists. Everything in the
 * payloads is ciphertext the server stores and returns without opening (AI_RULES.md #13).
 */
class VaultItemController extends Controller
{
    /** GET /api/v1/recipients/{id}/items */
    public function index(Request $request, Recipient $recipient, ListVaultItemsAction $action): JsonResponse
    {
        Gate::authorize('view', $recipient);

        return response()->json(['items' => self::toArrays($action->execute($recipient))]);
    }

    /** POST /api/v1/recipients/{id}/items — Audit: vault_item.created. */
    public function store(StoreVaultItemRequest $request, Recipient $recipient, CreateVaultItemAction $action): JsonResponse
    {
        Gate::authorize('update', $recipient);

        $dto = $action->execute($request->user(), $recipient, $request->toData(), $request->ip());

        return response()->json(['item' => $dto->toArray()], 201);
    }

    /** POST /api/v1/recipients/{id}/items/reorder — Audit: vault_item.reordered. */
    public function reorder(ReorderVaultItemsRequest $request, Recipient $recipient, ReorderVaultItemsAction $action): JsonResponse
    {
        Gate::authorize('update', $recipient);

        $items = $action->execute($request->user(), $recipient, $request->order(), $request->ip());

        return response()->json(['items' => self::toArrays($items)]);
    }

    /** GET /api/v1/vault-items/{uuid} */
    public function show(VaultItem $vaultItem, GetVaultItemAction $action): JsonResponse
    {
        Gate::authorize('view', $vaultItem);

        return response()->json(['item' => $action->execute($vaultItem)->toArray()]);
    }

    /** PATCH /api/v1/vault-items/{uuid} — Audit: vault_item.updated. */
    public function update(UpdateVaultItemRequest $request, VaultItem $vaultItem, UpdateVaultItemAction $action): JsonResponse
    {
        Gate::authorize('update', $vaultItem);

        $dto = $action->execute($request->user(), $vaultItem, $request->toCiphertext(), $request->ip());

        return response()->json(['item' => $dto->toArray()]);
    }

    /** DELETE /api/v1/vault-items/{uuid} — Audit: vault_item.deleted. */
    public function destroy(Request $request, VaultItem $vaultItem, DeleteVaultItemAction $action): JsonResponse
    {
        Gate::authorize('delete', $vaultItem);

        $action->execute($request->user(), $vaultItem, $request->ip());

        return response()->json(['ok' => true]);
    }

    /**
     * @param list<VaultItemDto> $items
     * @return list<array<string, mixed>>
     */
    private static function toArrays(array $items): array
    {
        return array_map(fn (VaultItemDto $dto): array => $dto->toArray(), $items);
    }
}
