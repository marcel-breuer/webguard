<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MonitoringPerformanceStatus;
use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\NotificationType;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\MonitoringPerformanceLocationState;
use App\Models\MonitoringPerformanceState;
use App\Models\MonitoringResponse;

final class MonitoringPerformanceService
{
    public function __construct(private readonly MonitoringResponseTimeBaselineService $baselineService) {}

    public function reconcile(Monitoring $monitoring, MonitoringResponse $monitoringResponse, MonitoringStatus $monitoringStatus): void
    {
        $historicalTransition = $this->reconcileHistoricalResponseTime($monitoring, $monitoringResponse, $monitoringStatus);

        $responseTimeThreshold = $this->responseTimeThresholdFor($monitoring);

        if ($responseTimeThreshold === null) {
            return;
        }

        $monitoringPerformanceState = MonitoringPerformanceState::query()->firstOrCreate(
            ['monitoring_id' => $monitoring->id],
            ['status' => MonitoringPerformanceStatus::NORMAL, 'consecutive_breaches' => 0],
        );

        $responseTime = $this->observedResponseTime($monitoring, $monitoringResponse);

        if ($monitoringStatus !== MonitoringStatus::UP || $responseTime === null) {
            return;
        }

        $isSlow = $responseTime >= $responseTimeThreshold;
        $requiredBreaches = max(1, (int) ($monitoring->response_time_confirmation_threshold ?? 2));

        if ($isSlow) {
            $breaches = $monitoringPerformanceState->consecutive_breaches + 1;
            $monitoringPerformanceState->update(['consecutive_breaches' => $breaches]);

            if ($monitoringPerformanceState->status !== MonitoringPerformanceStatus::DEGRADED && $breaches >= $requiredBreaches) {
                $monitoringPerformanceState->update([
                    'status' => MonitoringPerformanceStatus::DEGRADED,
                    'degraded_at' => now(),
                    'recovered_at' => null,
                ]);
                if ($historicalTransition !== 'DEGRADED') {
                    $this->recordNotification($monitoring, 'DEGRADED');
                }
            }

            return;
        }

        $wasDegraded = $monitoringPerformanceState->status === MonitoringPerformanceStatus::DEGRADED;
        $monitoringPerformanceState->update([
            'status' => MonitoringPerformanceStatus::NORMAL,
            'consecutive_breaches' => 0,
            'recovered_at' => $wasDegraded ? now() : $monitoringPerformanceState->recovered_at,
        ]);

        if ($wasDegraded) {
            if ($historicalTransition !== 'RECOVERED') {
                $this->recordNotification($monitoring, 'RECOVERED');
            }
        }
    }

    /**
     * @param  array<string, int|float|string>|null  $metadata
     */
    private function recordNotification(Monitoring $monitoring, string $message, ?array $metadata = null): void
    {
        MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::PERFORMANCE,
            'message' => $message,
            'metadata' => $metadata,
            'read' => false,
            'sent' => false,
        ]);
    }

    private function reconcileHistoricalResponseTime(
        Monitoring $monitoring,
        MonitoringResponse $monitoringResponse,
        MonitoringStatus $monitoringStatus
    ): ?string {
        $thresholdPercent = $monitoring->response_time_degradation_percent;

        if ($thresholdPercent === null || ! in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true)) {
            return null;
        }

        $locationCode = $monitoringResponse->location_code ?? 'default';
        $state = MonitoringPerformanceLocationState::query()->firstOrCreate(
            ['monitoring_id' => $monitoring->id, 'location_code' => $locationCode],
            ['status' => MonitoringPerformanceStatus::NORMAL->value, 'consecutive_breaches' => 0],
        );

        $responseTime = $monitoringResponse->response_time;

        if ($monitoringStatus !== MonitoringStatus::UP || $responseTime === null) {
            if ($state->status !== MonitoringPerformanceStatus::DEGRADED->value && $state->consecutive_breaches !== 0) {
                $state->update(['consecutive_breaches' => 0]);
            }

            return null;
        }

        $baseline = $this->baselineService->for($monitoring, $monitoringResponse);

        if ($baseline === null || $baseline['median_ms'] <= 0) {
            if ($state->status !== MonitoringPerformanceStatus::DEGRADED->value && $state->consecutive_breaches !== 0) {
                $state->update(['consecutive_breaches' => 0]);
            }

            return null;
        }

        $isSlow = $responseTime >= $baseline['median_ms'] * (1 + ((int) $thresholdPercent / 100));
        $requiredBreaches = max(1, (int) ($monitoring->response_time_confirmation_threshold ?? 2));
        $metadata = [
            'alert_kind' => 'historical_response_time',
            'location_code' => $locationCode,
            'response_time_ms' => (int) round($responseTime),
            'baseline_median_ms' => (int) round($baseline['median_ms']),
            'increase_percent' => (int) round((($responseTime / $baseline['median_ms']) - 1) * 100),
            'threshold_percent' => (int) $thresholdPercent,
            'sample_count' => $baseline['sample_count'],
        ];

        if ($isSlow) {
            if ($state->status === MonitoringPerformanceStatus::DEGRADED->value) {
                return null;
            }

            $breaches = $state->consecutive_breaches + 1;
            $state->update(['consecutive_breaches' => $breaches]);

            if ($breaches >= $requiredBreaches) {
                $state->update([
                    'status' => MonitoringPerformanceStatus::DEGRADED->value,
                    'degraded_at' => now(),
                    'recovered_at' => null,
                ]);
                $this->recordNotification($monitoring, 'DEGRADED', $metadata);

                return 'DEGRADED';
            }

            return null;
        }

        $wasDegraded = $state->status === MonitoringPerformanceStatus::DEGRADED->value;
        $state->update([
            'status' => MonitoringPerformanceStatus::NORMAL->value,
            'consecutive_breaches' => 0,
            'recovered_at' => $wasDegraded ? now() : $state->recovered_at,
        ]);

        if ($wasDegraded) {
            $this->recordNotification($monitoring, 'RECOVERED', $metadata);

            return 'RECOVERED';
        }

        return null;
    }

    private function responseTimeThresholdFor(Monitoring $monitoring): ?int
    {
        return match ($monitoring->type) {
            MonitoringType::HTTP, MonitoringType::KEYWORD => $monitoring->response_time_threshold_ms,
            MonitoringType::SERVER_HEALTH => $monitoring->server_health_service_response_time_threshold_ms,
            default => null,
        };
    }

    private function observedResponseTime(Monitoring $monitoring, MonitoringResponse $monitoringResponse): ?float
    {
        if ($monitoring->type !== MonitoringType::SERVER_HEALTH) {
            return $monitoringResponse->response_time;
        }

        $serviceChecks = $monitoringResponse->server_health_metrics['service_checks'] ?? [];

        if (! is_array($serviceChecks)) {
            return null;
        }

        $responseTimes = collect($serviceChecks)
            ->filter(static fn (mixed $serviceCheck): bool => is_array($serviceCheck) && ($serviceCheck['success'] ?? false) === true)
            ->pluck('response_time_ms')
            ->filter(static fn (mixed $responseTime): bool => is_numeric($responseTime));

        return $responseTimes->isEmpty() ? null : (float) $responseTimes->max();
    }
}
