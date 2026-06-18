<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Models\DataSource;
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
        return DataSourceResource::collection($site->dataSources()->latest()->get());
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
