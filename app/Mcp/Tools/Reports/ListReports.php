<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Reports;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

final class ListReports extends ReadTool
{
    public function name(): string
    {
        return 'list_reports';
    }

    public function title(): string
    {
        return 'Listar reportes';
    }

    public function description(): string
    {
        return 'Los reportes generados, del más reciente al más antiguo, con su estado (draft = borrador, approved = aprobado, sent = enviado), '
            .'puntuación de salud y enlace del portal del cliente. Filtra por «site_id» o «status».';
    }

    public function ability(): McpAbility
    {
        return McpAbility::Read;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Solo los reportes de este sitio (opcional).'),
            'status' => ['type' => 'string', 'enum' => ['draft', 'approved', 'sent'], 'description' => 'Solo los reportes en este estado (opcional).'],
            'limit' => self::integer('Cuántos devolver (por defecto 20, máximo 100).'),
        ];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->optionalInt('site_id');
        $status = $arguments->optionalString('status');
        $limit = max(1, min($arguments->optionalInt('limit') ?? 20, 100));

        $definitions = [];
        foreach ($this->fetchList($context, 'report-definitions') as $definition) {
            $id = self::intOrNull($definition['id'] ?? null);
            if ($id !== null) {
                $definitions[$id] = $definition;
            }
        }
        $sites = [];
        foreach ($this->fetchList($context, 'sites') as $site) {
            $id = self::intOrNull($site['id'] ?? null);
            if ($id !== null) {
                $sites[$id] = self::str($site['name'] ?? '');
            }
        }

        $reports = [];
        foreach ($this->fetchList($context, 'reports', ['limit' => 100]) as $report) {
            $definition = $definitions[self::intOrNull($report['report_definition_id'] ?? null) ?? 0] ?? [];
            $reportSite = self::intOrNull($definition['site_id'] ?? null);

            if (($siteId !== null && $reportSite !== $siteId) || ($status !== null && ($report['status'] ?? null) !== $status)) {
                continue;
            }

            $reports[] = [
                ...self::pick($report, ['id', 'status', 'health_score', 'period_start', 'period_end', 'created_at']),
                'sitio' => $reportSite !== null ? ($sites[$reportSite] ?? null) : null,
                'site_id' => $reportSite,
                'configuracion' => $definition['name'] ?? null,
                'portal_url' => self::portalUrl($report['public_token'] ?? null),
            ];

            if (count($reports) >= $limit) {
                break;
            }
        }

        return ToolResult::data(['reportes' => $reports, 'total' => count($reports)]);
    }

    public static function portalUrl(mixed $token): ?string
    {
        return is_string($token) && $token !== '' ? url('/portal/'.$token) : null;
    }
}
