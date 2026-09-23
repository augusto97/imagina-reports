<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No se pudo conectar — {{ config('app.name', 'Imagina Reports') }}</title>
    @include('oauth.layout-styles')
</head>
<body>
    <main>
        <div class="badge">!</div>
        <h1>No se pudo conectar el asistente</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            <a class="button" href="{{ url('/admin') }}">Ir al panel</a>
        </div>
    </main>
</body>
</html>
