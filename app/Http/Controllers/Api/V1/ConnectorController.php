<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConfigField;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ProvidesSetupGuide;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the available connectors and their config schemas (CLAUDE.md §7/§11.1)
 * so the admin can render a data-source configuration form per source — driven by
 * configSchema(), never hardcoded.
 */
final class ConnectorController extends Controller
{
    /**
     * Connectors hidden from the data-source picker for now (still registered, so any
     * existing source keeps working — just not offered as a new source). Reversible.
     *
     * @var list<string>
     */
    private const HIDDEN = ['crowdsec', 'virusdie'];

    public function index(ConnectorRegistry $registry): JsonResponse
    {
        $available = array_filter(
            $registry->all(),
            static fn ($connector): bool => ! in_array($connector->key(), self::HIDDEN, true),
        );

        $connectors = array_map(static fn ($connector): array => [
            'key' => $connector->key(),
            'label' => $connector->label(),
            'config_schema' => array_map(
                static fn (ConfigField $field): array => $field->toArray(),
                $connector->configSchema(),
            ),
            'guide' => $connector instanceof ProvidesSetupGuide
                ? $connector->setupGuide()->toArray()
                : null,
        ], array_values($available));

        return response()->json($connectors);
    }
}
