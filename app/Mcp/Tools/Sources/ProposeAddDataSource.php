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
 * Connectors configured with fields (API keys, URLs). The preview never echoes a secret back —
 * it only says which fields were given — and applying runs the connection test right away, so
 * the person learns in the same turn whether the key works.
 */
final class ProposeAddDataSource extends ProposalTool
{
    public function name(): string
    {
        return 'propose_add_data_source';
    }

    public function title(): string
    {
        return 'Añadir una fuente de datos';
    }

    public function description(): string
    {
        return 'Propone conectar a un sitio una fuente que se configura con campos (claves de API, URLs…), p. ej. Cloudflare, MainWP, Better Stack. '
            .'Consulta en list_connectors los campos de cada tipo: los marcados como secretos van en «credentials», el resto en «config». '
            .'Para Google, Meta o WooCommerce usa get_connect_link. Al confirmarse con apply_proposal se prueba la conexión.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'type' => self::text('Tipo de fuente (ver list_connectors).'),
            'config' => ['type' => 'object', 'description' => 'Campos no secretos, clave → valor.'],
            'credentials' => ['type' => 'object', 'description' => 'Campos secretos (claves de API, tokens), clave → valor.'],
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'type'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $type = $arguments->string('type');
        $site = $this->site($context, $siteId);

        $connector = null;
        foreach ($this->fetchList($context, 'connectors') as $candidate) {
            if (($candidate['key'] ?? null) === $type) {
                $connector = $candidate;

                break;
            }
        }

        if ($connector === null) {
            throw new ToolError("«{$type}» no es un tipo de fuente disponible. Consulta list_connectors.");
        }

        $config = $arguments->stringMap('config');
        $credentials = $arguments->stringMap('credentials');

        $missing = [];
        foreach (is_array($connector['config_schema'] ?? null) ? $connector['config_schema'] : [] as $field) {
            if (! is_array($field) || ($field['required'] ?? false) !== true) {
                continue;
            }
            $key = self::str($field['key'] ?? '');
            if (($config[$key] ?? '') === '' && ($credentials[$key] ?? '') === '') {
                $missing[] = self::str($field['label'] ?? $key);
            }
        }

        if ($missing !== []) {
            throw new ToolError('Faltan campos obligatorios: '.implode(', ', $missing).'.');
        }

        $given = [...array_keys($config), ...array_map(static fn (string $key): string => $key.' (secreto)', array_keys($credentials))];

        return new PreparedAction(
            'Conectar '.self::str($connector['label'] ?? $type).' al sitio «'.self::str($site['name'] ?? '')
                .'» con los campos: '.($given === [] ? 'ninguno' : implode(', ', $given)).'.',
            ['site_id' => $siteId, 'type' => $type, 'config' => $config, 'credentials' => $credentials],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $source = $this->api->call($context, 'POST', "sites/{$siteId}/data-sources", [
            'type' => $arguments->string('type'),
            'config' => $arguments->stringMap('config'),
            'credentials' => $arguments->stringMap('credentials'),
        ])->orFail();

        $sourceId = self::intOrNull($source->get('id'));
        $test = $sourceId !== null ? $this->api->call($context, 'POST', "data-sources/{$sourceId}/test") : null;

        return ToolResult::data([
            'fuente' => self::pick($source->json, ['id', 'type', 'status']),
            'prueba_de_conexion' => $test === null ? null : [
                'conecta' => $test->get('successful') === true,
                'mensaje' => $test->get('message'),
            ],
        ], 'Fuente añadida.');
    }
}
