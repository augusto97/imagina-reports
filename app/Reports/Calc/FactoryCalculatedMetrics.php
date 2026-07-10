<?php

declare(strict_types=1);

namespace App\Reports\Calc;

/**
 * Built-in cross-source marketing metrics (CLAUDE.md §10.1) — ROAS, blended ad spend, CPA,
 * etc. — so an agency gets them without hand-writing formulas. They are computed exactly like
 * user calc metrics (as `calc.<key>`), at the LOWEST precedence, so a user's own metric with
 * the same key overrides the factory one.
 *
 * Bag-aware: each metric only references the sources actually present, so a site with only
 * Google Ads still gets a blended `ad_spend_total` (= just Google's cost) instead of the whole
 * formula being skipped for referencing an absent `facebook_ads.spend`. Division-based metrics
 * (ROAS/CPA) that would divide by zero are skipped gracefully by the evaluator (block hidden).
 */
final class FactoryCalculatedMetrics
{
    /**
     * Ad-platform metric keys that roll up into the blended totals. Add a new ad connector's
     * keys here (and its source key to AD_SOURCES) and it automatically feeds ROAS / blended
     * spend / CPA — no other change needed.
     *
     * @var array<string, list<string>>
     */
    private const AD_METRICS = [
        'spend' => ['google_ads.cost', 'facebook_ads.spend', 'linkedin_ads.cost', 'tiktok_ads.spend'],
        'conversions' => ['google_ads.conversions', 'facebook_ads.conversions', 'linkedin_ads.conversions', 'tiktok_ads.conversions'],
        'clicks' => ['google_ads.clicks', 'facebook_ads.clicks', 'linkedin_ads.clicks', 'tiktok_ads.clicks'],
        'impressions' => ['google_ads.impressions', 'facebook_ads.impressions', 'linkedin_ads.impressions', 'tiktok_ads.impressions'],
    ];

    /** Source keys considered "advertising" for the editor catalog. */
    private const AD_SOURCES = ['google_ads', 'facebook_ads', 'linkedin_ads', 'tiktok_ads'];

    /** Human labels for the factory metric keys, shared by the resolver and the editor catalog. */
    private const LABELS = [
        'ad_spend_total' => 'Inversión publicitaria total',
        'ad_conversions_total' => 'Conversiones publicitarias',
        'ad_clicks_total' => 'Clics publicitarios',
        'ad_impressions_total' => 'Impresiones publicitarias',
        'roas' => 'ROAS (retorno de inversión publicitaria)',
        'cpa' => 'Coste por conversión (CPA)',
        'ad_ctr' => 'CTR publicitario',
        'cac' => 'Coste por cliente nuevo (CAC)',
    ];

    /**
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return list<array{key: string, label: string, formula: string, factory: true}>
     */
    public function definitionsFor(array $bags): array
    {
        $present = $this->presentKeys($bags);
        $defs = [];

        $spend = $this->sumExpr($present, self::AD_METRICS['spend']);
        $conversions = $this->sumExpr($present, self::AD_METRICS['conversions']);
        $clicks = $this->sumExpr($present, self::AD_METRICS['clicks']);
        $impressions = $this->sumExpr($present, self::AD_METRICS['impressions']);
        $revenue = $this->firstPresent($present, ['woocommerce.revenue', 'ga4.revenue']);

        if ($spend !== null) {
            $defs[] = $this->def('ad_spend_total', $spend);
        }
        if ($conversions !== null) {
            $defs[] = $this->def('ad_conversions_total', $conversions);
        }
        if ($clicks !== null) {
            $defs[] = $this->def('ad_clicks_total', $clicks);
        }
        if ($impressions !== null) {
            $defs[] = $this->def('ad_impressions_total', $impressions);
        }
        if ($spend !== null && $revenue !== null) {
            $defs[] = $this->def('roas', "({$revenue}) / ({$spend})");
        }
        if ($spend !== null && $conversions !== null) {
            $defs[] = $this->def('cpa', "({$spend}) / ({$conversions})");
        }
        if ($clicks !== null && $impressions !== null) {
            $defs[] = $this->def('ad_ctr', "({$clicks}) / ({$impressions}) * 100");
        }
        if ($spend !== null && $this->present($present, 'woocommerce.new_customers')) {
            $defs[] = $this->def('cac', "({$spend}) / (woocommerce.new_customers)");
        }

        return $defs;
    }

    /**
     * The factory metrics offerable in the editor's binding picker for a site with these
     * connected source keys (design-time — a metric shows if its inputs' sources are
     * connected, even before any data has synced).
     *
     * @param  list<string>  $connectedSources
     * @return list<array{key: string, label: string}>
     */
    public function catalogFor(array $connectedSources): array
    {
        $has = static fn (string $source): bool => in_array($source, $connectedSources, true);
        $ads = array_intersect(self::AD_SOURCES, $connectedSources) !== [];
        $revenue = $has('woocommerce') || $has('ga4');

        $keys = [];
        if ($ads) {
            $keys = ['ad_spend_total', 'ad_conversions_total', 'ad_clicks_total', 'ad_impressions_total', 'ad_ctr', 'cpa'];
        }
        if ($ads && $revenue) {
            $keys[] = 'roas';
        }
        if ($ads && $has('woocommerce')) {
            $keys[] = 'cac';
        }

        return array_map(static fn (string $key): array => ['key' => $key, 'label' => self::LABELS[$key]], $keys);
    }

    /**
     * The scalar metric keys present across all bags (the ones a formula can reference).
     *
     * @param  array<string, array<array-key, mixed>>  $bags
     * @return array<string, true>
     */
    private function presentKeys(array $bags): array
    {
        $present = [];

        foreach ($bags as $metrics) {
            foreach ($metrics as $name => $value) {
                if (is_string($name) && is_numeric($value)) {
                    $present[$name] = true;
                }
            }
        }

        return $present;
    }

    /**
     * A `+`-sum of only the given keys that are present, or null when none are.
     *
     * @param  array<string, true>  $present
     * @param  list<string>  $keys
     */
    private function sumExpr(array $present, array $keys): ?string
    {
        $terms = array_values(array_filter($keys, fn (string $key): bool => $this->present($present, $key)));

        return $terms === [] ? null : implode(' + ', $terms);
    }

    /**
     * @param  array<string, true>  $present
     * @param  list<string>  $keys
     */
    private function firstPresent(array $present, array $keys): ?string
    {
        foreach ($keys as $key) {
            if ($this->present($present, $key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $present
     */
    private function present(array $present, string $key): bool
    {
        return array_key_exists($key, $present);
    }

    /**
     * @return array{key: string, label: string, formula: string, factory: true}
     */
    private function def(string $key, string $formula): array
    {
        return ['key' => $key, 'label' => self::LABELS[$key], 'formula' => $formula, 'factory' => true];
    }
}
