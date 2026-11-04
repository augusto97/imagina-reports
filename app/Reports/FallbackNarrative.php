<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * The plain-language summary written from the report's own figures when the AI narrative
 * isn't available (plan without AI, no API key, the API failed). Before, those reports had
 * no story at all: the summary block was hidden and the page opened with a lonely gauge.
 *
 * Deterministic and data-only — it never invents anything: each sentence appears only when
 * its metrics resolved. Recognises metrics by their binding (source.metric), so it works with
 * any template, not just the default one.
 */
final class FallbackNarrative
{
    /** Kind of figure → binding pattern (matched against "source.metric", lowercased). */
    private const KINDS = [
        'visits' => '/^ga4\.(sessions|users|active_users|total_users)$/',
        'uptime' => '/uptime/',
        'attacks' => '/attack|threat|blocked|bans?$|waf/',
        'updates' => '/updates_applied|updates$/',
        'revenue' => '/(woocommerce|shop|store)\.(revenue|net_revenue|gross_revenue|sales)$/',
        'orders' => '/(woocommerce|shop|store)\.orders$/',
        'clicks' => '/^gsc\.clicks$/',
    ];

    private const TEXT = [
        'es' => [
            'visits_uptime' => 'Este mes tu sitio recibió :visits visitas:visits_change y estuvo disponible el :uptime del tiempo.',
            'visits' => 'Este mes tu sitio recibió :visits visitas:visits_change.',
            'uptime' => 'Este mes tu sitio estuvo disponible el :uptime del tiempo.',
            'attacks_updates' => 'Bloqueamos :attacks ataques y aplicamos :updates actualizaciones para mantenerlo seguro y al día.',
            'attacks' => 'Bloqueamos :attacks ataques para mantenerlo seguro.',
            'updates' => 'Aplicamos :updates actualizaciones para mantenerlo seguro y al día.',
            'clicks' => 'Desde Google llegaron :clicks clics:clicks_change.',
            'revenue_orders' => 'Las ventas sumaron :revenue en :orders pedidos:revenue_change.',
            'revenue' => 'Las ventas sumaron :revenue:revenue_change.',
            'score' => 'El estado general del sitio es de :score/100: :verdict.',
            'change' => ' (:sign:percent frente al periodo anterior)',
            'verdicts' => ['excelente', 'muy bien', 'mejorable', 'requiere atención'],
            'footer' => 'Tu plan de soporte con :agency está activo: cuidamos tu sitio cada día. Si tienes cualquier duda sobre este informe, respóndenos y lo vemos juntos.',
            'footer_plain' => 'Tu plan de soporte está activo: cuidamos tu sitio cada día. Si tienes cualquier duda sobre este informe, respóndenos y lo vemos juntos.',
        ],
        'en' => [
            'visits_uptime' => 'This month your site received :visits visits:visits_change and was online :uptime of the time.',
            'visits' => 'This month your site received :visits visits:visits_change.',
            'uptime' => 'This month your site was online :uptime of the time.',
            'attacks_updates' => 'We blocked :attacks attacks and applied :updates updates to keep it secure and up to date.',
            'attacks' => 'We blocked :attacks attacks to keep it secure.',
            'updates' => 'We applied :updates updates to keep it secure and up to date.',
            'clicks' => 'Google sent :clicks clicks:clicks_change.',
            'revenue_orders' => 'Sales reached :revenue across :orders orders:revenue_change.',
            'revenue' => 'Sales reached :revenue:revenue_change.',
            'score' => 'Your site\'s overall health is :score/100: :verdict.',
            'change' => ' (:sign:percent vs. the previous period)',
            'verdicts' => ['excellent', 'very good', 'room to improve', 'needs attention'],
            'footer' => 'Your support plan with :agency is active: we look after your site every day. If you have any question about this report, just reply and we will go through it together.',
            'footer_plain' => 'Your support plan is active: we look after your site every day. If you have any question about this report, just reply and we will go through it together.',
        ],
        'pt' => [
            'visits_uptime' => 'Este mês seu site recebeu :visits visitas:visits_change e ficou no ar :uptime do tempo.',
            'visits' => 'Este mês seu site recebeu :visits visitas:visits_change.',
            'uptime' => 'Este mês seu site ficou no ar :uptime do tempo.',
            'attacks_updates' => 'Bloqueamos :attacks ataques e aplicamos :updates atualizações para mantê-lo seguro e em dia.',
            'attacks' => 'Bloqueamos :attacks ataques para mantê-lo seguro.',
            'updates' => 'Aplicamos :updates atualizações para mantê-lo seguro e em dia.',
            'clicks' => 'O Google trouxe :clicks cliques:clicks_change.',
            'revenue_orders' => 'As vendas somaram :revenue em :orders pedidos:revenue_change.',
            'revenue' => 'As vendas somaram :revenue:revenue_change.',
            'score' => 'A saúde geral do site é :score/100: :verdict.',
            'change' => ' (:sign:percent em relação ao período anterior)',
            'verdicts' => ['excelente', 'muito bom', 'pode melhorar', 'requer atenção'],
            'footer' => 'Seu plano de suporte com :agency está ativo: cuidamos do seu site todos os dias. Se tiver qualquer dúvida sobre este relatório, é só responder.',
            'footer_plain' => 'Seu plano de suporte está ativo: cuidamos do seu site todos os dias. Se tiver qualquer dúvida sobre este relatório, é só responder.',
        ],
    ];

