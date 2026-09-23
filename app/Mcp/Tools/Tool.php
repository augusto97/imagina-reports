<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\ApiGateway;
use App\Mcp\McpAbility;
use App\Mcp\McpContext;
use App\Mcp\ToolError;
use stdClass;

/**
 * One capability of the connector, as the assistant sees it.
 *
 * Descriptions are written for the model that reads them and addressed with «tú» in neutral
 * Spanish (owner's decision). They say what the tool does, when to use it, and — for writes —
 * that nothing happens until a person confirms.
 */
abstract class Tool
{
    public function __construct(protected readonly ApiGateway $api) {}

    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    abstract public function ability(): McpAbility;

    /**
     * JSON-schema properties of the arguments.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function required(): array
    {
        return [];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $properties = $this->properties();

        $schema = [
            'type' => 'object',
            // An empty PHP array would encode as `[]`; the schema needs a JSON object.
            'properties' => $properties === [] ? new stdClass : $properties,
        ];

        if ($this->required() !== []) {
            $schema['required'] = $this->required();
        }

        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $schema,
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => $this->readOnly(),
                'destructiveHint' => $this->destructive(),
                'idempotentHint' => $this->readOnly(),
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function integer(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function text(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function date(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description];
    }

    /**
     * One record from the API, or a ToolError with the API's own reason.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    protected function fetch(McpContext $context, string $path, array $query = []): array
    {
        return $this->api->get($context, $path, $query)->orFail()->json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<array-key, mixed>>
     */
    protected function fetchList(McpContext $context, string $path, array $query = []): array
    {
        return $this->api->get($context, $path, $query)->orFail()->records();
    }

    /**
     * The site, checked to exist in this agency — so a preview never describes a change to a
     * site the person can't see.
     *
     * @return array<array-key, mixed>
     */
    protected function site(McpContext $context, int $siteId): array
    {
        return $this->fetch($context, "sites/{$siteId}");
    }

    protected static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * Only the listed keys of a record, in that order.
     *
     * @param  array<array-key, mixed>  $record
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected static function pick(array $record, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $record[$key] ?? null;
        }

        return $picked;
    }

    /**
     * A month given as `YYYY-MM` (or an explicit range) turned into the period the API expects.
     *
     * @return array{period_start: string, period_end: string}
     */
    protected static function period(?string $month, ?string $start, ?string $end): array
    {
        if ($start !== null && $end !== null) {
            if ($end < $start) {
                throw new ToolError('La fecha final no puede ser anterior a la inicial.');
            }

            return ['period_start' => $start, 'period_end' => $end];
        }

        if ($month !== null && preg_match('/^(\d{4})-(\d{2})$/', $month, $parts) === 1) {
            $first = now()->setDate((int) $parts[1], (int) $parts[2], 1);

            return ['period_start' => $first->toDateString(), 'period_end' => $first->copy()->endOfMonth()->toDateString()];
        }

        if ($month !== null) {
            throw new ToolError('El mes debe tener el formato AAAA-MM (por ejemplo 2026-08).');
        }

        // Default: the last complete month — what a monthly report is about.
        $previous = now()->subMonthNoOverflow();

        return ['period_start' => $previous->copy()->startOfMonth()->toDateString(), 'period_end' => $previous->copy()->endOfMonth()->toDateString()];
    }
}
