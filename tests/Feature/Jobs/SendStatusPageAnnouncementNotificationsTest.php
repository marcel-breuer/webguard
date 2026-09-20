<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendStatusPageAnnouncementNotifications;
use App\Mail\PublicStatusPageAnnouncementMail;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendStatusPageAnnouncementNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_it_notifies_only_verified_subscribers_once(): void
    {
        Mail::fake();
        $statusPage = StatusPage::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Status',
            'is_public' => true,
        ]);
        StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'verified@example.test',
            'unsubscribe_token' => 'verified-token',
            'verified_at' => Date::now(),
        ]);
        StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'pending@example.test',
            'confirmation_token_hash' => StatusPageSubscription::hashToken('pending-token'),
            'unsubscribe_token' => 'pending-token',
        ]);
        $announcement = StatusPageAnnouncement::query()->create([
            'status_page_id' => $statusPage->id,
            'title' => 'Account changes',
            'message' => 'Account changes are temporarily unavailable.',
            'notify_subscribers' => true,
        ]);

        $job = new SendStatusPageAnnouncementNotifications($announcement->id);
        $job->handle();
        $job->handle();

        Mail::assertSent(PublicStatusPageAnnouncementMail::class, function (PublicStatusPageAnnouncementMail $mail): bool {
            return $mail->hasTo('verified@example.test');
        });
        Mail::assertNotSent(PublicStatusPageAnnouncementMail::class, function (PublicStatusPageAnnouncementMail $mail): bool {
            return $mail->hasTo('pending@example.test');
        });
        Mail::assertSent(PublicStatusPageAnnouncementMail::class, 1);
        $this->assertNotNull($announcement->refresh()->notified_at);
    }
}
