<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ReportVisibility;
use App\Models\ReportDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ReportDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->resource;

        if (! $definition instanceof ReportDefinition) {
            throw new LogicException('ReportDefinitionResource expects a ReportDefinition.');
        }

        return [
            'id' => $definition->id,
            'site_id' => $definition->site_id,
            'name' => $definition->name,
            'template_id' => $definition->template_id,
            'locale' => $definition->locale,
            'requested_metrics' => $definition->requested_metrics,
            'recipients' => $definition->recipients ?? [],
            'theme' => $definition->theme,
            'filters' => $definition->filters,
            'visibility' => ($definition->visibility ?? ReportVisibility::Public)->value,
            'has_password' => is_string($definition->password_hash) && $definition->password_hash !== '',
            'embed_domains' => $definition->embed_domains ?? [],
        ];
    }
}
