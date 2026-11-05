<?php

declare(strict_types=1);

namespace App\Reports;

use App\Ai\AiClient;
use App\Ai\AnthropicAiClient;
use App\Connectors\ConnectorRegistry;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Reports\Blocks\Block;
use App\Reports\Blocks\BlocksValidator;
use App\Reports\Blocks\BlockValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The "create a report in seconds" feature (CLAUDE.md §10.6). Two modes, both via
 * the Claude API (AiClient): template assembly and per-period narrative. The AI
 * returns structured blocks, never free prose — output is validated by
 * BlocksValidator AND every binding is checked against the site's real catalog, so
 * it can never invent metrics or bind to data that doesn't exist.
 */
final readonly class AiReportBuilder
{
    /**
     * Block types that belong to a whole report, not a single inserted section
     * (assembleSection strips them so the AI can't prepend a second cover/header).
     */
    private const SECTION_EXCLUDED_TYPES = ['header', 'cover', 'cta'];

    /**
     * Room for a whole report plan, thinking included (the client streams it and never asks for
     * more than the model allows). 16k was too little for a big multi-page report on a model that
     * reasons before answering.
     */
    private const PLAN_TOKENS = 64000;

    public function __construct(
        private AiClient $ai,
        private ConnectorRegistry $registry,
        private BlocksValidator $validator,
    ) {}

    /**
     * «Generar con IA»: the whole report. The AI writes a compact PLAN (pages, and per page which
     * metric to show and how); AiPlanLayout turns it into valid blocks on a tidy grid. Metrics the
     * site doesn't have are dropped and reported, never rendered (§10.6).
     *
     * @return array{blocks: list<array<string, mixed>>, narrative: string, dropped: list<array{type: string, metric: string}>, pages: list<string>}
     *
     * @throws AiReportException
     */
    public function assembleTemplate(Site $site, ?string $prompt = null): array
    {
        $catalog = $this->siteCatalog($site);
        if ($catalog === []) {
            throw new AiReportException('Este sitio todavía no tiene fuentes conectadas: conecta al menos una (Analytics, Search Console, MainWP…) y vuelve a intentarlo.');
        }

        $json = $this->ask($this->systemPrompt($this->language($site)), $this->userPrompt($site, $catalog, $prompt));
        $narrative = is_string($json['narrative'] ?? null) ? $json['narrative'] : '';

        // The plan format (pages/items); a layout of raw blocks is still accepted.
        if (! isset($json['pages']) && ! isset($json['items']) && isset($json['blocks'])) {
            return [...$this->fromBlocks($json['blocks'], $catalog, false), 'narrative' => $narrative, 'pages' => []];
        }

        $built = (new AiPlanLayout)->build($json, $catalog);
        if ($this->dataBlocks($built['blocks']) === 0) {
            throw new AiReportException($built['dropped'] !== []
                ? 'La IA eligió métricas que este sitio no tiene. Prueba de nuevo o pide algo más concreto.'
                : 'La IA no propuso ningún bloque con datos. Prueba de nuevo o describe qué quieres ver.');
        }

        return ['blocks' => $built['blocks'], 'narrative' => $narrative, 'dropped' => $built['dropped'], 'pages' => $built['pages']];
    }

    /**
     * "Add a section with AI" (§11.3 editor): builds ONLY the blocks for a described
     * section — no cover, header, executive summary or CTA — so they can be appended to
     * an existing canvas instead of replacing it. Same catalog validation as
     * assembleTemplate: invented bindings are dropped, never rendered.
     *
     * @return array{blocks: list<array<string, mixed>>, dropped: list<array{type: string, metric: string}>}
     *
     * @throws AiReportException
     */
    public function assembleSection(Site $site, string $prompt): array
    {
        $catalog = $this->siteCatalog($site);
        if ($catalog === []) {
            throw new AiReportException('Este sitio todavía no tiene fuentes conectadas.');
        }

        $json = $this->ask($this->sectionSystemPrompt($this->language($site)), $this->userPrompt($site, $catalog, $prompt));

        if (! isset($json['items']) && isset($json['blocks'])) {
            return $this->fromBlocks($json['blocks'], $catalog, true);
        }

        $items = is_array($json['items'] ?? null) ? $json['items'] : [];

        return (new AiPlanLayout)->section($items, $catalog);
    }

    /**
     * «Pedir cambios»: the agency describes a change to the report on the canvas («barras en vez de
     * líneas», «añade una página de anuncios», «títulos más claros»). The AI gets the current report
     * as a plan — every item with the `ref` of its block — and returns the revised plan; what it
     * keeps comes back exactly as it was (manual settings included), the rest is rebuilt.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $pageNames
     * @return array{blocks: list<array<string, mixed>>, dropped: list<array{type: string, metric: string}>, pages: list<string>}
     *
     * @throws AiReportException
     */
    public function adjustTemplate(Site $site, string $prompt, array $blocks, array $pageNames): array
    {
        $catalog = $this->siteCatalog($site);
        $current = AiPlanLayout::toPlan($blocks, $pageNames);
        if ($current['pages'] === []) {
            throw new AiReportException('El lienzo está vacío: genera un informe primero o elige una plantilla.');
        }

        $user = $this->userPrompt($site, $catalog, null)
            ."\n\nThe current report (JSON plan):\n".$this->encode($current)
            ."\n\nWhat the agency wants changed (follow it):\n".Str::limit(trim($prompt), 4000);
        $json = $this->ask($this->adjustSystemPrompt($this->language($site)), $user);

        $built = (new AiPlanLayout)->build($json, $catalog, $blocks);
        if ($built['blocks'] === []) {
            throw new AiReportException('La IA devolvió un informe vacío. Prueba a describir el cambio con otras palabras.');
        }

        return ['blocks' => $built['blocks'], 'dropped' => $built['dropped'], 'pages' => $built['pages']];
    }

    /**
     * One call, read as JSON, with one retry when the answer isn't valid JSON. Failures come back
     * as a message the person can act on (bad key, no balance, a cut answer…), not a generic one.
     *
     * @return array<array-key, mixed>
     *
     * @throws AiReportException
     */
    private function ask(string $system, string $user): array
    {
        // A whole multi-page plan can take the model a minute or two; don't let PHP's default cut it.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $this->ai->complete($system, $attempt === 1 ? $user : $user."\n\nYour previous answer was not a valid JSON object. Reply again with ONLY the JSON object, nothing else.", self::PLAN_TOKENS);
            $json = $this->decode($raw);
            if ($json !== null) {
                return $json;
            }

            $reason = $this->ai instanceof AnthropicAiClient ? $this->ai->lastError() : null;
            Log::warning('AI report builder: unusable answer', ['attempt' => $attempt, 'length' => strlen($raw), 'reason' => $reason]);
            if ($raw === '' && $reason !== null && ! str_contains($reason, 'incompleta')) {
                // The AI itself failed (key, balance, model, network): retrying won't help.
                throw new AiReportException('No se pudo usar la IA: '.$reason);
            }
        }

        $reason = $this->ai instanceof AnthropicAiClient ? $this->ai->lastError() : null;

        throw new AiReportException($reason !== null && str_contains($reason, 'incompleta')
            ? 'La respuesta de la IA quedó incompleta porque el informe pedido era muy grande. Pide menos páginas o inténtalo otra vez.'
            : 'La IA no devolvió un borrador que se pueda usar. Inténtalo otra vez; si se repite, describe el informe con otras palabras.');
    }

    /**
     * A layout of raw blocks (the earlier format): each block is checked on its own, so one bad
     * block is left out instead of failing the whole draft.
     *
     * @param  list<array{source: string, key: string, label: string, type: string, unit: string|null, dimensions: list<string>, measures: list<string>, has_data: bool}>  $catalog
     * @return array{blocks: list<array<string, mixed>>, dropped: list<array{type: string, metric: string}>}
     *
     * @throws AiReportException
     */
    private function fromBlocks(mixed $raw, array $catalog, bool $section): array
    {
        $catalogKeys = array_map(static fn (array $entry): string => $entry['key'], $catalog);
        $visible = [];
        $dropped = [];
        $errors = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $candidate) {
            try {
                $block = $this->validator->validate([$candidate])[0] ?? null;
            } catch (BlockValidationException $exception) {
                $errors = [...$errors, ...$exception->errors];

                continue;
            }
            if ($block === null || ($section && in_array($block->type->value, self::SECTION_EXCLUDED_TYPES, true))) {
                // Section mode inserts into an existing layout: cover/header/CTA don't belong.
                continue;
            }
            if (! $this->bindingExists($block, $catalogKeys)) {
                // Bound to a metric the site doesn't have — dropped so it can never invent data
                // (§10.6), and reported so the editor can say what was left out.
                $binding = is_array($block->binding) ? $block->binding : [];
                $source = is_string($binding['source'] ?? null) ? $binding['source'] : '';
                $metric = is_string($binding['metric'] ?? null) ? $binding['metric'] : '';
                $dropped[] = ['type' => $block->type->value, 'metric' => $source !== '' && $metric !== '' ? "{$source}.{$metric}" : $metric];

                continue;
            }
            $visible[] = $block->toArray();
        }

        if ($visible === [] && $errors !== []) {
            throw new AiReportException('La IA devolvió bloques que no se pueden usar. Inténtalo otra vez.');
        }

        return ['blocks' => $visible, 'dropped' => $dropped];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function dataBlocks(array $blocks): int
    {
        return count(array_filter($blocks, static fn (array $block): bool => $block['binding'] !== null));
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
     * Everything the site's sources can show, and whether each metric has data in the latest sync
     * (so the AI favours what will actually render).
     *
     * @return list<array{source: string, key: string, label: string, type: string, unit: string|null, dimensions: list<string>, measures: list<string>, has_data: bool, source_label: string}>
     */
    private function siteCatalog(Site $site): array
    {
        $entries = [];

        foreach ($site->dataSources()->get() as $source) {
            if (! $this->registry->has($source->type->value)) {
                continue;
            }

            $latest = MetricSnapshot::query()
                ->where('data_source_id', $source->id)
                ->orderByDesc('period_end')
                ->value('payload');
            $latest = is_string($latest) ? json_decode($latest, true) : $latest;
            $metrics = is_array($latest) && is_array($latest['metrics'] ?? null) ? $latest['metrics'] : [];

            foreach ($this->registry->for($source)->metricCatalog($source)->all() as $definition) {
                $value = $metrics[$definition->key] ?? null;
                $entries[] = [
                    'source' => $source->type->value,
                    'source_label' => $source->type->label(),
                    'key' => $definition->key,
                    'label' => $definition->label,
                    'type' => $definition->type->value,
                    'unit' => $definition->unit,
                    'dimensions' => array_values(array_filter($definition->dimensions, 'is_string')),
                    'measures' => array_map(static fn (array $measure): string => $measure['key'], $definition->measures),
                    'has_data' => $value !== null && $value !== [] && $value !== '',
                ];
            }
        }

        return $entries;
    }

    private function systemPrompt(string $language): string
    {
        return <<<PROMPT
        You design branded, client-facing website reports for a web agency ("Imagina Reports").
        The reader is the agency's client: not technical, reads it in 1-2 minutes, wants to see the value of the work.

        Reply with ONLY one JSON object (no prose, no markdown fences) in this exact shape:
        {"title": "cover title",
         "pages": [
           {"name": "Portada", "type": "cover"},
           {"name": "page name", "type": "content", "items": [ITEM, ...]},
           {"name": "Cierre", "type": "back_cover"}
         ]}
        ITEM is one of:
        - {"kind": "kpi", "metric": "<catalog key>", "title": "short label"}            one headline number (scalar or dataset metric)
        - {"kind": "chart", "metric": "<key>", "chart": "area"|"line"|"bar"|"donut", "title": "..."}   series → area/line/bar over time; table/dataset → donut (shares) or bar (ranking)
        - {"kind": "table", "metric": "<key>", "title": "..."}                            top-N list (table or dataset metric)
        - {"kind": "map", "metric": "<key>", "title": "..."}                              countries/cities (table/dataset with a geographic breakdown)
        - {"kind": "funnel", "metric": "<key>", "title": "..."}                           ordered steps (table metric)
        - {"kind": "healthscore"}   the 0-100 health gauge          - {"kind": "summary"}   AI-written executive summary of the month
        - {"kind": "advisory"}      diagnosis + recommendation      - {"kind": "security"}  security shield visual
        - {"kind": "worklog"}       "what we did this month"        - {"kind": "goals"}     the client's goals and progress
        - {"kind": "comments"}      notes from the team             - {"kind": "text", "title": "...", "text": "1-2 sentences explaining the section"}
        Optional on any item: "size": "small"|"medium"|"large"|"full". For dataset metrics, optional "by": one of its dimensions and "measure": one of its measures.

        Rules:
        - "metric" must be a key copied EXACTLY from the catalog. Never invent keys. Prefer metrics marked has_data.
        - Match the visual to the metric type shown in the catalog (scalar → kpi; series → chart; table/dataset → table, donut, bar, map or funnel).
        - Use every source that has data unless the user asks otherwise. Group by theme: one page per area (overview, security, uptime, traffic, SEO, ads, sales, maintenance…).
        - Multi-page reports: start with a cover page and end with a back_cover page. A short single-page report needs neither.
        - First content page: healthscore, summary, the 4-8 most important KPIs, and advisory.
        - Each content page: 3-4 KPIs first, then 2-6 charts/tables/maps. 5-14 items per page. Never the same metric twice with the same visual.
        - At most 10 pages and 100 items in total, even when asked for «all» metrics: pick the useful, interesting ones and skip near-duplicates.
        - Include worklog (on the maintenance page) and goals once each when it makes sense.
        - "text" items explain a section in plain words; never write figures in them (the data changes every month).
        - Titles: short (max 40 characters), plain words for a non-technical client, no metric keys or jargon.
        - Write all names, titles and texts in {$language}.
        PROMPT;
    }

    private function sectionSystemPrompt(string $language): string
    {
        return <<<PROMPT
        You build ONE section of a branded, client-facing website report for a web agency ("Imagina Reports").
        Reply with ONLY one JSON object (no prose, no markdown fences): {"items": [ITEM, ...]} with 2-8 items.
        ITEM is one of:
        - {"kind": "kpi", "metric": "<catalog key>", "title": "short label"}
        - {"kind": "chart", "metric": "<key>", "chart": "area"|"line"|"bar"|"donut", "title": "..."}
        - {"kind": "table", "metric": "<key>", "title": "..."}
        - {"kind": "map", "metric": "<key>", "title": "..."}
        - {"kind": "funnel", "metric": "<key>", "title": "..."}
        - {"kind": "security"}, {"kind": "worklog"}, {"kind": "goals"}, {"kind": "comments"}, {"kind": "text", "title": "...", "text": "..."}
        Optional: "size": "small"|"medium"|"large"|"full"; for dataset metrics "by" (a dimension) and "measure".
        Rules: "metric" must be a key copied EXACTLY from the catalog (prefer has_data); match the visual to the metric type;
        KPIs first, then charts/tables; titles short and plain; no figures inside "text". Write titles in {$language}.
        PROMPT;
    }

    private function adjustSystemPrompt(string $language): string
    {
        return <<<PROMPT
        You revise a branded, client-facing website report for a web agency ("Imagina Reports"). You receive the
        current report as a JSON plan and the change the agency wants. Reply with ONLY the COMPLETE revised plan as one
        JSON object (no prose, no markdown fences), in the same shape:
        {"title": "...", "pages": [{"name": "...", "type": "cover"|"content"|"back_cover", "ref"?: "...", "header_ref"?: "...", "items": [ITEM, ...]}]}
        ITEM kinds: kpi, chart (chart: area|line|bar|donut), table, map, funnel, goal, healthscore, summary, advisory,
        security, worklog, goals, comments, text, keep. Data kinds need "metric": a key copied EXACTLY from the catalog.
        Optional on any item: "title", "size": small|medium|large|full; dataset metrics: "by" and "measure".

        Rules:
        - Change ONLY what the agency asked for. Everything else stays as it is, in the same order.
        - Items of the current report carry a "ref". Keep the ref on every item you keep, even if you change its title,
          chart type or size: that preserves its manual settings. Keep "ref" and "header_ref" on pages you keep.
          New items have no ref. Remove an item by leaving it out. Never invent a ref.
        - "keep" items are blocks you can't edit (images, buttons…): keep them as they are unless asked to remove them.
        - Match the visual to the metric type (scalar → kpi; series → chart; table/dataset → table, donut, bar, map, funnel).
        - Titles short (max 40 characters) and plain. No figures inside "text" items.
        - At most 10 pages and 100 items in total.
        - Write any new names, titles and texts in {$language}.
        PROMPT;
    }

    /**
     * The catalog as compact lines, grouped by source (much smaller than JSON for big catalogs).
     *
     * @param  list<array{source: string, key: string, label: string, type: string, unit: string|null, dimensions: list<string>, measures: list<string>, has_data: bool, source_label: string}>  $catalog
     */
    private function userPrompt(Site $site, array $catalog, ?string $prompt): string
    {
        $lines = [];
        $current = null;
        foreach ($catalog as $entry) {
            if ($entry['source'] !== $current) {
                $current = $entry['source'];
                $withData = count(array_filter($catalog, static fn (array $e): bool => $e['source'] === $current && $e['has_data']));
                $lines[] = '';
                $lines[] = "Source {$entry['source']} ({$entry['source_label']})".($withData > 0 ? " — {$withData} metrics with data" : ' — no data synced yet');
            }
            $extra = $entry['type'] === 'dataset'
                ? ' dims: '.implode(',', $entry['dimensions']).'; measures: '.implode(',', $entry['measures'])
                : '';
            $lines[] = "- {$entry['key']} | {$entry['label']} | {$entry['type']}".($entry['unit'] !== null ? ", {$entry['unit']}" : '').$extra.($entry['has_data'] ? ' | has_data' : '');
        }

        $context = "Site: {$site->name} ({$site->url}).\nMetric catalog (key | label | type, unit | has_data):".implode("\n", $lines);
        if ($prompt !== null && trim($prompt) !== '') {
            $context .= "\n\nWhat the agency asked for (follow it):\n".Str::limit(trim($prompt), 4000);
        }

        return $context;
    }

    private function language(Site $site): string
    {
        $locale = $site->client !== null && is_string($site->client->locale) && $site->client->locale !== '' ? $site->client->locale : 'es';

        return match (substr($locale, 0, 2)) {
            'en' => 'English',
            'pt' => 'Brazilian Portuguese',
            default => 'neutral Spanish (address the reader as «tú», never «vos»)',
        };
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
