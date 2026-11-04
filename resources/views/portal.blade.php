<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- White-label: the client never sees the platform name; the page sets the real title (site · period · agency). --}}
    <title>Informe</title>
    <meta name="robots" content="noindex, nofollow">
    @vite('resources/js/portal/main.tsx')
</head>
<body>
    <div id="ir-portal-root" data-token="{{ $token ?? '' }}"></div>
</body>
</html>