    /**
     * The summary for a period, or null when no recognised figure resolved (better no
     * summary than an empty-sounding one).
     *
     * @param  array<array-key, mixed>  $blocks  Resolved, visible blocks.
     * @param  array<array-key, mixed>  $data  Block id → resolved value.
     */
    public static function summary(array $blocks, array $data, ?int $score, string $locale, string $currency = 'USD'): ?string
    {
        $lang = self::lang($locale);
        $t = self::TEXT[$lang];
        $figures = self::figures($blocks, $data);

        // The first comparison is spelled out ("frente al periodo anterior"); the rest just
        // carry the percentage, so the paragraph doesn't repeat itself.
        $spelled = false;
        $change = static function (string $kind) use ($figures, $t, $lang, &$spelled): string {
            $percent = $figures[$kind]['change'] ?? null;
            if ($percent === null || abs($percent) < 0.05) {
                return '';
            }
            $label = ($percent > 0 ? '+' : '−').self::percent(abs($percent), $lang);
            if ($spelled) {
                return ' ('.$label.')';
            }
            $spelled = true;

            return strtr($t['change'], [':sign' => $percent > 0 ? '+' : '−', ':percent' => self::percent(abs($percent), $lang)]);
        };
        $number = static fn (string $kind): string => self::number($figures[$kind]['value'] ?? 0, $lang);

        $sentences = [];

        if (isset($figures['visits'], $figures['uptime'])) {
            $sentences[] = strtr($t['visits_uptime'], [':visits' => $number('visits'), ':visits_change' => $change('visits'), ':uptime' => self::percent($figures['uptime']['value'], $lang)]);
        } elseif (isset($figures['visits'])) {
            $sentences[] = strtr($t['visits'], [':visits' => $number('visits'), ':visits_change' => $change('visits')]);
        } elseif (isset($figures['uptime'])) {
            $sentences[] = strtr($t['uptime'], [':uptime' => self::percent($figures['uptime']['value'], $lang)]);
        }

        if (isset($figures['attacks'], $figures['updates'])) {
            $sentences[] = strtr($t['attacks_updates'], [':attacks' => $number('attacks'), ':updates' => $number('updates')]);
        } elseif (isset($figures['attacks'])) {
            $sentences[] = strtr($t['attacks'], [':attacks' => $number('attacks')]);
        } elseif (isset($figures['updates'])) {
            $sentences[] = strtr($t['updates'], [':updates' => $number('updates')]);
        }

        if (isset($figures['clicks'])) {
            $sentences[] = strtr($t['clicks'], [':clicks' => $number('clicks'), ':clicks_change' => $change('clicks')]);
        }

        if (isset($figures['revenue'])) {
            $revenue = $number('revenue').' '.$currency;
            $sentences[] = isset($figures['orders'])
                ? strtr($t['revenue_orders'], [':revenue' => $revenue, ':orders' => $number('orders'), ':revenue_change' => $change('revenue')])
                : strtr($t['revenue'], [':revenue' => $revenue, ':revenue_change' => $change('revenue')]);
        }

        if ($sentences === []) {
            return null;
        }

        if ($score !== null) {
            $verdict = $t['verdicts'][$score >= 90 ? 0 : ($score >= 75 ? 1 : ($score >= 50 ? 2 : 3))];
            $sentences[] = strtr($t['score'], [':score' => (string) $score, ':verdict' => $verdict]);
        }

        return implode(' ', $sentences);
    }

