<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\TwoFactorActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorConfirmRequest;
use App\Http\Requests\TwoFactorDisableRequest;
use App\Http\Requests\TwoFactorEnableRequest;
use Illuminate\Http\JsonResponse;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorActions $actions)
    {
    }

    /** POST /api/v1/auth/2fa/enable — begin TOTP enrollment. */
    public function enable(TwoFactorEnableRequest $request): JsonResponse
    {
        $result = $this->actions->enable($request->user(), $request->validated('password'));

        return response()->json($result);
    }

    /** POST /api/v1/auth/2fa/confirm — Audit: auth.2fa_enabled. Backup codes returned once. */
    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $result = $this->actions->confirm($request->user(), $request->validated('code'), $request->ip());

        return response()->json($result);
    }

    /** POST /api/v1/auth/2fa/disable — Audit: auth.2fa_disabled. */
    public function disable(TwoFactorDisableRequest $request): JsonResponse
    {
        $this->actions->disable(
            $request->user(),
            $request->validated('password'),
            $request->validated('code'),
            $request->ip(),
        );

        return response()->json(['ok' => true]);
    }
}
