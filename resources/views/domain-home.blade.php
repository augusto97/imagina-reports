<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $agencyName }}</title>
    @include('connect.styles')
</head>
<body>
<main>
    <div class="card">
        <div class="head">
            <div class="brand"><span class="mark">{{ mb_strtoupper(mb_substr($agencyName, 0, 1)) }}</span><span>{{ $agencyName }}</span></div>
            <h1>Portal de informes</h1>
            <p>Aquí se publican los informes que {{ $agencyName }} prepara para sus clientes. Abre el enlace que recibiste por correo para ver el tuyo.</p>
        </div>
    </div>
</main>
</body>
</html>
