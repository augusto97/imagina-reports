<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Ready-made workflows the person can pick from their assistant's menu. Each one is just a
 * well-written request that names the tools to use and insists on confirmation before any
 * change — the assistant still does the work through the normal tools.
 */
final class McpPrompts
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(static fn (array $prompt): array => [
            'name' => $prompt['name'],
            'title' => $prompt['title'],
            'description' => $prompt['description'],
            'arguments' => $prompt['arguments'],
        ], self::prompts());
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function get(string $name, array $arguments): array
    {
        foreach (self::prompts() as $prompt) {
            if ($prompt['name'] !== $name) {
                continue;
            }

            $text = $prompt['text'];
            foreach ($prompt['arguments'] as $argument) {
                $value = $arguments[$argument['name']] ?? null;
                if (($value === null || $value === '') && $argument['required']) {
                    throw new ToolError("Falta el argumento «{$argument['name']}».");
                }
                $text = str_replace('{'.$argument['name'].'}', is_scalar($value) ? (string) $value : '', $text);
            }

            return [
                'description' => $prompt['description'],
                'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
            ];
        }

        throw new ToolError("No existe la plantilla de petición «{$name}».");
    }

    /**
     * @return list<array{name: string, title: string, description: string, text: string, arguments: list<array{name: string, description: string, required: bool}>}>
     */
    private static function prompts(): array
    {
        $confirm = 'Antes de cada cambio, muéstrame la vista previa y espera a que la confirme.';

        return [
            [
                'name' => 'cierre_de_mes',
                'title' => 'Cierre de mes de un cliente',
                'description' => 'Revisa las fuentes, genera los reportes del último mes de un cliente y prepara su envío.',
                'arguments' => [['name' => 'cliente', 'description' => 'Nombre (o parte del nombre) del cliente.', 'required' => true]],
                'text' => 'Haz el cierre de mes del cliente «{cliente}» en Imagina Reports. '
                    .'1) Encuéntralo con list_clients. 2) Para cada sitio, revisa get_sync_status y dime qué fuentes fallan y por qué; si faltan datos del último mes, propón sincronizar. '
                    .'3) Propón generar cada reporte (propose_generate_report) para el último mes completo. '
                    .'4) Cuando estén listos, resúmeme cada uno con get_report y propón aprobarlo y enviarlo. '.$confirm,
            ],
            [
                'name' => 'revision_de_fuentes',
                'title' => 'Revisión de fuentes con error',
                'description' => 'Encuentra todas las fuentes de datos que fallan y explica qué hacer con cada una.',
                'arguments' => [],
                'text' => 'Revisa en Imagina Reports todas las fuentes de datos de todos mis sitios (list_clients y get_sync_status). '
                    .'Hazme una lista de las que tienen error, con el motivo real que dio el proveedor y qué debo hacer para arreglar cada una. '
                    .'Si alguna se arregla sincronizando o eligiendo la cuenta correcta, propónlo. '.$confirm,
            ],
            [
                'name' => 'resumen_semanal',
                'title' => 'Resumen de la semana',
                'description' => 'Anomalías, fuentes con error y reportes pendientes de aprobar o enviar.',
                'arguments' => [],
                'text' => 'Dame el resumen de la semana de mi agencia en Imagina Reports: anomalías sin revisar (list_anomalies), '
                    .'fuentes con error (get_sync_status de cada sitio) y reportes en borrador o aprobados sin enviar (list_reports). '
                    .'Ordénalo por urgencia y dime qué debería hacer primero.',
            ],
            [
                'name' => 'anotar_trabajo',
                'title' => 'Anotar trabajo realizado',
                'description' => 'Convierte lo que cuentes que hiciste en tareas del «trabajo realizado» de un sitio.',
                'arguments' => [
                    ['name' => 'sitio', 'description' => 'Nombre o URL del sitio.', 'required' => true],
                    ['name' => 'trabajo', 'description' => 'Lo que se hizo, en tus palabras.', 'required' => true],
                ],
                'text' => 'En Imagina Reports, anota en el trabajo realizado del sitio «{sitio}» lo siguiente: {trabajo}. '
                    .'Encuentra el sitio con list_clients, separa el texto en tareas claras escritas para el cliente '
                    .'y usa propose_add_work_logs. '.$confirm,
            ],
        ];
    }
}
