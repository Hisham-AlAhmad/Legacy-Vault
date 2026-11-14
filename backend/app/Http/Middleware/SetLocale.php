<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale — the authenticated user's stored locale wins, otherwise
 * Accept-Language, falling back to en (docs/09_Backend_Architecture.md §9).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->getPreferredLanguage(['en', 'ar'])
            ?? 'en';

        app()->setLocale($locale);

        return $next($request);
    }
}
