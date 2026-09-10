<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use App\Models\User;
use App\Queries\MonitoringDetailQuery;
use App\Services\MobileMonitoringDetailPayloadService;
use App\Services\MonitoringAvailabilityService;
use App\Services\MonitoringCheckHistoryService;
use App\Services\MonitoringResponseTimeService;
use App\Services\MonitoringServerHealthTelemetryService;
use App\Services\MonitoringUptimeCalendarService;
use App\Support\MonitoringDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MonitoringDetailDataController extends Controller
{
    private const DAYS = 30;

    private const DEFAULT_RESPONSE_TIME_DAYS = 1;

    private const RECENT_CHECKS_LIMIT = 5;

    private const MAX_RECENT_CHECKS_OFFSET = 1_000;

    private const INCIDENT_LIMIT = 5;

    private const MAX_INCIDENT_OFFSET = 1_000;

    public function __invoke(
        Request $request,
        string $monitoring,
        MonitoringDetailQuery $monitoringDetailQuery,
        MobileMonitoringDetailPayloadService $mobileMonitoringDetailPayloadService,
        MonitoringAvailabilityService $monitoringAvailabilityService,
        MonitoringCheckHistoryService $monitoringCheckHistoryService,
        MonitoringResponseTimeService $monitoringResponseTimeService,
        MonitoringServerHealthTelemetryService $monitoringServerHealthTelemetryService,
        MonitoringUptimeCalendarService $monitoringUptimeCalendarService,
    ): JsonResponse {
        $validated = $request->validate([
            'checks_offset' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_RECENT_CHECKS_OFFSET],
            'incident_offset' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_INCIDENT_OFFSET],
            'response_time_days' => ['nullable', 'integer', 'in:1,7,30'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $checksOffset = (int) ($validated['checks_offset'] ?? 0);
        $incidentOffset = (int) ($validated['incident_offset'] ?? 0);
        $responseTimeDays = (int) ($validated['response_time_days'] ?? self::DEFAULT_RESPONSE_TIME_DAYS);
        $monitoring = $monitoringDetailQuery->findVisible($user, $monitoring);
        $monitoringDateRange = MonitoringDateRange::pastDays(self::DAYS);
        $payload = $mobileMonitoringDetailPayloadService->for(
            $monitoring,
            $user,
            self::DAYS,
            self::INCIDENT_LIMIT,
            $incidentOffset,
            includeCurrentYearCalendar: true,
        );
        $payload['meta']['uptime_calendar'] = [
            'oldest_available_month' => $monitoringUptimeCalendarService->oldestRecordedMonth($monitoring),
        ];
        $recentChecks = $monitoringCheckHistoryService->getHistory(
            $monitoring,
            $monitoringDateRange->startDate,
            $monitoringDateRange->endDate,
            self::RECENT_CHECKS_LIMIT,
            $checksOffset,
        );

        $payload['data']['summary']['public_status_enabled'] = $monitoring->public_label_enabled;
        $payload['data']['summary']['ownership']['name'] = $monitoring->isTeamOwned()
            ? $monitoring->team?->name
            : $monitoring->user?->name;
        $payload['data']['summary']['groups'] = $monitoring->groups->map(static fn ($group): array => [
            'id' => $group->id,
            'name' => $group->name,
        ])->values()->all();
        $payload['data']['summary']['check_regions'] = $monitoring->preferredLocationCodes();
        $payload['data']['summary']['notification_channels'] = array_values($monitoring->notification_channels ?? []);
        $statusPages = $monitoring->statusPageComponents
            ->map(static fn ($component) => $component->statusPage)
            ->filter();

        if ($monitoring->groups->isNotEmpty()) {
            $statusPages = $statusPages->merge(
                $user->statusPages()
                    ->whereHas('components', fn (Builder $builder): Builder => $builder->whereIn(
                        'monitoring_group_id',
                        $monitoring->groups->modelKeys(),
                    ))
                    ->get(['id', 'name'])
            );
        }

        $payload['data']['summary']['status_pages'] = $statusPages
            ->unique('id')
            ->map(static fn (StatusPage $statusPage): array => [
                'id' => $statusPage->id,
                'name' => $statusPage->name,
            ])->values()->all();
        $payload['data']['recent_checks'] = $recentChecks['data'];
        if ($responseTimeDays !== self::DAYS) {
            $responseTimeRange = MonitoringDateRange::pastDays($responseTimeDays);
            $payload['data']['response_times'] = $monitoringResponseTimeService->getResponseTimes(
                $monitoring,
                $responseTimeRange->startDate,
                $responseTimeRange->endDate,
                $responseTimeRange->shouldUseResponseTimeAggregates(),
            )->toArray();
        }
        $payload['data']['availability_periods'] = collect([7, 30, 90])->mapWithKeys(
            function (int $days) use ($monitoring, $monitoringAvailabilityService, $payload): array {
                if ($days === self::DAYS) {
                    return [(string) $days => $payload['data']['availability']];
                }

                $monitoringDateRange = MonitoringDateRange::pastDays($days);

                return [(string) $days => $monitoringAvailabilityService->getUptimeDowntime(
                    $monitoring,
                    $monitoringDateRange->startDate,
                    $monitoringDateRange->endDate,
                    $monitoringDateRange->shouldUseUptimeAggregates($monitoring),
                    $monitoringDateRange->shouldIncludeIntradayRawData(),
                )->toArray()];
            }
        )->all();
        $payload['meta']['recent_checks'] = [
            'limit' => self::RECENT_CHECKS_LIMIT,
            'has_more' => $recentChecks['has_more'],
            'next_offset' => $recentChecks['next_offset'],
        ];
        $payload['meta']['response_times'] = [
            'days' => $responseTimeDays,
        ];
        $payload['data']['server_health_telemetry'] = $monitoring->isServerHealth()
            ? $monitoringServerHealthTelemetryService->getTelemetry($monitoring, $monitoringDateRange->startDate, $monitoringDateRange->endDate)
            : null;

        return response()->json($payload);
    }
}
