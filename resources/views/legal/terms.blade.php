@extends('legal.layout')

@section('title', 'Términos del servicio')

@section('content')
    <h1>Términos del servicio</h1>
    <p class="updated">Última actualización: {{ $updated }}</p>

    <h2>1. El servicio</h2>
    <p>
        {{ config('app.name', 'Imagina Reports') }} permite a agencias web conectar fuentes de datos de sus clientes (analítica,
        Search Console, publicidad, seguridad, disponibilidad y tienda) y generar con ellas reportes y paneles con su propia marca.
    </p>

    <h2>2. Cuentas y responsabilidades</h2>
    <ul>
        <li>La agencia es responsable de las personas a las que da acceso y de mantener sus credenciales seguras.</li>
        <li>Solo debes conectar cuentas de las que seas titular o para las que tengas autorización de su titular.</li>
        <li>No puedes usar el servicio para fines ilícitos ni para acceder a datos de terceros sin permiso.</li>
    </ul>

    <h2>3. Acceso a fuentes de datos</h2>
    <p>
        Las conexiones con Google, Meta y otras plataformas son de solo lectura. Puedes desconectarlas en cualquier momento desde el
        panel o desde la configuración de seguridad de cada plataforma. El tratamiento de esos datos se describe en la
        <a href="{{ route('legal.privacy') }}">política de privacidad</a>.
    </p>

    <h2>4. Disponibilidad</h2>
    <p>
        Trabajamos para que el servicio esté siempre disponible, pero depende de las API de terceros: si una plataforma no responde,
        el reporte usa los últimos datos disponibles o indica que faltan datos.
    </p>

    <h2>5. Cambios</h2>
    <p>Podemos actualizar estos términos; la fecha de arriba indica la última versión.</p>

    <h2>6. Contacto</h2>
    <p>
        @if ($contact !== '')Escribe a <a href="mailto:{{ $contact }}">{{ $contact }}</a>.@else Contacta con la agencia que te dio acceso.@endif
    </p>
@endsection
