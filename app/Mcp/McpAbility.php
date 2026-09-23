<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * What an API token may do through the MCP connector.
 *
 * Deliberately coarse: a person granting access to an assistant decides in terms of "may it
 * send reports to my clients?", not in terms of endpoints. Every tool declares exactly one of
 * these; reading is its own permission so a token can be read-only (the default).
 */
enum McpAbility: string
{
    case Read = 'read';
    case ReportsWrite = 'reports:write';
    case ReportsSend = 'reports:send';
    case WorkLogsWrite = 'worklogs:write';
    case ClientsWrite = 'clients:write';
    case SourcesWrite = 'sources:write';
    case TemplatesWrite = 'templates:write';
    case SchedulesWrite = 'schedules:write';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Consultar clientes, sitios, métricas y reportes',
            self::ReportsWrite => 'Generar reportes, editar su resumen, aprobarlos y comentarlos',
            self::ReportsSend => 'Enviar reportes a los clientes',
            self::WorkLogsWrite => 'Anotar trabajo realizado',
            self::ClientsWrite => 'Crear y editar clientes y sitios',
            self::SourcesWrite => 'Conectar, probar y sincronizar fuentes de datos',
            self::TemplatesWrite => 'Crear plantillas con IA y configurar reportes',
            self::SchedulesWrite => 'Programar envíos automáticos',
            self::Delete => 'Eliminar clientes, sitios, fuentes, reportes y anotaciones',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
