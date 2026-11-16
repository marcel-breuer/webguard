<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Skips the CSRF check for token-authenticated API clients.
 *
 * Browsers cannot attach an `Authorization` header to a cross-site request without a
 * CORS preflight, so a bearer-token request is not forgeable. Cookie-authenticated
 * browser requests keep the full CSRF protection. Server-side dispatches made by the
 * MCP bridge carry an internal marker that a client request can never set.
 */
class PreventRequestForgeryExceptBearer extends PreventRequestForgery
{
    public const INTERNAL_DISPATCH_ATTRIBUTE = 'webguard.internal_dispatch';

    protected function inExceptArray($request): bool
    {
        if ($request->attributes->get(self::INTERNAL_DISPATCH_ATTRIBUTE) === true) {
            return true;
        }

        if ($request->is('api/*') && is_string($request->bearerToken()) && $request->bearerToken() !== '') {
            return true;
        }

        return parent::inExceptArray($request);
    }
}
