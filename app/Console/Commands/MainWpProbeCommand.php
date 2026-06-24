<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnostic (CLAUDE.md §0 — never invent connector API shapes, discover them). Dumps
 * a MainWP dashboard's REST route index (which extension endpoints exist) plus the full
 * field list of the managed site, so new MainWP-extension metrics are built against the
 * REAL payloads instead of assumptions. Read-only; also writes a JSON dump under
 * storage/app/mainwp-probe for sharing. Run on the server where the source lives:
 *   php artisan mainwp:probe            (all MainWP sources)
 *   php artisan mainwp:probe 12         (one source by id)
 */
final class MainWpProbeCommand extends Command
{
    protected $signature = 'mainwp:probe {source? : ir_data_sources id of a MainWP source (defaults to all)}';

    protected $description = 'Discover a MainWP dashboard REST routes + site fields (no guessing of API shapes).';

    private const API_PREFIX = '/wp-json/mainwp/v2';

    public function handle(): int
    {
        $sources = $this->sources();

        if ($sources === []) {
            $this->warn('No MainWP data sources found.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->probe($source);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<DataSource>
     */
    private function sources(): array
    {
        $id = $this->argument('source');

        $query = DataSource::query()->where('type', DataSourceType::MainWp->value);

        if (is_string($id) && $id !== '') {
            $query->whereKey($id);
        }

        return array_values($query->get()->all());
    }

    private function probe(DataSource $source): void
    {
        $base = rtrim($this->str(Arr::get($source->config ?? [], 'dashboard_url')), '/');
        $token = $this->str(Arr::get($source->credentials ?? [], 'token'));

        $this->newLine();
        $this->info("== MainWP source #{$source->id} — {$base} ==");

        if ($base === '' || $token === '') {
            $this->warn('Missing dashboard_url or token.');

            return;
        }

        $client = Http::baseUrl($base)->withToken($token)->acceptJson()->timeout(30);

        // 1) Route index — which (extension) endpoints this dashboard exposes.
        $routes = [];
        try {
            $json = $client->get(self::API_PREFIX)->json();
            $routes = is_array($json) && is_array($json['routes'] ?? null)
                ? array_map($this->str(...), array_keys($json['routes']))
                : [];
        } catch (Throwable $e) {
            $this->warn('Route index error: '.$e->getMessage());
        }

        $this->line('Available routes ('.count($routes).'):');
        foreach ($routes as $route) {
            $this->line('  '.$route);
        }

        // 2) Full field list of the managed site (extensions often ride along here).
        $sampleSite = null;
        try {
            $sampleSite = $this->extractSites($client->get(self::API_PREFIX.'/sites')->json())[0] ?? null;
        } catch (Throwable $e) {
            $this->warn('Sites error: '.$e->getMessage());
        }

        if (is_array($sampleSite)) {
            $this->line('Site fields ('.count($sampleSite).'):');
            foreach ($sampleSite as $key => $value) {
                $this->line('  '.$this->str($key).' : '.$this->preview($value));
            }
        } else {
            $this->line('Site fields (0): no managed site matched on this dashboard.');
        }

        // 3) Persist a shareable dump.
        $path = "mainwp-probe/source-{$source->id}.json";
        Storage::put($path, (string) json_encode([
            'dashboard_url' => $base,
            'routes' => $routes,
            'sample_site' => $sampleSite,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Full dump written to storage/app/'.$path);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function extractSites(mixed $payload): array
    {
        $list = match (true) {
            is_array($payload) && array_is_list($payload) => $payload,
            is_array($payload) && is_array($payload['data'] ?? null) => $payload['data'],
            is_array($payload) && is_array($payload['sites'] ?? null) => $payload['sites'],
            default => [],
        };

        return array_values(array_filter($list, is_array(...)));
    }

    private function preview(mixed $value): string
    {
        if (is_array($value)) {
            return 'array('.count($value).')';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        $string = $this->str($value);

        return strlen($string) > 80 ? substr($string, 0, 77).'...' : $string;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
