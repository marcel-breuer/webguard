<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MonitoringResponse;
use App\Models\MonitoringResponseArchived;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class MonitoringResponseHistory
{
    /**
     * @return Builder<Model>
     */
    public static function queryForEndDate(Carbon $endDate): Builder
    {
        if ($endDate->lt(Date::now()->subWeek()->startOfDay())) {
            return MonitoringResponseArchived::query();
        }

        return MonitoringResponse::query();
    }

    public static function groupingForDays(int $days): string
    {
        return match (true) {
            $days <= 1 => '%Y-%m-%d %H',
            $days <= 30 => '%Y-%m-%d',
            default => '%Y-%m',
        };
    }

    public static function periodExpression(string $column, string $format): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "strftime('{$format}', {$column})";
        }

        return "DATE_FORMAT({$column}, '{$format}')";
    }
}
