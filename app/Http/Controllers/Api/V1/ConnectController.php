<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Connect\ConnectRegistry;
use App\Enums\DataSourceStatus;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
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

    public function __construct(private readonly ConnectRegistry $registry) {}

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

        Cache::put($this->intentKey($nonce), [
            'type' => $type,
            'site_id' => $site->id,
            'agency_id' => $agency->id,
            'input' => $input,
        ], now()->addMinutes(self::INTENT_TTL_MINUTES));

        $callbackUrl = route('api.connect.callback', ['type' => $type]);
        $returnUrl = $this->safeReturnUrl(is_string($validated['return_url'] ?? null) ? $validated['return_url'] : null);

        return response()->json([
            'redirect_url' => $provider->redirectUrl($input, $nonce, $callbackUrl, $returnUrl),
        ]);
    }

    /**
     * Public: the provider posts the granted credentials here. We tie it back to the pending
     * intent by the one-time nonce, then create/update the source with the stored config +
     * the granted credentials. No auth/tenant context — the intent carries the agency + site.
     */
    public function callback(Request $request, string $type): JsonResponse
    {
        $provider = $this->registry->for($type);
        abort_if($provider === null, 404);

        $callback = $provider->parseCallback($request);
        abort_if($callback === null, 422, 'La conexión no se completó o no otorgó acceso de lectura.');

        // Single-use: pull() reads and forgets so a nonce can't be replayed.
        $intent = Cache::pull($this->intentKey($callback->nonce));
        abort_if(! is_array($intent) || ($intent['type'] ?? null) !== $type, 422, 'La solicitud de conexión expiró o no es válida. Vuelve a intentarlo.');

        $agencyId = is_int($intent['agency_id'] ?? null) ? $intent['agency_id'] : null;
        $siteId = is_int($intent['site_id'] ?? null) ? $intent['site_id'] : null;
        abort_if($agencyId === null || $siteId === null, 422);

        /** @var array<string, mixed> $config */
        $config = is_array($intent['input'] ?? null) ? $intent['input'] : [];
        $config = array_merge($config, $callback->config);

        // No tenant context here (public route): scope by the intent's agency explicitly and
        // upsert by (agency, site, type) so re-connecting refreshes the same source.
        DataSource::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => $agencyId, 'site_id' => $siteId, 'type' => $type],
            [
                'config' => $config,
                'credentials' => $callback->credentials,
                'status' => DataSourceStatus::Pending,
                'last_error' => null,
            ],
        );

        return response()->json(['connected' => true]);
    }

    private function intentKey(string $nonce): string
    {
        return "connect:intent:{$nonce}";
    }

    /**
     * Only ever redirect the client back to our own app (prevents an open redirect via a
     * crafted return_url). Falls back to the configured app URL.
     */
    private function safeReturnUrl(?string $candidate): string
    {
        $appUrl = config('app.url');
        $appUrl = is_string($appUrl) ? $appUrl : '';

        if ($candidate !== null && $appUrl !== '' && str_starts_with($candidate, $appUrl)) {
            return $candidate;
        }

        return $appUrl;
    }
}
