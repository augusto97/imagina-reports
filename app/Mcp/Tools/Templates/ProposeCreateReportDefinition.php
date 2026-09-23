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

final class ProposeCreateReportDefinition extends ProposalTool
{
    public function name(): string
    {
        return 'propose_create_report_definition';
    }

    public function title(): string
    {
        return 'Configurar un reporte para un sitio';
    }

    public function description(): string
    {
        return 'Propone crear la configuración de reporte de un sitio: qué plantilla usa, en qué idioma y a quién se envía. '
            .'Es lo que después se genera (propose_generate_report) y se programa (propose_create_schedule). Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::TemplatesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'name' => self::text('Nombre, p. ej. «Reporte mensual».'),
            'template_id' => self::integer('Plantilla a usar (ver list_templates). Opcional: sin plantilla se usa la predeterminada.'),
            'recipients' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Emails de los destinatarios (opcional).'],
            'locale' => self::text('Idioma: es, en o pt-BR (opcional).'),
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
        $templateId = $arguments->optionalInt('template_id');
        $recipients = self::emails($arguments->stringList('recipients'));

        $templateName = 'la predeterminada';
        if ($templateId !== null) {
            $templateName = '«'.self::str($this->fetch($context, "report-templates/{$templateId}")['name'] ?? '').'»';
        }

        $payload = array_filter([
            'site_id' => $siteId,
            'name' => $name,
            'template_id' => $templateId,
            'recipients' => $recipients === [] ? null : $recipients,
            'locale' => $arguments->optionalString('locale'),
        ], static fn (mixed $value): bool => $value !== null);

        return new PreparedAction(
            "Configurar el reporte «{$name}» para «".self::str($site['name'] ?? '')."» con la plantilla {$templateName}"
                .($recipients === [] ? ', sin destinatarios todavía.' : ', enviado a: '.implode(', ', $recipients).'.'),
            $payload,
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $definition = $this->api->call($context, 'POST', 'report-definitions', $arguments->all())->orFail();

        return ToolResult::data(['configuracion' => self::pick($definition->json, ['id', 'name', 'site_id', 'template_id', 'recipients'])], 'Reporte configurado.');
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public static function emails(array $candidates): array
    {
        $emails = [];
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                throw new ToolError("«{$candidate}» no es un email válido.");
            }
            $emails[] = mb_strtolower($candidate);
        }

        return array_values(array_unique($emails));
    }
}
