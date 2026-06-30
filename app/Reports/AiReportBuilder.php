<?php

declare(strict_types=1);

namespace App\Reports;

use App\Ai\AiClient;
use App\Connectors\ConnectorRegistry;
use App\Models\Site;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockValidationException;

/**
 * The "create a report in seconds" feature (CLAUDE.md §10.6). Two modes, both via
 * the Claude API (AiClient): template assembly and per-period narrative. The AI
 * returns structured blocks, never free prose — output is validated by
 * BlocksValidator AND every binding is checked against the site's real catalog, so
 * it can never invent metrics or bind to data that doesn't exist.
 */
final readonly class AiReportBuilder
{
    public function __construct(
        private AiClient $ai,
        private ConnectorRegistry $registry,
        private BlocksValidator $validator,
    ) {}

    /**
     * @return array{blocks: list<array<string, mixed>>, narrative: string, dropped: list<array{type: string, metric: string}>}
     *
     * @throws AiReportException
     */
    public function assembleTemplate(Site $site, ?string $prompt = null): array
    {
        $catalog = $this->siteCatalog($site);

        $raw = $this->ai->complete($this->systemPrompt(), $this->userPrompt($site, $catalog, $prompt));

        $json = $this->decode($raw);

        if ($json === null) {
            throw new AiReportException('The AI did not return parseable JSON.');
        }

        try {
            $blocks = $this->validator->validate($json['blocks'] ?? null);
        } catch (BlockValidationException $exception) {
            throw new AiReportException('The AI returned an invalid block layout: '.implode(' ', $exception->errors));
        }

        $catalogKeys = array_map(static fn (array $entry): string => $entry['key'], $catalog);

        $visible = [];
        $dropped = [];
        foreach ($blocks as $block) {
            if (! $this->bindingExists($block, $catalogKeys)) {
                // The AI bound this block to a metric the site doesn't have — drop it so it
                // can never invent data (§10.6), but record it so the editor can tell the
                // user *what* was left out instead of silently shrinking their layout.
                $binding = is_array($block->binding) ? $block->binding : [];
                $source = is_string($binding['source'] ?? null) ? $binding['source'] : '';
                $metric = is_string($binding['metric'] ?? null) ? $binding['metric'] : '';

                $dropped[] = [
                    'type' => $block->type->value,
                    'metric' => $source !== '' && $metric !== '' ? "{$source}.{$metric}" : $metric,
                ];

                continue;
            }

            $visible[] = $block->toArray();
        }

        $narrative = $json['narrative'] ?? '';

        return [
            'blocks' => $visible,
            'narrative' => is_string($narrative) ? $narrative : '',
            'dropped' => $dropped,
        ];
    }

    /**
     * Regenerate the per-period narrative from resolved metrics, in the report's locale.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function narrative(array $metrics, string $locale = 'es'): string
    {
        $system = "You write a concise, plain-language report summary for a non-technical client in locale '{$locale}'. 2-4 sentences. No jargon.";
        $user = 'Resolved metrics (JSON): '.$this->encode($metrics);

        return trim($this->ai->complete($system, $user));
    }

    /**
     * Advisory "site condition" insight (the §10.6 added-value block): a short, consultative
     * diagnosis that reasons over the CURRENT period, the change vs. the previous one, the
     * multi-month health trend, the maintenance done and the uptime/incidents — and ends with
     * an actionable recommendation ONLY when the data justifies it (downtime, traffic spikes,
     * vulnerabilities, stale maintenance). When the site is healthy it just reassures, never
     * inventing problems. Plain language, the client's locale, no markdown.
     *
     * @param  array<string, mixed>  $facts
     */
    public function advisory(array $facts, string $locale = 'es'): string
    {
        $system = 'Eres un consultor de una agencia web que cuida el sitio de un cliente. A partir de los datos del informe '
            .'(periodo actual, comparación con el periodo anterior, tendencia de salud de varios meses, mantenimiento realizado y '
            ."disponibilidad/caídas), escribe un diagnóstico breve y claro para un cliente NO técnico, en el idioma '{$locale}'. "
            .'3 a 5 frases. Menciona cifras concretas (subidas/bajadas en %, caídas del servidor, actualizaciones aplicadas). '
            .'Termina con UNA recomendación accionable SOLO si los datos la justifican (caídas del servidor, picos de tráfico, '
            .'vulnerabilidades, o mantenimiento insuficiente). Si el sitio está sano y estable, NO inventes problemas ni '
            .'recomiendes nada: cierra con una frase de tranquilidad (su sitio está protegido y en buenas manos con nosotros). '
            .'Sin jerga, sin markdown, sin listas.';

        return trim($this->ai->complete($system, 'Datos del informe (JSON): '.$this->encode($facts)));
    }

    /**
     * AI insights (competitor parity): 3-5 short, plain-language takeaways derived
     * from a report's resolved metrics, in the report's locale. Returns a list of
     * one-sentence strings (the AI is constrained to a JSON array, never free prose).
     *
     * @param  array<string, mixed>  $metrics
     * @return list<string>
     */
    public function insights(array $metrics, string $locale = 'es'): array
    {
        $system = "You are a web-agency analyst. From the report's metrics, write 3 to 5 SHORT, "
            ."plain-language insights for a non-technical client in locale '{$locale}'. Each insight is one "
            .'specific sentence that references the numbers. Return ONLY a JSON array of strings. No jargon, no markdown.';

        $raw = $this->ai->complete($system, 'Metrics (JSON): '.$this->encode($metrics));

        return $this->decodeStringList($raw);
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $raw): array
    {
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return [];
        }

        $list = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return array_slice($list, 0, 5);
    }

    /**
     * @param  list<string>  $catalogKeys
     */
    private function bindingExists(Block $block, array $catalogKeys): bool
    {
        $binding = $block->binding;

        if ($binding === null) {
            return true;
        }

        $source = $binding['source'] ?? null;
        $metric = $binding['metric'] ?? null;

        if (! is_string($source) || ! is_string($metric)) {
            return true;
        }

        return in_array("{$source}.{$metric}", $catalogKeys, true);
    }

    /**
     * @return list<array{source: string, key: string, label: string, type: string}>
     */
    private function siteCatalog(Site $site): array
    {
        $entries = [];

        foreach ($site->dataSources()->get() as $source) {
            if (! $this->registry->has($source->type->value)) {
                continue;
            }

            foreach ($this->registry->for($source)->metricCatalog($source)->all() as $definition) {
                $entries[] = [
                    'source' => $source->type->value,
                    'key' => $definition->key,
                    'label' => $definition->label,
                    'type' => $definition->type->value,
                ];
            }
        }

        return $entries;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You assemble a branded client report as a block layout for "Imagina Reports".
        Return ONLY a JSON object: {"blocks": [...], "narrative": "..."}.
        Each block is {"id": string, "type": one of
        [header,kpi,chart,table,narrative,healthscore,security_shield,worklog_timeline,image,divider,sales_summary,goal,cta,comments,custom],
        "binding": {"source": string, "metric": string}|null, "props": object, "style": object}.
        A "goal" block also needs props.target (a number). A "cta" block uses props.headline/text/buttonLabel/buttonUrl.
        Data blocks (kpi,chart,table,sales_summary,goal) MUST bind to a metric from the provided catalog
        (use the catalog's "source" and "metric"). Never invent metrics. Ids must be unique. No prose outside the JSON.
        PROMPT;
    }

    /**
     * @param  list<array{source: string, key: string, label: string, type: string}>  $catalog
     */
    private function userPrompt(Site $site, array $catalog, ?string $prompt): string
    {
        $context = "Site: {$site->name} ({$site->url}). Available metric catalog (JSON): ".$this->encode($catalog);

        if ($prompt !== null && $prompt !== '') {
            $context .= "\nUser focus: {$prompt}";
        }

        return $context;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encode(mixed $value): string
    {
        $json = json_encode($value);

        return $json === false ? '[]' : $json;
    }
}
