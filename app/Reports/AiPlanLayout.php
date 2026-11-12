<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Blocks\BlockType;

/**
 * Turns the AI's report PLAN into real blocks (CLAUDE.md §10.6). The AI decides WHAT goes in the
 * report — pages, which metric, which kind of visual, the titles — in a compact format; this class
 * decides HOW: valid block JSON, bindings, formats and a tidy 12-column layout per page. So the AI
 * can't produce a broken layout, can't bind to a metric the site doesn't have (it's dropped and
 * reported), and a big multi-page report stays small enough for the model to write reliably.
 *
 * Plan: {"title": "…", "pages": [{"name": "…", "type": "cover"|"back_cover"|"content", "items": [ITEM…]}]}
 * ITEM: {"kind": kpi|chart|table|map|funnel|goal|healthscore|summary|advisory|security|worklog|goals|comments|text,
 *        "metric": "source.key", "title": "…", "chart": line|area|bar|donut, "size": small|medium|large|full,
 *        "by": dimension, "measure": measure, "text": "…", "target": number}
 *
 * @phpstan-type CatalogEntry array{source: string, key: string, label: string, type: string, unit: string|null, dimensions: list<string>, measures: list<string>, has_data: bool}
 * @phpstan-type Dropped array{type: string, metric: string}
 * @phpstan-type Built array{type: BlockType, binding: array<string, mixed>|null, props: array<string, mixed>, style: array<string, mixed>, w: int, h: int, wide: bool, keep?: array<array-key, mixed>}
 */
final class AiPlanLayout
{
    private const COLS = 12;

    /** Kinds that need a metric from the catalog. */
    private const DATA_KINDS = ['kpi', 'chart', 'table', 'map', 'funnel', 'goal'];

    private const GEO_DIMENSIONS = ['country', 'region', 'city'];

    /** @var list<Dropped> */
    private array $dropped = [];

    /**
     * The current report's blocks by id, when ADJUSTING it: a plan item that carries the `ref` of
     * one of them (and still shows the same data the same way) reuses that block — its manual
     * settings and size — instead of rebuilding it from scratch.
     *
     * @var array<string, array<array-key, mixed>>
     */
    private array $originals = [];

    /** @var array<string, true> */
    private array $usedIds = [];

