<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Claude models a key can use, read live from Anthropic's Models API (GET /v1/models) — so new
 * models appear on their own and retired ones drop out, with no list kept in code.
 *
 * For each model: its name, family, which effort levels it accepts (`capabilities.effort`) and
 * whether it supports the server-side fallback (`allowed_fallback_models`, published under
 * AiModels::LIST_BETA). Cached per key (hashed, never the key itself) for 12 hours; the last good
 * list is kept so a temporary outage doesn't empty the panel or change the model in use.
 *
 * @phpstan-type Model array{id: string, label: string, family: string, created_at: string, efforts: list<string>, fallbacks: bool}
 */
final class AiModelCatalog
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/models';

    private const VERSION = '2023-06-01';

    private const TTL = 12 * 3600;

    /**
     * @return array{models: list<Model>, error: string|null}
     */
    public function list(string $key, bool $refresh = false): array
    {
        $cacheKey = $this->cacheKey($key);
        if (! $refresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var list<Model> $cached */
                return ['models' => $cached, 'error' => null];
            }
        }

        $fetched = $this->fetch($key);
        if ($fetched['error'] === null && $fetched['models'] !== []) {
            Cache::put($cacheKey, $fetched['models'], self::TTL);
            Cache::forever($cacheKey.':last', $fetched['models']);

            return $fetched;
        }

        // Keep working with the last list we could read; say why it couldn't be refreshed.
        $last = Cache::get($cacheKey.':last');
        /** @var list<Model> $models */
        $models = is_array($last) ? $last : [];

        return ['models' => $models, 'error' => $fetched['error'] ?? 'Anthropic no devolvió modelos para esta clave.'];
    }

    /**
     * What's already known for this key, without calling Anthropic (for labels on hot paths).
     *
     * @return list<Model>
     */
    public function known(string $key): array
    {
        foreach ([$this->cacheKey($key), $this->cacheKey($key).':last'] as $cacheKey) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var list<Model> $cached */
                return $cached;
            }
        }

        return [];
    }

    /** After a «model not found»: read the list again on the next call. */
    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    /**
     * The model a choice means right now. `auto:{family}` → that family's newest; a pinned id that
     * Anthropic no longer lists → its family's newest; with no list at all → the id itself (or
     * AiModels::LAST_RESORT for an `auto:` choice).
     *
     * @param  list<Model>  $models
     * @return array{id: string, label: string, model: Model|null}
     */
    public static function resolve(string $choice, array $models): array
    {
        $family = AiModels::autoFamily($choice);
        if ($family === null) {
            foreach ($models as $model) {
                if ($model['id'] === $choice) {
                    return ['id' => $model['id'], 'label' => $model['label'], 'model' => $model];
                }
            }
            if ($models === []) {
                return ['id' => $choice, 'label' => AiModels::prettyId($choice), 'model' => null];
            }
            $family = AiModels::familyOf($choice);
        }

        $newest = self::newestOf($family, $models) ?? self::newestOf(AiModels::familyOf(AiModels::LAST_RESORT), $models) ?? ($models[0] ?? null);
        if ($newest === null) {
            return ['id' => AiModels::LAST_RESORT, 'label' => AiModels::prettyId(AiModels::LAST_RESORT), 'model' => null];
        }

        return ['id' => $newest['id'], 'label' => $newest['label'], 'model' => $newest];
    }

    /**
     * The options for the panel: «always the newest» per family, then every model pinned.
     *
     * @param  list<Model>  $models
     * @return list<array{id: string, label: string, hint: string, group: string}>
     */
    public static function choices(array $models): array
    {
        $families = array_values(array_unique(array_map(static fn (array $model): string => $model['family'], $models)));
        usort($families, static fn (string $a, string $b): int => AiModels::familyRank($a) <=> AiModels::familyRank($b));

        $out = [];
        foreach ($families as $family) {
            $newest = self::newestOf($family, $models);
            if ($newest === null) {
                continue;
            }
            $out[] = [
                'id' => AiModels::AUTO_PREFIX.$family,
                'label' => 'El '.AiModels::familyName($family).' más reciente',
                'hint' => trim(AiModels::familyHint($family).' Cuando Anthropic saque uno nuevo, se usa solo.'),
                'group' => 'auto',
            ];
        }

        $pinned = $models;
        usort($pinned, static fn (array $a, array $b): int => [AiModels::familyRank($a['family']), $b['created_at']] <=> [AiModels::familyRank($b['family']), $a['created_at']]);
        foreach ($pinned as $model) {
            $out[] = [
                'id' => $model['id'],
                'label' => $model['label'],
                'hint' => 'Siempre este modelo. Si Anthropic lo retira, se usa el '.AiModels::familyName($model['family']).' más reciente.',
                'group' => 'fixed',
            ];
        }

        return $out;
    }

    /**
     * @param  list<Model>  $models
     * @return Model|null
     */
    private static function newestOf(string $family, array $models): ?array
    {
        $best = null;
        foreach ($models as $model) {
            if ($model['family'] === $family && ($best === null || strcmp($model['created_at'], $best['created_at']) > 0)) {
                $best = $model;
            }
        }

        return $best;
    }

    /**
     * @return array{models: list<Model>, error: string|null}
     */
    private function fetch(string $key): array
    {
        $models = [];
        $after = null;
        $beta = true;

        for ($page = 0; $page < 10; $page++) {
            $headers = ['x-api-key' => $key, 'anthropic-version' => self::VERSION];
            if ($beta) {
                $headers['anthropic-beta'] = AiModels::LIST_BETA;
            }

            try {
                $response = Http::withHeaders($headers)->timeout(15)->get(self::ENDPOINT, array_filter(['limit' => 100, 'after_id' => $after]));
            } catch (ConnectionException) {
                return ['models' => [], 'error' => 'No se pudo conectar con Anthropic para leer los modelos.'];
            }

            // If that beta header is ever retired, list without it rather than fail.
            if ($response->status() === 400 && $beta) {
                $beta = false;
                $page--;

                continue;
            }
            if (! $response->successful()) {
                return ['models' => [], 'error' => AnthropicAiClient::explain($response->status(), $response->json(), '')];
            }

            $json = $response->json();
            $data = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [];
            foreach ($data as $row) {
                $model = is_array($row) ? self::model($row) : null;
                if ($model !== null) {
                    $models[] = $model;
                }
            }

            $last = is_array($json) ? ($json['last_id'] ?? null) : null;
            if (! is_array($json) || ($json['has_more'] ?? false) !== true || ! is_string($last)) {
                break;
            }
            $after = $last;
        }

        return ['models' => $models, 'error' => null];
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return Model|null
     */
    private static function model(array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }
        $name = is_string($row['display_name'] ?? null) && $row['display_name'] !== '' ? $row['display_name'] : AiModels::prettyId($id);

        // Effort levels the model accepts, in Anthropic's order (none → we never send `effort`).
        $efforts = [];
        $effort = data_get($row, 'capabilities.effort');
        if (is_array($effort) && ($effort['supported'] ?? false) === true) {
            foreach ($effort as $level => $info) {
                if (is_string($level) && $level !== 'supported' && is_array($info) && ($info['supported'] ?? false) === true) {
                    $efforts[] = $level;
                }
            }
        }

        $fallbackTargets = $row['allowed_fallback_models'] ?? null;

        return [
            'id' => $id,
            'label' => $name,
            'family' => AiModels::familyOf($id, $name),
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
            'efforts' => $efforts,
            'fallbacks' => is_array($fallbackTargets) && $fallbackTargets !== [],
        ];
    }

    private function cacheKey(string $key): string
    {
        return 'ai-models:'.hash('sha256', $key);
    }
}
