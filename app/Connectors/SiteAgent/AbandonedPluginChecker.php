<?php

declare(strict_types=1);

namespace App\Connectors\SiteAgent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Detects abandoned plugins the way MainWP does: by asking the WordPress.org plugin
 * directory when each installed plugin was last updated. The companion agent reports the
 * installed plugin list (slug + version) but never calls wp.org itself (golden rule
 * §3.3 forbids external calls from inside the client site) — so this lookup lives on the
 * Laravel side, during the SYNC stage, with aggressive per-slug caching.
 *
 * Correctness note: a plugin that is NOT in the free directory (premium plugins like
 * Elementor Pro, ACF Pro, or a custom plugin) returns no data. We deliberately do NOT
 * flag those as abandoned — absence from the repo is not staleness, and flagging a
 * well-maintained premium plugin would be a false positive. Only plugins that ARE in the
 * directory AND whose last update is older than the threshold are flagged.
 */
final class AbandonedPluginChecker
{
    private const API = 'https://api.wordpress.org/plugins/info/1.0/';

    private const CACHE_DAYS = 7;

    public function __construct(private readonly int $staleMonths = 24) {}

    /**
     * @param  list<array<array-key, mixed>>  $plugins  Each: slug, name, version, active.
     * @return list<array{slug: string, name: string, last_updated: string, reason: string}>
     */
    public function detect(array $plugins): array
    {
        $thresholdTs = now()->subMonths($this->staleMonths)->getTimestamp();
        $abandoned = [];

        foreach ($plugins as $plugin) {
            $slug = is_string($plugin['slug'] ?? null) ? trim($plugin['slug']) : '';
            if ($slug === '') {
                continue;
            }

            $info = $this->lookup($slug);
            if ($info === null) {
                continue; // Not in the directory (premium/custom/removed) — do not flag.
            }

            $lastUpdated = is_string($info['last_updated'] ?? null) ? $info['last_updated'] : '';
            $ts = $lastUpdated !== '' ? strtotime($lastUpdated) : false;
            if ($ts === false || $ts >= $thresholdTs) {
                continue;
            }

            $name = is_string($plugin['name'] ?? null) && $plugin['name'] !== ''
                ? $plugin['name']
                : (is_string($info['name'] ?? null) ? $info['name'] : $slug);

            $abandoned[] = [
                'slug' => $slug,
                'name' => $name,
                'last_updated' => date('Y-m-d', $ts),
                'reason' => 'Sin actualizar desde '.date('m/Y', $ts),
            ];
        }

        return $abandoned;
    }

    /**
     * Look up a slug on wp.org, caching both hits and definitive misses for a week.
     * Transient network/server errors are NOT cached so they retry next sync.
     *
     * @return array<array-key, mixed>|null Plugin info, or null when not in the directory.
     */
    private function lookup(string $slug): ?array
    {
        $key = 'wporg.plugin.'.$slug;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ($cached['__missing'] ?? false) === true ? null : $cached;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(self::API.$slug.'.json');
        } catch (Throwable) {
            return null; // Transient — do not cache, retry next time.
        }

        if ($response->failed()) {
            return null; // Server error — do not cache.
        }

        $json = $response->json();

        // wp.org returns JSON null (or an {"error": ...} object) for unknown plugins.
        if (! is_array($json) || isset($json['error'])) {
            Cache::put($key, ['__missing' => true], now()->addDays(self::CACHE_DAYS));

            return null;
        }

        Cache::put($key, $json, now()->addDays(self::CACHE_DAYS));

        return $json;
    }
}
