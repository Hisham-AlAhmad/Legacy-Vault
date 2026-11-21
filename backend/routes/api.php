<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmergencyContactController;
use App\Http\Controllers\Api\V1\IntegrityEventController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\RecipientController;
use App\Http\Controllers\Api\V1\RecoveryKeyController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TrashController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Http\Controllers\Api\V1\VaultItemController;
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

            // Vault items — docs/08_API_Specification.md. Listed/created/reordered under
            // their recipient; addressed individually by their client-generated UUID.
            Route::get('/recipients/{recipient}/items', [VaultItemController::class, 'index']);
            Route::post('/recipients/{recipient}/items', [VaultItemController::class, 'store']);
            Route::post('/recipients/{recipient}/items/reorder', [VaultItemController::class, 'reorder']);
            Route::get('/vault-items/{vaultItem}', [VaultItemController::class, 'show']);
            Route::patch('/vault-items/{vaultItem}', [VaultItemController::class, 'update']);
            Route::delete('/vault-items/{vaultItem}', [VaultItemController::class, 'destroy']);

            // Attachments — docs/08_API_Specification.md §Attachments. An upload is
            // declared first (which reserves quota and is the rate-limited step), then
            // its encrypted chunks follow in order (docs/19_Decision_Log.md Decision #25).
            Route::get('/vault/storage', [AttachmentController::class, 'storage']);
            Route::get('/vault-items/{vaultItem}/attachments', [AttachmentController::class, 'index']);
            Route::post('/vault-items/{vaultItem}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
            Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);
            Route::post('/attachments/{attachment}/chunks', [AttachmentController::class, 'storeChunk']);
            Route::get('/attachments/{attachment}/content', [AttachmentController::class, 'download']);
            Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

            // Recently deleted — restore inside the undo window, or erase now. Erasing
            // is irreversible, so it is a step-up endpoint (Decision #28).
            Route::get('/trash', [TrashController::class, 'index']);
            Route::post('/trash/recipients/{trashedRecipient}/restore', [TrashController::class, 'restoreRecipient']);
            Route::post('/trash/items/{trashedItem}/restore', [TrashController::class, 'restoreItem']);
            Route::post('/trash/attachments/{trashedAttachment}/restore', [TrashController::class, 'restoreAttachment']);
            Route::delete('/trash/recipients/{trashedRecipient}', [TrashController::class, 'eraseRecipient'])->middleware('throttle:step-up');
            Route::delete('/trash/items/{trashedItem}', [TrashController::class, 'eraseItem'])->middleware('throttle:step-up');
            Route::delete('/trash/attachments/{trashedAttachment}', [TrashController::class, 'eraseAttachment'])->middleware('throttle:step-up');

            // Client-reported integrity failures — IDs and enumerated codes only, bounded
            // by a request limiter, an event budget and dedup (Decision #31).
            Route::post('/vault/integrity-events', [IntegrityEventController::class, 'store'])->middleware('throttle:integrity-reports');

            Route::get('/emergency-contact', [EmergencyContactController::class, 'show']);
            Route::post('/emergency-contact', [EmergencyContactController::class, 'store']);
            Route::delete('/emergency-contact', [EmergencyContactController::class, 'destroy'])->middleware('throttle:step-up');
        });
    });
});
