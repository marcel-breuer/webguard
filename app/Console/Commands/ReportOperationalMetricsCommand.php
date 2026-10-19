<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OperationalMetricsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

#[Description('Report recent application and queue operational metrics')]
#[Signature('webguard:report-operational-metrics')]
final class ReportOperationalMetricsCommand extends Command
{
    public function handle(OperationalMetricsService $operationalMetricsService): int
    {
        $queues = array_unique([
            (string) config('queue.connections.' . config('queue.default') . '.queue', 'default'),
            (string) config('monitoring.heartbeat_queue', 'heartbeat'),
        ]);
        $queue = Queue::connection((string) config('queue.default'));

        foreach ($queues as $queueName) {
            try {
                $backlog = (int) $queue->size($queueName);
            } catch (Throwable $exception) {
                Log::warning('webguard.operational_metrics.queue_backlog_unavailable', [
                    'queue' => $queueName,
                    'exception' => $exception::class,
                ]);

                continue;
            }

            $operationalMetricsService->reportQueueBacklog($queueName, $backlog);
        }

        $operationalMetricsService->flushPreviousMinute();

        return self::SUCCESS;
    }
}
