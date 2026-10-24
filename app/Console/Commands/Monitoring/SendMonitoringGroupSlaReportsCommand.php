<?php

declare(strict_types=1);

namespace App\Console\Commands\Monitoring;

use App\Mail\MonitoringGroupSlaReportMail;
use App\Models\MonitoringGroup;
use App\Services\MonitoringGroupSlaReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Description('Sends monthly SLA reports for enabled monitoring groups.')]
#[Signature('monitoring:send-sla-reports {--period-end= : Final date of the report period.} {--force : Send reports even when the current month has not started.}')]
class SendMonitoringGroupSlaReportsCommand extends Command
{
    public function __construct(private readonly MonitoringGroupSlaReportService $reportService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force') && Date::now()->day !== 1 && ! $this->option('period-end')) {
            return Command::SUCCESS;
        }

        $periodEndOption = $this->option('period-end');
        $asOf = is_string($periodEndOption) && $periodEndOption !== ''
            ? Date::parse($periodEndOption)->startOfDay()->addMonthNoOverflow()
            : Date::now();

        MonitoringGroup::query()
            ->whereNotNull('sla_target_percentage')
            ->where('sla_report_enabled', true)
            ->whereHas('user', fn ($query) => $query
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->whereNotNull('email_verified_at'))
            ->with('user', 'monitorings', 'maintenanceWindows')
            ->orderBy('id')
            ->chunkById(100, function ($groups) use ($asOf): void {
                foreach ($groups as $group) {
                    $report = $this->reportService->previousCalendarMonth($group, $asOf);

                    if ($report['monitorings'] === []) {
                        continue;
                    }

                    try {
                        Mail::to($group->user->email)
                            ->send(new MonitoringGroupSlaReportMail($group, $report, $this->reportService->csv($report)));
                    } catch (Throwable $throwable) {
                        report($throwable);
                        $this->components->error("Could not send SLA report for monitoring group {$group->id}.");
                    }
                }
            });

        return Command::SUCCESS;
    }
}
