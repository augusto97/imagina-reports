<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Imagina Reports') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #fafafa;
            color: #18181b;
        }
        main { text-align: center; padding: 2rem; }
        h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 .5rem; }
        p { color: #71717a; margin: 0 0 1.5rem; }
        a { color: #18181b; text-decoration: none; font-weight: 500; border-bottom: 1px solid currentColor; }
    </style>
</head>
<body>
    <main>
        <h1>{{ config('app.name', 'Imagina Reports') }}</h1>
        <p>Branded, narrated client reports — unified across your whole stack.</p>
        <a href="{{ route('admin') }}">Open the admin panel →</a>
    </main>
</body>
</html>
