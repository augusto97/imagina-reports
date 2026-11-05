<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\AiDesignJob;
use App\Models\Agency;
use App\Models\Site;
use App\Services\Platform\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Generar con IA» (CLAUDE.md §10.6/§11.1): a whole report, a section to add, or a change to the
 * report on the canvas. Each request queues an AiDesignJob (a big report takes the model minutes)
 * and answers with its status; the editor polls `status` until it's done. With a synchronous queue
 * the first answer already carries the result.
 */
final class AiTemplateController extends Controller
{
    public function store(Request $request, Site $site, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $agency = $this->agency($tenant, $entitlements);
        $request->validate(['prompt' => ['nullable', 'string', 'max:6000']]);

        return $this->run($agency, AiDesignJob::start($agency->id, $site->id, 'template', $request->string('prompt')->trim()->toString()));
    }

    /**
     * "Add a section with AI": only the blocks for a described section, to append to the canvas.
     */
    public function section(Request $request, Site $site, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $agency = $this->agency($tenant, $entitlements);
        $prompt = $request->string('prompt')->trim()->toString();
        if ($prompt === '') {
            return response()->json(['message' => 'Describe la sección que quieres añadir.'], 422);
        }

        return $this->run($agency, AiDesignJob::start($agency->id, $site->id, 'section', $prompt));
    }

    /**
     * «Pedir cambios»: revise the report on the canvas as described.
     */
    public function adjust(Request $request, Site $site, Entitlements $entitlements, TenantContext $tenant): JsonResponse
    {
        $agency = $this->agency($tenant, $entitlements);
        $request->validate([
            'prompt' => ['required', 'string', 'max:6000'],
            'blocks' => ['required', 'array', 'min:1', 'max:400'],
            'pages' => ['sometimes', 'array', 'max:40'],
        ], [
            'prompt.required' => 'Describe qué quieres cambiar.',
            'blocks.min' => 'El lienzo está vacío: genera un informe primero o elige una plantilla.',
        ]);

        $blocks = $request->input('blocks');
        $pages = $request->input('pages', []);

        return $this->run($agency, AiDesignJob::start(
            $agency->id,
            $site->id,
            'adjust',
            $request->string('prompt')->trim()->toString(),
            is_array($blocks) ? $blocks : [],
            is_array($pages) ? $pages : [],
        ));
    }

    /** Where a run stands (the editor polls this). */
    public function status(string $job, TenantContext $tenant): JsonResponse
    {
        $agencyId = $tenant->id();
        $state = $agencyId !== null ? AiDesignJob::status($job, $agencyId) : null;
        if ($state === null) {
            return response()->json(['message' => 'Esa generación ya no existe. Vuelve a intentarlo.'], 404);
        }

        return $this->present($job, $state);
    }

    private function run(Agency $agency, string $job): JsonResponse
    {
        $state = AiDesignJob::status($job, $agency->id) ?? ['status' => 'queued'];

        return $this->present($job, $state);
    }

    /**
     * Done → 200 with the result's fields at the top level; failed → 422 with the reason;
     * otherwise 202 while it's queued or running.
     *
     * @param  array<string, mixed>  $state
     */
    private function present(string $job, array $state): JsonResponse
    {
        $status = is_string($state['status'] ?? null) ? $state['status'] : 'queued';
        $result = is_array($state['result'] ?? null) ? $state['result'] : [];
        $payload = ['job' => $job, 'status' => $status, ...$result];
        foreach (['message', 'queued_at', 'started_at'] as $field) {
            if (isset($state[$field])) {
                $payload[$field] = $state[$field];
            }
        }

        return response()->json($payload, match ($status) {
            'done' => 200,
            'failed' => 422,
            default => 202,
        });
    }

    private function agency(TenantContext $tenant, Entitlements $entitlements): Agency
    {
        $agency = Agency::query()->findOrFail($tenant->id());
        abort_unless($entitlements->hasFeature($agency, 'ai_builder'), 403, 'Tu plan no incluye el generador con IA. Mejora el plan para usarlo.');

        return $agency;
    }
}
