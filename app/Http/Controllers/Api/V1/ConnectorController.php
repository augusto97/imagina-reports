<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConfigField;
use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the available connectors and their config schemas (CLAUDE.md §7/§11.1)
 * so the admin can render a data-source configuration form per source — driven by
 * configSchema(), never hardcoded.
 */
final class ConnectorController extends Controller
{
    public function index(ConnectorRegistry $registry): JsonResponse
    {
        $connectors = array_map(static fn ($connector): array => [
            'key' => $connector->key(),
            'label' => $connector->label(),
            'config_schema' => array_map(
                static fn (ConfigField $field): array => $field->toArray(),
                $connector->configSchema(),
            ),
        ], $registry->all());

        return response()->json($connectors);
    }
}
