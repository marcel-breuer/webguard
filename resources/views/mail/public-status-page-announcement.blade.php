<x-mail::message>
# {{ __('mail.public_status_page_announcement.title') }}

{{ __('mail.public_status_page_announcement.intro', ['statusPageName' => $statusPage->name]) }}

## {{ $announcement->title }}

{{ $announcement->message }}

<x-mail::button :url="$statusPageUrl">
{{ __('mail.public_status_page_announcement.button_text') }}
</x-mail::button>

[{{ __('mail.public_status_page_announcement.unsubscribe_text') }}]({{ $unsubscribeUrl }})

{{ __('mail.public_status_page_announcement.salutation') }},<br>
{{ config('app.name') }}
</x-mail::message>
