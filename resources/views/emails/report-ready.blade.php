@component('mail::message')
# {{ __('report.ready_heading') }}

{{ __('report.ready_intro', ['agency' => $agency?->name ?? config('app.name')]) }}

@if($report->health_score !== null)
**{{ __('report.health_status') }}:** {{ $report->health_score }}/100
@endif

@component('mail::button', ['url' => $portalUrl])
{{ __('report.ready_cta') }}
@endcomponent

{{ __('report.ready_pdf_note') }}

{{ __('report.ready_thanks') }}<br>
{{ $agency?->name ?? config('app.name') }}
@endcomponent
