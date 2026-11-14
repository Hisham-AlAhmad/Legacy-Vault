<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Password reset request — docs/02_User_Flows.md Flow 6 step 2: the response is identical
 * whether or not the email exists (no account enumeration, AUTH-9), and a dummy hash keeps
 * timing comparable (docs/05_Security_Architecture.md §13).
 */
final class SendPasswordResetLinkAction
{
    public const TOKEN_TTL_MINUTES = 60;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function execute(string $email, ?string $ipAddress): void
    {
        $user = User::query()
            ->where('email', $email)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($user === null) {
            // Same work profile as the real path, same (generic) response from the caller.
            Hash::make(Str::random(32));

            return;
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        Mail::to($user->email)->queue(new PasswordResetMail($token, $user->email, $user->locale));

        $this->audit->log($user->id, 'user', 'auth.password_reset_requested', [], $ipAddress);
    }
}
