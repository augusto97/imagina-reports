<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Client;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SiteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection(Site::query()->latest()->get());
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $data = $request->validated();

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
}
