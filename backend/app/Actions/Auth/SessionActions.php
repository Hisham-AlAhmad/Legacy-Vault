<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Trusted devices / session list — AUTH-6, docs/02_User_Flows.md Flow 10.
 *
 * Raw session IDs are never exposed to the client (a session list containing other
 * sessions' raw tokens would make one stolen session a bridge to all of them); each
 * session is addressed by a SHA-256 hash of its ID.
 */
final class SessionActions
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(User $user, Request $request): array
    {
        $currentId = $request->session()->getId();
        $rows = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get();
        $devices = Device::query()->where('user_id', $user->id)->get()->keyBy('id');

        return $rows->map(function (object $row) use ($currentId, $devices): array {
            $deviceId = self::deviceIdFromPayload($row->payload);
            $device = $deviceId !== null ? $devices->get($deviceId) : null;

            return [
                'id' => hash('sha256', $row->id),
                'is_current' => $row->id === $currentId,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => \Illuminate\Support\Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                'device_label' => $device?->label,
                'device_trusted' => (bool) ($device?->trusted ?? false),
            ];
        })->values()->all();
    }

    /**
     * Revoke a session server-side, effective immediately
     * (docs/05_Security_Architecture.md §3). Revoking the CURRENT session requires
     * re-entering the password (Flow 10 step 3).
     */
    public function revoke(User $user, string $sessionIdHash, ?string $password, Request $request): void
    {
        $rows = DB::table('sessions')->where('user_id', $user->id)->get();
        $target = $rows->first(fn (object $row): bool => hash('sha256', $row->id) === $sessionIdHash);

        if ($target === null) {
            throw new ApiException('SESSION_NOT_FOUND', 'legacyvault.auth.session_not_found', 404);
        }

        if ($target->id === $request->session()->getId()) {
            if ($password === null || ! Hash::check($password, $user->password_hash)) {
                throw ApiException::invalidCredentials();
            }
        }

        DB::table('sessions')->where('id', $target->id)->delete();

        $this->audit->log($user->id, 'user', 'session.revoked', [
            'session_id_hash' => $sessionIdHash,
        ], $request->ip());
    }

    /** The database session driver serializes session data into `payload`. */
    private static function deviceIdFromPayload(string $payload): ?int
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $data = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) && isset($data['device_id']) && is_int($data['device_id'])
            ? $data['device_id']
            : null;
    }
}
