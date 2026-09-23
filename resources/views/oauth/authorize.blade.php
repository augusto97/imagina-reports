<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Conectar asistente — {{ config('app.name', 'Imagina Reports') }}</title>
    @include('oauth.layout-styles')
</head>
<body>
    <main>
        <div class="badge">✦</div>
        <h1>«{{ $client->name }}» quiere conectarse a Imagina Reports</h1>
        <p>
            Podrá consultar y preparar cambios en la agencia con tu cuenta ({{ $user->email }}).
            Cada cambio te lo mostrará como propuesta antes de aplicarlo, y puedes desconectarlo cuando
            quieras en Ajustes → Asistentes IA.
        </p>

        <form method="POST" action="{{ url('/oauth/authorize') }}">
            @csrf
            <input type="hidden" name="response_type" value="code">
            <input type="hidden" name="client_id" value="{{ $client->client_id }}">
            <input type="hidden" name="redirect_uri" value="{{ $params['redirect_uri'] }}">
            <input type="hidden" name="code_challenge" value="{{ $params['code_challenge'] }}">
            <input type="hidden" name="code_challenge_method" value="S256">
            @if ($params['state'] !== null)
                <input type="hidden" name="state" value="{{ $params['state'] }}">
            @endif
            @if ($params['scope'] !== null)
                <input type="hidden" name="scope" value="{{ $params['scope'] }}">
            @endif

            <fieldset>
                <legend>Qué podrá hacer</legend>
                @foreach ($abilities as $ability)
                    <label>
                        <input type="checkbox" name="abilities[]" value="{{ $ability['value'] }}"
                            @checked($ability['checked']) @disabled($ability['locked'])>
                        <span>{{ $ability['label'] }}@if ($ability['locked']) <span class="muted">(siempre)</span>@endif</span>
                    </label>
                @endforeach
            </fieldset>

            <p class="muted">Solo conecta aplicaciones en las que confíes. Volverás a «{{ parse_url($params['redirect_uri'], PHP_URL_HOST) ?: $params['redirect_uri'] }}».</p>

            <div class="actions">
                <button type="submit" name="decision" value="deny">Cancelar</button>
                <button type="submit" name="decision" value="approve" class="primary">Autorizar</button>
            </div>
        </form>
    </main>
</body>
</html>
