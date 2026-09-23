<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\Clients\GetSite;
use App\Mcp\Tools\Reports\GetReport;

/**
 * Read-only context the assistant (or the person) can attach to a conversation: a generated
 * report or a site, as Markdown. Built from the same tools, so they share permissions and
 * shape — nothing here reads data the tools can't.
 */
final class McpResources
{
    private const SCHEME = 'imagina-reports://';

    public function __construct(
        private readonly ApiGateway $api,
        private readonly GetReport $getReport,
        private readonly GetSite $getSite,
    ) {}

    /**
     * The most recent reports, ready to attach.
     *
     * @return list<array<string, mixed>>
     */
    public function list(McpContext $context): array
    {
        if (! $context->can(McpAbility::Read)) {
            return [];
        }

        $resources = [];
        foreach (array_slice($this->api->get($context, 'reports', ['limit' => 20])->records(), 0, 20) as $report) {
            $id = $report['id'] ?? null;
            if (! is_int($id)) {
                continue;
            }
            $period = substr(is_string($report['period_start'] ?? null) ? $report['period_start'] : '', 0, 7);
            $resources[] = [
                'uri' => self::SCHEME."report/{$id}",
                'name' => "reporte-{$id}",
                'title' => "Reporte #{$id} ({$period})",
                'mimeType' => 'text/markdown',
            ];
        }

        return $resources;
    }

    /**
     * @return list<array<string, string>>
     */
    public function templates(): array
    {
        return [
            ['uriTemplate' => self::SCHEME.'report/{report_id}', 'name' => 'reporte', 'title' => 'Un reporte generado', 'mimeType' => 'text/markdown'],
            ['uriTemplate' => self::SCHEME.'site/{site_id}', 'name' => 'sitio', 'title' => 'Un sitio con sus fuentes y reportes', 'mimeType' => 'text/markdown'],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function read(string $uri, McpContext $context): array
    {
        if (! $context->can(McpAbility::Read)) {
            throw new ToolError('Este conector no tiene permiso de lectura.');
        }

        if (preg_match('#^'.preg_quote(self::SCHEME, '#').'(report|site)/(\d+)$#', $uri, $match) !== 1) {
            throw new ToolError("Recurso desconocido: {$uri}");
        }

        $result = $match[1] === 'report'
            ? $this->getReport->call(new ToolArguments(['report_id' => (int) $match[2]]), $context)
            : $this->getSite->call(new ToolArguments(['site_id' => (int) $match[2]]), $context);

        $title = $match[1] === 'report' ? "Reporte #{$match[2]}" : "Sitio #{$match[2]}";

        return [[
            'uri' => $uri,
            'mimeType' => 'text/markdown',
            'text' => "# {$title}\n\n```json\n".$result->text."\n```\n",
        ]];
    }
}
