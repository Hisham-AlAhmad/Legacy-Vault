<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Recipient;
use App\Models\User;
use App\Services\Crypto\ServerKeyWrapService;
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
     * `{recipient}` resolves only within the authenticated user's own rows
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

        RateLimiter::for('api-user', function (Request $request): Limit {
            return Limit::perMinute(120)->by('api:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
