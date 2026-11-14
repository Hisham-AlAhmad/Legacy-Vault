<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vault endpoints require a finalized account. A `pending` registration (Recovery Key not
 * yet acknowledged, docs/02_User_Flows.md Flow 1 edge case) has a session but is purged
 * after 24h — it must not accumulate vault data first.
 */
final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== User::STATUS_ACTIVE) {
            throw new ApiException('ACCOUNT_NOT_ACTIVE', 'legacyvault.auth.account_not_active', 403);
        }

        return $next($request);
    }
}
