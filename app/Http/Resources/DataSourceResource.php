<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Exposes a data source WITHOUT its credentials (CLAUDE.md §6 — never leak secrets).
 */
final class DataSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = $this->resource;

        if (! $source instanceof DataSource) {
            throw new LogicException('DataSourceResource expects a DataSource.');
        }

        return [
            'id' => $source->id,
            'site_id' => $source->site_id,
            'type' => $source->type->value,
            'status' => $source->status->value,
            'config' => $source->config,
            'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            'last_error' => $source->last_error,
        ];
    }
}
