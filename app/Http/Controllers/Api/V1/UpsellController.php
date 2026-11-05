<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Period;
use App\Enums\UpsellType;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\Site;
use App\Reports\AgencyUpsell;
use App\Reports\UpsellDetector;
use App\Reports\UpsellOpportunity;
use App\Reports\UpsellProposal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agency-wide upsell opportunities (CLAUDE.md §13). Re-evaluates the upsell signals
 * from each site's most recent frozen report — no live API calls. Internal-only.
 *
 * Etapa 5: the agency tunes its own thresholds, marks an opportunity as already offered (it
 * hides for AgencyUpsell::OFFERED_DAYS), and gets a ready-to-edit proposal email per signal.
 */
final class UpsellController extends Controller
{
    public function __construct(private readonly AgencyUpsell $upsell) {}

    public function index(): JsonResponse
    {
        return response()->json($this->upsell->build());
    }

    public function thresholds(Request $request, TenantContext $tenant): JsonResponse
    {
        $rules = [];
        foreach (UpsellDetector::THRESHOLDS as $key) {
            $rules[$key] = ['sometimes', 'numeric', 'min:0', 'max:1000000'];
        }
        $request->validate($rules);

        $agency = $this->agency($tenant);
        $settings = $agency->settings ?? [];
        $current = is_array($settings['upsell_thresholds'] ?? null) ? $settings['upsell_thresholds'] : [];
        foreach (UpsellDetector::THRESHOLDS as $key) {
            if ($request->exists($key) && is_numeric($request->input($key))) {
                $current[$key] = (float) $request->input($key);
            }
        }
        $settings['upsell_thresholds'] = $current;
        $agency->settings = $settings;
        $agency->save();

        return response()->json(['thresholds' => UpsellDetector::thresholds($current)]);
    }

    /** «Ya la ofrecí»: hide this signal for this site for a while. */
    public function offered(Request $request, TenantContext $tenant): JsonResponse
    {
        ['site' => $site, 'type' => $type] = $this->target($request);

        $agency = $this->agency($tenant);
        $settings = $agency->settings ?? [];
        $offered = is_array($settings['upsell_offered'] ?? null) ? $settings['upsell_offered'] : [];
        // Forget the expired ones so the list doesn't grow forever.
        $offered = array_filter($offered, static fn (mixed $at): bool => is_string($at) && now()->subDays(AgencyUpsell::OFFERED_DAYS)->lt($at));
        $offered[AgencyUpsell::offeredKey($site->id, $type->value)] = now()->toIso8601String();
        $settings['upsell_offered'] = $offered;
        $agency->settings = $settings;
        $agency->save();

        return response()->json(['offered' => true]);
    }

    public function proposal(Request $request, TenantContext $tenant, UpsellProposal $writer): JsonResponse
    {
        ['site' => $site, 'type' => $type] = $this->target($request);
        $agency = $this->agency($tenant);

        $opportunity = $this->current($site, $type, $agency);
        if ($opportunity === null) {
            return response()->json(['message' => 'Esa oportunidad ya no aparece en el último informe de este sitio.'], 404);
        }

        return response()->json([
            ...$writer->draft($site, $opportunity, $agency),
            'to' => $site->client?->contact_email,
        ]);
    }

    /**
     * @return array{site: Site, type: UpsellType}
     */
    private function target(Request $request): array
    {
        $request->validate([
            'site_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(UpsellType::class)],
        ]);

        $site = Site::query()->with('client')->findOrFail($request->integer('site_id'));
        $type = UpsellType::from($request->string('type')->toString());

        return ['site' => $site, 'type' => $type];
    }

    /** The signal as the site's latest report shows it now (fresh numbers for the email). */
    private function current(Site $site, UpsellType $type, Agency $agency): ?UpsellOpportunity
    {
        $report = Report::query()
            ->whereHas('definition', static fn ($query) => $query->where('site_id', $site->id))
            ->orderByDesc('period_end')
            ->first(['id', 'report_definition_id', 'period_start', 'period_end']);
        if ($report === null) {
            return null;
        }

        $connected = [];
        foreach (DataSource::query()->where('site_id', $site->id)->get() as $source) {
            $connected[] = $source->type->value;
        }

        foreach ($this->upsell->forSite($site, new Period($report->period_start, $report->period_end), $connected, AgencyUpsell::agencyThresholds($agency)) as $opportunity) {
            if ($opportunity->type === $type) {
                return $opportunity;
            }
        }

        return null;
    }

    private function agency(TenantContext $tenant): Agency
    {
        return Agency::query()->findOrFail($tenant->id());
    }
}
