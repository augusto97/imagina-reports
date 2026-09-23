<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Templates;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

final class ProposeUpdateReportDefinition extends ProposalTool
{
    public function name(): string
    {
        return 'propose_update_report_definition';
    }

    public function title(): string
    {
        return 'Cambiar la configuración de un reporte';
    }

    public function description(): string
    {
        return 'Propone cambiar el nombre, la plantilla, el idioma o los destinatarios de una configuración de reporte. '
            .'Para destinatarios: «recipients» sustituye la lista completa; «add_recipients» y «remove_recipients» la retocan. '
            .'Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::TemplatesWrite;
    }

    protected function properties(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'report_definition_id' => self::integer('Id de la configuración de reporte (ver get_site).'),
            'name' => self::text('Nuevo nombre (opcional).'),
            'template_id' => self::integer('Nueva plantilla (opcional).'),
            'locale' => self::text('Nuevo idioma (opcional).'),
            'recipients' => [...$list, 'description' => 'Lista completa de destinatarios (opcional).'],
            'add_recipients' => [...$list, 'description' => 'Emails a añadir (opcional).'],
            'remove_recipients' => [...$list, 'description' => 'Emails a quitar (opcional).'],
        ];
    }

    protected function required(): array
    {
        return ['report_definition_id'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $definitionId = $arguments->int('report_definition_id');
        $current = $this->fetch($context, "report-definitions/{$definitionId}");

        $changes = [];
        $lines = [];

        $name = $arguments->optionalString('name');
        if ($name !== null) {
            $changes['name'] = $name;
            $lines[] = '- nombre: «'.self::str($current['name'] ?? '')."» → «{$name}»";
        }

        $templateId = $arguments->optionalInt('template_id');
        if ($templateId !== null) {
            $template = $this->fetch($context, "report-templates/{$templateId}");
            $changes['template_id'] = $templateId;
            $lines[] = '- plantilla: «'.self::str($template['name'] ?? '').'»';
        }

        $locale = $arguments->optionalString('locale');
        if ($locale !== null) {
            $changes['locale'] = $locale;
            $lines[] = "- idioma: {$locale}";
        }

        $recipients = [];
        foreach (is_array($current['recipients'] ?? null) ? $current['recipients'] : [] as $email) {
            if (is_string($email)) {
                $recipients[] = mb_strtolower($email);
            }
        }
        $before = $recipients;

        if ($arguments->has('recipients')) {
            $recipients = ProposeCreateReportDefinition::emails($arguments->stringList('recipients'));
        }
        $recipients = array_values(array_unique([...$recipients, ...ProposeCreateReportDefinition::emails($arguments->stringList('add_recipients'))]));
        $remove = array_map('mb_strtolower', $arguments->stringList('remove_recipients'));
        $recipients = array_values(array_diff($recipients, $remove));

        if ($recipients !== $before) {
            $changes['recipients'] = $recipients;
            $lines[] = '- destinatarios: '.($recipients === [] ? 'ninguno' : implode(', ', $recipients));
        }

        if ($changes === []) {
            throw new ToolError('No hay cambios que aplicar.');
        }

        return new PreparedAction(
            'Cambiar la configuración «'.self::str($current['name'] ?? '')."»:\n".implode("\n", $lines),
            ['report_definition_id' => $definitionId, 'changes' => $changes],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $definitionId = $arguments->int('report_definition_id');
        $changes = $arguments->all()['changes'] ?? [];

        $definition = $this->api->call($context, 'PUT', "report-definitions/{$definitionId}", is_array($changes) ? self::stringKeys($changes) : [])->orFail();

        return ToolResult::data(['configuracion' => self::pick($definition->json, ['id', 'name', 'template_id', 'locale', 'recipients'])], 'Configuración actualizada.');
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeys(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
