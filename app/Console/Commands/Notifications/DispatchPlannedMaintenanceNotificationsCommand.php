<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Jobs\SendPlannedMaintenanceNotification;
use App\Models\StatusPageMaintenanceDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Description('Dispatches pending planned maintenance subscriber notifications, including retries.')]
#[Signature('notifications:dispatch-planned-maintenance')]
class DispatchPlannedMaintenanceNotificationsCommand extends Command
{
    public function handle(): int
    {
        $failed = false;
        $query = StatusPageMaintenanceDelivery::query()->ready()
            ->where(fn (Builder $builder) => $builder->whereNull('queued_at')
                ->orWhere('queued_at', '<=', Date::now()->subMinutes(StatusPageMaintenanceDelivery::LEASE_MINUTES)));

        foreach ($query->lazyById(100) as $statusPageMaintenanceDelivery) {
            $claimed = (clone $query)->whereKey($statusPageMaintenanceDelivery->id)->update(['queued_at' => Date::now()]);

            if ($claimed === 0) {
                continue;
            }

            try {
                Bus::dispatch(new SendPlannedMaintenanceNotification($statusPageMaintenanceDelivery->id));
            } catch (Throwable) {
                $failed = true;
                StatusPageMaintenanceDelivery::query()->whereKey($statusPageMaintenanceDelivery->id)->update(['queued_at' => null]);
                Log::warning('Planned maintenance notification could not be queued.', [
                    'delivery_id' => $statusPageMaintenanceDelivery->id,
                ]);
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
