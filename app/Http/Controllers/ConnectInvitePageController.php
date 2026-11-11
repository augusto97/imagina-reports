<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Connectors\Connect\ConnectIntents;
use App\Connectors\Connect\ConnectRegistry;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Jobs\NotifySourceEvent;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\DataSource;
use App\Models\Site;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\Entitlements;
use App\Support\CustomDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The public page behind a connection link: the client (who has no account in the app)
 * sees the agency's brand, the site and the sources the agency asked for, and connects
 * each one with their own Google/Meta/WooCommerce login. The token is the only key, so
 * everything is scoped to the invite's agency, site and types, and the page is never
 * cached, indexed or leaked through the Referer header to the providers.
 */
final class ConnectInvitePageController extends Controller
{
    private const HEADERS = [
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Cache-Control' => 'no-store, private',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(
        private readonly ConnectRegistry $registry,
        private readonly ConnectIntents $intents,
    ) {}

    public function show(Request $request, string $token, Entitlements $entitlements): Response
    {
        $invite = ConnectInvite::findActive($token);
        if ($invite === null || CustomDomain::blocks($request, $invite->agency_id)) {
            return $this->invalid();
        }

        [$agency, $site] = $this->context($invite);
        if ($agency === null || $site === null) {
            return $this->invalid();
        }

        $client = Client::query()->withoutGlobalScopes()->find($site->client_id);
        $sources = DataSource::query()->withoutGlobalScopes()
            ->where('agency_id', $invite->agency_id)
            ->where('site_id', $site->id)
            ->get()
            ->keyBy(static fn (DataSource $source): string => $source->type->value);

        $items = [];
        foreach ($invite->types as $type) {
            $provider = $this->registry->for($type);
            if ($provider === null) {
                continue;
            }
            $source = $sources->get($type);
            $options = $source?->meta['connect_options'] ?? null;

            $items[] = [
                'type' => $type,
                'label' => DataSourceType::tryFrom($type)?->label() ?? $type,
                'button' => $provider->label(),
                'fields' => $provider->startFields(),
                'status' => match (true) {
                    $source === null || $source->credentials === null => 'missing',
                    is_array($options) && is_array($options['options'] ?? null) && $options['options'] !== [] => 'choose',
                    $source->last_error !== null => 'error',
                    default => 'connected',
                },
                'source_id' => $source?->id,
                'error' => $source?->last_error,
                'choice' => is_array($options) ? $options : null,
            ];
        }

        $expires = $invite->expires_at->copy();
        $expires->locale('es');

        $connected = $request->query('connected');
        $error = $request->query('connect_error');

        return response()->view('connect.invite', [
            'token' => $token,
            'agency' => $agency,
            'logo' => $agency->logoUrl(),
            'accent' => $this->accent($agency->brand_color),
            'site' => $site,
            'client' => $client,
            'items' => $items,
            'expires' => $expires->translatedFormat('j \d\e F \d\e Y'),
            'connectedLabel' => is_string($connected) && $invite->allows($connected) ? (DataSourceType::tryFrom($connected)?->label() ?? $connected) : null,
            'error' => is_string($error) ? mb_substr($error, 0, 400) : null,
            'saved' => $request->query('saved') !== null,
            'showBranding' => ! $entitlements->hasFeature($agency, 'remove_branding'),
        ])->withHeaders(self::HEADERS);
    }

