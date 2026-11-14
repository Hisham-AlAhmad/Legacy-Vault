<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\CreateRecipientAction;
use App\Actions\Vault\DeleteRecipientAction;
use App\Actions\Vault\ListRecipientsAction;
use App\Actions\Vault\UpdateRecipientAction;
use App\DTOs\Vault\RecipientDto;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteRecipientRequest;
use App\Http\Requests\StoreRecipientRequest;
use App\Http\Requests\UpdateRecipientRequest;
use App\Models\Recipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recipients — docs/08_API_Specification.md. `{recipient}` is resolved through the
 * authenticated user's own recipients (AppServiceProvider route binding), so another
 * user's ID is a 404, never a 403 that would confirm it exists.
 */
class RecipientController extends Controller
{
    /** GET /api/v1/recipients */
    public function index(Request $request, ListRecipientsAction $action): JsonResponse
    {
        return response()->json([
            'recipients' => array_map(
                fn (RecipientDto $dto): array => $dto->toArray(),
                $action->execute($request->user()),
            ),
        ]);
    }

    /** POST /api/v1/recipients — Audit: recipient.created. */
    public function store(StoreRecipientRequest $request, CreateRecipientAction $action): JsonResponse
    {
        $dto = $action->execute($request->user(), $request->toData(), $request->ip());

        return response()->json(['recipient' => $dto->toArray()], 201);
    }

    /** PATCH /api/v1/recipients/{id} — Audit: recipient.updated. */
    public function update(UpdateRecipientRequest $request, Recipient $recipient, UpdateRecipientAction $action): JsonResponse
    {
        Gate::authorize('update', $recipient);

        $dto = $action->execute($request->user(), $recipient, $request->toData($recipient), $request->ip());

        return response()->json(['recipient' => $dto->toArray()]);
    }

    /** DELETE /api/v1/recipients/{id} — step-up password. Audit: recipient.deleted. */
    public function destroy(DeleteRecipientRequest $request, Recipient $recipient, DeleteRecipientAction $action): JsonResponse
    {
        Gate::authorize('delete', $recipient);

        $action->execute($request->user(), $recipient, $request->validated('password'), $request->ip());

        return response()->json(['ok' => true]);
    }
}