    /**
     * @param  array<array-key, mixed>  $plan
     * @param  list<CatalogEntry>  $catalog
     * @param  array<array-key, mixed>  $originals  The current blocks, when adjusting a report.
     * @return array{blocks: list<array<string, mixed>>, pages: list<string>, dropped: list<Dropped>}
     */
    public function build(array $plan, array $catalog, array $originals = []): array
    {
        $this->dropped = [];
        $this->usedIds = [];
        $this->originals = [];
        foreach ($originals as $original) {
            if (is_array($original) && is_string($original['id'] ?? null)) {
                $this->originals[$original['id']] = $original;
            }
        }
        $index = $this->index($catalog);
        $title = is_string($plan['title'] ?? null) && trim($plan['title']) !== '' ? trim($plan['title']) : 'Informe de tu sitio';
        $pagesIn = is_array($plan['pages'] ?? null) ? array_values($plan['pages']) : [];
        // A plan that's just a list of items is one page.
        if ($pagesIn === [] && is_array($plan['items'] ?? null)) {
            $pagesIn = [['name' => $title, 'items' => $plan['items']]];
        }

        $blocks = [];
        $names = [];
        foreach ($pagesIn as $pageIn) {
            if (! is_array($pageIn)) {
                continue;
            }
            $page = count($names);
            $name = is_string($pageIn['name'] ?? null) ? trim($pageIn['name']) : '';
            $type = $pageIn['type'] ?? 'content';

            $kept = $this->original($pageIn['ref'] ?? null);
            if ($type === 'cover') {
                $props = $kept !== null && ($kept['type'] ?? null) === BlockType::Cover->value ? $this->props($kept) : [
                    'subtitle' => 'Resumen del trabajo realizado y el rendimiento de tu sitio este periodo.',
                    'showScore' => true,
                ];
                $blocks[] = $this->block(BlockType::Cover, null, [...$props, 'title' => $title], $kept !== null ? $this->style($kept) : [], ['x' => 0, 'y' => 0, 'w' => self::COLS, 'h' => 16], $page, $kept);
                $names[] = $name !== '' ? $name : 'Portada';

                continue;
            }
            if ($type === 'back_cover') {
                $props = $kept !== null && ($kept['type'] ?? null) === BlockType::BackCover->value ? $this->props($kept) : [
                    'headline' => 'Tu plan de soporte está activo y protegiendo tu sitio.',
                    'text' => 'Gracias por confiar en nosotros para cuidar tu sitio cada mes. Cualquier duda, estamos a un mensaje de distancia.',
                    'contact' => '{{agency}}',
                ];
                $blocks[] = $this->block(BlockType::BackCover, null, $props, $kept !== null ? $this->style($kept) : [], ['x' => 0, 'y' => 0, 'w' => self::COLS, 'h' => 16], $page, $kept);
                $names[] = $name !== '' ? $name : 'Cierre';

                continue;
            }

            $items = $this->items(is_array($pageIn['items'] ?? null) ? $pageIn['items'] : [], $index);
            if ($items === []) {
                continue; // nothing usable on this page: leave it out
            }

            $keptHeader = $this->original($pageIn['header_ref'] ?? null);
            $headerProps = $keptHeader !== null && ($keptHeader['type'] ?? null) === BlockType::Header->value
                ? $this->props($keptHeader)
                : ['eyebrow' => '{{agency}}', 'subtitle' => '{{client}} · {{site}} · {{period}}'];
            $header = $this->block(BlockType::Header, null, [...$headerProps, 'title' => $name !== '' ? $name : $title], $keptHeader !== null ? $this->style($keptHeader) : [], ['x' => 0, 'y' => 0, 'w' => self::COLS, 'h' => 3], $page, $keptHeader);
            $blocks = [...$blocks, $header, ...$this->place($items, 3, $page)];
            $names[] = $name !== '' ? $name : 'Página '.($page + 1);
        }

        return ['blocks' => $blocks, 'pages' => $names, 'dropped' => $this->dropped];
    }

    /**
     * Only the blocks for one section, laid out from the top (the editor stacks them below what's there).
     *
     * @param  array<array-key, mixed>  $items
     * @param  list<CatalogEntry>  $catalog
     * @return array{blocks: list<array<string, mixed>>, dropped: list<Dropped>}
     */
    public function section(array $items, array $catalog): array
    {
        $this->dropped = [];
        $this->usedIds = [];
        $this->originals = [];
        $built = $this->items($items, $this->index($catalog));

        return ['blocks' => $this->place($built, 0, 0), 'dropped' => $this->dropped];
    }

