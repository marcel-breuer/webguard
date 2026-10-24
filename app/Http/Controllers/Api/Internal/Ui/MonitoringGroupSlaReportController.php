<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Models\MonitoringGroup;
use App\Models\User;
use App\Services\MonitoringGroupSlaReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonitoringGroupSlaReportController extends Controller
{
    public function __invoke(Request $request, string $monitoringGroup, MonitoringGroupSlaReportService $reportService): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $group = MonitoringGroup::query()
            ->where('user_id', $user->id)
            ->whereKey($monitoringGroup)
            ->firstOrFail();

        abort_if($group->sla_target_percentage === null, 404);

        $report = $reportService->previousCalendarMonth($group);
        $filename = sprintf('webguard-sla-%s-%s.csv', $group->id, $report['period_start']->format('Y-m'));

        return response()->streamDownload(
            static function () use ($reportService, $report): void {
                echo $reportService->csv($report);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
