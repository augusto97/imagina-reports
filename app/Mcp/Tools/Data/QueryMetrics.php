<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Data;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

/**
 * Answers "how is X doing?" from data already synced.
 *
 * It goes through the editor's live-preview endpoint with one KPI block per metric, so the
 * numbers are resolved by exactly the same engine as a real report — including the comparison
 * with the previous period — and it never calls an external API (CLAUDE.md §3.1/§3.3).
 */
final class QueryMetrics extends ReadTool
{
    private const MAX_METRICS = 20;

    private const MAX_ROWS = 25;

    public function name(): string
    {
        return 'query_metrics';
    }

    public function title(): string
    {
        return 'Consultar métricas';
    }

    public function description(): string
    {
        return 'Lee métricas ya sincronizadas de un sitio para un periodo y las compara con el periodo anterior. '
            .'Úsala para preguntas como «¿cómo va el tráfico de este mes?». Las claves salen de get_metric_catalog (p. ej. «ga4.sessions»). '
            .'Periodo: «month» (AAAA-MM) o «period_start»+«period_end»; si no indicas nada, el último mes completo. '
            .'Si una métrica no tiene datos, sincroniza el sitio (propose_sync_site).';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'metrics' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Claves de métrica, p. ej. ["ga4.sessions", "gsc.clicks"]. Máximo 20.'],
            'month' => self::text('Mes AAAA-MM (opcional).'),
            'period_start' => self::date('Inicio del periodo (opcional).'),
            'period_end' => self::date('Fin del periodo (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'metrics'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $keys = array_values(array_unique($arguments->stringList('metrics')));

        if ($keys === []) {
            throw new ToolError('Indica al menos una métrica en «metrics».');
        }
        if (count($keys) > self::MAX_METRICS) {
            throw new ToolError('Como máximo '.self::MAX_METRICS.' métricas por consulta.');
        }

        $period = self::period($arguments->optionalString('month'), $arguments->optionalDate('period_start'), $arguments->optionalDate('period_end'));

        $blocks = [];
        foreach ($keys as $index => $key) {
            $dot = strpos($key, '.');
            if ($dot === false || $dot === 0 || $dot === strlen($key) - 1) {
                throw new ToolError("«{$key}» no es una clave de métrica válida (formato fuente.metrica, p. ej. ga4.sessions).");
            }
            $blocks[] = [
                'id' => 'm'.$index,
                'type' => 'kpi',
                'binding' => ['source' => substr($key, 0, $dot), 'metric' => substr($key, $dot + 1), 'compare' => 'prev_period'],
            ];
        }

        $response = $this->api->call($context, 'POST', "sites/{$siteId}/preview", ['blocks' => $blocks, ...$period])->orFail();
        $data = $response->get('data');
        $data = is_array($data) ? $data : [];

        $results = [];
        $withoutData = [];
        foreach ($keys as $index => $key) {
            if (! array_key_exists('m'.$index, $data)) {
                $withoutData[] = $key;

                continue;
            }
            $results[$key] = $this->compact($data['m'.$index]);
        }

        return ToolResult::data([
            'periodo' => $period,
            'metricas' => $results,
            'sin_datos_en_el_periodo' => $withoutData,
            'fuentes_con_datos' => $response->get('sources_with_data'),
        ]);
    }

    /**
     * Tables are cut to their first rows: enough to answer, small enough for a chat.
     */
    private function compact(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value) && count($value) > self::MAX_ROWS) {
            return ['filas' => array_slice($value, 0, self::MAX_ROWS), 'filas_totales' => count($value)];
        }

        return $value;
    }
}
