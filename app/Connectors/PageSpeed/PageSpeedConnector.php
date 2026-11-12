<?php

declare(strict_types=1);

namespace App\Connectors\PageSpeed;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Connectors\ConnectionResult;
use App\Connectors\Contracts\DataSourceConnector;
use App\Connectors\Contracts\MeasuresOnlyNow;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Connectors\MetricCatalog;
use App\Connectors\MetricDefinition;
use App\Connectors\MetricSet;
use App\Connectors\MetricType;
use App\Connectors\Period;
use App\Connectors\SetupGuide;
use App\Connectors\Support\DescribesApiErrors;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Services\Platform\OAuthCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * How fast the site loads, from Google PageSpeed Insights (`runPagespeed`, v5): the 0–100
 * performance score Google's lab test gives on a phone and on a computer, the timings behind it,
 * and — when the site has enough visitors for Chrome to report on — what real visitors lived
 * through over the last 28 days (Core Web Vitals). Nothing to install and no client account:
 * the site URL is enough. It measures the site as it is NOW, so past periods are skipped
 * (MeasuresOnlyNow). It needs the platform's API key (super-admin → Integraciones → Google):
 * without one, requests ride Google's shared free quota, which answers 429 (seen in practice).
 *
 * @phpstan-type Run array{ok: bool, json: array<array-key, mixed>, error: string}
 */
final class PageSpeedConnector implements DataSourceConnector, MeasuresOnlyNow, ProvidesSetupGuide
{
    use DescribesApiErrors;

    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** A Lighthouse run on a slow site can take a while; Google gives up at about a minute. */
    private const TIMEOUT = 90;

    /** Metrics that need the computer (desktop) run; everything else comes from the phone run. */
    private const DESKTOP = ['pagespeed.score_desktop', 'pagespeed.lcp_desktop'];

    /** Good / needs-improvement limits (Google's own thresholds) for the summary table. */
    private const THRESHOLDS = [
        'lcp' => [2.5, 4.0],
        'fcp' => [1.8, 3.0],
        'inp' => [200, 500],
        'tbt' => [200, 600],
        'cls' => [0.1, 0.25],
    ];

    public function key(): string
    {
        return DataSourceType::PageSpeed->value;
    }

    public function label(): string
    {
        return DataSourceType::PageSpeed->label();
    }

    public function configSchema(): array
    {
        return [
            new ConfigField('url', 'Página a medir', ConfigFieldType::Text, required: false, help: 'Déjalo vacío para medir la portada del sitio. También puedes poner otra página, por ejemplo la de un producto o un servicio.'),
        ];
    }

