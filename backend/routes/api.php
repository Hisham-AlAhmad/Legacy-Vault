<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmergencyContactController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\RecipientController;
use App\Http\Controllers\Api\V1\RecoveryKeyController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\VaultController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 — docs/08_API_Specification.md. Base path /api/v1, Sanctum cookie sessions,
 * CSRF on all state-changing requests, rate limits per docs/05_Security_Architecture.md §7.
 */
Route::prefix('v1')->group(function (): void {
    // Public
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password-forgot');
    Route::post('/auth/password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password-forgot');

    // Authenticated (Sanctum SPA cookie session)
    Route::middleware(['auth:sanctum', 'throttle:api-user'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::post('/auth/password/change', [PasswordController::class, 'change']);

        Route::post('/auth/2fa/enable', [TwoFactorController::class, 'enable']);
        Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable']);

        Route::get('/auth/sessions', [SessionController::class, 'index']);
        Route::delete('/auth/sessions/{idHash}', [SessionController::class, 'destroy']);
        Route::get('/auth/login-history', [SessionController::class, 'loginHistory']);

        Route::post('/vault/recovery-key/acknowledge', [RecoveryKeyController::class, 'acknowledge']);
        Route::post('/vault/recovery-key/verify', [RecoveryKeyController::class, 'verify']);
        Route::post('/vault/recovery-key/rotate', [RecoveryKeyController::class, 'rotate']);

        // Vault — finalized (active) accounts only. Step-up endpoints (password in the
        // body) share a tighter limiter so a stolen session can't guess the password
        // through them (docs/05_Security_Architecture.md §3, §12).
        Route::middleware('account.active')->group(function (): void {
            Route::post('/vault/unlock', [VaultController::class, 'unlock'])->middleware('throttle:step-up');

            Route::get('/recipients', [RecipientController::class, 'index']);
            Route::post('/recipients', [RecipientController::class, 'store']);
            Route::patch('/recipients/{recipient}', [RecipientController::class, 'update']);
            Route::delete('/recipients/{recipient}', [RecipientController::class, 'destroy'])->middleware('throttle:step-up');

            Route::get('/emergency-contact', [EmergencyContactController::class, 'show']);
            Route::post('/emergency-contact', [EmergencyContactController::class, 'store']);
            Route::delete('/emergency-contact', [EmergencyContactController::class, 'destroy'])->middleware('throttle:step-up');
        });
    });
});
