<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

/**
 * The "pick your property / ad account" dropdown, as a proposal. Only values the provider
 * actually offered are accepted — the same list the panel shows.
 */
final class ProposeSelectAccount extends ProposalTool
{
    public function name(): string
    {
        return 'propose_select_account';
    }

    public function title(): string
    {
        return 'Elegir la cuenta de una fuente';
    }

    public function description(): string
    {
        return 'Cuando una fuente encontró varias cuentas o propiedades (get_site las muestra en «cuentas_por_elegir»), '
            .'propone quedarse con una. «value» debe ser uno de los valores ofrecidos. Requiere confirmación con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'data_source_id' => self::integer('Id de la fuente.'),
            'value' => self::text('El valor de la cuenta elegida (tal cual aparece en «cuentas_por_elegir»).'),
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'data_source_id', 'value'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $sourceId = $arguments->int('data_source_id');
        $value = $arguments->string('value');

        $source = null;
        foreach ($this->fetchList($context, "sites/{$siteId}/data-sources") as $candidate) {
            if (self::intOrNull($candidate['id'] ?? null) === $sourceId) {
                $source = $candidate;

                break;
            }
        }

        $options = is_array($source['connect_options'] ?? null) ? $source['connect_options'] : null;
        if ($source === null || $options === null) {
            throw new ToolError('Esa fuente no tiene cuentas pendientes de elegir. Usa discover_accounts para volver a buscarlas.');
        }

        $field = self::str($options['field'] ?? '');
        $label = null;
        foreach (is_array($options['options'] ?? null) ? $options['options'] : [] as $option) {
            if (is_array($option) && self::str($option['value'] ?? '') === $value) {
                $label = self::str($option['label'] ?? $value);
            }
        }

        if ($field === '' || $label === null) {
            throw new ToolError('Ese valor no está entre las cuentas ofrecidas. Elige uno de «cuentas_por_elegir».');
        }

        $config = [];
        foreach (is_array($source['config'] ?? null) ? $source['config'] : [] as $key => $current) {
            if (is_string($key) && is_scalar($current)) {
                $config[$key] = (string) $current;
            }
        }
        $config[$field] = $value;

        return new PreparedAction(
            'Usar «'.$label.'» en la fuente '.self::str($source['type'] ?? '').'.',
            ['data_source_id' => $sourceId, 'config' => $config],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $sourceId = $arguments->int('data_source_id');
        $this->api->call($context, 'PUT', "data-sources/{$sourceId}", ['config' => $arguments->stringMap('config')])->orFail();
        $test = $this->api->call($context, 'POST', "data-sources/{$sourceId}/test");

        return ToolResult::data([
            'data_source_id' => $sourceId,
            'prueba_de_conexion' => ['conecta' => $test->get('successful') === true, 'mensaje' => $test->get('message')],
        ], 'Cuenta seleccionada.');
    }
}
