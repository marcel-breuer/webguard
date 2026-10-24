<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MonitoringStatus;
use App\Enums\MonitoringType;
use App\Enums\RegionalConsensusStatus;
use App\Models\Monitoring;
use Illuminate\Support\Facades\Date;

class MonitoringStatusService
{
    public function __construct(
        private readonly RegionalConsensusService $regionalConsensusService,
        private readonly MonitoringHealthEvaluator $monitoringHealthEvaluator,
        private readonly MonitoringCheckIntervalService $monitoringCheckIntervalService
    ) {}

    /**
     * @return array{status: string, since: string|null}
     */
    public function getStatusSince(Monitoring $monitoring): array
    {
        $latest = $monitoring->latestIncident;

        if (count($monitoring->preferredLocationCodes()) > 1 && ! $latest) {
            $snapshot = $this->regionalConsensusService->snapshot($monitoring);

            return [
                'status' => $this->monitoringStatus($snapshot['status']),
                'since' => $monitoring->latestResponseResult?->created_at->toIso8601String(),
            ];
        }

        if (! $latest) {
            return [
                'status' => $monitoring->latestResponseResult ? $this->monitoringHealthEvaluator->availabilityFor($monitoring, $monitoring->latestResponseResult)->value : MonitoringStatus::UNKNOWN->value,
                'since' => $monitoring->latestResponseResult ? $monitoring->created_at->toIso8601String() : null,
            ];
        }

        if ($latest->up_at) {
            return [
                'status' => MonitoringStatus::UP->value,
                'since' => $latest->up_at->toIso8601String(),
            ];
        }

        return [
            'status' => MonitoringStatus::DOWN->value,
            'since' => $latest->down_at->toIso8601String(),
        ];
    }

    /**
     * @return array{status: MonitoringStatus|string, checked_at: string|null, next: string, interval: int}
     */
    public function getStatusNow(Monitoring $monitoring, ?int $cronjobInterval = null): array
    {
        if ($monitoring->isHeartbeat()) {
            $heartbeatInterval = ((int) ($monitoring->heartbeat_interval_minutes ?? 0)) * 60;
            $referenceTimestamp = $monitoring->heartbeat_last_ping_at ?? $monitoring->created_at;
            $latest = $monitoring->latestResponseResult;

            return [
                'status' => $latest ? $this->monitoringHealthEvaluator->availabilityFor($monitoring, $latest) : MonitoringStatus::UNKNOWN->value,
                'checked_at' => $monitoring->heartbeat_last_ping_at?->toIso8601String(),
                'next' => $referenceTimestamp->copy()->addSeconds($heartbeatInterval)->toIso8601String(),
                'interval' => $heartbeatInterval,
            ];
        }

        if ($monitoring->isServerHealth()) {
            $reportInterval = ((int) ($monitoring->server_health_report_interval_minutes ?? 1)) * 60;
            $referenceTimestamp = $monitoring->server_health_last_reported_at ?? $monitoring->created_at;
            $latest = $monitoring->latestResponseResult;

            return [
                'status' => $latest ? $this->monitoringHealthEvaluator->availabilityFor($monitoring, $latest) : MonitoringStatus::UNKNOWN->value,
                'checked_at' => $monitoring->server_health_last_reported_at?->toIso8601String(),
                'next' => $referenceTimestamp->copy()->addSeconds($reportInterval)->toIso8601String(),
                'interval' => $reportInterval,
            ];
        }

        $cronjobInterval ??= $this->monitoringCheckIntervalService->secondsFor($monitoring);
        $latest = $monitoring->latestResponseResult;
        $initialWebsiteCheck = $latest === null
            && in_array($monitoring->type, [MonitoringType::HTTP, MonitoringType::KEYWORD], true);

        if (count($monitoring->preferredLocationCodes()) > 1) {
            $snapshot = $this->regionalConsensusService->snapshot($monitoring);

            return [
                'status' => $this->monitoringStatus($snapshot['status']),
                'checked_at' => $latest?->updated_at->toIso8601String(),
                'next' => $latest
                    ? $latest->updated_at->addSeconds($cronjobInterval)->toIso8601String()
                    : Date::now()->addSeconds($initialWebsiteCheck ? MonitoringCheckIntervalService::INSTANCE_POLL_INTERVAL_SECONDS : $cronjobInterval)->toIso8601String(),
                'interval' => $cronjobInterval,
            ];
        }

        return [
            'status' => $latest ? $this->monitoringHealthEvaluator->availabilityFor($monitoring, $latest) : MonitoringStatus::UNKNOWN->value,
            'checked_at' => $latest ? $latest->updated_at->toIso8601String() : null,
            'next' => $latest
                ? $latest->updated_at->addSeconds($cronjobInterval)->toIso8601String()
                : Date::now()->addSeconds($initialWebsiteCheck ? MonitoringCheckIntervalService::INSTANCE_POLL_INTERVAL_SECONDS : $cronjobInterval)->toIso8601String(),
            'interval' => $cronjobInterval,
        ];
    }

    private function monitoringStatus(RegionalConsensusStatus $regionalConsensusStatus): MonitoringStatus
    {
        return match ($regionalConsensusStatus) {
            RegionalConsensusStatus::HEALTHY => MonitoringStatus::UP,
            RegionalConsensusStatus::REGIONAL, RegionalConsensusStatus::GLOBAL => MonitoringStatus::DOWN,
            default => MonitoringStatus::UNKNOWN,
        };
    }
}
