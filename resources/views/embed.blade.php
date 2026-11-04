<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- White-label: the client never sees the platform name; the page sets the real title (site · period · agency). --}}
    <title>Informe</title>
    <meta name="robots" content="noindex, nofollow">
    @vite('resources/js/report/main.tsx')
    <style>html, body { background: transparent; margin: 0; }</style>
</head>
<body>
    {{-- Same shared BlockRenderer SPA as the report page; embedded via iframe on
         allowlisted domains only (CSP frame-ancestors set by EmbedController). No
         print token, so the public API gate (password/private) still applies. --}}
    <div id="ir-report-root" data-token="{{ $token }}" data-print-token=""></div>
</body>
</html>
