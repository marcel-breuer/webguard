<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendStatusPageAnnouncementNotifications;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StatusPageAnnouncementService
{
    /**
     * @param  array{title: string, message: string, notify_subscribers: bool}  $attributes
     */
    public function create(StatusPage $statusPage, User $user, array $attributes): StatusPageAnnouncement
    {
        abort_if($user->isDemo(), 403);
        abort_unless($statusPage->is_public, 422);

        $announcement = DB::transaction(function () use ($statusPage, $attributes): StatusPageAnnouncement {
            $lockedStatusPage = StatusPage::query()->lockForUpdate()->findOrFail($statusPage->id);

            if ($lockedStatusPage->activeAnnouncement()->exists()) {
                throw ValidationException::withMessages([
                    'announcement' => ['Dismiss the current announcement before publishing another one.'],
                ]);
            }

            return $lockedStatusPage->announcements()->create($attributes);
        });

        if ($announcement->notify_subscribers) {
            SendStatusPageAnnouncementNotifications::dispatch($announcement->id)->afterCommit();
        }

        $this->log($user, $announcement, 'status_page_announcement_published');

        return $announcement;
    }

    /**
     * @param  array{title: string, message: string}  $attributes
     */
    public function update(StatusPageAnnouncement $announcement, User $user, array $attributes): StatusPageAnnouncement
    {
        abort_if($user->isDemo(), 403);
        abort_if($announcement->dismissed_at !== null, 404);

        $announcement->update($attributes);
        $this->log($user, $announcement, 'status_page_announcement_updated');

        return $announcement->refresh();
    }

    public function dismiss(StatusPageAnnouncement $announcement, User $user): void
    {
        abort_if($user->isDemo(), 403);
        abort_if($announcement->dismissed_at !== null, 404);

        $announcement->update(['dismissed_at' => Date::now()]);
        $this->log($user, $announcement, 'status_page_announcement_dismissed');
    }

    private function log(User $user, StatusPageAnnouncement $announcement, string $event): void
    {
        activity('status_page')
            ->causedBy($user)
            ->performedOn($announcement)
            ->event($event)
            ->log($event);
    }
}