    /**
     * @param  array<array-key, mixed>  $itemsIn
     * @param  array<string, CatalogEntry>  $index
     * @return list<Built>
     */
    private function items(array $itemsIn, array $index): array
    {
        $out = [];
        $seen = [];
        foreach ($itemsIn as $item) {
            if (! is_array($item) || ! is_string($item['kind'] ?? null)) {
                continue;
            }
            $built = $this->item($item, $index);
            if ($built === null) {
                continue;
            }
            // The same visual of the same metric twice on a page adds nothing.
            $signature = $built['type']->value.'|'.json_encode($built['binding']);
            if ($built['binding'] !== null && isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $built;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  array<string, CatalogEntry>  $index
     * @return Built|null
     */
    private function item(array $item, array $index): ?array
    {
        $kind = is_string($item['kind'] ?? null) ? strtolower($item['kind']) : '';
        $title = is_string($item['title'] ?? null) ? mb_substr(trim($item['title']), 0, 60) : '';
        $size = is_string($item['size'] ?? null) ? $item['size'] : null;

        // Adjusting: an item the AI kept (same data, same kind of visual) reuses the current block.
        $original = $this->original($item['ref'] ?? null);
        if ($original !== null) {
            $kept = $this->keep($original, $item, $kind, $title, $size, $index);
            if ($kept !== null) {
                return $kept;
            }
        }
        if ($kind === 'keep') {
            return null;
        }

        if (! in_array($kind, self::DATA_KINDS, true)) {
            return $this->staticItem($kind, $title, $item, $size);
        }

        $key = is_string($item['metric'] ?? null) ? trim($item['metric']) : '';
        $entry = $index[$key] ?? null;
        if ($entry === null) {
            $this->dropped[] = ['type' => $kind, 'metric' => $key];

            return null;
        }

        // Match the visual to what the metric can draw (a KPI of a time series becomes a chart…).
        $asked = $kind;
        $kind = $this->fit($kind, $entry, $item);
        if ($kind === null) {
            $this->dropped[] = ['type' => $asked, 'metric' => $key];

            return null;
        }

        $binding = ['source' => $entry['source'], 'metric' => substr($entry['key'], strlen($entry['source']) + 1)];
        $label = $title !== '' ? $title : $entry['label'];
        $format = $this->format($entry['unit']);

        if ($entry['type'] === 'dataset') {
            $binding = [...$binding, ...$this->datasetShape($kind, $entry, $item)];
        }

        return match ($kind) {
            'kpi' => $this->sized(BlockType::Kpi, [...$binding, 'compare' => 'prev_period'], ['label' => $label], $format !== null ? ['format' => $format] : [], $size, 3, 4),
            'goal' => $this->sized(BlockType::Goal, $binding, ['label' => $label, 'target' => is_numeric($item['target'] ?? null) ? $item['target'] + 0 : 100], [], $size, 3, 4),
            'chart' => $this->chartItem($binding, $label, $entry, $item, $format, $size),
            'table' => $this->sized(BlockType::Table, $binding, ['title' => $label], [], $size, 6, 7),
            'map' => $this->sized(BlockType::GeoMap, $binding, ['title' => $label], [], $size, 6, 7),
            default => $this->sized(BlockType::Funnel, $binding, ['title' => $label], [], $size, 6, 7),
        };
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return Built|null
     */
    private function staticItem(string $kind, string $title, array $item, ?string $size): ?array
    {
        return match ($kind) {
            'healthscore' => $this->sized(BlockType::HealthScore, null, ['title' => $title !== '' ? $title : 'Estado general'], [], $size, 4, 7),
            'summary' => $this->sized(BlockType::Narrative, null, ['variant' => 'executive_summary', 'title' => $title !== '' ? $title : 'Resumen del mes'], [], $size, 12, 5),
            'advisory' => $this->sized(BlockType::Advisory, null, ['title' => $title !== '' ? $title : 'Diagnóstico y recomendaciones'], [], $size, 12, 5),
            'security' => $this->sized(BlockType::SecurityShield, null, ['title' => $title !== '' ? $title : 'Tu sitio, protegido'], [], $size, 6, 6),
            'worklog' => $this->sized(BlockType::WorklogTimeline, null, ['title' => $title !== '' ? $title : 'Lo que hicimos este mes'], [], $size, 12, 6),
            'goals' => $this->sized(BlockType::Goals, null, ['title' => $title !== '' ? $title : 'Metas del mes'], [], $size, 12, 6),
            'comments' => $this->sized(BlockType::Comments, null, ['title' => $title !== '' ? $title : 'Notas del equipo'], [], $size, 12, 5),
            'text' => $this->textItem($title, $item, $size),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return Built|null
     */
    private function textItem(string $title, array $item, ?string $size): ?array
    {
        $text = is_string($item['text'] ?? null) ? trim(strip_tags($item['text'])) : '';
        if ($text === '') {
            return null;
        }
        $paragraphs = array_filter(array_map('trim', preg_split('/\n{2,}/', mb_substr($text, 0, 800)) ?: []));
        $html = implode('', array_map(static fn (string $p): string => '<p>'.e($p).'</p>', $paragraphs));

        return $this->sized(BlockType::Narrative, null, array_filter(['title' => $title, 'text' => $html]), [], $size, 12, 3);
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  CatalogEntry  $entry
     * @param  array<array-key, mixed>  $item
     * @return Built
     */
    private function chartItem(array $binding, string $label, array $entry, array $item, ?string $format, ?string $size): array
    {
        $wanted = is_string($item['chart'] ?? null) ? $item['chart'] : null;
        $isSeries = $entry['type'] === 'series';
        // Lines and areas need a time axis; a breakdown (sources, devices…) reads as a donut or bars.
        $chartType = match (true) {
            $isSeries && in_array($wanted, ['line', 'area', 'bar'], true) => $wanted,
            $isSeries => 'area',
            in_array($wanted, ['donut', 'bar'], true) => $wanted,
            default => 'bar',
        };
        $style = array_filter(['format' => $format, 'legend' => $chartType === 'donut' ? true : null], static fn (mixed $v): bool => $v !== null);

        return $this->sized(BlockType::Chart, $binding, ['chartType' => $chartType, 'title' => $label], $style, $size, $chartType === 'donut' ? 4 : 6, 7);
    }

    /**
     * The visual this metric can actually draw, or null when none fits.
     *
     * @param  CatalogEntry  $entry
     * @param  array<array-key, mixed>  $item
     */
    private function fit(string $kind, array $entry, array $item): ?string
    {
        $type = $entry['type'];
        $isGeo = $this->geoDimension($entry, $item) !== null || preg_match('/countr|pais|país|geo|city|region/i', $entry['key']) === 1;

        return match ($kind) {
            'kpi', 'goal' => match ($type) {
                'scalar', 'dataset' => $kind,
                'series' => 'chart',
                'table' => 'table',
                default => null,
            },
            'chart' => in_array($type, ['series', 'table', 'dataset'], true) ? 'chart' : 'kpi',
            'table' => in_array($type, ['table', 'dataset'], true) ? 'table' : ($type === 'series' ? 'chart' : 'kpi'),
            'map' => in_array($type, ['table', 'dataset'], true) && $isGeo ? 'map' : (in_array($type, ['table', 'dataset'], true) ? 'table' : null),
            'funnel' => in_array($type, ['table', 'dataset'], true) ? 'funnel' : null,
            default => null,
        };
    }

    /**
     * How a dataset is shaped for this block: one aggregated number for a KPI, a breakdown otherwise.
     *
     * @param  CatalogEntry  $entry
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>
     */
    private function datasetShape(string $kind, array $entry, array $item): array
    {
        $measure = is_string($item['measure'] ?? null) && in_array($item['measure'], $entry['measures'], true) ? $item['measure'] : ($entry['measures'][0] ?? null);
        $shape = array_filter(['measure' => $measure]);
        if ($kind === 'kpi' || $kind === 'goal') {
            return $shape;
        }

        $by = $kind === 'map'
            ? $this->geoDimension($entry, $item)
            : (is_string($item['by'] ?? null) && in_array($item['by'], $entry['dimensions'], true) ? $item['by'] : ($entry['dimensions'][0] ?? null));

        // Grouped rows are {label, value}: the default order (value, highest first) is the right one.
        return array_filter([...$shape, 'breakdown' => $by, 'limit' => 10]);
    }

    /**
     * @param  CatalogEntry  $entry
     * @param  array<array-key, mixed>  $item
     */
    private function geoDimension(array $entry, array $item): ?string
    {
        if (is_string($item['by'] ?? null) && in_array($item['by'], self::GEO_DIMENSIONS, true) && in_array($item['by'], $entry['dimensions'], true)) {
            return $item['by'];
        }
        foreach (self::GEO_DIMENSIONS as $dimension) {
            if (in_array($dimension, $entry['dimensions'], true)) {
                return $dimension;
            }
        }

        return null;
    }

    private function format(?string $unit): ?string
    {
        return $unit !== null ? (MetricUnits::FORMATS[$unit] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>|null  $binding
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $style
     * @return Built
     */
    private function sized(BlockType $type, ?array $binding, array $props, array $style, ?string $size, int $w, int $h): array
    {
        $wide = $w === self::COLS;
        $w = match ($size) {
            'small' => $type === BlockType::Kpi || $type === BlockType::Goal ? 3 : 4,
            'medium' => $type === BlockType::Kpi || $type === BlockType::Goal ? 4 : 6,
            'large' => 8,
            'full' => self::COLS,
            default => $w,
        };

        return ['type' => $type, 'binding' => $binding, 'props' => $props, 'style' => $style, 'w' => $w, 'h' => $h, 'wide' => $wide && $size === null];
    }

    /**
     * Flow the items into rows on the 12-column grid: a row closes when the next item doesn't fit,
     * its spare columns are shared out so it ends flush, and its items take the row's height.
     * A wide-by-default item (summary, text…) takes the rest of a started row when there's room.
     *
     * @param  list<Built>  $items
     * @return list<array<string, mixed>>
     */
    private function place(array $items, int $top, int $page): array
    {
        $rows = [];
        $row = [];
        $used = 0;
        foreach ($items as $item) {
            if ($item['wide'] && $used > 0 && self::COLS - $used >= 6) {
                $item['w'] = self::COLS - $used;
            }
            if ($used + $item['w'] > self::COLS && $row !== []) {
                $rows[] = $row;
                $row = [];
                $used = 0;
            }
            $row[] = $item;
            $used += $item['w'];
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        $blocks = [];
        $y = $top;
        foreach ($rows as $row) {
            $row = $this->fill($row);
            $height = max(array_map(static fn (array $item): int => $item['h'], $row));
            $x = 0;
            foreach ($row as $item) {
                $blocks[] = $this->block($item['type'], $item['binding'], $item['props'], $item['style'], ['x' => $x, 'y' => $y, 'w' => $item['w'], 'h' => $height], $page, $item['keep'] ?? null);
                $x += $item['w'];
            }
            $y += $height;
        }

        return $blocks;
    }

    /**
     * Share a row's spare columns so it ends flush: KPIs grow evenly, otherwise the visuals get it.
     *
     * @param  non-empty-list<Built>  $row
     * @return non-empty-list<Built>
     */
    private function fill(array $row): array
    {
        $spare = self::COLS - array_sum(array_map(static fn (array $item): int => $item['w'], $row));
        $count = count($row);
        $extra = intdiv(max(0, $spare), $count);
        $remainder = max(0, $spare) % $count;

        $out = [];
        foreach ($row as $i => $item) {
            $item['w'] += $extra + ($i < $remainder ? 1 : 0);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $binding
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $style
     * @param  array{x: int, y: int, w: int, h: int}  $layout
     * @param  array<array-key, mixed>|null  $original
     * @return array<string, mixed>
     */
    private function block(BlockType $type, ?array $binding, array $props, array $style, array $layout, int $page, ?array $original = null): array
    {
        // A kept block keeps its id (anything keyed by it keeps working); new ones get a fresh one.
        $id = is_array($original) && is_string($original['id'] ?? null) && ! isset($this->usedIds[$original['id']])
            ? $original['id']
            : 'ai_'.bin2hex(random_bytes(5));
        $this->usedIds[$id] = true;

        return [
            'id' => $id,
            'type' => $type->value,
            'binding' => $binding,
            'props' => $props,
            'style' => $style,
            'layout' => $layout,
            'page' => $page,
        ];
    }

    /**
     * The current report as a plan the AI can edit: pages with their items, each carrying the `ref`
     * of its block so what the AI leaves alone comes back exactly as it was.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $pageNames
     * @return array{title: string, pages: list<array<string, mixed>>}
     */
    public static function toPlan(array $blocks, array $pageNames): array
    {
        $byPage = [];
        foreach (array_values($blocks) as $order => $block) {
            if (! is_array($block) || ! is_string($block['id'] ?? null) || ! is_string($block['type'] ?? null)) {
                continue;
            }
            $page = is_numeric($block['page'] ?? null) ? max(0, (int) $block['page']) : 0;
            $layout = is_array($block['layout'] ?? null) ? $block['layout'] : [];
            $y = is_numeric($layout['y'] ?? null) ? (int) $layout['y'] : $order * 100;
            $x = is_numeric($layout['x'] ?? null) ? (int) $layout['x'] : 0;
            $byPage[$page][] = ['sort' => $y * 100 + $x, 'block' => $block];
        }
        ksort($byPage);

        $title = 'Informe de tu sitio';
        $pages = [];
        foreach ($byPage as $page => $entries) {
            usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
            $name = is_string($pageNames[$page] ?? null) ? trim($pageNames[$page]) : '';
            $first = $entries[0]['block'];

            if ($first['type'] === BlockType::Cover->value || $first['type'] === BlockType::BackCover->value) {
                $props = is_array($first['props'] ?? null) ? $first['props'] : [];
                if ($first['type'] === BlockType::Cover->value && is_string($props['title'] ?? null) && $props['title'] !== '') {
                    $title = $props['title'];
                }
                $pages[] = ['name' => $name !== '' ? $name : ($first['type'] === BlockType::Cover->value ? 'Portada' : 'Cierre'), 'type' => $first['type'], 'ref' => $first['id']];

                continue;
            }

            $plan = ['name' => $name, 'type' => 'content', 'items' => []];
            foreach ($entries as $position => $entry) {
                $block = $entry['block'];
                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                // The page's own header becomes its name.
                if ($position === 0 && $block['type'] === BlockType::Header->value) {
                    $plan['header_ref'] = $block['id'];
                    if ($plan['name'] === '' && is_string($props['title'] ?? null)) {
                        $plan['name'] = $props['title'];
                    }

                    continue;
                }
                $plan['items'][] = self::planItem($block, $props);
            }
            $pages[] = $plan;
        }

        return ['title' => $title, 'pages' => $pages];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @param  array<array-key, mixed>  $props
     * @return array<string, mixed>
     */
    private static function planItem(array $block, array $props): array
    {
        $typeName = is_string($block['type'] ?? null) ? $block['type'] : '';
        $type = BlockType::tryFrom($typeName);
        $kind = $type !== null ? self::kindOf($type, $props) : 'keep';
        $item = ['ref' => $block['id'], 'kind' => $kind];

        $binding = is_array($block['binding'] ?? null) ? $block['binding'] : [];
        if (is_string($binding['source'] ?? null) && is_string($binding['metric'] ?? null)) {
            $item['metric'] = $binding['source'].'.'.$binding['metric'];
        }
        $title = $props['label'] ?? $props['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $item['title'] = $title;
        }
        if ($kind === 'chart' && is_string($props['chartType'] ?? null)) {
            $item['chart'] = $props['chartType'];
        }
        if ($kind === 'text' && is_string($props['text'] ?? null)) {
            $item['text'] = mb_substr(trim(strip_tags(str_replace('</p>', "\n\n", $props['text']))), 0, 400);
        }
        if ($kind === 'keep') {
            $item['about'] = $typeName;
        }

        return $item;
    }

    /**
     * The plan «kind» a block is shown as.
     *
     * @param  array<array-key, mixed>  $props
     */
    private static function kindOf(BlockType $type, array $props): string
    {
        return match ($type) {
            BlockType::Kpi => 'kpi',
            BlockType::Chart => 'chart',
            BlockType::Table => 'table',
            BlockType::GeoMap => 'map',
            BlockType::Funnel => 'funnel',
            BlockType::Goal => 'goal',
            BlockType::HealthScore => 'healthscore',
            BlockType::Narrative => ($props['variant'] ?? null) === 'executive_summary' ? 'summary' : 'text',
            BlockType::Advisory => 'advisory',
            BlockType::SecurityShield => 'security',
            BlockType::WorklogTimeline => 'worklog',
            BlockType::Goals => 'goals',
            BlockType::Comments => 'comments',
            default => 'keep',
        };
    }

    /**
     * Reuse a current block for an item the AI kept: same data and the same kind of visual. Its
     * manual settings stay; only what the item changes (title, chart type, text, size) is applied.
     *
     * @param  array<array-key, mixed>  $original
     * @param  array<array-key, mixed>  $item
     * @param  array<string, CatalogEntry>  $index
     * @return Built|null
     */
    private function keep(array $original, array $item, string $kind, string $title, ?string $size, array $index): ?array
    {
        $type = BlockType::tryFrom(is_string($original['type'] ?? null) ? $original['type'] : '');
        if ($type === null) {
            return null;
        }
        $props = $this->props($original);
        $binding = is_array($original['binding'] ?? null) ? array_filter($original['binding'], 'is_string', ARRAY_FILTER_USE_KEY) : null;
        $originalKey = is_string($binding['source'] ?? null) && is_string($binding['metric'] ?? null) ? $binding['source'].'.'.$binding['metric'] : null;
        $itemKey = is_string($item['metric'] ?? null) && $item['metric'] !== '' ? $item['metric'] : null;

        $sameKind = $kind === 'keep' || self::kindOf($type, $props) === $kind;
        $sameData = $kind === 'keep' || $itemKey === null || $itemKey === $originalKey;
        if (! $sameKind || ! $sameData) {
            return null; // the AI changed what this shows: build it again from the item
        }

        if ($title !== '') {
            $props[in_array($type, [BlockType::Kpi, BlockType::Goal], true) ? 'label' : 'title'] = $title;
        }
        $style = $this->style($original);
        if ($type === BlockType::Chart && is_string($item['chart'] ?? null) && $originalKey !== null && isset($index[$originalKey])) {
            $chart = $this->chartItem([], '', $index[$originalKey], $item, null, null);
            $props['chartType'] = $chart['props']['chartType'];
            $style['legend'] = $props['chartType'] === 'donut' ? true : ($style['legend'] ?? null);
            $style = array_filter($style, static fn (mixed $value): bool => $value !== null);
        }
        if ($kind === 'text' && is_string($item['text'] ?? null) && trim($item['text']) !== '') {
            $text = $this->textItem($title, $item, null);
            if ($text !== null) {
                $props['text'] = $text['props']['text'];
            }
        }

        $layout = is_array($original['layout'] ?? null) ? $original['layout'] : [];
        $w = is_numeric($layout['w'] ?? null) ? max(1, min(self::COLS, (int) $layout['w'])) : 6;
        $h = is_numeric($layout['h'] ?? null) ? max(1, (int) $layout['h']) : 5;
        $built = $this->sized($type, $binding, $props, $style, $size, $w, $h);
        $built['wide'] = false;
        $built['keep'] = $original;

        return $built;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function original(mixed $ref): ?array
    {
        return is_string($ref) ? ($this->originals[$ref] ?? null) : null;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function props(array $block): array
    {
        $props = $block['props'] ?? [];

        return is_array($props) ? array_filter($props, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>
     */
    private function style(array $block): array
    {
        $style = $block['style'] ?? [];

        return is_array($style) ? array_filter($style, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param  list<CatalogEntry>  $catalog
     * @return array<string, CatalogEntry>
     */
    private function index(array $catalog): array
    {
        $index = [];
        foreach ($catalog as $entry) {
            $index[$entry['key']] = $entry;
        }

        return $index;
    }
}
