<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\NotificationType;
use App\Enums\TeamRole;
use App\Mail\PublicStatusPageAnnouncementMail;
use App\Mail\PublicStatusPageMaintenanceScheduledMail;
use App\Mail\PublicStatusPageStatusUpdateMail;
use App\Mail\PublicStatusPageSubscriptionConfirmationMail;
use App\Mail\StatusPageStatusUpdateMail;
use App\Mail\StatusPageSubscriptionConfirmationMail;
use App\Mail\TeamInvitationMail;
use App\Models\Monitoring;
use App\Models\MonitoringNotification;
use App\Models\Package;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscriber;
use App\Models\StatusPageSubscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class MailableContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Package::factory()->create();
    }

    public function test_team_invitation_mail_exposes_subject_view_and_accept_url(): void
    {
        $inviter = User::factory()->create();
        $team = Team::factory()->create([
            'name' => 'Operations',
            'created_by_user_id' => $inviter->id,
        ]);
        $teamInvitation = TeamInvitation::query()->create([
            'team_id' => $team->id,
            'email' => 'new-member@example.com',
            'role' => TeamRole::MEMBER,
            'token_hash' => hash('sha256', 'invite-token'),
            'invited_by_user_id' => $inviter->id,
            'expires_at' => now()->addDay(),
        ]);

        $teamInvitationMail = new TeamInvitationMail($teamInvitation, 'invite-token');

        $this->assertSame(__('team.mail.invitation.subject', ['team' => 'Operations']), $teamInvitationMail->envelope()->subject);
        $this->assertSame('mail.team-invitation', $teamInvitationMail->content()->view);
        $this->assertSame(
            route('team-invitations.accept', ['token' => 'invite-token']),
            $teamInvitationMail->content()->with['acceptUrl']
        );
    }

    public function test_team_invitation_mail_uses_a_localized_absolute_expiry_date(): void
    {
        app()->setLocale('de');

        $inviter = User::factory()->create();
        $team = Team::factory()->create([
            'name' => 'Operations',
            'created_by_user_id' => $inviter->id,
        ]);
        $teamInvitation = TeamInvitation::query()->create([
            'team_id' => $team->id,
            'email' => 'new-member@example.com',
            'role' => TeamRole::MEMBER,
            'token_hash' => hash('sha256', 'invite-token'),
            'invited_by_user_id' => $inviter->id,
            'expires_at' => Date::parse('2027-01-01 16:53:00'),
        ]);

        $rendered = (new TeamInvitationMail($teamInvitation, 'invite-token'))->render();

        $this->assertStringContainsString('Diese Einladung läuft am 01.01.2027 um 16:53 Uhr ab.', $rendered);
    }

    public function test_status_page_subscription_confirmation_mail_exposes_confirmation_contract(): void
    {
        $monitoring = Monitoring::factory()->for(User::factory())->create([
            'name' => 'Public API',
            'public_label_enabled' => true,
        ]);
        $statusPageSubscriber = StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => StatusPageSubscriber::hashToken('confirm-token'),
            'unsubscribe_token' => 'unsubscribe-token',
        ]);

        $statusPageSubscriptionConfirmationMail = new StatusPageSubscriptionConfirmationMail($statusPageSubscriber, 'confirm-token');

        $this->assertSame(__('mail.status_page_subscription_confirmation.subject', ['monitoringName' => 'Public API']), $statusPageSubscriptionConfirmationMail->envelope()->subject);
        $this->assertSame('mail.status-page-subscription-confirmation', $statusPageSubscriptionConfirmationMail->content()->view);
        $this->assertSame($statusPageSubscriber->id, $statusPageSubscriptionConfirmationMail->content()->with['subscriber']->id);
        $this->assertSame($monitoring->id, $statusPageSubscriptionConfirmationMail->content()->with['monitoring']->id);
        $this->assertSame(
            route('public-status-pages.subscribers.confirm', ['statusPage' => $monitoring, 'token' => 'confirm-token']),
            $statusPageSubscriptionConfirmationMail->content()->with['confirmUrl']
        );
        $this->assertSame([], $statusPageSubscriptionConfirmationMail->attachments());
    }

    public function test_status_page_status_update_mail_exposes_status_and_unsubscribe_contract(): void
    {
        $monitoring = Monitoring::factory()->for(User::factory())->create([
            'name' => 'Public API',
            'public_label_enabled' => true,
        ]);
        $statusPageSubscriber = StatusPageSubscriber::query()->create([
            'monitoring_id' => $monitoring->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => now(),
        ]);
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'Monitoring is down',
            'read' => false,
            'sent' => true,
        ]);

        $statusPageStatusUpdateMail = new StatusPageStatusUpdateMail($statusPageSubscriber, $monitoringNotification, 'down');

        $this->assertSame(__('mail.status_page_status_update.subject', [
            'monitoringName' => 'Public API',
            'status' => 'DOWN',
        ]), $statusPageStatusUpdateMail->envelope()->subject);
        $this->assertSame('mail.status-page-status-update', $statusPageStatusUpdateMail->content()->view);
        $this->assertSame($monitoring->id, $statusPageStatusUpdateMail->content()->with['monitoring']->id);
        $this->assertSame($monitoringNotification->id, $statusPageStatusUpdateMail->content()->with['notification']->id);
        $this->assertSame('down', $statusPageStatusUpdateMail->content()->with['status']);
        $this->assertSame('DOWN', $statusPageStatusUpdateMail->content()->with['statusLabel']);
        $this->assertSame(route('public-status-pages.show', $monitoring), $statusPageStatusUpdateMail->content()->with['statusPageUrl']);
        $this->assertSame(
            route('public-status-pages.subscribers.unsubscribe', ['statusPage' => $monitoring, 'token' => 'unsubscribe-token']),
            $statusPageStatusUpdateMail->content()->with['unsubscribeUrl']
        );
        $this->assertSame([], $statusPageStatusUpdateMail->attachments());
    }

    public function test_public_status_page_subscription_confirmation_mail_exposes_confirmation_contract(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => StatusPageSubscription::hashToken('confirm-token'),
            'unsubscribe_token' => 'unsubscribe-token',
        ]);

        $publicStatusPageSubscriptionConfirmationMail = new PublicStatusPageSubscriptionConfirmationMail($statusPageSubscription, 'confirm-token');

        $this->assertSame(__('mail.public_status_page_subscription_confirmation.subject', [
            'statusPageName' => 'Acme Status',
        ]), $publicStatusPageSubscriptionConfirmationMail->envelope()->subject);
        $this->assertSame('mail.public-status-page-subscription-confirmation', $publicStatusPageSubscriptionConfirmationMail->content()->view);
        $this->assertSame($statusPageSubscription->id, $publicStatusPageSubscriptionConfirmationMail->content()->with['subscription']->id);
        $this->assertSame($statusPage->id, $publicStatusPageSubscriptionConfirmationMail->content()->with['statusPage']->id);
        $this->assertSame(route('public-status-pages.subscribers.confirm', [
            'statusPage' => $statusPage,
            'token' => 'confirm-token',
        ]), $publicStatusPageSubscriptionConfirmationMail->content()->with['confirmUrl']);
        $this->assertSame([], $publicStatusPageSubscriptionConfirmationMail->attachments());
    }

    public function test_public_status_page_status_update_mail_exposes_status_and_unsubscribe_contract(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => now(),
        ]);
        $monitoringNotification = MonitoringNotification::query()->create([
            'monitoring_id' => $monitoring->id,
            'type' => NotificationType::STATUS_CHANGE,
            'message' => 'Monitoring is down',
            'read' => false,
            'sent' => true,
        ]);

        $publicStatusPageStatusUpdateMail = new PublicStatusPageStatusUpdateMail($statusPageSubscription, $monitoring, $monitoringNotification, 'down');

        $this->assertSame(__('mail.public_status_page_status_update.subject', [
            'statusPageName' => 'Acme Status',
            'monitoringName' => 'Checkout API',
            'status' => 'DOWN',
        ]), $publicStatusPageStatusUpdateMail->envelope()->subject);
        $this->assertSame('mail.public-status-page-status-update', $publicStatusPageStatusUpdateMail->content()->view);
        $this->assertSame($statusPage->id, $publicStatusPageStatusUpdateMail->content()->with['statusPage']->id);
        $this->assertSame($monitoring->id, $publicStatusPageStatusUpdateMail->content()->with['monitoring']->id);
        $this->assertSame($monitoringNotification->id, $publicStatusPageStatusUpdateMail->content()->with['notification']->id);
        $this->assertSame('down', $publicStatusPageStatusUpdateMail->content()->with['status']);
        $this->assertSame('DOWN', $publicStatusPageStatusUpdateMail->content()->with['statusLabel']);
        $this->assertSame(route('public-status-pages.show', $statusPage), $publicStatusPageStatusUpdateMail->content()->with['statusPageUrl']);
        $this->assertSame(route('public-status-pages.subscribers.unsubscribe', [
            'statusPage' => $statusPage,
            'token' => 'unsubscribe-token',
        ]), $publicStatusPageStatusUpdateMail->content()->with['unsubscribeUrl']);
        $this->assertSame([], $publicStatusPageStatusUpdateMail->attachments());
    }

    public function test_public_status_page_maintenance_scheduled_mail_exposes_schedule_and_unsubscribe_contract(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => now(),
        ]);

        $publicStatusPageMaintenanceScheduledMail = new PublicStatusPageMaintenanceScheduledMail(
            $statusPageSubscription,
            collect([$monitoring]),
            Date::parse('2026-08-20 10:00:00 UTC'),
            Date::parse('2026-08-20 11:00:00 UTC'),
            'Europe/Berlin',
            true,
        );

        $this->assertSame(__('mail.public_status_page_maintenance_scheduled.subject', [
            'statusPageName' => 'Acme Status',
        ]), $publicStatusPageMaintenanceScheduledMail->envelope()->subject);
        $this->assertSame('mail.public-status-page-maintenance-scheduled', $publicStatusPageMaintenanceScheduledMail->content()->view);
        $this->assertSame($statusPage->id, $publicStatusPageMaintenanceScheduledMail->content()->with['statusPage']->id);
        $this->assertTrue($publicStatusPageMaintenanceScheduledMail->content()->with['monitorings']->contains($monitoring));
        $this->assertTrue($publicStatusPageMaintenanceScheduledMail->content()->with['recurring']);
        $this->assertSame(route('public-status-pages.show', $statusPage), $publicStatusPageMaintenanceScheduledMail->content()->with['statusPageUrl']);
        $this->assertSame(route('public-status-pages.subscribers.unsubscribe', [
            'statusPage' => $statusPage,
            'token' => 'unsubscribe-token',
        ]), $publicStatusPageMaintenanceScheduledMail->content()->with['unsubscribeUrl']);
        $this->assertSame([], $publicStatusPageMaintenanceScheduledMail->attachments());
    }

    public function test_public_status_page_announcement_mail_exposes_announcement_and_unsubscribe_contract(): void
    {
        $statusPage = StatusPage::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => now(),
        ]);
        $statusPageAnnouncement = StatusPageAnnouncement::query()->create([
            'status_page_id' => $statusPage->id,
            'title' => 'Planned account changes',
            'message' => 'Account changes are temporarily unavailable.',
        ]);

        $publicStatusPageAnnouncementMail = new PublicStatusPageAnnouncementMail($statusPageSubscription, $statusPageAnnouncement);

        $this->assertSame(__('mail.public_status_page_announcement.subject', ['statusPageName' => 'Acme Status']), $publicStatusPageAnnouncementMail->envelope()->subject);
        $this->assertSame('mail.public-status-page-announcement', $publicStatusPageAnnouncementMail->content()->view);
        $this->assertSame($statusPage->id, $publicStatusPageAnnouncementMail->content()->with['statusPage']->id);
        $this->assertSame($statusPageAnnouncement->id, $publicStatusPageAnnouncementMail->content()->with['announcement']->id);
        $this->assertSame(route('public-status-pages.show', $statusPage), $publicStatusPageAnnouncementMail->content()->with['statusPageUrl']);
        $this->assertSame(route('public-status-pages.subscribers.unsubscribe', [
            'statusPage' => $statusPage,
            'token' => 'unsubscribe-token',
        ]), $publicStatusPageAnnouncementMail->content()->with['unsubscribeUrl']);
        $this->assertSame([], $publicStatusPageAnnouncementMail->attachments());
    }

    public function test_public_status_page_maintenance_scheduled_mail_renders_localized_schedule_branches(): void
    {
        $user = User::factory()->create();
        $monitoring = Monitoring::factory()->for($user)->create(['name' => 'Checkout API']);
        $statusPage = StatusPage::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);
        $statusPageSubscription = StatusPageSubscription::query()->create([
            'status_page_id' => $statusPage->id,
            'email' => 'subscriber@example.com',
            'confirmation_token_hash' => null,
            'unsubscribe_token' => 'unsubscribe-token',
            'verified_at' => now(),
        ]);

        app()->setLocale('en');
        $recurringMail = new PublicStatusPageMaintenanceScheduledMail(
            $statusPageSubscription,
            collect([$monitoring]),
            Date::parse('2026-08-20 10:00:00 UTC'),
            Date::parse('2026-08-20 11:00:00 UTC'),
            'Europe/Berlin',
            true,
        );

        $recurringRendered = $recurringMail->render();

        $this->assertStringContainsString('recurring planned maintenance scheduled', $recurringRendered);
        $this->assertStringContainsString('Affected services:', $recurringRendered);
        $this->assertStringContainsString('Checkout API', $recurringRendered);
        $this->assertSame('20.08.2026 13:00', $recurringMail->content()->with['endsAt']);
        $this->assertStringContainsString('Expected end:', $recurringRendered);

        app()->setLocale('de');
        $openEndedMail = new PublicStatusPageMaintenanceScheduledMail(
            $statusPageSubscription,
            collect([$monitoring]),
            Date::parse('2026-08-20 10:00:00 UTC'),
            null,
            'Europe/Berlin',
            false,
        );

        $openEndedRendered = $openEndedMail->render();

        $this->assertStringContainsString('wurde eine geplante Wartung eingetragen', $openEndedRendered);
        $this->assertStringContainsString('Betroffene Dienste:', $openEndedRendered);
        $this->assertStringContainsString('Ein Endzeitpunkt wurde noch nicht angegeben.', $openEndedRendered);
        $this->assertStringContainsString('Statusseite anzeigen', $openEndedRendered);
        $this->assertStringContainsString('Diese Statusseiten-Updates abbestellen', $openEndedRendered);
    }
}
