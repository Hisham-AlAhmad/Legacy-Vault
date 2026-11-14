<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\DTOs\Auth\WrappedKeyInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    /** POST /api/v1/auth/password/forgot — identical response either way (AUTH-9). */
    public function forgot(ForgotPasswordRequest $request, SendPasswordResetLinkAction $action): JsonResponse
    {
        $action->execute(strtolower($request->validated('email')), $request->ip());

        return response()->json(['message' => __('legacyvault.auth.reset_link_sent')]);
    }

    /** POST /api/v1/auth/password/reset — restores account access, NOT vault access (AUTH-10). */
    public function reset(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->execute(
            email: strtolower($request->validated('email')),
            token: $request->validated('token'),
            newPassword: $request->validated('new_password'),
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => __('legacyvault.auth.password_reset_done')]);
    }

    /** POST /api/v1/auth/password/change — Flow 8. Audit: auth.password_changed. */
    public function change(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $action->execute(
            user: $request->user(),
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('new_password'),
            newPasswordWrappedMasterKey: WrappedKeyInput::fromArray($request->validated('password_wrapped_master_key')),
            ipAddress: $request->ip(),
        );

        return response()->json(['ok' => true]);
    }
}
