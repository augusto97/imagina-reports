<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sources;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolArguments;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ReadTool;

/**
 * One-click connectors (Google, Meta, WooCommerce) need the person to consent in their own
 * browser — that can't happen inside a chat. So the tool hands back the same link the panel's
 * "Conectar con…" button opens; nothing is created until the provider redirects back.
 */
final class GetConnectLink extends ReadTool
{
    public function name(): string
    {
        return 'get_connect_link';
    }

    public function title(): string
    {
        return 'Enlace para conectar una fuente';
    }

    public function description(): string
    {
        return 'Para fuentes de «conexión con un clic» (GA4, Search Console, Google Ads, Facebook Ads, Instagram, WooCommerce): '
            .'devuelve un enlace que la persona debe abrir en su navegador para autorizar el acceso. Caduca en unos minutos. '
            .'Después, revisa la fuente con get_site: si encontró varias cuentas, elige una con propose_select_account.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::SourcesWrite;
    }

    public function readOnly(): bool
    {
        return false;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'type' => self::text('Tipo de fuente (ver list_connectors), p. ej. ga4 o facebook_ads.'),
            'input' => ['type' => 'object', 'description' => 'Datos previos que pida la conexión (p. ej. la URL de la tienda para WooCommerce). Opcional.'],
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'type'];
    }

    public function call(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $type = $arguments->string('type');

        $response = $this->api->call($context, 'POST', "sites/{$siteId}/connect/{$type}", [
            'input' => $arguments->stringMap('input'),
            'return_url' => url('/admin'),
        ])->orFail();

        return ToolResult::data([
            'enlace' => $response->get('redirect_url'),
            'instrucciones' => 'Pídele a la persona que abra este enlace en su navegador y autorice el acceso. Al terminar volverá al panel de Imagina Reports.',
        ]);
    }
}
