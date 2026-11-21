<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Recipient;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Crypto\ServerKeyWrapService;
use App\Services\Vault\UndoWindow;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The SWK is read from config exactly once, here, and handed only to this service
        // (docs/06_Cryptography.md §5 point 4 — restrict SWK access to one class).
        $this->app->singleton(ServerKeyWrapService::class, function (): ServerKeyWrapService {
            return new ServerKeyWrapService(config('legacyvault.server_wrap_key'));
        });
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureOwnerScopedBindings();
    }

    /**
     * `{recipient}` and `{vaultItem}` resolve only within the authenticated user's own rows
     * (docs/05_Security_Architecture.md §2: `$user->recipients()->findOrFail()`, never
     * `Recipient::findOrFail()`). Anyone else's ID is a plain 404.
     */
    private function configureOwnerScopedBindings(): void
    {
        Route::bind('recipient', function (string $value): Recipient {
            $user = request()->user();

            if (! $user instanceof User || ! ctype_digit($value)) {
                throw new NotFoundHttpException();
            }

            return $user->recipients()->findOrFail((int) $value);
        });

        // `{vaultItem}` is the client-generated UUID. It resolves only to the caller's own
        // live item whose recipient is also live — everything else, including a malformed
        // ID, is the same 404.
        Route::bind('vaultItem', function (string $value): VaultItem {
            $user = request()->user();

            if (! $user instanceof User || preg_match(VaultItem::UUID_PATTERN, $value) !== 1) {
                throw new NotFoundHttpException();
            }

            return $user->vaultItems()->where('uuid', $value)->whereHas('recipient')->firstOrFail();
        });

        // `{attachment}`: the caller's own live attachment (an upload in progress
        // included) whose item and that item's recipient are both live.
        Route::bind('attachment', function (string $value): Attachment {
            $user = self::userWithUuid($value);

            return $user->attachments()
                ->where('uuid', $value)
                ->whereHas('vaultItem', fn ($item) => $item->whereHas('recipient'))
                ->firstOrFail();
        });

        // Recently deleted (docs/19_Decision_Log.md Decision #28): the caller's own
        // soft-deleted rows, still inside the undo window, whose parent is live — the
        // same set GET /trash lists. Anything else is a 404.
        Route::bind('trashedRecipient', function (string $value): Recipient {
            $user = request()->user();

            if (! $user instanceof User || ! ctype_digit($value)) {
                throw new NotFoundHttpException();
            }

            return $user->recipients()->onlyTrashed()
                ->where('deleted_at', '>', app(UndoWindow::class)->cutoff())
                ->findOrFail((int) $value);
        });

        Route::bind('trashedItem', function (string $value): VaultItem {
            return self::userWithUuid($value)->vaultItems()->onlyTrashed()
                ->where('uuid', $value)
                ->where('deleted_at', '>', app(UndoWindow::class)->cutoff())
                ->whereHas('recipient')
                ->firstOrFail();
        });

        Route::bind('trashedAttachment', function (string $value): Attachment {
            return self::userWithUuid($value)->attachments()->onlyTrashed()
                ->where('uuid', $value)
                ->where('deleted_at', '>', app(UndoWindow::class)->cutoff())
                ->whereHas('vaultItem', fn ($item) => $item->whereHas('recipient'))
                ->firstOrFail();
        });
    }

    /** The authenticated user, provided `$value` is a canonical UUIDv4 — otherwise a 404. */
    private static function userWithUuid(string $value): User
    {
        $user = request()->user();

        if (! $user instanceof User || preg_match(VaultItem::UUID_PATTERN, $value) !== 1) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    /** Rate limits per docs/05_Security_Architecture.md §7. */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            return [
                Limit::perMinute(5)->by('login-ip:'.$request->ip()),
                // Per-account limit independent of per-IP (docs/05_Security_Architecture.md §1)
                Limit::perMinutes(15, 5)->by('login-account:'.strtolower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perHour(3)->by('register-ip:'.$request->ip());
        });

        RateLimiter::for('password-forgot', function (Request $request): Limit {
            return Limit::perHour(3)->by(
                'pwreset:'.strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        // Endpoints that re-verify the password inside a session (vault unlock, recipient /
        // emergency-contact removal): tight per-user limit, independent of the general tier.
        RateLimiter::for('step-up', function (Request $request): Limit {
            return Limit::perMinute(10)->by('step-up:'.($request->user()?->id ?? $request->ip()));
        });

        // Declared uploads per hour (docs/05_Security_Architecture.md §7). Chunk requests
        // are not counted here — they fall under the general tier below.
        RateLimiter::for('uploads', function (Request $request): Limit {
            return Limit::perHour((int) config('legacyvault.attachments.uploads_per_hour'))
                ->by('uploads:'.($request->user()?->id ?? $request->ip()));
        });

        // Client-reported integrity failures (Decision #31). Requests only — the recorded
        // events have their own daily budget in RecordIntegrityEventsAction.
        RateLimiter::for('integrity-reports', function (Request $request): Limit {
            return Limit::perHour((int) config('legacyvault.integrity_events.requests_per_hour'))
                ->by('integrity-reports:'.($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('api-user', function (Request $request): Limit {
            return Limit::perMinute(120)->by('api:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
