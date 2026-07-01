<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SiteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection(Site::query()->latest()->get());
    }

    public function store(StoreSiteRequest $request, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $data = $request->validated();

        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->canAddSite($agency), 403, 'Has alcanzado el límite de sitios de tu plan. Mejora el plan para añadir más.');

        // Enforce that the client belongs to this agency (scoped 404 otherwise).
        Client::query()->findOrFail($data['client_id']);

        $site = Site::query()->create($data);

        return SiteResource::make($site)->response()->setStatusCode(201);
    }

    public function show(Site $site): SiteResource
    {
        return new SiteResource($site);
    }

    public function update(UpdateSiteRequest $request, Site $site): SiteResource
    {
        $data = $request->validated();

        // If reassigning the client, enforce it belongs to this agency (scoped 404).
        if (array_key_exists('client_id', $data)) {
            Client::query()->findOrFail($data['client_id']);
        }

        $site->update($data);

        return new SiteResource($site);
    }

    /**
     * The distinct periods for which this site has synced snapshots — so the report
     * generation form can default to a period that actually has data and warn when the
     * chosen one doesn't (the cause of "the report shows no metrics").
     */
    public function snapshotPeriods(Site $site): JsonResponse
    {
        $periods = MetricSnapshot::query()
            ->whereIn('data_source_id', $site->dataSources()->select('id'))
            ->select('period_start', 'period_end')
            ->distinct()
            ->orderByDesc('period_end')
            ->get()
            ->map(static fn (MetricSnapshot $snapshot): array => [
                'period_start' => $snapshot->period_start->toIso8601String(),
                'period_end' => $snapshot->period_end->toIso8601String(),
            ])
            ->all();

        return response()->json($periods);
    }
}
