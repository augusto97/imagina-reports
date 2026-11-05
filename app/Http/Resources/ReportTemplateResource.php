<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportDefinition;
use App\Models\ReportTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ReportTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = $this->resource;

        if (! $template instanceof ReportTemplate) {
            throw new LogicException('ReportTemplateResource expects a ReportTemplate.');
        }

        return [
            'id' => $template->id,
            'name' => $template->name,
            'blocks' => $template->blocks,
            'calculated_metrics' => $template->calculated_metrics ?? [],
            'theme' => $template->theme,
            'filters' => $template->filters,
            'pages' => $template->pages,
            'is_default' => $template->is_default,
            'locale' => $template->locale,
            // Which clients' reports follow this template (linked: editing it changes their
            // next report). Only when loaded, so lists stay cheap where it isn't needed.
            'used_by' => $this->whenLoaded('definitions', fn (): array => $template->definitions
                ->map(static fn (ReportDefinition $definition): array => [
                    'id' => $definition->id,
                    'name' => $definition->name,
                    'site' => $definition->site?->name,
                ])->values()->all()),
        ];
    }
}
