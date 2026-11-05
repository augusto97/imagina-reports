<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A site's goals (Etapa 4): what the agency agreed to reach with the client, shown in every
 * report through the «Metas» block. Tenant-scoped by route binding.
 */
final class SiteGoalController extends Controller
{
    /** A handful is a plan; dozens is a dashboard nobody reads. */
    private const MAX_PER_SITE = 12;

    public function index(Site $site): JsonResponse
    {
        return response()->json(['data' => $site->goals()->get()->map(static fn (SiteGoal $goal): array => $goal->present())->all()]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        if ($site->goals()->count() >= self::MAX_PER_SITE) {
            return response()->json(['message' => 'Este sitio ya tiene '.self::MAX_PER_SITE.' metas. Quita alguna antes de añadir otra.'], 422);
        }

        $goal = $site->goals()->create([
            ...$this->validated($request),
            'agency_id' => $site->agency_id,
            'position' => (is_numeric($last = $site->goals()->max('position')) ? (int) $last : 0) + 1,
        ]);

        return response()->json(['data' => $goal->present()], 201);
    }

    public function update(Request $request, SiteGoal $goal): JsonResponse
    {
        $goal->update($this->validated($request, partial: true));

        return response()->json(['data' => $goal->present()]);
    }

    public function destroy(SiteGoal $goal): JsonResponse
    {
        $goal->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $request->validate([
            'label' => [$required, 'string', 'max:80'],
            'source' => [$required, 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'metric' => [$required, 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'target' => [$required, 'numeric', 'min:0', 'max:999999999999'],
            'direction' => ['sometimes', Rule::in([SiteGoal::AT_LEAST, SiteGoal::AT_MOST])],
            'format' => ['sometimes', 'nullable', Rule::in(SiteGoal::FORMATS)],
        ], [
            'label.required' => 'Ponle un nombre a la meta.',
            'source.required' => 'Elige la métrica que mide esta meta.',
            'metric.required' => 'Elige la métrica que mide esta meta.',
            'target.required' => 'Indica el objetivo.',
            'target.numeric' => 'El objetivo debe ser un número.',
        ]);

        $fields = [];
        foreach (['label', 'source', 'metric', 'target', 'direction', 'format'] as $field) {
            if ($request->exists($field)) {
                $fields[$field] = $request->input($field);
            }
        }

        return $fields;
    }
}
