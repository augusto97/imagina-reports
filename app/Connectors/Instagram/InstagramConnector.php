<?php

declare(strict_types=1);

namespace App\Connectors\Instagram;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\Connect\ConnectableResources;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\ParsesValues;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Instagram connector (CLAUDE.md §9). Reads a business/creator account's audience and
 * activity from the Instagram Graph API (via the linked Facebook Page): current followers
 * and posts, plus reach, profile views, website clicks and net new followers over the
 * period. Insights aggregate server-side by design (§3.3) — the response is a handful of
 * daily points regardless of account size. Returns a normalized `instagram.*` metric bag.
 *
 * Auth is a Meta access token (same one the "Connect with Facebook" flow mints) + the
 * Instagram business account id. Catches its own errors (§7).
 */
final class InstagramConnector implements DataSourceConnector, ListsConnectableResources, ProvidesSetupGuide
{
    use ParsesValues;

    /** Bump when moving to a newer Graph API version. */
    private const API_VERSION = 'v21.0';

    private const API_BASE = 'https://graph.facebook.com';

    /** Day-period account insights we sum over the report period (stable across API versions). */
    private const PERIOD_METRICS = ['reach', 'follower_count', 'profile_views', 'website_clicks'];

    public function key(): string
    {
        return DataSourceType::Instagram->value;
    }

