<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Models\Monitoring;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Determines when tracking of a monitoring began.
 */
class AvailabilityTrackingStart
{
    public function fromResponses(Monitoring $monitoring): ?Carbon
    {
        $trackingStartedAt = collect([
            $monitoring->archivedResponseResults()->min('created_at'),
            $monitoring->responseResults()->min('created_at'),
        ])->filter()->map(fn ($date): Carbon => Date::parse($date))->sort()->first();

        return $trackingStartedAt instanceof Carbon ? $trackingStartedAt : null;
    }

    public function fromDailyResults(Monitoring $monitoring): ?Carbon
    {
        $trackingStartedAt = $monitoring->dailyResults()->min('date');

        if (! $trackingStartedAt) {
            return null;
        }

        return Date::parse($trackingStartedAt)->startOfDay();
    }
}