    /** The closing line for a footer/CTA block that the agency left without text. */
    public static function footer(?string $agencyName, string $locale): string
    {
        $t = self::TEXT[self::lang($locale)];

        return $agencyName !== null && $agencyName !== '' ? strtr($t['footer'], [':agency' => $agencyName]) : $t['footer_plain'];
    }

    /**
     * Headline value + change per recognised kind (the first block of each kind wins).
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $data
     * @return array<string, array{value: float, change: float|null}>
     */
    private static function figures(array $blocks, array $data): array
    {
        $figures = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['kpi', 'sales_summary'], true)) {
                continue;
            }
            $id = $block['id'] ?? null;
            $binding = is_array($block['binding'] ?? null) ? $block['binding'] : [];
            $source = is_string($binding['source'] ?? null) ? $binding['source'] : '';
            $metric = is_string($binding['metric'] ?? null) ? $binding['metric'] : '';
            if (! is_string($id) || ! array_key_exists($id, $data) || $metric === '') {
                continue;
            }

            $raw = $data[$id];
            $value = is_array($raw) ? ($raw['value'] ?? null) : $raw;
            if (! is_numeric($value)) {
                continue;
            }
            $change = is_array($raw) && is_numeric($raw['change_percent'] ?? null) ? (float) $raw['change_percent'] : null;

            $key = strtolower($source.'.'.$metric);
            foreach (self::KINDS as $kind => $pattern) {
                if (! isset($figures[$kind]) && preg_match($pattern, $key) === 1) {
                    $figures[$kind] = ['value' => (float) $value, 'change' => $change];
                    break;
                }
            }
        }

        return $figures;
    }

    /** 'es', 'en' or 'pt' from a locale like 'pt-BR' / 'en_US'. */
    public static function lang(string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));

        return array_key_exists($lang, self::TEXT) ? $lang : 'es';
    }

    /** Spanish leaves 4-digit numbers ungrouped (RAE), like the report renderer does. */
    public static function number(float $value, string $lang): string
    {
        $decimals = abs($value - round($value)) > 0.001 && abs($value) < 100 ? 1 : 0;
        [$thousands, $point] = $lang === 'en' ? [',', '.'] : ['.', ','];
        if ($lang === 'es' && abs($value) < 10000) {
            $thousands = '';
        }

        return number_format($value, $decimals, $point, $thousands);
    }

    public static function percent(float $value, string $lang): string
    {
        // 99,98 % must not round up to a "100,0 %" the site didn't reach.
        $decimals = $value >= 99.95 && $value < 100 ? 2 : (abs($value - round($value)) > 0.001 ? 1 : 0);
        $point = $lang === 'en' ? '.' : ',';

        return number_format($value, $decimals, $point, '').($lang === 'en' ? '%' : ' %');
    }
}
