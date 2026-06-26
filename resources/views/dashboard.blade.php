<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Imagina Reports') }}</title>
    @vite('resources/js/dashboard/main.tsx')
</head>
<body>
    {{-- Live client dashboard SPA (CLAUDE.md §11.2/Etapa D): re-resolves from the latest
         snapshots for the client-chosen date range, via the shared BlockRenderer. --}}
    <div id="ir-dashboard-root" data-token="{{ $token }}"></div>
</body>
</html>
