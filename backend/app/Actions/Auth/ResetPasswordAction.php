<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction as ResetLink;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Perform a password reset — docs/02_User_Flows.md Flow 6, docs/06_Cryptography.md §19.
 *
 * CRITICAL: this changes the AUTHENTICATION credential only. It cannot and must not touch
 * the encrypted_keys rows — the vault stays locked until the user completes Recovery Key
 * recovery (Flow 7). The stale password-wrapped Master Key copy is left in place; it becomes
 * unwrappable (old PDK), which the client detects and routes to recovery. This is correct
 * behavior, not a bug (AUTH-10).
 */
final class ResetPasswordAction
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(
        string $email,
        string $token,
        #[SensitiveParameter] string $newPassword,
        ?string $ipAddress,
    ): void {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($row === null
            || ! Hash::check($token, $row->token)
            || \Illuminate\Support\Carbon::parse($row->created_at)->addMinutes(ResetLink::TOKEN_TTL_MINUTES)->isPast()) {
            throw new ApiException('INVALID_RESET_TOKEN', 'legacyvault.auth.invalid_reset_token', 400);
        }

        $user = User::query()->where('email', $email)->firstOrFail();

        DB::transaction(function () use ($user, $email, $newPassword, $ipAddress): void {
            $user->forceFill(['password_hash' => Hash::make($newPassword)])->save();

            // Single-use token (docs/06_Cryptography.md §17).
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Revoke all existing sessions — a reset usually means the password was lost
            // or compromised (docs/05_Security_Architecture.md §3).
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $this->audit->log($user->id, 'user', 'auth.password_reset_completed', [], $ipAddress);
        });
    }
}
