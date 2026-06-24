<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\ReceivesPushedData;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDataSourceRequest;
use App\Http\Requests\UpdateDataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

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

            $this->decoratePush($source);
        }

        return DataSourceResource::collection($sources);
    }

    /**
     * Push-capable sources (CrowdSec) are configured by installing an outbound push on
     * the client VPS, not by polling — so they need a per-source push token + the ingest
     * URL surfaced to the admin. Generate the token lazily so existing sources get one
     * the first time they're listed, then expose both for the install snippet.
     */
    private function decoratePush(DataSource $source): void
    {
        if (! $this->registry->for($source) instanceof ReceivesPushedData) {
            return;
        }

        if ($source->push_token === null || $source->push_token === '') {
            $source->forceFill(['push_token' => Str::random(48)])->save();
        }

        $source->setAttribute('is_push', true);
        $source->setAttribute('push_token', $source->push_token);
        $source->setAttribute('ingest_url', route('api.ingest.store', ['token' => $source->push_token]));
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

        // Mint the push token immediately so the install snippet is available right away.
        $this->decoratePush($source);

        return DataSourceResource::make($source)->response()->setStatusCode(201);
    }

    public function update(UpdateDataSourceRequest $request, DataSource $dataSource): DataSourceResource
    {
        $data = $request->validated();
        $changes = [];

        if (array_key_exists('type', $data) && $data['type'] !== null) {
            $changes['type'] = $data['type'];
        }

        if (array_key_exists('config', $data) && is_array($data['config'])) {
            $changes['config'] = $data['config'];
        }

        if (array_key_exists('credentials', $data) && is_array($data['credentials'])) {
            // Keep any existing secret the user left blank (the form can't show secrets back).
            $merged = $dataSource->credentials ?? [];
            foreach ($data['credentials'] as $key => $value) {
                // A blank field means "keep the current secret". Laravel's
                // ConvertEmptyStringsToNull turns "" into null, so treat both as blank.
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $merged[$key] = $value;
            }
            $changes['credentials'] = $merged;
        }

        // Config/credentials changed → reset to pending so the operator re-tests/syncs.
        $changes['status'] = DataSourceStatus::Pending;
        $changes['last_error'] = null;

        $dataSource->update($changes);

        return new DataSourceResource($dataSource);
    }

    public function destroy(DataSource $dataSource): JsonResponse
    {
        // Snapshots cascade on delete (FK), so this removes the source and its history.
        $dataSource->delete();

        return response()->json(null, 204);
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
