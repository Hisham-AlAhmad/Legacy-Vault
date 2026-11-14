<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AcknowledgeRecoveryKeyAction;
use App\Actions\Auth\RotateRecoveryKeyAction;
use App\DTOs\Auth\WrappedKeyDto;
use App\DTOs\Auth\WrappedKeyInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\RotateRecoveryKeyRequest;
use App\Models\EncryptedKey;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecoveryKeyController extends Controller
{
    /** POST /api/v1/vault/recovery-key/acknowledge — finalizes registration (Flow 1). */
    public function acknowledge(Request $request, AcknowledgeRecoveryKeyAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->ip());

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/vault/recovery-key/verify — begin recovery (Flow 7 step 2): returns the
     * recovery-wrapped Master Key so the client can attempt the unwrap locally. The server
     * cannot verify the Recovery Key itself (it never stores it) — a wrong key simply fails
     * the client-side GCM tag check.
     */
    public function verify(Request $request, AuditLogger $audit): JsonResponse
    {
        $key = $request->user()->encryptedKeys()
            ->where('key_type', EncryptedKey::TYPE_RECOVERY_WRAPPED)
            ->firstOrFail();

        $audit->log($request->user()->id, 'user', 'recovery_key.recovery_started', [], $request->ip());

        return response()->json(['recovery_wrapped_master_key' => WrappedKeyDto::fromModel($key)->toArray()]);
    }

    /** POST /api/v1/vault/recovery-key/rotate — Audit: recovery_key.rotated. */
    public function rotate(RotateRecoveryKeyRequest $request, RotateRecoveryKeyAction $action): JsonResponse
    {
        $validated = $request->validated();

        $action->execute(
            user: $request->user(),
            currentPassword: $validated['password'],
            newRecoveryWrappedMasterKey: WrappedKeyInput::fromArray($validated['recovery_wrapped_master_key']),
            newPasswordWrappedMasterKey: isset($validated['password_wrapped_master_key'])
                ? WrappedKeyInput::fromArray($validated['password_wrapped_master_key'])
                : null,
            ipAddress: $request->ip(),
        );

        return response()->json(['ok' => true]);
    }
}
