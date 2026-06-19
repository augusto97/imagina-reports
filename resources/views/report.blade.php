<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Imagina Reports') }}</title>
    @vite('resources/js/report/main.tsx')
</head>
<body>
    {{-- The shared BlockRenderer mounts here; Browsershot prints this same page (§10.7). --}}
    <div id="ir-report-root" data-token="{{ $token }}"></div>
</body>
</html>
