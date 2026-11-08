<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\MonitoringAvailabilityPayload;
use App\Enums\MonitoringStatus;
use App\Models\Incident;
use App\Models\Monitoring;
use App\Models\MonitoringDailyResult;
use App\Models\MonitoringResponse;
use App\Models\MonitoringResponseArchived;
use App\Models\Package;
use App\Models\User;
use App\Services\MonitoringAvailabilityService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringAvailabilityServiceCharacterizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_availability_results_stay_stable_across_calculation_paths(): void
    {
        Date::setTestNow('2026-04-12 12:00:00');
        Package::factory()->create();
        $user = User::factory()->create();
        $service = resolve(MonitoringAvailabilityService::class);
        $now = Date::now();

        $monitoring = Monitoring::factory()->for($user)->create(['created_at' => $now->copy()->subDays(40)]);
        $empty = Monitoring::factory()->for($user)->create(['created_at' => $now->copy()->subDays(40)]);
        $young = Monitoring::factory()->for($user)->create(['created_at' => $now->copy()->subHours(5)]);

        foreach (range(2, 20) as $daysAgo) {
            $this->dailyResult($monitoring, $now->copy()->subDays($daysAgo)->toDateString(), $daysAgo);
        }

        $this->archivedResponse($monitoring, MonitoringStatus::UP, $now->copy()->subDays(21)->toDateTimeString());
        $this->response($monitoring, MonitoringStatus::UP, '2026-04-11 20:00:00');
        $this->response($monitoring, MonitoringStatus::DOWN, '2026-04-12 02:00:00');
        $this->response($monitoring, MonitoringStatus::UP, '2026-04-12 04:30:00');
        $this->response($monitoring, MonitoringStatus::UP, '2026-04-12 06:00:00', httpStatus: 500);
        $this->response($monitoring, MonitoringStatus::UP, '2026-04-12 07:00:00', httpStatus: 200);

        Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => '2026-04-12 02:00:00', 'up_at' => '2026-04-12 04:30:00']);
        Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => '2026-04-05 02:00:00', 'up_at' => '2026-04-05 03:00:00']);
        Incident::query()->create(['monitoring_id' => $monitoring->id, 'down_at' => '2026-03-01 02:00:00', 'up_at' => null]);

        $this->response($young, MonitoringStatus::UP, '2026-04-12 08:00:00');
        $this->response($young, MonitoringStatus::DOWN, '2026-04-12 10:00:00');

        $results = [
            'start after end' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->addDay(), $now->copy()->subDay())),
            'future end is capped' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subHours(6), $now->copy()->addDays(3))),
            'raw today' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->startOfDay(), $now)),
            'raw explicit tracking start' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subDay(), $now, trackingStartedAt: $now->copy()->subHours(10))),
            'raw tracking after end' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subDays(50), $now->copy()->subDays(45))),
            'raw without data' => $this->summary($service->getUptimeDowntime($empty, $now->copy()->subDay(), $now)),
            'aggregated with intraday' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subDays(10)->startOfDay(), $now, true, true)),
            'aggregated without intraday' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subDays(10)->startOfDay(), $now, true, false)),
            'aggregated without data' => $this->summary($service->getUptimeDowntime($empty, $now->copy()->subDays(10)->startOfDay(), $now, true)),
            'aggregated historic only' => $this->summary($service->getUptimeDowntime($monitoring, $now->copy()->subDays(15)->startOfDay(), $now->copy()->subDays(12)->endOfDay(), true, true)),
            'ranges' => array_map($this->summary(...), $service->getUptimeDowntimesForRanges($monitoring, [30, 7, 7, 0, -1, 1])),
            'ranges rolling' => array_map($this->summary(...), $service->getUptimeDowntimesForRanges($monitoring, [30, 7, 14])),
            'ranges rolling without intraday' => array_map($this->summary(...), $service->getUptimeDowntimesForRanges($monitoring, [30, 7], false)),
            'ranges young monitoring' => array_map($this->summary(...), $service->getUptimeDowntimesForRanges($young, [7, 30])),
            'ranges without tracking' => array_map($this->summary(...), $service->getUptimeDowntimesForRanges($empty, [7, 30])),
            'ranges empty' => $service->getUptimeDowntimesForRanges($monitoring, []),
            'count incidents' => $service->countIncidents($monitoring, $now->copy()->subDays(10), $now),
            'aggregated incidents' => $service->getAggregatedIncidentsCount($monitoring, $now->copy()->subDays(10), $now),
            'aggregated incidents without intraday' => $service->getAggregatedIncidentsCount($monitoring, $now->copy()->subDays(10), $now, false),
            'aggregated incidents start after end' => $service->getAggregatedIncidentsCount($monitoring, $now->copy()->addDay(), $now->copy()->subDay()),
        ];

        $this->assertSame(self::expected(), $results);
    }

    /**
     * @return array<string, mixed>
     */
    private static function expected(): array
    {
        return [
            'start after end' => [
                'from' => '2026-04-13 12:00:00',
                'to' => '2026-04-11 12:00:00',
                'has_data' => false,
                'tracking_started_at' => null,
                'uptime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
                'downtime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                    3 => 0,
                ],
                'unknown' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
            ],
            'future end is capped' => [
                'from' => '2026-04-12 06:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => true,
                'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                'uptime' => [
                    0 => 300,
                    1 => 83.33333333333334,
                    2 => 1,
                ],
                'downtime' => [
                    0 => 60,
                    1 => 16.666666666666664,
                    2 => 1,
                    3 => 1,
                ],
                'unknown' => [
                    0 => 0,
                    1 => 0.0,
                    2 => 0,
                ],
            ],
            'raw today' => [
                'from' => '2026-04-12 00:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => true,
                'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                'uptime' => [
                    0 => 510,
                    1 => 70.83333333333334,
                    2 => 2,
                ],
                'downtime' => [
                    0 => 210,
                    1 => 29.166666666666668,
                    2 => 2,
                    3 => 2,
                ],
                'unknown' => [
                    0 => 0,
                    1 => 0.0,
                    2 => 0,
                ],
            ],
            'raw explicit tracking start' => [
                'from' => '2026-04-11 12:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => true,
                'tracking_started_at' => '2026-04-12T02:00:00+02:00',
                'uptime' => [
                    0 => 390,
                    1 => 65.0,
                    2 => 2,
                ],
                'downtime' => [
                    0 => 210,
                    1 => 35.0,
                    2 => 2,
                    3 => 2,
                ],
                'unknown' => [
                    0 => 0,
                    1 => 0.0,
                    2 => 0,
                ],
            ],
            'raw tracking after end' => [
                'from' => '2026-02-21 12:00:00',
                'to' => '2026-02-26 12:00:00',
                'has_data' => false,
                'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                'uptime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
                'downtime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                    3 => 0,
                ],
                'unknown' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
            ],
            'raw without data' => [
                'from' => '2026-04-11 12:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => false,
                'tracking_started_at' => null,
                'uptime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
                'downtime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                    3 => 0,
                ],
                'unknown' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
            ],
            'aggregated with intraday' => [
                'from' => '2026-04-02 00:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => true,
                'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                'uptime' => [
                    0 => 9564,
                    1 => 96.28511023859862,
                    2 => 146,
                ],
                'downtime' => [
                    0 => 350,
                    1 => 3.5236081747709656,
                    2 => 11,
                    3 => 3,
                ],
                'unknown' => [
                    0 => 19,
                    1 => 0.19128158663042383,
                    2 => 4,
                ],
            ],
            'aggregated without intraday' => [
                'from' => '2026-04-02 00:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => true,
                'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                'uptime' => [
                    0 => 9054,
                    1 => 98.27417779225009,
                    2 => 144,
                ],
                'downtime' => [
                    0 => 140,
                    1 => 1.519591881037664,
                    2 => 9,
                    3 => 3,
                ],
                'unknown' => [
                    0 => 19,
                    1 => 0.20623032671225444,
                    2 => 4,
                ],
            ],
            'aggregated without data' => [
                'from' => '2026-04-02 00:00:00',
                'to' => '2026-04-12 12:00:00',
                'has_data' => false,
                'tracking_started_at' => null,
                'uptime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
                'downtime' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                    3 => 0,
                ],
                'unknown' => [
                    0 => 0,
                    1 => null,
                    2 => 0,
                ],
            ],
            'aggregated historic only' => [
                'from' => '2026-03-28 00:00:00',
                'to' => '2026-03-31 23:59:59',
                'has_data' => true,
                'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                'uptime' => [
                    0 => 3042,
                    1 => 97.84496622708267,
                    2 => 72,
                ],
                'downtime' => [
                    0 => 60,
                    1 => 1.9298809906722418,
                    2 => 3,
                    3 => 1,
                ],
                'unknown' => [
                    0 => 7,
                    1 => 0.2251527822450949,
                    2 => 2,
                ],
            ],
            'ranges' => [
                1 => [
                    'from' => '2026-04-11 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-22T12:00:00+01:00',
                    'uptime' => [
                        0 => 750,
                        1 => 34.72222222222222,
                        2 => 3,
                    ],
                    'downtime' => [
                        0 => 210,
                        1 => 9.722222222222223,
                        2 => 2,
                        3 => 2,
                    ],
                    'unknown' => [
                        0 => 1200,
                        1 => 55.55555555555556,
                        2 => 0,
                    ],
                ],
                7 => [
                    'from' => '2026-04-05 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 6027,
                        1 => 98.01593755082128,
                        2 => 87,
                    ],
                    'downtime' => [
                        0 => 110,
                        1 => 1.7889087656529516,
                        2 => 6,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 12,
                        1 => 0.19515368352577656,
                        2 => 3,
                    ],
                ],
                30 => [
                    'from' => '2026-03-13 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 19209,
                        1 => 98.3161019551643,
                        2 => 399,
                    ],
                    'downtime' => [
                        0 => 290,
                        1 => 1.484287030402293,
                        2 => 20,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 39,
                        1 => 0.1996110144334118,
                        2 => 9,
                    ],
                ],
            ],
            'ranges rolling' => [
                7 => [
                    'from' => '2026-04-05 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 6537,
                        1 => 95.16669093026641,
                        2 => 89,
                    ],
                    'downtime' => [
                        0 => 320,
                        1 => 4.658611151550444,
                        2 => 8,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 12,
                        1 => 0.17469791818314165,
                        2 => 3,
                    ],
                ],
                14 => [
                    'from' => '2026-03-29 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 13614,
                        1 => 96.87611186223583,
                        2 => 236,
                    ],
                    'downtime' => [
                        0 => 410,
                        1 => 2.917526506795702,
                        2 => 16,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 29,
                        1 => 0.20636163096847648,
                        2 => 6,
                    ],
                ],
                30 => [
                    'from' => '2026-03-13 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 19719,
                        1 => 97.33932273669662,
                        2 => 401,
                    ],
                    'downtime' => [
                        0 => 500,
                        1 => 2.4681607266265178,
                        2 => 22,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 39,
                        1 => 0.19251653667686838,
                        2 => 9,
                    ],
                ],
            ],
            'ranges rolling without intraday' => [
                7 => [
                    'from' => '2026-04-05 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 6027,
                        1 => 98.01593755082128,
                        2 => 87,
                    ],
                    'downtime' => [
                        0 => 110,
                        1 => 1.7889087656529516,
                        2 => 6,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 12,
                        1 => 0.19515368352577656,
                        2 => 3,
                    ],
                ],
                30 => [
                    'from' => '2026-03-13 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-03-23T00:00:00+01:00',
                    'uptime' => [
                        0 => 19209,
                        1 => 98.3161019551643,
                        2 => 399,
                    ],
                    'downtime' => [
                        0 => 290,
                        1 => 1.484287030402293,
                        2 => 20,
                        3 => 3,
                    ],
                    'unknown' => [
                        0 => 39,
                        1 => 0.1996110144334118,
                        2 => 9,
                    ],
                ],
            ],
            'ranges young monitoring' => [
                7 => [
                    'from' => '2026-04-05 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-04-12T08:00:00+02:00',
                    'uptime' => [
                        0 => 120,
                        1 => 50.0,
                        2 => 1,
                    ],
                    'downtime' => [
                        0 => 120,
                        1 => 50.0,
                        2 => 1,
                        3 => 0,
                    ],
                    'unknown' => [
                        0 => 0,
                        1 => 0.0,
                        2 => 0,
                    ],
                ],
                30 => [
                    'from' => '2026-03-13 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => true,
                    'tracking_started_at' => '2026-04-12T08:00:00+02:00',
                    'uptime' => [
                        0 => 120,
                        1 => 50.0,
                        2 => 1,
                    ],
                    'downtime' => [
                        0 => 120,
                        1 => 50.0,
                        2 => 1,
                        3 => 0,
                    ],
                    'unknown' => [
                        0 => 0,
                        1 => 0.0,
                        2 => 0,
                    ],
                ],
            ],
            'ranges without tracking' => [
                7 => [
                    'from' => '2026-04-05 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => false,
                    'tracking_started_at' => null,
                    'uptime' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                    ],
                    'downtime' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                        3 => 0,
                    ],
                    'unknown' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                    ],
                ],
                30 => [
                    'from' => '2026-03-13 00:00:00',
                    'to' => '2026-04-12 12:00:00',
                    'has_data' => false,
                    'tracking_started_at' => null,
                    'uptime' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                    ],
                    'downtime' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                        3 => 0,
                    ],
                    'unknown' => [
                        0 => 0,
                        1 => null,
                        2 => 0,
                    ],
                ],
            ],
            'ranges empty' => [
            ],
            'count incidents' => 2,
            'aggregated incidents' => 3,
            'aggregated incidents without intraday' => 2,
            'aggregated incidents start after end' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(MonitoringAvailabilityPayload $payload): array
    {
        return [
            'from' => $payload->from->toDateTimeString(),
            'to' => $payload->to->toDateTimeString(),
            'has_data' => $payload->hasData,
            'tracking_started_at' => $payload->trackingStartedAt,
            'uptime' => [$payload->uptime->minutes, $payload->uptime->percentage, $payload->uptime->total],
            'downtime' => [$payload->downtime->minutes, $payload->downtime->percentage, $payload->downtime->total, $payload->downtime->incidentsCount],
            'unknown' => [$payload->unknown->minutes, $payload->unknown->percentage, $payload->unknown->total],
        ];
    }

    private function response(Monitoring $monitoring, MonitoringStatus $status, string $checkedAt, ?int $httpStatus = null): void
    {
        MonitoringResponse::query()->forceCreate([
            'monitoring_id' => $monitoring->id,
            'status' => $status,
            'http_status_code' => $httpStatus ?? ($status === MonitoringStatus::UP ? 200 : 503),
            'response_time' => 100.0,
            'created_at' => Date::parse($checkedAt),
            'updated_at' => Date::parse($checkedAt),
        ]);
    }

    private function archivedResponse(Monitoring $monitoring, MonitoringStatus $status, string $checkedAt): void
    {
        MonitoringResponseArchived::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'monitoring_id' => $monitoring->id,
            'status' => $status,
            'http_status_code' => 200,
            'response_time' => 100.0,
            'created_at' => Date::parse($checkedAt),
            'updated_at' => Date::parse($checkedAt),
        ]);
    }

    private function dailyResult(Monitoring $monitoring, string $date, int $seed): void
    {
        MonitoringDailyResult::query()->create([
            'monitoring_id' => $monitoring->id,
            'date' => $date,
            'uptime_total' => 10 + $seed,
            'downtime_total' => $seed % 3,
            'unknown_total' => $seed % 2,
            'uptime_percentage' => 90.0,
            'downtime_percentage' => 10.0,
            'unknown_percentage' => 0.0,
            'uptime_minutes' => 1000 + $seed,
            'downtime_minutes' => 10 * ($seed % 4),
            'unknown_minutes' => $seed % 5,
            'avg_response_time' => 100.0,
            'min_response_time' => 80,
            'max_response_time' => 120,
            'incidents_count' => 1,
        ]);
    }
}
