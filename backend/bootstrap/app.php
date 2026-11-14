<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum cookie-based SPA sessions (docs/05_Security_Architecture.md §3-5):
        // CSRF cookie + header double-submit on every state-changing request.
        $middleware->statefulApi();

        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
        $middleware->api(append: App\Http\Middleware\SetLocale::class);

        $middleware->alias([
            'account.active' => App\Http\Middleware\EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Standard error envelope for the API — docs/08_API_Specification.md §1.
        // No stack traces or internal messages ever reach a response body
        // (docs/09_Backend_Architecture.md §10).
        $envelope = fn (string $code, string $message, array $fields = []): array => [
            'error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields],
        ];

        $exceptions->render(function (ApiException $e, Request $request) use ($envelope) {
            return response()->json(
                $envelope($e->errorCode, __($e->getMessage()), $e->fields),
                $e->status,
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return response()->json(
                    $envelope('VALIDATION_FAILED', __('legacyvault.validation.failed'), $e->errors()),
                    422,
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return response()->json($envelope('UNAUTHENTICATED', __('legacyvault.auth.unauthenticated')), 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return response()->json($envelope('RATE_LIMITED', __('legacyvault.errors.rate_limited')), 429);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return response()->json($envelope('FORBIDDEN', __('legacyvault.errors.forbidden')), 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return response()->json($envelope('NOT_FOUND', __('legacyvault.errors.not_found')), 404);
            }
        });
    })->create();
