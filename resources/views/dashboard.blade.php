<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- White-label: the client never sees the platform name; the page sets the real title (site · period · agency). --}}
    <title>Panel</title>
    <meta name="robots" content="noindex, nofollow">
    @vite('resources/js/dashboard/main.tsx')
</head>
<body>
    {{-- Live client dashboard SPA (CLAUDE.md §11.2/Etapa D): re-resolves from the latest
         snapshots for the client-chosen date range, via the shared BlockRenderer. --}}
    <div id="ir-dashboard-root" data-token="{{ $token }}"></div>
</body>
</html>