    public function label(): string
    {
        return DataSourceType::Instagram->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('ig_user_id', 'ID de la cuenta de Instagram', ConfigFieldType::Text, help: 'ID numérico de la cuenta de Instagram Business/Creator (se obtiene solo al conectar con Facebook).'),
            new ConfigField('access_token', 'Access token', ConfigFieldType::Password, secret: true, help: 'Token de acceso de Meta con permisos de Instagram (instagram_basic + instagram_manage_insights).'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('instagram.followers', 'Seguidores', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.media_count', 'Publicaciones', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.new_followers', 'Nuevos seguidores (periodo)', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.reach', 'Alcance', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.profile_views', 'Visitas al perfil', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.website_clicks', 'Clics al sitio web', MetricType::Scalar, 'count'),
            new MetricDefinition('instagram.reach_by_date', 'Alcance por día', MetricType::Series, 'count'),
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $igUserId = $this->igUserId($source);

        if ($igUserId === '') {
            return ConnectionResult::failure('Falta el ID de la cuenta de Instagram.');
        }

        try {
            $response = $this->client($source)->get('/'.$igUserId, ['fields' => 'username']);

            return $response->successful()
                ? ConnectionResult::success('Cuenta de Instagram accesible.')
                : ConnectionResult::failure('Instagram respondió HTTP '.$response->status().' '.$this->apiError($response->json()));
        } catch (Throwable $e) {
            return ConnectionResult::failure('No se pudo conectar con Instagram: '.$e->getMessage());
        }
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $igUserId = $this->igUserId($source);

        if ($igUserId === '') {
            return MetricSet::failed('Instagram: falta el ID de la cuenta.');
        }

        try {
            $client = $this->client($source);

            $account = $client->get('/'.$igUserId, ['fields' => 'followers_count,media_count']);

            if ($account->failed()) {
                return MetricSet::failed('Instagram request failed: HTTP '.$account->status().' '.$this->apiError($account->json()));
            }

            $metrics = [
                'instagram.followers' => $this->toInt($account->json('followers_count')),
                'instagram.media_count' => $this->toInt($account->json('media_count')),
            ];

            $errors = [];
            $this->collectInsights($client, $igUserId, $period, $metrics, $errors);

            return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode('; ', $errors));
        } catch (Throwable $e) {
            return MetricSet::failed('Instagram request error: '.$e->getMessage());
        }
    }

    /**
     * Sum the day-period insights over the report window and expose the reach series. A
     * single failing metric only degrades to partial — the follower/media scalars still show.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectInsights(PendingRequest $client, string $igUserId, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->get('/'.$igUserId.'/insights', [
                'metric' => implode(',', self::PERIOD_METRICS),
                'period' => 'day',
                'since' => $period->start->timestamp,
                'until' => $period->end->timestamp,
            ]);

            if ($response->failed()) {
                $errors[] = 'insights: HTTP '.$response->status();

                return;
            }

            $byName = [];
            foreach ($this->listOf($response->json('data')) as $entry) {
                $byName[$this->toStr(Arr::get($entry, 'name'))] = $this->listOf(Arr::get($entry, 'values'));
            }

            $metrics['instagram.reach'] = $this->sumValues($byName['reach'] ?? []);
            $metrics['instagram.new_followers'] = $this->sumValues($byName['follower_count'] ?? []);
            $metrics['instagram.profile_views'] = $this->sumValues($byName['profile_views'] ?? []);
            $metrics['instagram.website_clicks'] = $this->sumValues($byName['website_clicks'] ?? []);
            $metrics['instagram.reach_by_date'] = $this->series($byName['reach'] ?? []);
        } catch (Throwable $e) {
            $errors[] = 'insights: '.$e->getMessage();
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $values
     */
    private function sumValues(array $values): int
    {
        $total = 0;

        foreach ($values as $point) {
            $total += $this->toInt(Arr::get($point, 'value'));
        }

        return $total;
    }

    /**
     * @param  list<array<array-key, mixed>>  $values
     * @return list<array{date: string, value: int}>
     */
    private function series(array $values): array
    {
        return array_map(fn (array $point): array => [
            'date' => substr($this->toStr(Arr::get($point, 'end_time')), 0, 10),
            'value' => $this->toInt(Arr::get($point, 'value')),
        ], $values);
    }

    /**
     * The Instagram business accounts this connected user can access, discovered through
     * their Facebook Pages (`/me/accounts` → `instagram_business_account`). Lets the client
     * pick their account after the one-click connect. Best-effort — null on error.
     */
    public function connectableResources(DataSource $source): ?ConnectableResources
    {
        if ($this->accessToken($source) === '') {
            return null;
        }

        $response = $this->client($source)->get('/me/accounts', [
            'fields' => 'name,instagram_business_account{id,username}',
            'limit' => 200,
        ]);

        if ($response->failed()) {
            return null;
        }

        $options = [];
        foreach ($this->listOf($response->json('data')) as $page) {
            $ig = $this->arrayOf(Arr::get($page, 'instagram_business_account'));
            $id = $this->toStr(Arr::get($ig, 'id'));
            if ($id === '') {
                continue;
            }
            $username = $this->toStr(Arr::get($ig, 'username'));
            $pageName = $this->toStr(Arr::get($page, 'name'));
            $label = $username !== '' ? '@'.$username : $id;
            $options[] = ['value' => $id, 'label' => $pageName !== '' ? "{$label} — {$pageName}" : $label];
        }

        return new ConnectableResources('ig_user_id', 'Cuenta de Instagram', $options);
    }

    private function client(DataSource $source): PendingRequest
    {
        return Http::baseUrl(self::API_BASE.'/'.self::API_VERSION)
            ->withToken($this->accessToken($source))
            ->acceptJson()
            ->timeout(30);
    }

    private function accessToken(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->credentials ?? [], 'access_token'));
    }

    private function igUserId(DataSource $source): string
    {
        return $this->toStr(Arr::get($source->config ?? [], 'ig_user_id'));
    }

    private function apiError(mixed $json): string
    {
        $message = is_array($json) ? Arr::get($json, 'error.message') : null;

        return is_string($message) ? $message : '';
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Conecta una cuenta de Instagram Business o Creator. Lo más fácil es el botón «Conectar con Facebook»: '
            .'autoriza y elige la cuenta. La cuenta de Instagram debe estar vinculada a una página de Facebook.',
            [
                'Asegúrate de que la cuenta de Instagram es Business o Creator y está vinculada a una página de Facebook.',
                'Pulsa «Conectar con Facebook» y autoriza el acceso de solo lectura.',
                'Elige la cuenta de Instagram en el desplegable que aparece tras conectar.',
                'Alternativa manual: pega un token de Meta con instagram_basic + instagram_manage_insights y el ID de la cuenta de Instagram.',
            ],
            'https://developers.facebook.com/docs/instagram-api/guides/insights',
        );
    }
}
