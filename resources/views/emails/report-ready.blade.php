@component('mail::message')
# Tu reporte está listo

{{ $agency?->name ?? config('app.name') }} ha preparado tu reporte del período.

@if($report->health_score !== null)
**Estado general:** {{ $report->health_score }}/100
@endif

@component('mail::button', ['url' => $portalUrl])
Ver reporte
@endcomponent

El PDF va adjunto a este correo.

Gracias,<br>
{{ $agency?->name ?? config('app.name') }}
@endcomponent
