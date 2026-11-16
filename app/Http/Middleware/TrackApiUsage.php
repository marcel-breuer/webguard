<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\LogApiUsage;
use App\Support\Http\RequestCorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class TrackApiUsage
{
    private const DECAY_SECONDS = 60;

    /**
     * Middleware to validate API access for internal and external clients.
     *
     * This middleware permits requests if they are authenticated via a valid Sanctum token.
     * For authenticated external requests, it dispatches a job to log API usage.
     * Unauthorized requests receive a 403 response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestCorrelationId::for($request);
        $request->attributes->set('request_id', $requestId);

        if (auth('sanctum')->check()) {
            $user = auth('sanctum')->user();

            if ($user) {
                $accessToken = $user->currentAccessToken();
                if (! $accessToken instanceof PersonalAccessToken || $this->isMobileAppToken($accessToken)) {
                    return $this->withRequestId($next($request), $requestId);
                }

                $key = 'api:' . $user->getAuthIdentifier();
                $maxAttempts = max(1, (int) config('external_api.rate_limit_per_minute'));

                if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                    return $this->withRequestId(response()->json([
                        'message' => 'Too many requests.',
                    ], 429)
                        ->header('Retry-After', (string) RateLimiter::availableIn($key))
                        ->header('X-RateLimit-Limit', (string) $maxAttempts)
                        ->header('X-RateLimit-Remaining', '0')
                        ->header('X-RateLimit-Reset', (string) now()->addSeconds(RateLimiter::availableIn($key))->getTimestamp()), $requestId);
                }

                RateLimiter::hit($key, self::DECAY_SECONDS);

                dispatch(new LogApiUsage((string) $user->getAuthIdentifier(), url()->current()));

                $response = $next($request);
                $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
                $response->headers->set(
                    'X-RateLimit-Remaining',
                    (string) max(0, $maxAttempts - RateLimiter::attempts($key))
                );
                $response->headers->set(
                    'X-RateLimit-Reset',
                    (string) now()->addSeconds(RateLimiter::availableIn($key))->getTimestamp()
                );

                return $this->withRequestId($response, $requestId);
            }
        }

        abort(403);
    }

    private function withRequestId(Response $response, string $requestId): Response
    {
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function isMobileAppToken(mixed $accessToken): bool
    {
        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        return Str::startsWith($accessToken->name, ['ios-app:', 'android-app:']);
    }
}
