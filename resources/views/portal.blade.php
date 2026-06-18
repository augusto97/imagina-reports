<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Imagina Reports') }}</title>
    @vite('resources/js/portal/main.tsx')
</head>
<body>
    <div id="ir-portal-root"></div>
</body>
</html>
