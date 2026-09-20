<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PublicStatusPageAnnouncementMail;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

class SendStatusPageAnnouncementNotifications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $announcementId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->announcementId;
    }

    public function handle(): void
    {
        $announcement = StatusPageAnnouncement::query()
            ->with('statusPage')
            ->find($this->announcementId);

        if (! $announcement instanceof StatusPageAnnouncement
            || ! $announcement->notify_subscribers
            || $announcement->notified_at !== null
            || $announcement->dismissed_at !== null
            || $announcement->statusPage === null
            || ! $announcement->statusPage->is_public) {
            return;
        }

        $announcement->statusPage->subscriptions()
            ->verified()
            ->orderBy('id')
            ->each(function (StatusPageSubscription $subscription) use ($announcement): void {
                Mail::to($subscription->email)->send(new PublicStatusPageAnnouncementMail($subscription, $announcement));
            });

        $announcement->forceFill(['notified_at' => Date::now()])->save();
    }
}
