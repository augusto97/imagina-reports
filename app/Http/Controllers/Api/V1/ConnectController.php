<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Connect\ConnectRegistry;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Enums\DataSourceStatus;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-click "connect your account" flows (the alternative to the manual configSchema
 * form). `start` is authenticated and hands back the provider URL to send the client to;
 * `callback` is public — the provider (e.g. the client's WooCommerce store) posts the
 * granted credentials to it, tied back to the pending intent by a one-time nonce.
 */
final class ConnectController extends Controller
{
    /** How long a started connect intent stays valid before the nonce expires. */
    private const INTENT_TTL_MINUTES = 15;

    public function __construct(
        private readonly ConnectRegistry $registry,
        private readonly ConnectorRegistry $connectors,
    ) {}

    public function start(Request $request, Site $site, string $type, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $provider = $this->registry->for($type);
        abort_if($provider === null, 404, 'Esta fuente no admite conexión con un clic.');

        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->canAddDataSource($agency, null), 403, 'Has alcanzado el límite de fuentes de datos de tu plan. Mejora el plan para conectar más.');
        abort_unless($entitlements->allowsConnector($agency, $type), 403, 'Tu plan no incluye este conector. Mejora el plan para usarlo.');

        // Validate the provider's up-front fields (e.g. the WooCommerce store URL).
        $rules = [];
        foreach ($provider->startFields() as $field) {
            $rules["input.{$field->key}"] = $field->required ? ['required'] : ['nullable'];
        }
        $rules['return_url'] = ['nullable', 'string'];
        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);

        /** @var array<string, mixed> $input */
        $input = is_array($validated['input'] ?? null) ? $validated['input'] : [];

        $nonce = Str::random(48);
        $returnUrl = $this->safeReturnUrl(is_string($validated['return_url'] ?? null) ? $validated['return_url'] : null);

        Cache::put($this->intentKey($nonce), [
            'type' => $type,
            'site_id' => $site->id,
            'agency_id' => $agency->id,
            'input' => $input,
            'return_url' => $returnUrl,
        ], now()->addMinutes(self::INTENT_TTL_MINUTES));

        return response()->json([
            'redirect_url' => $provider->redirectUrl($input, $nonce, $this->callbackUrl($type), $returnUrl),
        ]);
    }

    /**
     * Public: the provider hands back the granted access here — WooCommerce POSTs the keys
     * server-to-server, an OAuth provider redirects the client's browser with a `code`. We
     * tie it to the pending intent by the one-time nonce, create/update the source, then
     * (OAuth) redirect the browser back to the app or (Woo) return JSON. No auth/tenant here.
     */
    public function callback(Request $request, string $type): JsonResponse|RedirectResponse
    {
        $provider = $this->registry->for($type);
        abort_if($provider === null, 404);

        $isBrowser = $provider->callbackIsBrowserRedirect();

        // The intent carries the return URL, so we can bounce the browser back even on denial.
        $intent = Cache::pull($this->intentKey($provider->nonceFromCallback($request)));
        $returnUrl = is_array($intent) && is_string($intent['return_url'] ?? null) ? $intent['return_url'] : $this->appUrl();

        if (! is_array($intent) || ($intent['type'] ?? null) !== $type) {
            return $this->fail($isBrowser, $returnUrl, 'La solicitud de conexión expiró o no es válida. Vuelve a intentarlo.');
        }

        $callback = $provider->parseCallback($request, $this->callbackUrl($type));
        if ($callback === null) {
            return $this->fail($isBrowser, $returnUrl, 'La conexión no se completó o no otorgó acceso de lectura.');
        }

        $agencyId = is_int($intent['agency_id'] ?? null) ? $intent['agency_id'] : null;
        $siteId = is_int($intent['site_id'] ?? null) ? $intent['site_id'] : null;
        if ($agencyId === null || $siteId === null) {
            return $this->fail($isBrowser, $returnUrl, 'La solicitud de conexión no es válida.');
        }

        /** @var array<string, mixed> $config */
        $config = is_array($intent['input'] ?? null) ? $intent['input'] : [];
        $config = array_merge($config, $callback->config);

        // No tenant context here (public route): scope by the intent's agency explicitly and
        // upsert by (agency, site, type) so re-connecting refreshes the same source.
        $source = DataSource::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => $agencyId, 'site_id' => $siteId, 'type' => $type],
            [
                'config' => $config,
                'credentials' => $callback->credentials,
                'status' => DataSourceStatus::Pending,
                'last_error' => null,
            ],
        );

        // Discover the pickable resources (GA4 properties, ad accounts…) now that we hold the
        // token, so the UI offers a dropdown. Auto-select when there's exactly one.
        $this->discoverResources($source);

        if ($isBrowser) {
            return redirect()->away($this->withQuery($returnUrl, ['connected' => $type, 'source' => (string) $source->id]));
        }

        return response()->json(['connected' => true, 'source_id' => $source->id]);
    }

    /**
     * Best-effort: list what the connected account can access and stash it on the source's
     * meta for the picker. If there's a single option, fill the config field directly and
     * skip the picker. Never throws — a failure just leaves the client to enter the ID.
     */
    private function discoverResources(DataSource $source): void
    {
        $connector = $this->connectors->for($source);

        if (! $connector instanceof ListsConnectableResources) {
            return;
        }

        try {
            $resources = $connector->connectableResources($source);
        } catch (\Throwable) {
            return;
        }

        if ($resources === null || $resources->options === []) {
            return;
        }

        if (count($resources->options) === 1) {
            $only = $resources->options[0];
            $source->forceFill([
                'config' => array_merge($source->config ?? [], [$resources->field => $only['value']]),
            ])->save();

            return;
        }

        $source->forceFill([
            'meta' => array_merge($source->meta ?? [], ['connect_options' => $resources->toArray()]),
        ])->save();
    }

    private function intentKey(string $nonce): string
    {
        return "connect:intent:{$nonce}";
    }

    private function callbackUrl(string $type): string
    {
        return route('api.connect.callback', ['type' => $type]);
    }

    private function appUrl(): string
    {
        $appUrl = config('app.url');

        return is_string($appUrl) ? $appUrl : '';
    }

    /** Fail: bounce an OAuth browser back to the app with an error flag, else return JSON 422. */
    private function fail(bool $isBrowser, string $returnUrl, string $message): JsonResponse|RedirectResponse
    {
        if ($isBrowser) {
            return redirect()->away($this->withQuery($returnUrl, ['connect_error' => $message]));
        }

        return response()->json(['message' => $message], 422);
    }

    /**
     * Append query params to a URL, inserting them before any #fragment so an SPA hash route
     * still parses them.
     *
     * @param  array<string, string>  $params
     */
    private function withQuery(string $url, array $params): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $separator = str_contains((string) $base, '?') ? '&' : '?';
        $withParams = $base.$separator.http_build_query($params);

        return $fragment === null ? $withParams : $withParams.'#'.$fragment;
    }

    /**
     * Only ever redirect the client back to our own app (prevents an open redirect via a
     * crafted return_url). Falls back to the configured app URL.
     */
    private function safeReturnUrl(?string $candidate): string
    {
        $appUrl = $this->appUrl();

        if ($candidate !== null && $appUrl !== '' && str_starts_with($candidate, $appUrl)) {
            return $candidate;
        }

        return $appUrl;
    }
}
