<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyAbility;
use App\Models\PersonalAccessToken;
use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireMcpReadAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        abort_unless(
            ! Str::startsWith($token->name, ['ios-app:', 'android-app:']),
            403
        );

        if (ApiKeyService::isManagedKey($token)) {
            abort_unless($token->can(ApiKeyAbility::ANALYTICS_READ->value), 403);

            return $next($request);
        }

        if (config('external_api.enforce_token_abilities')) {
            abort_unless($token->can('external:read'), 403);
        }

        return $next($request);
    }
}
