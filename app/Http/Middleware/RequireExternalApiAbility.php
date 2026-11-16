<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyAbility;
use App\Models\PersonalAccessToken;
use App\Services\ApiKeyService;
use App\Support\Api\ExternalApiSurface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class RequireExternalApiAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if (ApiKeyService::isManagedKey($token)) {
            $route = $request->route();
            abort_unless($route instanceof Route, 403);

            $allowed = $request->isMethodSafe() && $token->can(ApiKeyAbility::ANALYTICS_READ->value) && ExternalApiSurface::isAnalyticsRoute($route);

            if (! $allowed && ExternalApiSurface::allowsManagementKey($route)) {
                $allowed = $token->can(
                    ($request->isMethodSafe() ? ApiKeyAbility::MANAGEMENT_READ : ApiKeyAbility::MANAGEMENT_WRITE)->value
                );
            }

            abort_unless($allowed, 403);

            return $next($request);
        }

        if (! config('external_api.enforce_token_abilities')) {
            return $next($request);
        }

        $ability = $request->isMethodSafe() ? 'external:read' : 'external:write';

        abort_unless($request->user()?->tokenCan($ability), 403);

        return $next($request);
    }
}
