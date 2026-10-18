<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PublicStatusPageMaintenanceScheduledMail;
use App\Models\Monitoring;
use App\Models\StatusPageMaintenanceDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPlannedMaintenanceNotification implements ShouldQueue
{
    use Queueable;

    private const array RETRY_DELAYS_SECONDS = [60, 300, 900, 3600, 3600];

    // The durable notice owns the retry budget, including recovered queue messages.
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // A database lease also excludes duplicate or recovered queue messages.
        $claimed = StatusPageMaintenanceDelivery::query()->ready()->whereKey($this->deliveryId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'available_at' => Date::now()->addMinutes(StatusPageMaintenanceDelivery::LEASE_MINUTES),
        ]);

        if ($claimed === 0) {
            return;
        }

        $statusPageMaintenanceDelivery = StatusPageMaintenanceDelivery::query()
            ->with('subscription.statusPage')->find($this->deliveryId);
        $subscription = $statusPageMaintenanceDelivery?->subscription;

        if ($statusPageMaintenanceDelivery === null) {
            return;
        }

        if ($subscription === null || ! $subscription->isVerified() || ! $subscription->statusPage?->is_public) {
            $statusPageMaintenanceDelivery->update(['cancelled_at' => Date::now()]);

            return;
        }

        $payload = $statusPageMaintenanceDelivery->payload;

        try {
            Mail::to($subscription->email)->send(new PublicStatusPageMaintenanceScheduledMail(
                $subscription,
                collect($payload['monitorings'])->map(fn (array $monitoring): Monitoring => new Monitoring($monitoring)),
                Date::parse($payload['starts_at']),
                $payload['ends_at'] === null ? null : Date::parse($payload['ends_at']),
                $payload['timezone'],
                $payload['recurring'],
            ));
        } catch (Throwable) {
            $delay = self::RETRY_DELAYS_SECONDS[$statusPageMaintenanceDelivery->attempts - 1];
            $statusPageMaintenanceDelivery->update([
                'available_at' => Date::now()->addSeconds($delay),
                'queued_at' => null,
            ]);
            Log::warning('Planned maintenance notification delivery failed.', [
                'delivery_id' => $this->deliveryId,
                'attempts' => $statusPageMaintenanceDelivery->attempts,
            ]);

            return;
        }

        $statusPageMaintenanceDelivery->update(['sent_at' => Date::now()]);
    }
}
