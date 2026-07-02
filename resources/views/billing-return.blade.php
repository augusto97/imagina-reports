<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Imagina Reports') }} — Pago</title>
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
        main { text-align: center; padding: 2rem; max-width: 28rem; }
        .badge {
            width: 3rem; height: 3rem; margin: 0 auto 1.25rem;
            display: grid; place-items: center;
            border-radius: 9999px; background: #eef2ff; color: #4f46e5; font-size: 1.5rem;
        }
        h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 .5rem; }
        p { color: #71717a; margin: 0 0 1.5rem; line-height: 1.5; }
        a {
            display: inline-block; padding: .6rem 1.1rem; border-radius: .5rem;
            background: #18181b; color: #fafafa; text-decoration: none; font-weight: 500;
        }
    </style>
</head>
<body>
    <main>
        @if (request('status') === 'cancelled')
            <div class="badge" style="background:#fef2f2;color:#dc2626;">✕</div>
            <h1>Pago cancelado</h1>
            <p>
                No se realizó ningún cobro. Puedes volver al panel e intentarlo de nuevo cuando quieras
                desde «Plan y facturación».
            </p>
        @else
            <div class="badge">✓</div>
            <h1>Gracias, estamos confirmando tu pago</h1>
            <p>
                Tu suscripción se activará automáticamente en cuanto {{ config('app.name', 'Imagina Reports') }}
                reciba la confirmación del proveedor de pago (normalmente unos segundos). Puedes volver al panel
                y revisar el estado en «Plan y facturación».
            </p>
        @endif
        <a href="{{ route('admin') }}#/settings">Volver al panel →</a>
    </main>
</body>
</html>