    public function start(Request $request, string $token, string $type, Entitlements $entitlements): SymfonyResponse
    {
        $invite = ConnectInvite::findActive($token);
        $provider = $this->registry->for($type);
        if ($invite === null || $provider === null || ! $invite->allows($type) || CustomDomain::blocks($request, $invite->agency_id)) {
            return $this->invalid();
        }

        [$agency, $site] = $this->context($invite);
        if ($agency === null || $site === null) {
            return $this->invalid();
        }

        $exists = DataSource::query()->withoutGlobalScopes()
            ->where('agency_id', $agency->id)->where('site_id', $site->id)->where('type', $type)->exists();
        if (! $entitlements->allowsConnector($agency, $type) || (! $exists && ! $entitlements->canAddDataSource($agency, null))) {
            return $this->back($token, ['connect_error' => 'Ahora mismo no se puede conectar esta fuente. Avísale a '.$agency->name.' para que lo revise.']);
        }

        $rules = [];
        foreach ($provider->startFields() as $field) {
            $rules["input.{$field->key}"] = $field->required ? ['required', 'string', 'max:500'] : ['nullable', 'string', 'max:500'];
        }
        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules, ['input.*.required' => 'Completa este dato para continuar.']);
        /** @var array<string, mixed> $input */
        $input = is_array($validated['input'] ?? null) ? $validated['input'] : [];

        $nonce = $this->intents->create($type, $site->id, $agency->id, $input, $invite->url(), $invite->id);
        $invite->forceFill(['last_used_at' => now()])->save();

        return redirect()->away($provider->redirectUrl($input, $nonce, route('api.connect.callback', ['type' => $type]), $invite->url()))
            ->withHeaders(self::HEADERS);
    }

    /** The client picks which of their properties/accounts this site uses. */
    public function select(Request $request, string $token, int $source): SymfonyResponse
    {
        $invite = ConnectInvite::findActive($token);
        if ($invite === null || CustomDomain::blocks($request, $invite->agency_id)) {
            return $this->invalid();
        }

        $dataSource = DataSource::query()->withoutGlobalScopes()
            ->where('agency_id', $invite->agency_id)->where('site_id', $invite->site_id)->find($source);
        if ($dataSource === null || ! $invite->allows($dataSource->type->value)) {
            return $this->invalid();
        }

        $choice = $dataSource->meta['connect_options'] ?? null;
        $field = is_array($choice) && is_string($choice['field'] ?? null) ? $choice['field'] : null;
        $values = is_array($choice) && is_array($choice['options'] ?? null)
            ? array_map(static fn (mixed $option): string => is_array($option) && is_scalar($option['value'] ?? null) ? (string) $option['value'] : '', $choice['options'])
            : [];
        $picked = $request->input('value');

        if ($field === null || ! is_string($picked) || ! in_array($picked, $values, true)) {
            return $this->back($token, ['connect_error' => 'Elige una de las opciones de la lista.']);
        }

        $meta = $dataSource->meta ?? [];
        unset($meta['connect_options']);
        $dataSource->forceFill([
            'config' => array_merge($dataSource->config ?? [], [$field => $picked]),
            'meta' => $meta,
            'status' => DataSourceStatus::Pending,
            'last_error' => null,
        ])->save();

        AuditLogger::record(
            AuditLogger::CONNECT_INVITE_USED,
            $dataSource,
            'El cliente eligió la cuenta de '.$dataSource->type->label().' desde su enlace de conexión.',
            ['invite_id' => $invite->id, 'type' => $dataSource->type->value],
            agencyId: $invite->agency_id,
        );

        NotifySourceEvent::connected($dataSource, null, byClient: true, again: $dataSource->last_synced_at !== null);

        return $this->back($token, ['saved' => '1']);
    }

    /**
     * @return array{0: Agency|null, 1: Site|null}
     */
    private function context(ConnectInvite $invite): array
    {
        $agency = Agency::query()->find($invite->agency_id);
        $site = Site::query()->withoutGlobalScopes()->where('agency_id', $invite->agency_id)->find($invite->site_id);

        return [$agency, $site];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function back(string $token, array $query): RedirectResponse
    {
        return redirect()->to(route('connect.invite', ['token' => $token]).'?'.http_build_query($query))->withHeaders(self::HEADERS);
    }

    private function invalid(): Response
    {
        return response()->view('connect.invalid', [], 404)->withHeaders(self::HEADERS);
    }

    /** The agency's brand colour, only when it is a plain hex colour (it lands in CSS). */
    private function accent(?string $color): string
    {
        return $color !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : '#18181b';
    }
}
