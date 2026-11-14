<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AttemptLoginAction;
use App\Actions\Auth\RegisterUserAction;
use App\DTOs\Auth\UserSummaryDto;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /** POST /api/v1/auth/register — Audit: user.registered. */
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->execute($request->toData(), $request->ip());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => UserSummaryDto::fromModel($user)->toArray()], 201);
    }

    /** POST /api/v1/auth/login — Audit: auth.login_success / auth.login_failed. */
    public function login(LoginRequest $request, AttemptLoginAction $action): JsonResponse
    {
        $result = $action->execute(
            email: strtolower($request->validated('email')),
            password: $request->validated('password'),
            totpCode: $request->validated('totp_code'),
            remember: (bool) $request->validated('remember', false),
            request: $request,
        );

        return response()->json([
            'user' => UserSummaryDto::fromModel($result['user'])->toArray(),
            'password_wrapped_master_key' => $result['password_wrapped_master_key']->toArray(),
        ]);
    }

    /** POST /api/v1/auth/logout — server-side invalidation, immediate. Audit: auth.logout. */
    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $userId = $request->user()?->id;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $audit->log($userId, 'user', 'auth.logout', [], $request->ip());

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/auth/me — session bootstrap for the SPA. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserSummaryDto::fromModel($request->user())->toArray()]);
    }
}
