<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Ga4\Ga4Connector;
use App\Connectors\Period;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    /**
     * POST /data-sources/{dataSource}/ga4/datasets/test — run the composed dataset for a
     * recent window WITHOUT saving it, so the builder can show a live sample (§10.6/A.3).
     */
    public function test(Request $request, DataSource $dataSource, Ga4Connector $connector): JsonResponse
    {
        abort_unless($dataSource->type === DataSourceType::Ga4, 404);

        $spec = $this->validateSpec($request);
        $spec['key'] = 'preview';

        // Register the spec in-memory only (never persisted) and fetch a sample for the
        // chosen window (defaults to the last 28 days) so the builder can preview real data.
        $config = $dataSource->config ?? [];
        $config['custom_datasets'] = [$spec];
        $dataSource->config = $config;

        $set = $connector->fetch($dataSource, $this->previewPeriod($request), ['ga4.custom.preview']);
        $rows = $set->get('ga4.custom.preview');

        return response()->json([
            'ok' => $set->isOk() || $set->isPartial(),
            'rows' => array_slice(is_array($rows) ? $rows : [], 0, 20),
            'error' => $set->error,
        ]);
    }

    /**
     * POST /data-sources/{dataSource}/ga4/datasets — persist (or replace by key) a custom
     * dataset on the source config. It then appears in the catalog like a factory one.
     */
    public function store(Request $request, DataSource $dataSource): JsonResponse
    {
        abort_unless($dataSource->type === DataSourceType::Ga4, 404);

        $spec = $this->validateSpec($request);

        $config = $dataSource->config ?? [];
        $existing = is_array($config['custom_datasets'] ?? null) ? $config['custom_datasets'] : [];
        $existing = array_values(array_filter(
            $existing,
            static fn ($entry): bool => ! is_array($entry) || ($entry['key'] ?? null) !== $spec['key'],
        ));
        $existing[] = $spec;
        $config['custom_datasets'] = $existing;
        $dataSource->update(['config' => $config]);

        return response()->json(['custom_datasets' => $config['custom_datasets']]);
    }

    /**
     * DELETE /data-sources/{dataSource}/ga4/datasets/{key} — remove a custom dataset.
     */
    public function destroy(DataSource $dataSource, string $key): JsonResponse
    {
        abort_unless($dataSource->type === DataSourceType::Ga4, 404);

        $config = $dataSource->config ?? [];
        $existing = is_array($config['custom_datasets'] ?? null) ? $config['custom_datasets'] : [];
        $config['custom_datasets'] = array_values(array_filter(
            $existing,
            static fn ($entry): bool => ! is_array($entry) || ($entry['key'] ?? null) !== $key,
        ));
        $dataSource->update(['config' => $config]);

        return response()->json(['custom_datasets' => $config['custom_datasets']]);
    }

    /**
     * Validate a composed dataset spec from the builder. The connector re-sanitizes on
     * read, so this just enforces the shape and the aggregate-at-source caps (§3.3).
     *
     * @return array<array-key, mixed>
     */
    private function validateSpec(Request $request): array
    {
        return Validator::make($request->all(), [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]+$/i', 'max:50'],
            'label' => ['required', 'string', 'max:100'],
            'dimensions' => ['required', 'array', 'min:1', 'max:5'],
            'dimensions.*.key' => ['required', 'string', 'max:50'],
            'dimensions.*.label' => ['nullable', 'string', 'max:100'],
            'dimensions.*.api' => ['required', 'string', 'max:100'],
            'measures' => ['required', 'array', 'min:1', 'max:10'],
            'measures.*.key' => ['required', 'string', 'max:50'],
            'measures.*.label' => ['nullable', 'string', 'max:100'],
            'measures.*.api' => ['required', 'string', 'max:100'],
            'measures.*.unit' => ['nullable', 'string', 'max:20'],
            'measures.*.cast' => ['nullable', 'in:int,float'],
            'measures.*.scale' => ['nullable', 'numeric'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'order_by' => ['nullable', 'string', 'max:50'],
        ])->validate();
    }

    /**
     * The window the builder wants to preview: validated from/to dates, else the last 28 days.
     */
    private function previewPeriod(Request $request): Period
    {
        $from = $request->input('from');
        $to = $request->input('to');

        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            try {
                return Period::make($from, $to);
            } catch (Throwable) {
                // Fall through to the default window on an unparseable range.
            }
        }

        return Period::make('28 days ago', 'today');
    }
}
