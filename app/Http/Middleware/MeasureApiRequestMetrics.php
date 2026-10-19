<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OperationalMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MeasureApiRequestMetrics
{
    public function __construct(private readonly OperationalMetricsService $operationalMetricsService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            try {
                return $next($request);
            } finally {
                $this->operationalMetricsService->flushCacheMetrics();
            }
        }

        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->operationalMetricsService->recordApiRequest(
                $this->routeName($request),
                $request->method(),
                null,
                $this->durationSince($startedAt),
            );

            throw $exception;
        }

        $this->operationalMetricsService->recordApiRequest(
            $this->routeName($request),
            $request->method(),
            $response->getStatusCode(),
            $this->durationSince($startedAt),
        );

        return $response;
    }

    private function routeName(Request $request): string
    {
        return $request->route()?->getName() ?? 'unmatched';
    }

    private function durationSince(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
