@extends('layouts.mail')

@section('title', $isTest ? __('mail.monitoring_alert.test_title') : $eventLabel)
@section('eyebrow', __('mail.general.notification_eyebrow'))

@section('content')
    <p>{{ __('mail.monitoring_alert.greeting', ['userName' => $user->name]) }}</p>

    <p>{{ $intro }}</p>

    @unless ($isTest)
        <table class="digest-table" width="100%" cellpadding="8" cellspacing="0">
            <tbody>
                <tr>
                    <td><strong>{{ __('mail.monitoring_alert.event_label') }}</strong></td>
                    <td>{{ $eventLabel }}</td>
                </tr>
                <tr>
                    <td><strong>{{ __('mail.monitoring_alert.severity_label') }}</strong></td>
                    <td>{{ $severityLabel }}</td>
                </tr>
                @if ($monitoringName)
                    <tr>
                        <td><strong>{{ __('mail.monitoring_alert.monitoring_label') }}</strong></td>
                        <td>{{ $monitoringName }}</td>
                    </tr>
                @endif
                @if ($monitoringTarget)
                    <tr>
                        <td><strong>{{ __('mail.monitoring_alert.target_label') }}</strong></td>
                        <td>{{ $monitoringTarget }}</td>
                    </tr>
                @endif
                @if ($occurredAt)
                    <tr>
                        <td><strong>{{ __('mail.monitoring_alert.occurred_at_label') }}</strong></td>
                        <td>{{ $occurredAt }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endunless

    @if ($evidence)
        <h2>{{ __('mail.monitoring_alert.evidence_heading') }}</h2>
        <p>{{ __('mail.monitoring_alert.evidence_summary', [
            'classification' => $evidence['classification'],
            'failed_count' => $evidence['failed_count'],
            'reporting' => $evidence['reporting'],
            'total' => $evidence['total'],
            'required' => $evidence['required'],
        ]) }}</p>

        @if ($evidence['locations'] !== [])
            <table class="digest-table" width="100%" cellpadding="8" cellspacing="0">
                <thead>
                    <tr>
                        <th align="left">{{ __('mail.monitoring_alert.location_label') }}</th>
                        <th align="left">{{ __('mail.monitoring_alert.location_status_label') }}</th>
                        <th align="left">{{ __('mail.monitoring_alert.checked_at_label') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($evidence['locations'] as $location)
                        <tr>
                            <td>{{ $location['code'] }}</td>
                            <td>{{ $location['status'] }}</td>
                            <td>{{ $location['checked_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @elseif (! $isTest && $details !== '')
        <p>{{ $details }}</p>
    @endif

    <p>
        <a href="{{ $actionUrl }}" class="mail-button">{{ $isTest ? __('mail.monitoring_alert.settings_button_text') : __('mail.monitoring_alert.button_text') }}</a>
    </p>

    <p>{{ __('mail.monitoring_alert.manage_text') }}</p>

    <p>{{ __('mail.monitoring_alert.salutation') }}<br>
    {{ __('mail.general.team_name') }}</p>
@endsection
