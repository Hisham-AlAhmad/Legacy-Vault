<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vault\GetEmergencyContactAction;
use App\Actions\Vault\RemoveEmergencyContactAction;
use App\Actions\Vault\SetEmergencyContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RemoveEmergencyContactRequest;
use App\Http\Requests\SetEmergencyContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Emergency contact — EC-1, EC-4 (docs/08_API_Specification.md). Always the caller's own. */
class EmergencyContactController extends Controller
{
    /** GET /api/v1/emergency-contact */
    public function show(Request $request, GetEmergencyContactAction $action): JsonResponse
    {
        return response()->json(['emergency_contact' => $action->execute($request->user())?->toArray()]);
    }

    /** POST /api/v1/emergency-contact — set or replace. Audit: emergency_contact.set. */
    public function store(SetEmergencyContactRequest $request, SetEmergencyContactAction $action): JsonResponse
    {
        $dto = $action->execute($request->user(), $request->toData(), $request->ip());

        return response()->json(['emergency_contact' => $dto->toArray()]);
    }

    /** DELETE /api/v1/emergency-contact — step-up password. Audit: emergency_contact.removed. */
    public function destroy(RemoveEmergencyContactRequest $request, RemoveEmergencyContactAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->validated('password'), $request->ip());

        return response()->json(['ok' => true]);
    }
}
