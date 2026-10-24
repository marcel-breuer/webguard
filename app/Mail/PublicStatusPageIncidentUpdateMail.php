<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicStatusPageIncidentUpdateMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public StatusPageSubscription $subscription,
        public StatusPage $statusPage,
        public IncidentUpdate $incidentUpdate,
        public string $status,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.public_status_page_incident_update.subject', [
                'statusPageName' => $this->statusPage->name,
                'status' => __('mail.public_status_page_incident_update.' . $this->status),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.public-status-page-incident-update',
            with: [
                'statusPage' => $this->statusPage,
                'incidentUpdate' => $this->incidentUpdate,
                'statusPageUrl' => route('public-status-pages.show', $this->statusPage),
                'unsubscribeUrl' => route('public-status-pages.subscribers.unsubscribe', [
                    'statusPage' => $this->statusPage,
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
