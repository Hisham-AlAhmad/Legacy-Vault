<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\UnlockVaultAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UnlockVaultRequest;
use Illuminate\Http\JsonResponse;

class VaultController extends Controller
{
    /** POST /api/v1/vault/unlock — Audit: vault.unlocked / vault.unlock_failed. */
    public function unlock(UnlockVaultRequest $request, UnlockVaultAction $action): JsonResponse
    {
        $wrapped = $action->execute($request->user(), $request->validated('password'), $request->ip());

        return response()->json(['password_wrapped_master_key' => $wrapped->toArray()]);
    }
}
