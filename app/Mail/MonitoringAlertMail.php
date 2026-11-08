<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Lang;

class MonitoringAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  Serialized notification payload as produced by NotificationPayload::toArray().
     */
    public function __construct(
        public User $user,
        public array $payload
    ) {}

    public function envelope(): Envelope
    {
        $monitoringName = data_get($this->payload, 'monitoring.name');

        return new Envelope(
            subject: is_string($monitoringName) && $monitoringName !== ''
                ? __('mail.monitoring_alert.subject', ['event' => $this->eventLabel(), 'monitoringName' => $monitoringName])
                : __('mail.monitoring_alert.test_subject'),
        );
    }

    public function content(): Content
    {
        $monitoringId = data_get($this->payload, 'monitoring.id');
        $occurredAt = data_get($this->payload, 'occurred_at');
        $baseUrl = mb_rtrim((string) config('app.url'), '/');

        return new Content(
            view: 'mail.monitoring-alert',
            with: [
                'user' => $this->user,
                'isTest' => (bool) data_get($this->payload, 'meta.test', false),
                'eventLabel' => $this->eventLabel(),
                'severityLabel' => $this->severityLabel(),
                'intro' => $this->intro(),
                'details' => (string) data_get($this->payload, 'message', ''),
                'monitoringName' => data_get($this->payload, 'monitoring.name'),
                'monitoringTarget' => data_get($this->payload, 'monitoring.target'),
                'occurredAt' => is_string($occurredAt)
                    ? Date::parse($occurredAt)->locale(app()->getLocale())->isoFormat('L LT')
                    : null,
                'evidence' => $this->regionalConsensusEvidence(),
                'actionUrl' => is_string($monitoringId) && $monitoringId !== ''
                    ? $baseUrl . '/monitorings/' . rawurlencode($monitoringId)
                    : $baseUrl . '/profile/notifications',
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

    private function eventType(): string
    {
        return (string) data_get($this->payload, 'event_type', '');
    }

    private function eventLabel(): string
    {
        $key = 'mail.monitoring_alert.events.' . $this->eventType();

        return Lang::has($key) ? __($key) : __('mail.monitoring_alert.events.default');
    }

    private function severityLabel(): string
    {
        $key = 'mail.monitoring_alert.severities.' . data_get($this->payload, 'severity', '');

        return Lang::has($key) ? __($key) : (string) data_get($this->payload, 'severity', '');
    }

    private function intro(): string
    {
        if ((bool) data_get($this->payload, 'meta.test', false)) {
            return __('mail.monitoring_alert.test_intro');
        }

        $key = 'mail.monitoring_alert.intros.' . $this->eventType();
        $replace = ['monitoringName' => (string) data_get($this->payload, 'monitoring.name', '')];

        return Lang::has($key) ? __($key, $replace) : __('mail.monitoring_alert.intros.default', $replace);
    }

    /**
     * @return array{classification: string, failed_count: int, reporting: int, total: int, required: int, locations: list<array{code: string, status: string, checked_at: string}>}|null
     */
    private function regionalConsensusEvidence(): ?array
    {
        $snapshot = data_get($this->payload, 'meta.regional_consensus');

        if (! is_array($snapshot)) {
            return null;
        }

        $status = in_array($snapshot['status'] ?? null, ['regional', 'global'], true) ? $snapshot['status'] : 'regional';
        $affectedLocations = is_array($snapshot['affected_locations'] ?? null) ? $snapshot['affected_locations'] : [];
        $locations = is_array($snapshot['locations'] ?? null) ? $snapshot['locations'] : [];

        return [
            'classification' => __('notifications.regional_consensus.statuses.' . $status),
            'failed_count' => count($affectedLocations),
            'reporting' => (int) ($snapshot['reporting_locations'] ?? 0),
            'total' => (int) ($snapshot['total_locations'] ?? 0),
            'required' => (int) ($snapshot['required_failures'] ?? 0),
            'locations' => collect($locations)
                ->filter(static fn (mixed $location): bool => is_array($location) && is_string($location['code'] ?? null))
                ->map(fn (array $location): array => [
                    'code' => (string) $location['code'],
                    'status' => $this->locationStatusLabel($location),
                    'checked_at' => is_string($location['checked_at'] ?? null)
                        ? Date::parse($location['checked_at'])->locale(app()->getLocale())->isoFormat('L LT')
                        : '—',
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function locationStatusLabel(array $location): string
    {
        $availability = $location['availability'] ?? null;

        if (in_array($availability, ['stale', 'missing'], true)) {
            return __('notifications.regional_consensus.availability.' . $availability);
        }

        $key = 'mail.monitoring_alert.location_statuses.' . ($location['status'] ?? 'unknown');

        return Lang::has($key) ? __($key) : __('mail.monitoring_alert.location_statuses.unknown');
    }
}
