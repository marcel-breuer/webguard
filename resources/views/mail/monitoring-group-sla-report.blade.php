<x-mail::message>
# {{ __('mail.monitoring_group_sla_report.heading', ['group' => $monitoringGroup->name]) }}

{{ __('mail.monitoring_group_sla_report.summary', ['period' => $report['period_start']->format('F Y')]) }}

{{ __('mail.monitoring_group_sla_report.target', ['target' => number_format($report['target_percentage'], 2)]) }}

{{ __('mail.monitoring_group_sla_report.actual', ['actual' => $report['uptime_percentage'] === null ? __('mail.monitoring_group_sla_report.no_data') : number_format($report['uptime_percentage'], 2)]) }}

{{ __('mail.monitoring_group_sla_report.result', ['result' => $report['meets_target'] === null ? __('mail.monitoring_group_sla_report.no_data') : ($report['meets_target'] ? __('mail.monitoring_group_sla_report.met') : __('mail.monitoring_group_sla_report.missed'))]) }}

{{ __('mail.monitoring_group_sla_report.attachment') }}

{{ config('app.name') }}
</x-mail::message>
