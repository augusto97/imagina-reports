<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- White-label: the client never sees the platform name; the page sets the real title (site · period · agency). --}}
    <title>Informe</title>
    <meta name="robots" content="noindex, nofollow">
    @vite('resources/js/report/main.tsx')
</head>
<body>
    {{-- The shared BlockRenderer mounts here; Browsershot prints this same page (§10.7). --}}
    <div id="ir-report-root" data-token="{{ $token }}" data-print-token="{{ $printToken ?? '' }}"></div>
</body>
</html>
