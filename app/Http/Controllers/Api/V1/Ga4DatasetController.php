<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Ga4\Ga4Connector;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Self-serve GA4 metric/dataset builder (CLAUDE.md §10.6/A.3). Exposes the GA4
 * property's metadata so the editor can compose a runReport from valid dimensions and
 * metrics — registering new datasets without per-metric code. Tenant isolation comes
 * from the DataSource global scope (a cross-agency id 404s on bind).
 */
final class Ga4DatasetController extends Controller
{
    /**
     * GET /data-sources/{dataSource}/ga4/metadata — the property's available dimensions
     * and metrics (including its custom definitions) to populate the builder.
     */
    public function metadata(DataSource $dataSource, Ga4Connector $connector): JsonResponse
    {
        abort_unless($dataSource->type === DataSourceType::Ga4, 404);

        try {
            return response()->json($connector->metadata($dataSource));
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'No se pudo leer el catálogo de GA4. Revisa las credenciales y el property_id. Detalle: '.$e->getMessage(),
            ], 502);
        }
    }
}
