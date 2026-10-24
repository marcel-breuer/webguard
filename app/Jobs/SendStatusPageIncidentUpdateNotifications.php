<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PublicStatusPageIncidentUpdateMail;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

class SendStatusPageIncidentUpdateNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $statusPageId, public string $incidentUpdateId, public string $status)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->statusPageId, $this->incidentUpdateId, $this->status]);
    }

    public function handle(): void
    {
        $statusPage = StatusPage::query()->find($this->statusPageId);
        $incidentUpdate = IncidentUpdate::query()->with('incident.monitoring')->find($this->incidentUpdateId);

        if (! $statusPage instanceof StatusPage
            || ! $statusPage->is_public
            || ! $statusPage->auto_publish_incident_updates
            || ! $incidentUpdate instanceof IncidentUpdate
            || $incidentUpdate->incident === null
            || $incidentUpdate->incident->monitoring === null) {
            return;
        }

        if ($incidentUpdate->status->value !== $this->status) {
            return;
        }

        $statusPage->subscriptions()->verified()->orderBy('id')->each(
            function (StatusPageSubscription $subscription) use ($statusPage, $incidentUpdate): void {
                Mail::to($subscription->email)->send(
                    new PublicStatusPageIncidentUpdateMail($subscription, $statusPage, $incidentUpdate, $this->status),
                );
            },
        );

        $incidentUpdate->forceFill(['notified_at' => Date::now()])->save();
    }
}