    public function metricCatalog(DataSource $source): MetricCatalog
    {
        return new MetricCatalog(
            new MetricDefinition('pagespeed.score_mobile', 'Nota de velocidad en celular', MetricType::Scalar, 'score', description: 'Nota de 0 a 100 que Google le pone a la velocidad de tu web en un celular: 90 o más es rápida; menos de 50, lenta.'),
            new MetricDefinition('pagespeed.score_desktop', 'Nota de velocidad en computador', MetricType::Scalar, 'score', description: 'La misma nota de 0 a 100 de Google, medida en un computador (casi siempre más alta que en celular).'),
            new MetricDefinition('pagespeed.lcp_mobile', 'Carga del contenido principal (celular)', MetricType::Scalar, 'seconds', description: 'Segundos hasta que se ve lo principal de la página en un celular, en la prueba de Google. Bueno: 2,5 s o menos.'),
            new MetricDefinition('pagespeed.lcp_desktop', 'Carga del contenido principal (computador)', MetricType::Scalar, 'seconds', description: 'Segundos hasta que se ve lo principal de la página en un computador, en la prueba de Google.'),
            new MetricDefinition('pagespeed.fcp_mobile', 'Primer contenido visible (celular)', MetricType::Scalar, 'seconds', description: 'Segundos hasta que aparece algo en la pantalla del celular. Bueno: 1,8 s o menos.'),
            new MetricDefinition('pagespeed.tbt_mobile', 'Tiempo sin responder al cargar (celular)', MetricType::Scalar, 'ms', description: 'Milisegundos en que la página no responde a toques mientras carga, en la prueba de Google. Bueno: 200 ms o menos.'),
            new MetricDefinition('pagespeed.cls_mobile', 'Estabilidad visual (celular)', MetricType::Scalar, 'unit', description: 'Cuánto se mueve el contenido mientras carga (botones que saltan, textos que se corren). Bueno: 0,1 o menos.'),
            new MetricDefinition('pagespeed.field_lcp', 'Carga para visitantes reales', MetricType::Scalar, 'seconds', description: 'Lo que tardó en verse lo principal para tus visitantes reales en los últimos 28 días, según Chrome. Solo existe si el sitio tiene suficiente tráfico.'),
            new MetricDefinition('pagespeed.field_inp', 'Respuesta al tocar, visitantes reales', MetricType::Scalar, 'ms', description: 'Milisegundos que tardó la página en reaccionar a un toque o clic de tus visitantes reales (últimos 28 días). Bueno: 200 ms o menos.'),
            new MetricDefinition('pagespeed.field_cls', 'Estabilidad visual, visitantes reales', MetricType::Scalar, 'unit', description: 'Cuánto se movió el contenido para tus visitantes reales en los últimos 28 días. Bueno: 0,1 o menos.'),
            new MetricDefinition('pagespeed.vitals', 'Resumen de velocidad (Core Web Vitals)', MetricType::Table, description: 'Los indicadores de velocidad que usa Google, cada uno con su resultado y si está bien, es mejorable o está mal. Usa los datos de visitantes reales cuando los hay.'),
            new MetricDefinition('pagespeed.opportunities', 'Qué haría más rápida tu web', MetricType::Table, description: 'Las mejoras que Google recomienda para esta página, con el tiempo que ahorraría cada una.'),
        );
    }

    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            'No hay que instalar nada ni pedirle cuentas al cliente: Google mide la página por su dirección pública.',
            [
                'Deja vacía «Página a medir» para medir la portada, o pon la dirección de otra página.',
                'Guarda y pulsa «Probar conexión»: Google tarda entre 10 y 60 segundos en medir.',
                'Cada sincronización mide la página en ese momento. Los meses pasados no se pueden medir hacia atrás.',
            ],
            'https://developers.google.com/speed/docs/insights/v5/about',
        );
    }

    public function testConnection(DataSource $source): ConnectionResult
    {
        $url = $this->target($source);
        if ($url === '') {
            return ConnectionResult::failure('Falta la dirección de la página: complétala aquí o en los datos del sitio.');
        }

        $run = $this->run($url, 'mobile');
        if (! $run['ok']) {
            return ConnectionResult::failure($run['error']);
        }

        $score = $this->score($run['json']);

        return ConnectionResult::success($score !== null
            ? "Google midió la página: {$score}/100 en celular."
            : 'Google midió la página, pero no devolvió una nota.');
    }

    public function fetch(DataSource $source, Period $period, array $requestedMetrics): MetricSet
    {
        $url = $this->target($source);
        if ($url === '') {
            return MetricSet::failed('Falta la dirección de la página a medir.');
        }

        $wants = static fn (string $key): bool => $requestedMetrics === [] || in_array($key, $requestedMetrics, true);
        $needsDesktop = array_filter(self::DESKTOP, $wants) !== [];
        $needsMobile = array_filter($this->metricCatalog($source)->keys(), static fn (string $key): bool => $wants($key) && ! in_array($key, self::DESKTOP, true)) !== [];

        $metrics = [];
        $errors = [];

        if ($needsMobile) {
            $mobile = $this->run($url, 'mobile');
            if ($mobile['ok']) {
                $metrics += $this->mobileMetrics($mobile['json']);
            } else {
                $errors[] = 'Celular: '.$mobile['error'];
            }
        }

        if ($needsDesktop) {
            $desktop = $this->run($url, 'desktop');
            if ($desktop['ok']) {
                $metrics['pagespeed.score_desktop'] = $this->score($desktop['json']);
                $metrics['pagespeed.lcp_desktop'] = $this->seconds($this->audit($desktop['json'], 'largest-contentful-paint'));
            } else {
                $errors[] = 'Computador: '.$desktop['error'];
            }
        }

        // Only what was asked, and only what Google actually measured.
        $metrics = array_filter($metrics, static fn (mixed $value, string $key): bool => $value !== null && $wants($key), ARRAY_FILTER_USE_BOTH);

        if ($metrics === [] && $errors !== []) {
            return MetricSet::failed(implode(' ', $errors));
        }

        return $errors === [] ? MetricSet::ok($metrics) : MetricSet::partial($metrics, implode(' ', $errors));
    }

    /**
     * Everything the phone run gives: score, lab timings, real-visitor data, the summary table
     * and the recommended improvements.
     *
     * @param  array<array-key, mixed>  $json
     * @return array<string, mixed>
     */
    private function mobileMetrics(array $json): array
    {
        $lcp = $this->seconds($this->audit($json, 'largest-contentful-paint'));
        $fcp = $this->seconds($this->audit($json, 'first-contentful-paint'));
        $tbt = $this->audit($json, 'total-blocking-time');
        $cls = $this->audit($json, 'cumulative-layout-shift');
        $field = $this->field($json);

        return [
            'pagespeed.score_mobile' => $this->score($json),
            'pagespeed.lcp_mobile' => $lcp,
            'pagespeed.fcp_mobile' => $fcp,
            'pagespeed.tbt_mobile' => $tbt !== null ? (int) round($tbt) : null,
            'pagespeed.cls_mobile' => $cls !== null ? round($cls, 3) : null,
            'pagespeed.field_lcp' => $field['lcp'],
            'pagespeed.field_inp' => $field['inp'],
            'pagespeed.field_cls' => $field['cls'],
            'pagespeed.vitals' => $this->vitals($field, $lcp, $fcp, $tbt, $cls),
            'pagespeed.opportunities' => $this->opportunities($json),
        ];
    }

    /**
     * One row per indicator, real visitors first when Chrome has data for the site.
     *
     * @param  array{lcp: float|null, inp: int|null, cls: float|null, fcp: float|null}  $field
     * @return list<array<string, string>>|null
     */
    private function vitals(array $field, ?float $lcp, ?float $fcp, ?float $tbt, ?float $cls): ?array
    {
        $rows = [];
        $add = function (string $name, ?float $value, string $kind, string $shown, string $origin) use (&$rows): void {
            if ($value === null) {
                return;
            }
            [$good, $poor] = self::THRESHOLDS[$kind];
            $rows[] = [
                'Indicador' => $name,
                'Resultado' => $shown,
                'Estado' => $value <= $good ? 'Bueno' : ($value <= $poor ? 'Mejorable' : 'Malo'),
                'Medido con' => $origin,
            ];
        };

        $real = 'Visitantes reales (28 días)';
        $lab = 'Prueba de Google (celular)';

        $fieldLcp = $field['lcp'];
        $add('Carga del contenido principal (LCP)', $fieldLcp ?? $lcp, 'lcp', $this->secondsText($fieldLcp ?? $lcp), $fieldLcp !== null ? $real : $lab);
        if ($field['inp'] !== null) {
            $add('Respuesta al tocar (INP)', (float) $field['inp'], 'inp', $field['inp'].' ms', $real);
        } elseif ($tbt !== null) {
            $add('Tiempo sin responder al cargar (TBT)', $tbt, 'tbt', (int) round($tbt).' ms', $lab);
        }
        $fieldCls = $field['cls'];
        $clsValue = $fieldCls ?? ($cls !== null ? round($cls, 3) : null);
        $add('Estabilidad visual (CLS)', $clsValue, 'cls', $clsValue !== null ? $this->decimal($clsValue, 2) : '', $fieldCls !== null ? $real : $lab);
        $fieldFcp = $field['fcp'];
        $add('Primer contenido visible (FCP)', $fieldFcp ?? $fcp, 'fcp', $this->secondsText($fieldFcp ?? $fcp), $fieldFcp !== null ? $real : $lab);

        return $rows === [] ? null : $rows;
    }

    /**
     * The improvements Lighthouse estimates would save the most time (titles come in Spanish).
     *
     * @param  array<array-key, mixed>  $json
     * @return list<array<string, string>>|null
     */
    private function opportunities(array $json): ?array
    {
        $audits = Arr::get($json, 'lighthouseResult.audits');
        if (! is_array($audits)) {
            return null;
        }

        $found = [];
        foreach ($audits as $audit) {
            if (! is_array($audit)) {
                continue;
            }
            $score = $audit['score'] ?? null;
            if (is_numeric($score) && (float) $score >= 0.9) {
                continue;
            }
            $savings = Arr::get($audit, 'details.overallSavingsMs');
            if (! is_numeric($savings)) {
                $savings = Arr::get($audit, 'metricSavings.LCP');
            }
            $title = $audit['title'] ?? null;
            if (! is_numeric($savings) || (float) $savings < 100 || ! is_string($title) || $title === '') {
                continue;
            }
            $found[] = ['title' => trim(str_replace('`', '', $title)), 'ms' => (float) $savings];
        }

        usort($found, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        $rows = array_map(fn (array $item): array => [
            'Mejora' => $item['title'],
            'Ahorro estimado' => $this->secondsText(round($item['ms'] / 1000, 1)),
        ], array_slice($found, 0, 6));

        return $rows === [] ? null : $rows;
    }

    /**
     * What Chrome measured for real visitors (75th percentile, last 28 days): for this page when
     * it has enough traffic, otherwise for the whole site.
     *
     * @param  array<array-key, mixed>  $json
     * @return array{lcp: float|null, inp: int|null, cls: float|null, fcp: float|null}
     */
    private function field(array $json): array
    {
        $metrics = Arr::get($json, 'loadingExperience.metrics');
        if (! is_array($metrics) || $metrics === []) {
            $metrics = Arr::get($json, 'originLoadingExperience.metrics');
        }
        $metrics = is_array($metrics) ? $metrics : [];

        $percentile = static function (string $key) use ($metrics): int|float|null {
            $value = Arr::get($metrics, "{$key}.percentile");

            return is_int($value) || is_float($value) ? $value : null;
        };

        $lcp = $percentile('LARGEST_CONTENTFUL_PAINT_MS');
        $fcp = $percentile('FIRST_CONTENTFUL_PAINT_MS');
        $inp = $percentile('INTERACTION_TO_NEXT_PAINT');
        $cls = $percentile('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            'lcp' => $lcp !== null ? round($lcp / 1000, 1) : null,
            'inp' => $inp !== null ? (int) round($inp) : null,
            // The API reports CLS as an integer ×100 (5 = 0,05); accept a plain decimal too.
            'cls' => $cls === null ? null : (is_int($cls) ? round($cls / 100, 2) : round($cls, 2)),
            'fcp' => $fcp !== null ? round($fcp / 1000, 1) : null,
        ];
    }

    /**
     * One PageSpeed Insights run.
     *
     * @return Run
     */
    private function run(string $url, string $strategy): array
    {
        $query = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance', 'locale' => 'es'];
        $key = (new OAuthCredentials)->googlePageSpeedKey();
        if ($key !== '') {
            $query['key'] = $key;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->acceptJson()->get(self::ENDPOINT, $query);
        } catch (ConnectionException) {
            return ['ok' => false, 'json' => [], 'error' => 'Google tardó demasiado en medir la página. Se volverá a intentar en la próxima sincronización.'];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful()) {
            return ['ok' => true, 'json' => $json, 'error' => ''];
        }

        $detail = $this->providerError($json);

        return ['ok' => false, 'json' => $json, 'error' => match (true) {
            // Without its own key the request rides Google's shared free quota, which is used up.
            $response->status() === 429 && $key === '' => 'Google rechazó la prueba porque la plataforma todavía no tiene su propia clave de PageSpeed (la cuota gratuita compartida está agotada). El administrador de la plataforma debe agregarla en Integraciones → Google.',
            $response->status() === 429 => 'Google limitó las pruebas de velocidad por ahora (se agotó la cuota). Se volverá a intentar en la próxima sincronización.',
            str_contains($detail, 'DOCUMENT_REQUEST') || str_contains($detail, 'NO_FCP') || str_contains($detail, 'DNS') => 'Google no pudo abrir la página ('.$url.'). Revisa que la dirección sea correcta y que el sitio esté en línea. Detalle: '.$detail,
            $detail !== '' => 'PageSpeed respondió HTTP '.$response->status().': '.$detail,
            default => 'PageSpeed respondió HTTP '.$response->status().'.',
        }];
    }

    /**
     * @param  array<array-key, mixed>  $json
     */
    private function score(array $json): ?int
    {
        $score = Arr::get($json, 'lighthouseResult.categories.performance.score');

        return is_numeric($score) ? (int) round((float) $score * 100) : null;
    }

    /**
     * An audit's measured value (milliseconds for timings, a plain number for CLS).
     *
     * @param  array<array-key, mixed>  $json
     */
    private function audit(array $json, string $id): ?float
    {
        $value = Arr::get($json, "lighthouseResult.audits.{$id}.numericValue");

        return is_numeric($value) ? (float) $value : null;
    }

    private function seconds(?float $milliseconds): ?float
    {
        return $milliseconds !== null ? round($milliseconds / 1000, 1) : null;
    }

    private function secondsText(?float $seconds): string
    {
        return $seconds !== null ? $this->decimal($seconds, 1).' s' : '';
    }

    private function decimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    /** The page to measure: the configured one, else the site's own address. */
    private function target(DataSource $source): string
    {
        $configured = Arr::get($source->config ?? [], 'url');
        $site = $source->site;
        $url = is_string($configured) && trim($configured) !== '' ? trim($configured) : ($site !== null ? $site->url : '');
        if ($url === '') {
            return '';
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
    }
}
