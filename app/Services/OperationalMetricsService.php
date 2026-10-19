<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OperationalMetricsService
{
    private const BUCKET_KEY_PREFIX = 'webguard:operational-metrics:';

    /** @var list<string> */
    private const WINDOW_METRICS = [
        'api_requests',
        'api_duration_ms',
        'api_slow_requests',
        'cache_hits',
        'cache_misses',
        'queue_jobs',
        'queue_lag_ms',
        'queue_slow_jobs',
        'scheduler_runs',
        'scheduler_duration_ms',
        'scheduler_slow_runs',
        'slow_queries',
        'slow_query_duration_ms',
    ];

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    public function recordApiRequest(string $route, string $method, ?int $status, float $durationMs): void
    {
        $durationMs = max(0, (int) round($durationMs));
        $metrics = [
            'api_requests' => 1,
            'api_duration_ms' => $durationMs,
        ];

        if ($durationMs >= (int) config('operational_metrics.slow_api_request_ms', 1000)) {
            $metrics['api_slow_requests'] = 1;
            Log::warning('webguard.operational_metrics.slow_api_request', [
                'route' => $route,
                'method' => $method,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);
        }

        $this->incrementMetrics($this->withCacheMetrics($metrics));
    }

    public function recordCacheHit(): void
    {
        $this->cacheHits++;
    }

    public function recordCacheMiss(): void
    {
        $this->cacheMisses++;
    }

    public function flushCacheMetrics(): void
    {
        $this->incrementMetrics($this->withCacheMetrics([]));
    }

    public function recordSlowQuery(string $sql, float $durationMs, string $connection): void
    {
        if ($durationMs < (float) config('operational_metrics.slow_query_ms', 500)) {
            return;
        }

        $durationMs = max(0, (int) round($durationMs));
        $fingerprint = mb_substr(hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', mb_trim($sql)) ?? $sql)), 0, 16);

        $this->incrementMetrics([
            'slow_queries' => 1,
            'slow_query_duration_ms' => $durationMs,
        ]);

        Log::warning('webguard.operational_metrics.slow_query', [
            'connection' => $connection,
            'fingerprint' => $fingerprint,
            'duration_ms' => $durationMs,
        ]);
    }

    public function recordQueueJob(string $queue, float $lagMs): void
    {
        $lagMs = max(0, (int) round($lagMs));
        $metrics = [
            'queue_jobs' => 1,
            'queue_lag_ms' => $lagMs,
        ];

        if ($lagMs >= (int) config('operational_metrics.queue_lag_warning_ms', 60_000)) {
            $metrics['queue_slow_jobs'] = 1;
            Log::warning('webguard.operational_metrics.queue_lag', [
                'queue' => $queue,
                'lag_ms' => $lagMs,
            ]);
        }

        $this->incrementMetrics($this->withCacheMetrics($metrics));
    }

    public function recordScheduledTask(float $durationMs, string $task): void
    {
        $durationMs = max(0, (int) round($durationMs));
        $metrics = [
            'scheduler_runs' => 1,
            'scheduler_duration_ms' => $durationMs,
        ];

        if ($durationMs >= (int) config('operational_metrics.slow_scheduled_task_ms', 5000)) {
            $metrics['scheduler_slow_runs'] = 1;
            Log::warning('webguard.operational_metrics.slow_scheduled_task', [
                'task' => $task,
                'duration_ms' => $durationMs,
            ]);
        }

        $this->incrementMetrics($this->withCacheMetrics($metrics));
    }

    public function reportQueueBacklog(string $queue, int $backlog): void
    {
        $backlog = max(0, $backlog);
        $context = [
            'queue' => $queue,
            'backlog_jobs' => $backlog,
            'warning_threshold' => (int) config('operational_metrics.queue_backlog_warning', 100),
        ];

        Log::info('webguard.operational_metrics.queue_backlog', $context);

        if ($backlog >= $context['warning_threshold']) {
            Log::warning('webguard.operational_metrics.queue_backlog_threshold_exceeded', $context);
        }
    }

    public function flushPreviousMinute(): void
    {
        $windowStart = now()->subMinute()->startOfMinute();
        $key = self::BUCKET_KEY_PREFIX . $windowStart->format('YmdHi');

        try {
            $redis = Redis::connection((string) config('operational_metrics.redis_connection', 'cache'));
            $storedMetrics = $redis->hgetall($key);
            $redis->del($key);
        } catch (Throwable) {
            return;
        }

        $metrics = array_fill_keys(self::WINDOW_METRICS, 0);

        foreach ($storedMetrics as $name => $value) {
            if (array_key_exists((string) $name, $metrics)) {
                $metrics[(string) $name] = (int) $value;
            }
        }

        $metrics['api_average_duration_ms'] = $this->average($metrics['api_duration_ms'], $metrics['api_requests']);
        $metrics['cache_hit_rate'] = $this->cacheHitRate($metrics['cache_hits'], $metrics['cache_misses']);
        $metrics['queue_average_lag_ms'] = $this->average($metrics['queue_lag_ms'], $metrics['queue_jobs']);
        $metrics['scheduler_average_duration_ms'] = $this->average($metrics['scheduler_duration_ms'], $metrics['scheduler_runs']);

        Log::info('webguard.operational_metrics.window', [
            'window_start' => $windowStart->toIso8601String(),
            'window_end' => $windowStart->copy()->addMinute()->toIso8601String(),
            'metrics' => $metrics,
        ]);
    }

    /** @param array<string, int> $metrics */
    private function incrementMetrics(array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $key = self::BUCKET_KEY_PREFIX . now()->format('YmdHi');
        $retentionSeconds = max(60, (int) config('operational_metrics.retention_minutes', 120) * 60);

        try {
            Redis::connection((string) config('operational_metrics.redis_connection', 'cache'))
                ->pipeline(static function ($pipeline) use ($key, $metrics, $retentionSeconds): void {
                    foreach ($metrics as $name => $value) {
                        $pipeline->hIncrBy($key, $name, $value);
                    }

                    $pipeline->expire($key, $retentionSeconds);
                });
        } catch (Throwable) {
            // Metrics are best-effort and must not interrupt application work.
        }
    }

    /** @param array<string, int> $metrics
     * @return array<string, int>
     */
    private function withCacheMetrics(array $metrics): array
    {
        if ($this->cacheHits > 0) {
            $metrics['cache_hits'] = ($metrics['cache_hits'] ?? 0) + $this->cacheHits;
        }

        if ($this->cacheMisses > 0) {
            $metrics['cache_misses'] = ($metrics['cache_misses'] ?? 0) + $this->cacheMisses;
        }

        $this->cacheHits = 0;
        $this->cacheMisses = 0;

        return $metrics;
    }

    private function average(int $total, int $count): ?float
    {
        return $count === 0 ? null : round($total / $count, 1);
    }

    private function cacheHitRate(int $hits, int $misses): ?float
    {
        $requests = $hits + $misses;

        return $requests === 0 ? null : round($hits / $requests, 4);
    }
}
