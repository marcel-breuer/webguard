<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\MonitoringAvailabilityPayload;
use App\Enums\MonitoringStatus;
use App\Models\Monitoring;
use App\Services\MonitoringHealthEvaluator;
use App\Support\MonitoringResponseHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Walks raw check responses and attributes the time between them to the status in effect.
 */
class RawAvailabilityCalculator
{
    public function __construct(
        private readonly MonitoringHealthEvaluator $monitoringHealthEvaluator,
        private readonly AvailabilityPayloadFactory $availabilityPayloadFactory,
        private readonly AvailabilityIncidentCounter $availabilityIncidentCounter,
        private readonly AvailabilityTrackingStart $availabilityTrackingStart,
    ) {}

    public function calculate(
        Monitoring $monitoring,
        Carbon $startDate,
        Carbon $endDate,
        bool $includeIncidentCount = true,
        ?Carbon $trackingStartedAt = null
    ): MonitoringAvailabilityPayload {
        $trackingStartedAt ??= $this->availabilityTrackingStart->fromResponses($monitoring);

        if (! $trackingStartedAt || $trackingStartedAt->gt($endDate)) {
            return $this->availabilityPayloadFactory->empty($startDate, $endDate, $trackingStartedAt);
        }

        $effectiveStartDate = $startDate->copy()->max($trackingStartedAt);
        $effectiveEndDate = $endDate->copy();

        if ($effectiveStartDate->gte($effectiveEndDate)) {
            return $this->availabilityPayloadFactory->empty($startDate, $endDate, $trackingStartedAt);
        }

        $builder = MonitoringResponseHistory::queryForEndDate($endDate)
            ->where('monitoring_id', $monitoring->id);

        $responseAtStart = (clone $builder)
            ->where('created_at', '<=', $effectiveStartDate)->latest()
            ->orderByDesc('id')
            ->first(['id', 'status', 'http_status_code', 'server_health_metrics', 'vital_values', 'created_at']);

        $responses = (clone $builder)
            ->select(['id', 'status', 'http_status_code', 'server_health_metrics', 'vital_values', 'created_at'])
            ->whereBetween('created_at', [$effectiveStartDate, $effectiveEndDate])->oldest()
            ->orderBy('id')
            ->get();

        $overallUptimeMinutes = 0;
        $overallDowntimeMinutes = 0;
        $overallUnknownMinutes = 0;
        $cursor = $effectiveStartDate->copy();
        $currentStatus = $responseAtStart ? $this->resolvedStatus($monitoring, $responseAtStart) : null;

        foreach ($responses as $response) {
            $responseTimestamp = Date::parse($response->created_at);

            if ($responseTimestamp->gt($cursor)) {
                $segmentMinutes = (int) $cursor->diffInMinutes($responseTimestamp);
                $this->incrementMinutesByStatus(
                    $currentStatus,
                    $segmentMinutes,
                    $overallUptimeMinutes,
                    $overallDowntimeMinutes,
                    $overallUnknownMinutes
                );

                $cursor = $responseTimestamp;
            }

            $currentStatus = $this->resolvedStatus($monitoring, $response);
        }

        if ($cursor->lt($effectiveEndDate)) {
            $segmentMinutes = (int) $cursor->diffInMinutes($effectiveEndDate);
            $this->incrementMinutesByStatus(
                $currentStatus,
                $segmentMinutes,
                $overallUptimeMinutes,
                $overallDowntimeMinutes,
                $overallUnknownMinutes
            );
        }

        $uptimeTotal = 0;
        $downtimeTotal = 0;
        $unknownTotal = 0;

        foreach ($responses as $response) {
            match ($this->resolvedStatus($monitoring, $response)) {
                MonitoringStatus::UP->value => $uptimeTotal++,
                MonitoringStatus::DOWN->value => $downtimeTotal++,
                default => $unknownTotal++,
            };
        }

        $incidentsCount = $includeIncidentCount
            ? $this->availabilityIncidentCounter->countOverlapping($monitoring, $effectiveStartDate, $effectiveEndDate)
            : 0;

        return $this->availabilityPayloadFactory->build(
            $startDate,
            $endDate,
            $trackingStartedAt,
            $overallUptimeMinutes,
            $overallDowntimeMinutes,
            $overallUnknownMinutes,
            $uptimeTotal,
            $downtimeTotal,
            $unknownTotal,
            $incidentsCount
        );
    }

    private function incrementMinutesByStatus(
        string|MonitoringStatus|null $status,
        int $minutes,
        int &$uptimeMinutes,
        int &$downtimeMinutes,
        int &$unknownMinutes
    ): void {
        if ($minutes <= 0) {
            return;
        }

        $statusValue = $status instanceof MonitoringStatus
            ? $status->value
            : $status;

        match ($statusValue) {
            MonitoringStatus::UP->value => $uptimeMinutes += $minutes,
            MonitoringStatus::DOWN->value => $downtimeMinutes += $minutes,
            default => $unknownMinutes += $minutes,
        };
    }

    private function resolvedStatus(Monitoring $monitoring, object $response): string
    {
        $status = $response->status ?? null;
        $legacyStatus = $status instanceof MonitoringStatus
            ? $status
            : MonitoringStatus::tryFrom((string) $status);

        return $this->monitoringHealthEvaluator->availabilityFromValues(
            $monitoring,
            $legacyStatus,
            isset($response->http_status_code) ? (int) $response->http_status_code : null,
            isset($response->server_health_metrics) && is_array($response->server_health_metrics) ? $response->server_health_metrics : null,
            isset($response->vital_values) && is_array($response->vital_values) ? $response->vital_values : null,
        )->value;
    }
}
