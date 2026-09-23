<?php

declare(strict_types=1);

namespace App\Mcp\Tools\WorkLogs;

use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\Proposals\PreparedAction;
use App\Mcp\ToolArguments;
use App\Mcp\ToolError;
use App\Mcp\ToolResult;
use App\Mcp\Tools\ProposalTool;

/**
 * "Anota que hoy actualicé los plugins de acme.com" — the most natural thing to do from a chat,
 * and the block that justifies the client's payment (§11.5).
 */
final class ProposeAddWorkLogs extends ProposalTool
{
    private const MAX_ENTRIES = 30;

    private const STATUSES = ['done', 'in_progress', 'planned'];

    public function name(): string
    {
        return 'propose_add_work_logs';
    }

    public function title(): string
    {
        return 'Anotar trabajo realizado';
    }

    public function description(): string
    {
        return 'Propone anotar una o varias tareas en el «trabajo realizado» de un sitio (lo que el cliente ve como «Qué hicimos este mes»). '
            .'Escribe cada descripción en lenguaje claro para el cliente. La fecha por defecto es hoy. '
            .'No anota nada hasta que se confirme con apply_proposal.';
    }

    public function ability(): McpAbility
    {
        return McpAbility::WorkLogsWrite;
    }

    protected function properties(): array
    {
        return [
            'site_id' => self::integer('Id del sitio.'),
            'entries' => [
                'type' => 'array',
                'description' => 'Tareas a anotar (máximo 30).',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => self::text('Qué se hizo, en lenguaje para el cliente.'),
                        'performed_at' => self::date('Cuándo (opcional, por defecto hoy).'),
                        'minutes' => self::integer('Tiempo dedicado en minutos (opcional).'),
                        'category' => self::text('Categoría, p. ej. Mantenimiento, Seguridad, SEO (opcional).'),
                        'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'done (hecho, por defecto), in_progress o planned.'],
                    ],
                    'required' => ['description'],
                ],
            ],
        ];
    }

    protected function required(): array
    {
        return ['site_id', 'entries'];
    }

    public function prepare(ToolArguments $arguments, McpContext $context): PreparedAction
    {
        $siteId = $arguments->int('site_id');
        $site = $this->site($context, $siteId);
        $entries = $arguments->objectList('entries');

        if (count($entries) > self::MAX_ENTRIES) {
            throw new ToolError('Como máximo '.self::MAX_ENTRIES.' tareas por propuesta.');
        }

        $normalized = [];
        $lines = [];
        foreach ($entries as $entry) {
            $status = $entry->optionalString('status') ?? 'done';
            if (! in_array($status, self::STATUSES, true)) {
                throw new ToolError('El estado de cada tarea debe ser done, in_progress o planned.');
            }

            $item = array_filter([
                'description' => $entry->string('description'),
                'performed_at' => $entry->optionalDate('performed_at') ?? now()->toDateString(),
                'minutes' => $entry->optionalInt('minutes'),
                'category' => $entry->optionalString('category'),
                'status' => $status,
            ], static fn (mixed $value): bool => $value !== null);

            $normalized[] = $item;
            $minutes = $entry->optionalInt('minutes');
            $lines[] = '- '.$item['performed_at'].': '.$entry->string('description').($minutes !== null ? " ({$minutes} min)" : '');
        }

        return new PreparedAction(
            'Anotar '.count($normalized).' tarea(s) en «'.self::str($site['name'] ?? '')."»:\n".implode("\n", $lines),
            ['site_id' => $siteId, 'entries' => $normalized],
        );
    }

    public function apply(ToolArguments $arguments, McpContext $context): ToolResult
    {
        $siteId = $arguments->int('site_id');
        $created = [];

        foreach ($arguments->objectList('entries') as $entry) {
            $log = $this->api->call($context, 'POST', "sites/{$siteId}/work-logs", $entry->all())->orFail();
            $created[] = self::pick($log->json, ['id', 'performed_at', 'description']);
        }

        return ToolResult::data(['anotadas' => $created], count($created).' tarea(s) anotada(s).');
    }
}
