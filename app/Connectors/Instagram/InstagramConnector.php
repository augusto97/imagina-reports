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
use App\Connectors\Support\DescribesApiErrors;
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
    use DescribesApiErrors;
    use ParsesValues;

    /** Bump when moving to a newer Graph API version. */
    private const API_VERSION = 'v21.0';

    private const API_BASE = 'https://graph.facebook.com';

    /** Day-period account insights we sum over the report period (stable across API versions). */
    private const PERIOD_METRICS = ['reach', 'follower_count', 'profile_views', 'website_clicks'];

    /** Posts kept per period for the modelable dataset. The editor filters over these. */
    private const DATASET_ROW_LIMIT = 100;

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

    /**
     * The posts dataset's shape. Instagram has no campaigns — that's Meta Ads, a different
     * source — so the axis an agency actually models here is the post. Measures are
     * additive only, for the same reason as the ad connectors: the DatasetEngine sums them.
     *
     * @return array{dimensions: array<string, string>, measures: array<string, array{label: string, unit: string}>}
     */
    private function mediaDataset(): array
    {
        return [
            'dimensions' => [
                'media' => 'Publicación',
                'media_type' => 'Tipo (imagen / vídeo / carrusel)',
            ],
            'measures' => [
                'reach' => ['label' => 'Alcance', 'unit' => 'count'],
                'likes' => ['label' => 'Me gusta', 'unit' => 'count'],
                'comments' => ['label' => 'Comentarios', 'unit' => 'count'],
                'saved' => ['label' => 'Guardados', 'unit' => 'count'],
            ],
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        $dataset = $this->mediaDataset();
        $measures = [];
        foreach ($dataset['measures'] as $key => $measure) {
            $measures[] = ['key' => $key, 'label' => $measure['label'], 'unit' => $measure['unit']];
        }

        return new MetricCatalog(
            new MetricDefinition(
                'instagram.media',
                'Publicaciones (modelable)',
                MetricType::Dataset,
                null,
                array_keys($dataset['dimensions']),
                null,
                $measures,
                $dataset['dimensions'],
            ),
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
            $this->collectMediaDataset($client, $igUserId, $period, $metrics, $errors);

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
     * Post-level rows for the modelable dataset, so a block can show a chosen set of posts
     * (or only reels, or the top ones by reach) straight from the editor.
     *
     * One request, not one per post: Meta lets `insights` be expanded inline as a field, so
     * the whole bounded page comes back aggregated at the source (§3.3) rather than N+1.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectMediaDataset(PendingRequest $client, string $igUserId, Period $period, array &$metrics, array &$errors): void
    {
        try {
            $response = $client->get('/'.$igUserId.'/media', [
                'fields' => 'caption,media_type,permalink,timestamp,like_count,comments_count,insights.metric(reach,saved)',
                'since' => $period->start->timestamp,
                'until' => $period->end->timestamp,
                'limit' => self::DATASET_ROW_LIMIT,
            ]);

            if ($response->failed()) {
                $errors[] = 'media: HTTP '.$response->status();

                return;
            }

            $rows = [];
            foreach ($this->listOf($response->json('data')) as $media) {
                $insights = $this->insightValues($media);

                $rows[] = [
                    'media' => $this->mediaLabel($media),
                    'media_type' => $this->toStr(Arr::get($media, 'media_type')),
                    'reach' => $insights['reach'] ?? 0,
                    'likes' => $this->toInt(Arr::get($media, 'like_count')),
                    'comments' => $this->toInt(Arr::get($media, 'comments_count')),
                    'saved' => $insights['saved'] ?? 0,
                ];
            }

            $metrics['instagram.media'] = $rows;
        } catch (Throwable $e) {
            $errors[] = 'media: '.$e->getMessage();
        }
    }

    /**
     * Flatten the inline `insights` expansion into `name => value`. Absent for some media
     * (very old posts, or types Instagram doesn't report on), which is why callers default.
     *
     * @param  array<array-key, mixed>  $media
     * @return array<string, int>
     */
    private function insightValues(array $media): array
    {
        $values = [];

        foreach ($this->listOf(Arr::get($media, 'insights.data')) as $entry) {
            $name = $this->toStr(Arr::get($entry, 'name'));
            if ($name === '') {
                continue;
            }
            $values[$name] = $this->toInt(Arr::get($this->listOf(Arr::get($entry, 'values'))[0] ?? [], 'value'));
        }

        return $values;
    }

    /**
     * A readable label for a post: its caption's first line, falling back to the link.
     *
     * @param  array<array-key, mixed>  $media
     */
    private function mediaLabel(array $media): string
    {
        $caption = trim($this->toStr(Arr::get($media, 'caption')));

        if ($caption === '') {
            return $this->toStr(Arr::get($media, 'permalink'));
        }

        $firstLine = trim((string) strtok($caption, "\n"));

        return mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 77).'…' : $firstLine;
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
     * The Instagram business accounts this connected user can reach, discovered through the
     * Facebook Pages they can reach (`instagram_business_account` on each page).
     *
     * Crucially that means BOTH the pages assigned to them personally and the ones held in
     * their business portfolios — an agency keeps client assets in a Business portfolio and
     * is frequently an admin of the business without holding a role on each individual page,
     * so `/me/accounts` alone finds nothing at all for exactly the people this product is for.
     */
    public function connectableResources(DataSource $source): ?ConnectableResources
    {
        if ($this->accessToken($source) === '') {
            return null;
        }

        $client = $this->client($source);
        $pages = $this->reachablePages($client);

        if ($pages === null) {
            return null;
        }

        $options = [];
        $seen = [];
        foreach ($pages as $page) {
            $ig = $this->arrayOf(Arr::get($page, 'instagram_business_account'));
            $id = $this->toStr(Arr::get($ig, 'id'));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $username = $this->toStr(Arr::get($ig, 'username'));
            $pageName = $this->toStr(Arr::get($page, 'name'));
            $label = $username !== '' ? '@'.$username : $id;
            $options[] = ['value' => $id, 'label' => $pageName !== '' ? "{$label} — {$pageName}" : $label];
        }

        return new ConnectableResources(
            'ig_user_id',
            'Cuenta de Instagram',
            $options,
            'La conexión funcionó, pero no encontramos ninguna cuenta de Instagram utilizable. '
            .'Instagram solo expone datos a través de una página de Facebook, así que revisa que: '
            .'(1) la cuenta sea Business o Creator —no personal—, (2) esté vinculada a una página de Facebook, '
            .'y (3) al conectar hayas marcado esa página Y el portafolio comercial en la pantalla de permisos de Meta. '
            .'Corrige lo que falte y pulsa «Detectar cuentas». '
            .'Si la conoces, también puedes pegar el ID de la cuenta directamente en «Editar».',
        );
    }

    /**
     * Every Facebook page the token can see, from the personal edge and from each business
     * portfolio (owned + shared-with-us). Returns null only when the personal edge itself
     * fails — that means the token is bad, which is worth reporting; a business edge that
     * fails (typically because `business_management` hasn't been granted) is skipped so the
     * pages we DID find still get offered.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private function reachablePages(PendingRequest $client): ?array
    {
        $response = $client->get('/me/accounts', [
            'fields' => 'name,instagram_business_account{id,username}',
            'limit' => 200,
        ]);

        if ($response->failed()) {
            throw $this->discoveryFailed('Meta', $response);
        }

        $pages = $this->listOf($response->json('data'));

        foreach ($this->businessIds($client) as $businessId) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                $pages = array_merge($pages, $this->pagesFrom($client, '/'.$businessId.'/'.$edge));
            }
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function businessIds(PendingRequest $client): array
    {
        try {
            $response = $client->get('/me/businesses', ['fields' => 'id', 'limit' => 100]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $ids = [];
        foreach ($this->listOf($response->json('data')) as $business) {
            $id = $this->toStr(Arr::get($business, 'id'));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function pagesFrom(PendingRequest $client, string $path): array
    {
        try {
            $response = $client->get($path, [
                'fields' => 'name,instagram_business_account{id,username}',
                'limit' => 200,
            ]);
        } catch (Throwable) {
            return [];
        }

        return $response->failed() ? [] : $this->listOf($response->json('data'));
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
