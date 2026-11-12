<?php

declare(strict_types=1);

namespace App\Connectors\GoogleBusiness;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\Connect\ConnectableResources;
use App\Connectors\Connect\OAuth\GoogleOAuthClient;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\Exceptions\DiscoveryFailed;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\DescribesApiErrors;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Business Profile (the business's listing on Google Search and Maps): how many people
 * saw it, what they did (called, asked for directions, visited the website, wrote, booked), the
 * searches that found it, and its reviews. Connected with the client's Google account (OAuth,
 * `business.manage`); the listing is picked from a dropdown after connecting.
 *
 * APIs (all aggregated by Google, CLAUDE.md §3.3):
 * - Business Profile Performance API — `locations/{id}:fetchMultiDailyMetricsTimeSeries` (daily
 *   counts; a value is absent when it's zero) and `locations/{id}/searchkeywords/impressions/monthly`.
 * - My Business API v4 — `accounts/{a}/locations/{l}/reviews` (average rating, total, the reviews).
 * - Account Management + Business Information APIs — to list the listings the account manages.
 *
 * Google only serves these to a Cloud project whose access request was approved (quota 0 until
 * then): the errors say so in plain words instead of a bare HTTP code.
 */
final class GoogleBusinessConnector implements DataSourceConnector, ListsConnectableResources, ProvidesSetupGuide
{
    use DescribesApiErrors;

    private const PERFORMANCE = 'https://businessprofileperformance.googleapis.com/v1';

    private const REVIEWS = 'https://mybusiness.googleapis.com/v4';

    private const ACCOUNTS = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    private const INFORMATION = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    /** Daily metrics read, by what they count. */
    private const VIEWS_SEARCH = ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'];

    private const VIEWS_MAPS = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS'];

    /** Customer actions on the listing, with their plain names. @var array<string, string> */
    private const ACTIONS = [
        'CALL_CLICKS' => 'Llamadas',
        'BUSINESS_DIRECTION_REQUESTS' => 'Cómo llegar',
        'WEBSITE_CLICKS' => 'Clics a la web',
        'MESSAGING_CLICKS' => 'Mensajes',
        'BUSINESS_BOOKINGS' => 'Reservas',
    ];

    /** Google keeps about 18 months of performance data. */
    private const HISTORY_DAYS = 540;

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** @var array<string, string> */
    private array $tokenCache = [];

    public function key(): string
    {
        return DataSourceType::GoogleBusiness->value;
    }

    public function label(): string
    {
        return DataSourceType::GoogleBusiness->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('location', 'Ficha del negocio', ConfigFieldType::Text, help: 'Se elige de la lista después de «Conectar con Google». Formato: accounts/…/locations/…'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('google_business.views_total', 'Veces que vieron tu ficha', MetricType::Scalar, 'count', description: 'Veces que tu negocio apareció en Google (Búsqueda y Maps). Cada persona cuenta una vez al día.'),
            new MetricDefinition('google_business.views_search', 'Vistas desde la Búsqueda de Google', MetricType::Scalar, 'count', description: 'Veces que tu ficha apareció en los resultados de búsqueda de Google, en celular y computador.'),
            new MetricDefinition('google_business.views_maps', 'Vistas desde Google Maps', MetricType::Scalar, 'count', description: 'Veces que tu ficha apareció en Google Maps, en celular y computador.'),
            new MetricDefinition('google_business.actions_total', 'Acciones de clientes en tu ficha', MetricType::Scalar, 'count', description: 'Llamadas, solicitudes de cómo llegar, clics a la web, mensajes y reservas sumados.'),
            new MetricDefinition('google_business.calls', 'Llamadas desde tu ficha', MetricType::Scalar, 'count', description: 'Veces que alguien tocó el botón de llamar en tu ficha de Google.'),
            new MetricDefinition('google_business.directions', 'Personas que pidieron cómo llegar', MetricType::Scalar, 'count', description: 'Veces que alguien pidió la ruta hasta tu negocio desde Google.'),
            new MetricDefinition('google_business.website_clicks', 'Clics a tu web desde la ficha', MetricType::Scalar, 'count', description: 'Veces que alguien entró a tu web desde tu ficha de Google.'),
            new MetricDefinition('google_business.messages', 'Clics para escribirte', MetricType::Scalar, 'count', description: 'Veces que alguien tocó el botón de mensajes (por ejemplo WhatsApp) en tu ficha.'),
            new MetricDefinition('google_business.bookings', 'Reservas desde tu ficha', MetricType::Scalar, 'count', description: 'Reservas hechas desde tu ficha con «Reservar con Google».'),
            new MetricDefinition('google_business.rating', 'Calificación media', MetricType::Scalar, 'unit', description: 'Promedio de estrellas de todas tus reseñas en Google (de 1 a 5).'),
            new MetricDefinition('google_business.reviews_total', 'Reseñas en total', MetricType::Scalar, 'count', description: 'Todas las reseñas que tiene tu negocio en Google.'),
            new MetricDefinition('google_business.reviews_new', 'Reseñas nuevas', MetricType::Scalar, 'count', description: 'Reseñas que dejaron tus clientes en Google durante el periodo.'),
            new MetricDefinition('google_business.reviews_unanswered', 'Reseñas sin responder', MetricType::Scalar, 'count', description: 'Reseñas del periodo que todavía no tienen respuesta del negocio.'),
            new MetricDefinition('google_business.views_by_date', 'Vistas de tu ficha por día', MetricType::Series, 'count', description: 'Veces que vieron tu ficha en Google y Maps cada día del periodo.'),
            new MetricDefinition('google_business.actions_by_date', 'Acciones de clientes por día', MetricType::Series, 'count', description: 'Llamadas, rutas, clics a la web, mensajes y reservas de cada día del periodo.'),
            new MetricDefinition('google_business.calls_by_date', 'Llamadas por día', MetricType::Series, 'count', description: 'Llamadas desde tu ficha de Google cada día del periodo.'),
            new MetricDefinition('google_business.actions_breakdown', 'Qué hicieron en tu ficha', MetricType::Table, description: 'Las acciones de los clientes en tu ficha (llamadas, rutas, clics a la web…), de más a menos.'),
            new MetricDefinition('google_business.search_keywords', 'Búsquedas con las que te encuentran', MetricType::Table, description: 'Lo que la gente escribió en Google o Maps antes de ver tu ficha, con cuántas veces apareciste (las de pocas búsquedas no se muestran).'),
            new MetricDefinition('google_business.recent_reviews', 'Últimas reseñas', MetricType::Table, description: 'Las reseñas del periodo con sus estrellas, el comentario y si ya se respondieron.'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'Se conecta con la cuenta de Google que administra la ficha del negocio, sin contraseñas.',
            [
                'Pulsa «Conectar con Google» (o envíale al cliente un enlace de conexión) y entra con una cuenta que sea propietaria o administradora de la ficha.',
                'Elige la ficha del negocio en la lista.',
                'Si aparece un error de acceso: Google exige que la plataforma tenga aprobado el acceso a las APIs de Perfil de Empresa. Lo solicita una sola vez el administrador de la plataforma.',
            ],
            'https://developers.google.com/my-business/content/prereqs',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $ids = $this->ids($source);
        if ($ids === null) {
            return ConnectionResult::failure('Falta elegir la ficha del negocio.');
        }

        $token = $this->token($source);
        if ($token === '') {
            return ConnectionResult::failure('Google no aceptó el permiso guardado (caducado o revocado). Vuelve a conectar la cuenta.');
        }

        $response = Http::withToken($token)->acceptJson()->get(self::INFORMATION.'/'.$ids['location'], ['readMask' => 'title']);
        if ($response->failed()) {
            return ConnectionResult::failure($this->explain($response));
        }

        $title = $response->json('title');

        return ConnectionResult::success(is_string($title) && $title !== '' ? "Ficha encontrada: {$title}." : 'Ficha encontrada.');
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $ids = $this->ids($source);
        if ($ids === null) {
            return MetricSet::failed('Falta elegir la ficha del negocio.');
        }

        $token = $this->token($source);
        if ($token === '') {
            return MetricSet::failed('Google no aceptó el permiso guardado (caducado o revocado). Vuelve a conectar la cuenta.');
        }

        $wants = static fn (string $key): bool => $requestedMetrics === [] || in_array($key, $requestedMetrics, true);
        $wantsAny = static function (array $keys) use ($wants): bool {
            foreach ($keys as $key) {
                if (is_string($key) && $wants($key)) {
                    return true;
                }
            }

            return false;
        };

        $metrics = [];
        $errors = [];

        if ($wantsAny(['google_business.views_total', 'google_business.views_search', 'google_business.views_maps', 'google_business.actions_total', 'google_business.calls', 'google_business.directions', 'google_business.website_clicks', 'google_business.messages', 'google_business.bookings', 'google_business.views_by_date', 'google_business.actions_by_date', 'google_business.calls_by_date', 'google_business.actions_breakdown'])) {
            $this->collectPerformance($token, $ids['location'], $period, $metrics, $errors);
        }

        if ($wants('google_business.search_keywords')) {
            $this->collectKeywords($token, $ids['location'], $period, $metrics, $errors);
        }

        if ($wantsAny(['google_business.rating', 'google_business.reviews_total', 'google_business.reviews_new', 'google_business.reviews_unanswered', 'google_business.recent_reviews'])) {
            if ($ids['account'] === null) {
                $errors[] = 'Reseñas: vuelve a elegir la ficha para poder leerlas.';
            } else {
                $this->collectReviews($token, $ids['account'].'/'.$ids['location'], $period, $metrics, $errors);
            }
        }

        $metrics = array_filter($metrics, static fn (mixed $value, string $key): bool => $value !== null && $wants($key), ARRAY_FILTER_USE_BOTH);

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode(' ', array_unique($errors)));
        }

        return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode(' ', array_unique($errors)));
    }

    /**
     * The listings this Google account manages, so the agency (or the client, from their link)
     * picks theirs from a dropdown.
     */
    public function connectableResources(DataSource $source): ?ConnectableResources
    {
        if ($this->refreshToken($source) === '') {
            return null;
        }

        $token = $this->token($source);
        if ($token === '') {
            throw DiscoveryFailed::because('Google no aceptó el permiso guardado (token caducado o revocado). Reconecta la fuente.');
        }

        $options = [];
        $accountsPage = null;
        for ($page = 0; $page < 5; $page++) {
            $response = Http::withToken($token)->acceptJson()->get(self::ACCOUNTS, array_filter(['pageSize' => 20, 'pageToken' => $accountsPage]));
            if ($response->failed()) {
                throw DiscoveryFailed::because($this->explain($response));
            }

            foreach ($this->list($response->json('accounts')) as $account) {
                $accountName = $account['name'] ?? null;
                if (is_string($accountName) && str_starts_with($accountName, 'accounts/')) {
                    $options = [...$options, ...$this->locationsOf($token, $accountName)];
                }
            }

            $accountsPage = $response->json('nextPageToken');
            if (! is_string($accountsPage) || $accountsPage === '') {
                break;
            }
        }

        return new ConnectableResources(
            'location',
            'Ficha del negocio',
            $options,
            'La conexión funcionó, pero esta cuenta de Google no administra ninguna ficha de Perfil de Empresa. '
            .'Pide que te agreguen como administrador de la ficha (o conéctate con la cuenta dueña) y pulsa «Detectar cuentas».',
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function locationsOf(string $token, string $account): array
    {
        $options = [];
        $next = null;
        for ($page = 0; $page < 3; $page++) {
            $response = Http::withToken($token)->acceptJson()->get(self::INFORMATION.'/'.$account.'/locations', array_filter([
                'pageSize' => 100,
                'readMask' => 'name,title,storefrontAddress',
                'pageToken' => $next,
            ]));
            if ($response->failed()) {
                throw DiscoveryFailed::because($this->explain($response));
            }

            foreach ($this->list($response->json('locations')) as $location) {
                $name = $location['name'] ?? null;
                if (! is_string($name) || ! str_starts_with($name, 'locations/')) {
                    continue;
                }
                $title = is_string($location['title'] ?? null) && $location['title'] !== '' ? $location['title'] : $name;
                $city = Arr::get($location, 'storefrontAddress.locality');
                $options[] = ['value' => $account.'/'.$name, 'label' => is_string($city) && $city !== '' ? "{$title} · {$city}" : $title];
            }

            $next = $response->json('nextPageToken');
            if (! is_string($next) || $next === '') {
                break;
            }
        }

        return $options;
    }

    /**
     * Daily counts → totals, per-day series and the actions breakdown. Google leaves a day's
     * value out when it is zero.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectPerformance(string $token, string $location, Period $period, array &$metrics, array &$errors): void
    {
        [$start, $end] = $this->dailyRange($period);
        if ($start === null || $end === null) {
            return;
        }

        $names = [...self::VIEWS_SEARCH, ...self::VIEWS_MAPS, ...array_keys(self::ACTIONS)];
        $query = implode('&', array_map(static fn (string $metric): string => 'dailyMetrics='.$metric, $names))
            .'&dailyRange.start_date.year='.$start->year.'&dailyRange.start_date.month='.$start->month.'&dailyRange.start_date.day='.$start->day
            .'&dailyRange.end_date.year='.$end->year.'&dailyRange.end_date.month='.$end->month.'&dailyRange.end_date.day='.$end->day;

        $response = Http::withToken($token)->acceptJson()->get(self::PERFORMANCE.'/'.$location.':fetchMultiDailyMetricsTimeSeries?'.$query);
        if ($response->failed()) {
            $errors[] = $this->explain($response);

            return;
        }

        /** @var array<string, array<string, int>> $byMetric metric → date → count */
        $byMetric = [];
        foreach ($this->list($response->json('multiDailyMetricTimeSeries')) as $group) {
            foreach ($this->list($group['dailyMetricTimeSeries'] ?? null) as $series) {
                $metric = $series['dailyMetric'] ?? null;
                if (! is_string($metric)) {
                    continue;
                }
                foreach ($this->list(Arr::get($series, 'timeSeries.datedValues')) as $point) {
                    $date = $this->date($point['date'] ?? null);
                    if ($date === null) {
                        continue;
                    }
                    $value = $point['value'] ?? '0';
                    $byMetric[$metric][$date] = ($byMetric[$metric][$date] ?? 0) + (is_numeric($value) ? (int) $value : 0);
                }
            }
        }

        $sum = static function (array $keys) use ($byMetric): int {
            $total = 0;
            foreach ($keys as $key) {
                $total += is_string($key) ? array_sum($byMetric[$key] ?? []) : 0;
            }

            return $total;
        };

        $metrics['google_business.views_search'] = $sum(self::VIEWS_SEARCH);
        $metrics['google_business.views_maps'] = $sum(self::VIEWS_MAPS);
        $metrics['google_business.views_total'] = $metrics['google_business.views_search'] + $metrics['google_business.views_maps'];
        $metrics['google_business.calls'] = $sum(['CALL_CLICKS']);
        $metrics['google_business.directions'] = $sum(['BUSINESS_DIRECTION_REQUESTS']);
        $metrics['google_business.website_clicks'] = $sum(['WEBSITE_CLICKS']);
        $metrics['google_business.messages'] = $sum(['MESSAGING_CLICKS']);
        $metrics['google_business.bookings'] = $sum(['BUSINESS_BOOKINGS']);
        $metrics['google_business.actions_total'] = $sum(array_keys(self::ACTIONS));

        $metrics['google_business.views_by_date'] = $this->series($byMetric, [...self::VIEWS_SEARCH, ...self::VIEWS_MAPS], $start, $end);
        $metrics['google_business.actions_by_date'] = $this->series($byMetric, array_keys(self::ACTIONS), $start, $end);
        $metrics['google_business.calls_by_date'] = $this->series($byMetric, ['CALL_CLICKS'], $start, $end);

        $breakdown = [];
        foreach (self::ACTIONS as $key => $label) {
            $count = $sum([$key]);
            if ($count > 0) {
                $breakdown[] = ['label' => $label, 'value' => $count];
            }
        }
        usort($breakdown, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $metrics['google_business.actions_breakdown'] = $breakdown;
    }

    /**
     * The searches that showed the listing, for the months the period covers. Google reports
     * small counts only as «fewer than N»: those are left out.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectKeywords(string $token, string $location, Period $period, array &$metrics, array &$errors): void
    {
        $today = CarbonImmutable::today();
        $start = $period->start;
        $end = $period->end->greaterThan($today) ? $today : $period->end;
        if ($start->greaterThan($end)) {
            return;
        }

        $counts = [];
        $next = null;
        for ($page = 0; $page < 3; $page++) {
            $query = 'monthlyRange.start_month.year='.$start->year.'&monthlyRange.start_month.month='.$start->month
                .'&monthlyRange.end_month.year='.$end->year.'&monthlyRange.end_month.month='.$end->month.'&pageSize=100'
                .($next !== null ? '&pageToken='.rawurlencode($next) : '');
            $response = Http::withToken($token)->acceptJson()->get(self::PERFORMANCE.'/'.$location.'/searchkeywords/impressions/monthly?'.$query);
            if ($response->failed()) {
                $errors[] = $this->explain($response);

                return;
            }

            foreach ($this->list($response->json('searchKeywordsCounts')) as $row) {
                $keyword = $row['searchKeyword'] ?? null;
                $value = Arr::get($row, 'insightsValue.value');
                if (is_string($keyword) && $keyword !== '' && is_numeric($value)) {
                    $counts[$keyword] = ($counts[$keyword] ?? 0) + (int) $value;
                }
            }

            $pageToken = $response->json('nextPageToken');
            $next = is_string($pageToken) && $pageToken !== '' ? $pageToken : null;
            if ($next === null) {
                break;
            }
        }

        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, 15, true) as $keyword => $value) {
            $rows[] = ['label' => (string) $keyword, 'value' => $value];
        }
        $metrics['google_business.search_keywords'] = $rows;
    }

    /**
     * Rating and total from Google; new and unanswered reviews counted over the period.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $errors
     */
    private function collectReviews(string $token, string $parent, Period $period, array &$metrics, array &$errors): void
    {
        $inPeriod = [];
        $next = null;
        for ($page = 0; $page < 4; $page++) {
            $response = Http::withToken($token)->acceptJson()->get(self::REVIEWS.'/'.$parent.'/reviews', array_filter([
                'pageSize' => 50,
                'orderBy' => 'updateTime desc',
                'pageToken' => $next,
            ]));
            if ($response->failed()) {
                $errors[] = 'Reseñas: '.$this->explain($response);

                return;
            }

            if ($page === 0) {
                $average = $response->json('averageRating');
                $total = $response->json('totalReviewCount');
                $metrics['google_business.rating'] = is_numeric($average) ? round((float) $average, 1) : null;
                $metrics['google_business.reviews_total'] = is_numeric($total) ? (int) $total : 0;
            }

            $olderSeen = false;
            foreach ($this->list($response->json('reviews')) as $review) {
                $created = $review['createTime'] ?? null;
                if (! is_string($created)) {
                    continue;
                }
                $when = CarbonImmutable::parse($created);
                if ($when->lessThan($period->start)) {
                    $olderSeen = true;

                    continue;
                }
                if ($when->greaterThan($period->end->endOfDay())) {
                    continue;
                }
                $inPeriod[] = $review;
            }

            $nextToken = $response->json('nextPageToken');
            $next = is_string($nextToken) && $nextToken !== '' ? $nextToken : null;
            // Newest first: once older reviews show up and there are no more pages worth reading, stop.
            if ($next === null || ($olderSeen && $page >= 1)) {
                break;
            }
        }

        $metrics['google_business.reviews_new'] = count($inPeriod);
        $metrics['google_business.reviews_unanswered'] = count(array_filter($inPeriod, static fn (array $review): bool => ! is_array($review['reviewReply'] ?? null)));

        $created = static fn (array $review): string => is_string($review['createTime'] ?? null) ? $review['createTime'] : '';
        usort($inPeriod, static fn (array $a, array $b): int => strcmp($created($b), $created($a)));
        $metrics['google_business.recent_reviews'] = array_map(static function (array $review) use ($created): array {
            $anonymous = Arr::get($review, 'reviewer.isAnonymous') === true;
            $author = Arr::get($review, 'reviewer.displayName');
            $rating = $review['starRating'] ?? null;
            $stars = is_string($rating) ? (self::STARS[$rating] ?? null) : null;
            $comment = $review['comment'] ?? '';

            return [
                'Fecha' => CarbonImmutable::parse($created($review) !== '' ? $created($review) : 'now')->format('d/m/Y'),
                'Autor' => ! $anonymous && is_string($author) && $author !== '' ? $author : 'Anónimo',
                'Estrellas' => $stars,
                'Comentario' => is_string($comment) && $comment !== '' ? Str::limit(trim($comment), 180) : '(sin comentario)',
                'Respondida' => is_array($review['reviewReply'] ?? null) ? 'Sí' : 'No',
            ];
        }, array_slice($inPeriod, 0, 10));
    }

    /**
     * One point per day of the range (zeros included, so the chart doesn't skip days).
     *
     * @param  array<string, array<string, int>>  $byMetric
     * @param  list<string>  $keys
     * @return list<array{date: string, value: int}>
     */
    private function series(array $byMetric, array $keys, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $points = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $value = 0;
            foreach ($keys as $key) {
                $value += $byMetric[$key][$date] ?? 0;
            }
            $points[] = ['date' => $date, 'value' => $value];
        }

        return $points;
    }

    /**
     * The days Google can answer for: not in the future, not older than its history.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function dailyRange(Period $period): array
    {
        $today = CarbonImmutable::today();
        $oldest = $today->subDays(self::HISTORY_DAYS);
        $start = $period->start->startOfDay()->lessThan($oldest) ? $oldest : $period->start->startOfDay();
        $end = $period->end->startOfDay()->greaterThan($today) ? $today : $period->end->startOfDay();

        return $start->greaterThan($end) ? [null, null] : [$start, $end];
    }

    private function date(mixed $date): ?string
    {
        if (! is_array($date) || ! is_numeric($date['year'] ?? null) || ! is_numeric($date['month'] ?? null) || ! is_numeric($date['day'] ?? null)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day']);
    }

    /**
     * Google's answer in words a person can act on, including the two platform-level cases
     * (APIs not enabled, access not approved yet).
     */
    private function explain(Response $response): string
    {
        $detail = $this->providerError($response->json());
        $status = $response->status();

        if ($status === 429 || str_contains($detail, 'Quota exceeded')) {
            return 'Google todavía no da acceso a la API de Perfil de Empresa a esta plataforma (cuota 0 hasta que aprueba la solicitud de acceso). El administrador de la plataforma debe pedirlo una vez a Google.';
        }
        if ($status === 403 && (str_contains($detail, 'has not been used') || str_contains($detail, 'is disabled'))) {
            return 'Las APIs de Perfil de Empresa no están activadas en el proyecto de Google de la plataforma. El administrador de la plataforma debe activarlas en Google Cloud.';
        }
        if ($status === 403 || $status === 404) {
            return 'Esta cuenta de Google no tiene acceso a la ficha elegida (debe ser propietaria o administradora). '.($detail !== '' ? 'Detalle: '.$detail : '');
        }
        if ($status === 401) {
            return 'Google no aceptó el permiso guardado (caducado o revocado). Vuelve a conectar la cuenta.';
        }

        return 'Perfil de Empresa respondió HTTP '.$status.($detail !== '' ? ': '.$detail : '.');
    }

    /**
     * «accounts/1/locations/2» → its parts. A bare «locations/2» still reads performance (but
     * not reviews, which live under the account).
     *
     * @return array{account: string|null, location: string}|null
     */
    private function ids(DataSource $source): ?array
    {
        $raw = Arr::get($source->config ?? [], 'location');
        if (! is_string($raw)) {
            return null;
        }

        if (preg_match('#^(accounts/[^/]+)/(locations/[^/]+)$#', trim($raw), $match) === 1) {
            return ['account' => $match[1], 'location' => $match[2]];
        }
        if (preg_match('#^locations/[^/]+$#', trim($raw)) === 1) {
            return ['account' => null, 'location' => trim($raw)];
        }

        return null;
    }

    private function token(DataSource $source): string
    {
        $key = $source->getKey();
        $cacheKey = is_scalar($key) ? (string) $key : (string) spl_object_id($source);
        if (isset($this->tokenCache[$cacheKey])) {
            return $this->tokenCache[$cacheKey];
        }

        $refresh = $this->refreshToken($source);
        $token = $refresh !== '' ? ((new GoogleOAuthClient)->accessTokenFromRefresh($refresh) ?? '') : '';
        if ($token !== '') {
            $this->tokenCache[$cacheKey] = $token;
        }

        return $token;
    }

    private function refreshToken(DataSource $source): string
    {
        $value = ($source->credentials ?? [])['oauth_refresh_token'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }
}
