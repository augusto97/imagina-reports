<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ListsConnectableResources;
use App\Models\DataSource;
use Throwable;

/**
 * Lists what a just-connected OAuth account can access and either fills the config field
 * (single option) or stashes a picker for the client (several).
 *
 * Its other job is to never fail silently. Before, a discovery that came back empty left a
 * source holding a valid token but no property/account id, with nothing on screen to say
 * why — the client only found out when "Probar" said "falta el ID". Now every outcome
 * writes a `last_error` the admin can read and act on, and the same routine can be re-run
 * on demand once they fix the cause (no need to redo the whole authorization).
 */
final class ResourceDiscovery
{
    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /**
     * Run discovery against the source's stored token.
     *
     * @return string|null null when a resource is set or a picker is ready; otherwise the
     *                     reason, already stored on the source's last_error.
     */
    public function discover(DataSource $source): ?string
    {
        $connector = $this->connectors->for($source);

        if (! $connector instanceof ListsConnectableResources) {
            return null;
        }

        try {
            $resources = $connector->connectableResources($source);
        } catch (Throwable) {
            $resources = null;
        }

        if ($resources === null) {
            return $this->fail($source, 'No pudimos consultar a qué cuentas tiene acceso esta conexión. '
                .'Puede ser un problema temporal del proveedor o un token caducado: vuelve a pulsar «Detectar cuentas», '
                .'y si sigue fallando reconecta la fuente.');
        }

        if ($resources->options === []) {
            return $this->fail($source, $resources->emptyHint
                ?? 'La cuenta conectada no expone ningún recurso que podamos leer.');
        }

        // Exactly one: pick it for them — a dropdown with a single choice is just friction.
        if (count($resources->options) === 1) {
            $only = $resources->options[0];
            $meta = $source->meta ?? [];
            unset($meta['connect_options']);

            $source->forceFill([
                'config' => array_merge($source->config ?? [], [$resources->field => $only['value']]),
                'meta' => $meta,
                'last_error' => null,
            ])->save();

            return null;
        }

        $source->forceFill([
            'meta' => array_merge($source->meta ?? [], ['connect_options' => $resources->toArray()]),
            'last_error' => null,
        ])->save();

        return null;
    }

    private function fail(DataSource $source, string $message): string
    {
        $meta = $source->meta ?? [];
        unset($meta['connect_options']);

        $source->forceFill(['meta' => $meta, 'last_error' => $message])->save();

        return $message;
    }
}
