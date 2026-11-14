<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SessionActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\RevokeSessionRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private readonly SessionActions $sessions)
    {
    }

    /** GET /api/v1/auth/sessions — trusted devices / active sessions (AUTH-6, Flow 10). */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['sessions' => $this->sessions->list($request->user(), $request)]);
    }

    /** DELETE /api/v1/auth/sessions/{idHash} — Audit: session.revoked. */
    public function destroy(RevokeSessionRequest $request, string $idHash): JsonResponse
    {
        $this->sessions->revoke($request->user(), $idHash, $request->validated('password'), $request);

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/auth/login-history — AUTH-7, read from the audit log. */
    public function loginHistory(Request $request): JsonResponse
    {
        $entries = AuditLog::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('event_type', ['auth.login_success', 'auth.login_failed'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'event_type' => $log->event_type,
                'ip_address' => $log->ip_address !== null ? inet_ntop($log->ip_address) : null,
                'user_agent' => $log->metadata['user_agent'] ?? null,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json(['entries' => $entries]);
    }
}
