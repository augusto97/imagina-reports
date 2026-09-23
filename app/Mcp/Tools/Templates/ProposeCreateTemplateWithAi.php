<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Templates;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

/**
 * The chat's way of "editing" a report design. Moving blocks one by one through a chat would be
 * a poor experience, so the connector exposes the AI builder instead: describe the report,
 * get a template bound only to metrics the site really has (§10.6), refine it in the editor.
 */
final class ProposeCreateTemplateWithAi extends ProposalTool
{
    public function name(): string
    {
        return 'propose_create_template_with_ai';
    }

    public function title(): string
    {
        return 'Crear una plantilla con IA';
    }

    public function description(): string
    {
        return 'Propone crear una plantilla de reporte con IA a partir de los datos que tiene un sitio y de lo que pida la persona '
            .'(p. ej. «enfocado en SEO y seguridad»). La IA solo usa métricas que el sitio tiene de verdad. '
            .'La plantilla se puede retocar después en el editor del panel. Asígnala con propose_create_report_definition. '
            .'Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::TemplatesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Sitio cuyos datos se usarán como base.'),
            'name' => self::text('Nombre de la plantilla.'),
            'prompt' => self::text('En qué debe enfocarse el reporte (opcional).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'name'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $site = $this->site($context, $siteId);
        $name = $arguments->string('name');
        $prompt = $arguments->optionalString('prompt');

        return new PreparedAction(
            "Crear con IA la plantilla «{$name}» usando los datos de «".self::str($site['name'] ?? '').'»'
                .($prompt !== null ? ", con este enfoque: {$prompt}" : '').'.',
            array_filter(['site_id' => $siteId, 'name' => $name, 'prompt' => $prompt], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $prompt = $arguments->optionalString('prompt');

        $draft = $this->api->call($context, 'POST', "sites/{$siteId}/ai-template", $prompt !== null ? ['prompt' => $prompt] : [])->orFail();
        $blocks = $draft->get('blocks');

        $template = $this->api->call($context, 'POST', 'report-templates', [
            'name' => $arguments->string('name'),
            'blocks' => is_array($blocks) ? $blocks : [],
        ])->orFail();

        return ToolResult::data([
            'plantilla' => self::pick($template->json, ['id', 'name']),
            'bloques' => is_array($blocks) ? count($blocks) : 0,
            'omitidos_por_falta_de_datos' => $draft->get('dropped'),
        ], 'Plantilla creada. Se puede retocar en el editor del panel.');
    }
}
