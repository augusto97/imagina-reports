@php
    $colors = ['ga4' => '#e37400', 'gsc' => '#1a73e8', 'google_ads' => '#34a853', 'facebook_ads' => '#1877f2', 'instagram' => '#d62976', 'woocommerce' => '#7f54b3'];
    $initials = ['ga4' => 'GA', 'gsc' => 'SC', 'google_ads' => 'Ads', 'facebook_ads' => 'f', 'instagram' => 'IG', 'woocommerce' => 'Woo'];
    $pending = collect($items)->whereIn('status', ['missing', 'choose', 'error'])->count();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Conecta tus cuentas — {{ $agency->name }}</title>
    @include('connect.styles')
</head>
<body>
<main>
    @if ($connectedLabel !== null && $error === null)
        <div class="notice ok">Listo, {{ $connectedLabel }} quedó conectado. @if ($pending > 0) Te falta {{ $pending === 1 ? 'una fuente' : $pending.' fuentes' }}. @endif</div>
    @elseif ($saved)
        <div class="notice ok">Guardado. {{ $agency->name }} ya puede usar esa cuenta en tus reportes.</div>
    @endif
    @if ($error !== null)
        <div class="notice err">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="head">
            <div class="brand">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $agency->name }}">
                @else
                    <span class="mark">{{ mb_strtoupper(mb_substr($agency->name, 0, 1)) }}</span>
                    <span>{{ $agency->name }}</span>
                @endif
            </div>
            <h1>{{ $pending === 0 ? '¡Todo conectado, gracias!' : 'Conecta tus cuentas para tu reporte' }}</h1>
            <p>
                @if ($pending === 0)
                    {{ $agency->name }} ya tiene lo que necesita para preparar tus reportes. Puedes cerrar esta página.
                @else
                    {{ $agency->name }} prepara el reporte mensual de tu sitio. Entra con tu propia cuenta en cada servicio
                    y autoriza el acceso de <strong>solo lectura</strong>. No tienes que crear ninguna cuenta ni compartir contraseñas.
                @endif
            </p>
            <span class="site">🌐 {{ $site->name }}@if ($client && $client->name !== $site->name) · {{ $client->name }}@endif</span>
        </div>

        <div class="list">
            @foreach ($items as $item)
                <div class="row">
                    <div class="row-top">
                        <span class="icon" style="background: {{ $colors[$item['type']] ?? '#52525b' }}">{{ $initials[$item['type']] ?? mb_strtoupper(mb_substr($item['label'], 0, 2)) }}</span>
                        <div class="grow">
                            <div class="name">{{ $item['label'] }}</div>
                            @if ($item['status'] === 'connected')
                                <div class="state ok">● Conectado</div>
                            @elseif ($item['status'] === 'choose')
                                <div class="state warn">● Falta un paso: elige cuál usar</div>
                            @elseif ($item['status'] === 'error')
                                <div class="state warn">● Conectado, pero hay algo por revisar</div>
                            @else
                                <div class="state">Sin conectar</div>
                            @endif
                        </div>
                        @if ($item['fields'] === [] && $item['status'] !== 'choose')
                            <form method="POST" action="{{ route('connect.invite.start', ['token' => $token, 'type' => $item['type']]) }}">
                                @csrf
                                <button type="submit" class="{{ $item['status'] === 'connected' ? 'ghost' : '' }}">
                                    {{ $item['status'] === 'missing' ? $item['button'] : 'Conectar de nuevo' }}
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($item['status'] === 'choose' && $item['choice'] !== null)
                        <form method="POST" action="{{ route('connect.invite.select', ['token' => $token, 'source' => $item['source_id']]) }}">
                            @csrf
                            <div class="inline">
                                <select name="value" aria-label="{{ $item['choice']['label'] ?? 'Cuenta' }}" required>
                                    <option value="">{{ $item['choice']['label'] ?? 'Elige una cuenta' }}…</option>
                                    @foreach ($item['choice']['options'] as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <button type="submit">Usar esta</button>
                            </div>
                            <div class="help">Tu cuenta tiene acceso a varias. Elige la que corresponde a {{ $site->name }}.</div>
                        </form>
                    @elseif ($item['status'] === 'error' && $item['error'])
                        <div class="help">{{ $item['error'] }}</div>
                    @endif

                    @if ($item['fields'] !== [] && $item['status'] !== 'choose')
                        <form method="POST" action="{{ route('connect.invite.start', ['token' => $token, 'type' => $item['type']]) }}">
                            @csrf
                            @foreach ($item['fields'] as $field)
                                <div class="inline">
                                    <input name="input[{{ $field->key }}]" type="{{ $field->type->value === 'url' ? 'url' : 'text' }}"
                                        placeholder="{{ $field->label }}" aria-label="{{ $field->label }}" value="{{ old('input.'.$field->key) }}" @required($field->required)>
                                    <button type="submit" class="{{ $item['status'] === 'connected' ? 'ghost' : '' }}">{{ $item['status'] === 'missing' ? 'Conectar' : 'Conectar de nuevo' }}</button>
                                </div>
                                @error('input.'.$field->key)<div class="field-error">{{ $message }}</div>@enderror
                                @if ($field->help)<div class="help">{{ $field->help }}</div>@endif
                            @endforeach
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="foot">
            <ul>
                <li>Solo lectura: {{ $agency->name }} podrá ver las estadísticas, no cambiar nada.</li>
                <li>Tu contraseña nunca pasa por aquí: inicias sesión directamente en Google, Meta o tu tienda.</li>
                <li>Puedes retirar el acceso cuando quieras desde la configuración de seguridad de tu cuenta.</li>
            </ul>
            <p>Este enlace es personal y vale hasta el {{ $expires }}. No lo reenvíes.</p>
        </div>
    </div>
    @if ($showBranding)
        <p class="powered">Reportes con {{ config('app.name', 'Imagina Reports') }}</p>
    @endif
</main>
</body>
</html>
