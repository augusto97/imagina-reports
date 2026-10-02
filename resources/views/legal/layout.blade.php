<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name', 'Imagina Reports') }}</title>
    <style>
        :root { color-scheme: light dark; --fg: #18181b; --muted: #52525b; --line: #e4e4e7; --bg: #fafafa; --card: #fff; }
        @media (prefers-color-scheme: dark) { :root { --fg: #fafafa; --muted: #a1a1aa; --line: #27272a; --bg: #09090b; --card: #18181b; } }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--fg); line-height: 1.6; }
        header, main, footer { max-width: 46rem; margin: 0 auto; padding: 0 1rem; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1.5rem; padding-bottom: 1.5rem; }
        header a.brand { font-weight: 600; color: var(--fg); text-decoration: none; }
        nav a { color: var(--muted); text-decoration: none; font-size: .9rem; margin-left: 1rem; }
        main { background: var(--card); border: 1px solid var(--line); border-radius: .75rem; padding: 2rem 1.5rem; }
        h1 { font-size: 1.6rem; letter-spacing: -.01em; margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 2rem 0 .5rem; }
        p, li { color: var(--muted); }
        a { color: inherit; }
        .updated { font-size: .85rem; margin-bottom: 1.5rem; }
        code { font-size: .85em; background: var(--bg); border: 1px solid var(--line); border-radius: .25rem; padding: 0 .3rem; }
        footer { padding-top: 1.5rem; padding-bottom: 2rem; font-size: .85rem; color: var(--muted); }
        footer a { margin-right: 1rem; }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ url('/') }}">{{ config('app.name', 'Imagina Reports') }}</a>
        <nav>
            <a href="{{ route('legal.privacy') }}">Privacidad</a>
            <a href="{{ route('legal.terms') }}">Términos</a>
            <a href="{{ route('admin') }}">Entrar</a>
        </nav>
    </header>
    <main>
        @yield('content')
    </main>
    <footer>
        <a href="{{ route('legal.privacy') }}">Política de privacidad</a>
        <a href="{{ route('legal.terms') }}">Términos del servicio</a>
        @if ($contact !== '')<a href="mailto:{{ $contact }}">{{ $contact }}</a>@endif
    </footer>
</body>
</html>
