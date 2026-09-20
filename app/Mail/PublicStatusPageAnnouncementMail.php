<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicStatusPageAnnouncementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public StatusPageSubscription $subscription,
        public StatusPageAnnouncement $announcement,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.public_status_page_announcement.subject', [
                'statusPageName' => $this->subscription->statusPage->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.public-status-page-announcement',
            with: [
                'statusPage' => $this->subscription->statusPage,
                'announcement' => $this->announcement,
                'statusPageUrl' => route('public-status-pages.show', $this->subscription->statusPage),
                'unsubscribeUrl' => route('public-status-pages.subscribers.unsubscribe', [
                    'statusPage' => $this->subscription->statusPage,
                    'token' => $this->subscription->unsubscribe_token,
                ]),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
