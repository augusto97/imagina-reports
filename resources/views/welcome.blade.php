@extends('legal.layout')

@section('title', 'Reportes para clientes de agencias web')

@section('content')
    <h1>{{ config('app.name', 'Imagina Reports') }}</h1>
    <p>
        Reportes mensuales con la marca de tu agencia que reúnen en un solo lugar lo que pasa en el sitio de cada cliente:
        visitas (Google Analytics), búsquedas (Search Console), publicidad (Google Ads, Meta), seguridad, disponibilidad,
        mantenimiento y ventas. Cada reporte se explica en lenguaje claro para que el cliente entienda en 30 segundos el
        trabajo que se hizo y su resultado.
    </p>

    <h2>Cómo funciona</h2>
    <ul>
        <li>La agencia conecta las fuentes de cada sitio con acceso de <strong>solo lectura</strong>.</li>
        <li>La app guarda métricas agregadas y arma el reporte con bloques y gráficos.</li>
        <li>El cliente lo recibe por email, en PDF y en un panel interactivo.</li>
    </ul>

    <h2>Tus datos</h2>
    <p>
        Solo leemos las métricas necesarias para los reportes y nunca modificamos tus cuentas. Consulta la
        <a href="{{ route('legal.privacy') }}">política de privacidad</a> y los <a href="{{ route('legal.terms') }}">términos del servicio</a>.
    </p>

    <p><a href="{{ route('admin') }}">Entrar al panel →</a></p>
@endsection
