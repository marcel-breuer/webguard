<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $timestamp = now()->toDateTimeString();

        foreach ($this->datePairs() as [$sourceDate, $targetDate]) {
            $this->insertMissingDailyResults($sourceDate, $targetDate, $timestamp);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration repairs historical aggregates and cannot be safely reversed.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function datePairs(): array
    {
        return [
            ['2026-10-01', '2026-10-02'],
            ['2026-10-02', '2026-10-03'],
            ['2026-10-03', '2026-10-04'],
            ['2026-10-06', '2026-10-07'],
            ['2026-10-22', '2026-10-23'],
        ];
    }

    private function insertMissingDailyResults(string $sourceDate, string $targetDate, string $timestamp): void
    {
        DB::table('monitoring_daily_results')->insertUsing(
            [
                'monitoring_id',
                'date',
                'uptime_total',
                'downtime_total',
                'unknown_total',
                'uptime_percentage',
                'downtime_percentage',
                'unknown_percentage',
                'uptime_minutes',
                'downtime_minutes',
                'unknown_minutes',
                'avg_response_time',
                'min_response_time',
                'max_response_time',
                'incidents_count',
                'created_at',
                'updated_at',
            ],
            DB::table('monitoring_daily_results as source')
                ->leftJoin('monitoring_daily_results as existing', function (JoinClause $join) use ($targetDate): void {
                    $join
                        ->on('existing.monitoring_id', '=', 'source.monitoring_id')
                        ->whereDate('existing.date', $targetDate);
                })
                ->whereDate('source.date', $sourceDate)
                ->whereNull('existing.id')
                ->select([
                    'source.monitoring_id',
                ])
                ->selectRaw('? as date', [$targetDate])
                ->addSelect([
                    'source.uptime_total',
                    'source.downtime_total',
                    'source.unknown_total',
                    'source.uptime_percentage',
                    'source.downtime_percentage',
                    'source.unknown_percentage',
                    'source.uptime_minutes',
                    'source.downtime_minutes',
                    'source.unknown_minutes',
                    'source.avg_response_time',
                    'source.min_response_time',
                    'source.max_response_time',
                    'source.incidents_count',
                ])
                ->selectRaw('? as created_at, ? as updated_at', [$timestamp, $timestamp])
        );
    }
};
