<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Clients CRUD (CLAUDE.md §8). Every query is agency-scoped by the AgencyScope, so
 * tenant isolation is automatic; route-model binding 404s on another agency's row.
 */
final class ClientController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(Client::query()->latest()->get());
    }

    public function store(StoreClientRequest $request, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->canAddClient($agency), 403, 'Has alcanzado el límite de clientes de tu plan. Mejora el plan para añadir más.');

        $client = Client::query()->create($request->validated());

        return ClientResource::make($client)->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $client->update($request->validated());

        return new ClientResource($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        // Deleting a client cascades its sites (and their data/reports), so refuse while
        // it still has sites — the operator should remove or reassign them first.
        if (Site::query()->where('client_id', $client->id)->exists()) {
            return response()->json([
                'message' => 'No puedes eliminar un cliente con sitios. Elimina o reasigna sus sitios primero.',
            ], 422);
        }

        $client->delete();

        return response()->json(null, 204);
    }
}
