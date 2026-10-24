<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MonitoringGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonitoringGroupSlaReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public MonitoringGroup $monitoringGroup, public array $report, public string $csv) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.monitoring_group_sla_report.subject', [
                'group' => $this->monitoringGroup->name,
                'period' => $this->report['period_start']->format('F Y'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.monitoring-group-sla-report',
            with: ['monitoringGroup' => $this->monitoringGroup, 'report' => $this->report],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = sprintf('webguard-sla-%s-%s.csv', $this->monitoringGroup->id, $this->report['period_start']->format('Y-m'));

        return [Attachment::fromData(fn (): string => $this->csv, $filename)->withMime('text/csv')];
    }
}
