<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class GetReport extends ReadTool
{
    private const MAX_ROWS = 10;

    public function name(): string
    {
        return 'get_report';
    }

    public function title(): string
    {
        return 'Ver un reporte';
    }

    public function description(): string
    {
        return 'Un reporte generado: estado, puntuación de salud, resumen ejecutivo, recomendaciones, métricas que se ocultaron por falta de datos, '
            .'enlace del portal y los datos de cada bloque en forma legible.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return ['report_id' => self::integer('Id del reporte (búscalo con list_reports).')];
    }

    protected function required(): array
    {
        return ['report_id'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $reportId = $arguments->int('report_id');
        $report = $this->fetch($context, "reports/{$reportId}");

        $summary = [];
        foreach ($this->fetchList($context, 'reports', ['limit' => 1000]) as $candidate) {
            if (self::intOrNull($candidate['id'] ?? null) === $reportId) {
                $summary = $candidate;

                break;
            }
        }

        $data = is_array($report['data'] ?? null) ? $report['data'] : [];
        $blocks = [];
        foreach (is_array($report['blocks'] ?? null) ? $report['blocks'] : [] as $block) {
            if (! is_array($block) || ! is_array($block['binding'] ?? null)) {
                continue;
            }
            $id = self::str($block['id'] ?? '');
            if ($id === '' || ! array_key_exists($id, $data)) {
                continue;
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $binding = $block['binding'];
            $label = self::str($props['title'] ?? '') ?: (self::str($props['label'] ?? '') ?: self::str($binding['source'] ?? '').'.'.self::str($binding['metric'] ?? ''));

            $blocks[] = ['bloque' => $label, 'tipo' => $block['type'] ?? null, 'valor' => $this->compact($data[$id])];
        }

        return ToolResult::data([
            'id' => $reportId,
            'estado' => $report['status'] ?? null,
            'periodo' => ['desde' => $report['period_start'] ?? null, 'hasta' => $report['period_end'] ?? null],
            'puntuacion_de_salud' => $report['health_score'] ?? null,
            'cliente' => data_get($report, 'context.client'),
            'sitio' => data_get($report, 'context.site'),
            'moneda' => $report['currency'] ?? null,
            'resumen_ejecutivo' => $summary['executive_summary'] ?? null,
            'recomendaciones' => $summary['advisory'] ?? null,
            'metricas_ocultas_por_falta_de_datos' => $summary['hidden_metrics'] ?? [],
            'portal_url' => ListReports::portalUrl($summary['public_token'] ?? null),
            'bloques' => $blocks,
        ]);
    }

    private function compact(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value) && count($value) > self::MAX_ROWS) {
            return ['filas' => array_slice($value, 0, self::MAX_ROWS), 'filas_totales' => count($value)];
        }

        return $value;
    }
}
