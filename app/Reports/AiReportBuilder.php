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
     * @return array{blocks: list<array<string, mixed>>, narrative: string}
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
        foreach ($blocks as $block) {
            if (! $this->bindingExists($block, $catalogKeys)) {
                continue; // drop blocks the AI tried to bind to a non-existent metric
            }

            $visible[] = $block->toArray();
        }

        $narrative = $json['narrative'] ?? '';

        return [
            'blocks' => $visible,
            'narrative' => is_string($narrative) ? $narrative : '',
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
        [header,kpi,chart,table,narrative,healthscore,security_shield,worklog_timeline,image,divider,sales_summary,custom],
        "binding": {"source": string, "metric": string}|null, "props": object, "style": object}.
        Data blocks (kpi,chart,table,sales_summary) MUST bind to a metric from the provided catalog
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
