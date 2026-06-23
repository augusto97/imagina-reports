<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Data sources for a site (CLAUDE.md §8/§9), plus the "Test connection" action that
 * runs the connector's testConnection() without storing a snapshot.
 */
final class DataSourceController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function index(Site $site): AnonymousResourceCollection
    {
        $sources = $site->dataSources()->latest()->get();

        // Attach MainWP's "Child Reports active" flag from the latest snapshot so the
        // sync panel can warn when the child site isn't recording its activity history.
        foreach ($sources as $source) {
            if ($source->type === DataSourceType::MainWp) {
                $source->setAttribute('child_reports_active', $this->childReportsActive($source));
            }
        }

        return DataSourceResource::collection($sources);
    }

    private function childReportsActive(DataSource $source): ?bool
    {
        $snapshot = MetricSnapshot::query()
            ->where('data_source_id', $source->id)
            ->latest('captured_at')
            ->first();

        $payload = $snapshot?->payload;
        $metrics = is_array($payload) && is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];

        if (! array_key_exists('mainwp.child_reports_active', $metrics)) {
            return null;
        }

        return (bool) $metrics['mainwp.child_reports_active'];
    }

    public function store(StoreDataSourceRequest $request, Site $site): JsonResponse
    {
        $source = $site->dataSources()->create($request->validated());

        return DataSourceResource::make($source)->response()->setStatusCode(201);
    }

    public function test(DataSource $dataSource): JsonResponse
    {
        $result = $this->registry->for($dataSource)->testConnection($dataSource);

        return response()->json([
            'successful' => $result->successful,
            'message' => $result->message,
        ]);
    }
}
