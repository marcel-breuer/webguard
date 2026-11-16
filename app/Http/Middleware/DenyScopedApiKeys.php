<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps named, scoped API keys away from route families that have their own token type.
 */
class DenyScopedApiKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        abort_if($token instanceof PersonalAccessToken && ApiKeyService::isManagedKey($token), 403);

        return $next($request);
    }
}
