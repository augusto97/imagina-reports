@extends('legal.layout')

@section('title', 'Política de privacidad')

@section('content')
    <h1>Política de privacidad</h1>
    <p class="updated">Última actualización: {{ $updated }}</p>

    <p>
        {{ config('app.name', 'Imagina Reports') }} es una herramienta que usan agencias web para generar reportes de resultados
        para sus clientes. Esta política explica qué datos tratamos, para qué y cómo puedes revocar el acceso o pedir que se borren.
    </p>

    <h2>1. Qué datos tratamos</h2>
    <ul>
        <li><strong>Cuentas de usuario de la agencia:</strong> nombre, email y contraseña cifrada de las personas que entran al panel.</li>
        <li><strong>Datos de clientes y sitios</strong> que la agencia registra: nombre del cliente, email de contacto, URL del sitio.</li>
        <li>
            <strong>Datos de las fuentes que se conectan</strong>, siempre con acceso de <strong>solo lectura</strong> y en forma de
            métricas agregadas (totales, series por día y listas de los principales elementos), nunca registros individuales de visitantes.
        </li>
    </ul>

    <h2>2. Datos de Google</h2>
    <p>Cuando una persona pulsa «Conectar con Google», solicitamos únicamente estos permisos de lectura, según la fuente:</p>
    <ul>
        <li><code>analytics.readonly</code> (Google Analytics 4): sesiones, usuarios, conversiones, fuentes de tráfico y páginas principales de la propiedad elegida.</li>
        <li><code>webmasters.readonly</code> (Google Search Console): clics, impresiones, CTR, posición media y consultas y páginas principales del sitio elegido.</li>
        <li><code>adwords</code> (Google Ads): impresiones, clics, coste, conversiones y campañas principales de la cuenta elegida. Solo leemos informes; nunca creamos ni modificamos campañas.</li>
    </ul>
    <p>
        Usamos estos datos <strong>solo</strong> para mostrar y enviar a la agencia y a su cliente los reportes y paneles que ellos mismos
        configuran. No los vendemos, no los usamos para publicidad, no los compartimos con terceros salvo lo indicado en el punto 4 y no
        permitimos que ninguna persona los lea fuera de ese fin, salvo con tu permiso, por seguridad o por obligación legal.
    </p>
    <p>
        El uso y la transferencia a cualquier otra aplicación de la información recibida de las API de Google se ajustan a la
        <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener">Política de datos de usuario de los servicios de API de Google</a>,
        incluidos los requisitos de uso limitado (<em>Limited Use</em>).
    </p>

    <h2>3. Cómo protegemos los datos</h2>
    <ul>
        <li>Los tokens de acceso de Google, Meta y demás fuentes se guardan <strong>cifrados</strong> y nunca se muestran en el panel.</li>
        <li>Toda la comunicación usa HTTPS.</li>
        <li>Cada agencia solo ve sus propios datos: el aislamiento entre agencias se aplica en cada consulta.</li>
    </ul>

    <h2>4. Con quién compartimos datos</h2>
    <ul>
        <li><strong>La agencia y su cliente</strong>, que son quienes reciben los reportes.</li>
        <li>
            <strong>Proveedor de IA (Anthropic):</strong> si la agencia usa la redacción automática del resumen, enviamos las métricas
            agregadas del reporte para que se redacte el texto. Ese proveedor no usa los datos para entrenar sus modelos.
        </li>
        <li><strong>Proveedores de infraestructura</strong> (servidor y envío de email), solo para operar el servicio.</li>
    </ul>

    <h2>5. Conservación y borrado</h2>
    <ul>
        <li>Conservamos las métricas mientras la fuente siga conectada, o durante el periodo de retención que configure la agencia.</li>
        <li>Al eliminar una fuente en el panel se borran su token y todas las métricas guardadas de esa fuente.</li>
        <li>Puedes revocar el acceso de Google en cualquier momento desde <a href="https://myaccount.google.com/permissions" rel="noopener">myaccount.google.com/permissions</a>; a partir de ese momento no leemos más datos.</li>
        <li>Para pedir el borrado de tus datos, escríbenos{{ $contact !== '' ? ' a '.$contact : '' }}.</li>
    </ul>

    <h2>6. Contacto</h2>
    <p>
        Para cualquier duda sobre esta política o sobre tus datos{{ $contact !== '' ? ', escribe a' : ', contacta con la agencia que te envía los reportes' }}
        @if ($contact !== '')<a href="mailto:{{ $contact }}">{{ $contact }}</a>@endif.
    </p>
@endsection
