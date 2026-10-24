<x-mail::message>
# {{ __('mail.public_status_page_incident_update.heading', ['statusPageName' => $statusPage->name]) }}

{{ $incidentUpdate->message }}

[{{ __('mail.public_status_page_incident_update.view_status_page') }}]({{ $statusPageUrl }})

{{ __('mail.public_status_page_incident_update.unsubscribe', ['url' => $unsubscribeUrl]) }}

{{ config('app.name') }}
</x-mail::message>
