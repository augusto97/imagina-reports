<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\DataSourceStatus;
use App\Enums\ReportMessageKind;
use App\Enums\ReportStatus;
use App\Enums\WorkLogStatus;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\ReportDefinition;
use App\Models\ReportMessage;
use App\Models\Site;
use App\Models\WorkLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which clients may be about to cancel (Etapa 5): a 0–100 score per site from signals the app
 * already has — they stopped opening the report, a question waits unanswered, the site's health
 * is sinking, connections are down (the report comes out with holes), goals are missed, or no
 * work was logged so the client can't see what they pay for. Each signal comes with the reason
 * in words and the action that fixes it. Tenant-scoped; reads stored data only (§3.1).
 *
 * Retention is the product's job (CLAUDE.md §1), so this is the list to work through first.
 *
 * @phpstan-type Reason array{key: string, text: string, weight: int, action: string}
 * @phpstan-type SiteRisk array{site_id: int, site_name: string, client_name: string|null, score: int, level: string, reasons: list<Reason>, latest_report_id: int|null}
 */
final class ChurnRisk
{
    public const HIGH = 50;

    public const MEDIUM = 25;

    /**
     * @return array{summary: array{sites: int, high: int, medium: int, low: int}, sites: list<SiteRisk>}
     */
    public function build(): array
    {
        $now = Carbon::now();
        $sites = Site::query()->with('client:id,name')->get(['id', 'client_id', 'name']);
        $definitions = ReportDefinition::query()->get(['id', 'site_id']);
        $siteOfDefinition = $definitions->pluck('site_id', 'id');

        /** @var Collection<int, Collection<int, Report>> $reportsBySite */
        $reportsBySite = Report::query()
            ->orderByDesc('period_end')
            ->get(['id', 'report_definition_id', 'period_start', 'period_end', 'status', 'health_score', 'first_viewed_at', 'updated_at'])
            ->groupBy(static function (Report $report) use ($siteOfDefinition): int {
                $siteId = $siteOfDefinition[$report->report_definition_id] ?? 0;

                return is_numeric($siteId) ? (int) $siteId : 0;
            });

        $failing = DataSource::query()->where('status', DataSourceStatus::Error->value)->get(['id', 'site_id'])->countBy('site_id');
        $waiting = ReportMessage::query()
            ->where('kind', ReportMessageKind::Client->value)
            ->whereNull('handled_at')
            ->where('created_at', '<', $now->copy()->subHours(48))
            ->with('report:id,report_definition_id')
            ->get(['id', 'report_id', 'created_at']);
        $workedSites = WorkLog::query()
            ->where('status', WorkLogStatus::Done->value)
            ->where('performed_at', '>=', $now->copy()->subDays(45))
            ->distinct()
            ->pluck('site_id')
            ->all();

        $out = [];
        foreach ($sites as $site) {
            $reports = $reportsBySite->get($site->id, collect());
            // Only clients we report to: a site with no report definition isn't a relationship yet.
            if (! $definitions->contains('site_id', $site->id)) {
                continue;
            }

            $reasons = [];
            $sent = $reports->filter(static fn (Report $report): bool => $report->status === ReportStatus::Sent)->values();
            $due = $sent->filter(static fn (Report $report): bool => $report->period_end->lt($now->copy()->subDays(10)))->values();

            $unopened = $due->take(2)->filter(static fn (Report $report): bool => $report->first_viewed_at === null)->count();
            if ($unopened === 2) {
                $reasons[] = $this->reason('unopened', 'No abrió los dos últimos informes', 35, 'reports');
            } elseif ($unopened === 1 && $due->first()?->first_viewed_at === null) {
                $reasons[] = $this->reason('unopened', 'No abrió el último informe', 20, 'reports');
            }

            $lastSent = $sent->first();
            if ($lastSent === null || $lastSent->period_end->lt($now->copy()->subDays(45))) {
                $reasons[] = $this->reason('no_report', $lastSent === null ? 'Aún no ha recibido ningún informe' : 'Hace más de un mes que no recibe un informe', 20, 'reports');
            }

            $questions = $waiting->filter(static fn (ReportMessage $message): bool => $message->report !== null && ($siteOfDefinition[$message->report->report_definition_id] ?? null) === $site->id);
            if ($questions->isNotEmpty()) {
                $oldest = $questions->min('created_at');
                $days = $oldest instanceof Carbon ? (int) $oldest->diffInDays($now) : 2;
                $reasons[] = $this->reason('question', "Tiene una pregunta sin responder desde hace {$days} días", 25, 'questions');
            }

            [$latest, $previous] = [$reports->first(), $reports->get(1)];
            if ($latest?->health_score !== null && $latest->health_score < 60) {
                $reasons[] = $this->reason('health_low', "La salud del sitio está en {$latest->health_score}/100", 15, 'site');
            } elseif ($latest?->health_score !== null && $previous?->health_score !== null && $previous->health_score - $latest->health_score >= 10) {
                $reasons[] = $this->reason('health_drop', "La salud bajó de {$previous->health_score} a {$latest->health_score}", 10, 'site');
            }

            $broken = (int) ($failing[$site->id] ?? 0);
            if ($broken > 0) {
                $reasons[] = $this->reason('sources', $broken === 1 ? 'Una conexión no funciona: el informe sale incompleto' : "{$broken} conexiones no funcionan: el informe sale incompleto", 15, 'site');
            }

            $missed = $latest !== null ? $this->missedGoals($latest) : null;
            if ($missed !== null) {
                $reasons[] = $this->reason('goals', "No alcanzó {$missed[0]} de sus {$missed[1]} metas", 10, 'site');
            }

            if (! in_array($site->id, $workedSites, true)) {
                $reasons[] = $this->reason('no_work', 'Sin trabajo registrado en el último mes: no ve lo que haces', 10, 'worklogs');
            }

            if ($reasons === []) {
                continue;
            }

            usort($reasons, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $score = min(100, array_sum(array_column($reasons, 'weight')));
            $out[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'client_name' => $site->client?->name,
                'score' => $score,
                'level' => $score >= self::HIGH ? 'high' : ($score >= self::MEDIUM ? 'medium' : 'low'),
                'reasons' => $reasons,
                'latest_report_id' => $latest?->id,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['site_name'], $b['site_name']));
        $levels = array_count_values(array_column($out, 'level'));

        return [
            'summary' => [
                'sites' => $definitions->pluck('site_id')->unique()->count(),
                'high' => $levels['high'] ?? 0,
                'medium' => $levels['medium'] ?? 0,
                'low' => $levels['low'] ?? 0,
            ],
            'sites' => $out,
        ];
    }

    /**
     * [missed, total] from the latest report's «Metas» block, when most goals were missed.
     *
     * @return array{0: int, 1: int}|null
     */
    private function missedGoals(Report $latest): ?array
    {
        $resolved = Report::query()->whereKey($latest->id)->value('resolved_blocks');
        $resolved = is_string($resolved) ? json_decode($resolved, true) : $resolved;
        $data = is_array($resolved) && is_array($resolved['data'] ?? null) ? $resolved['data'] : [];
        $blocks = is_array($resolved) && is_array($resolved['blocks'] ?? null) ? $resolved['blocks'] : [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'goals' || ! is_string($block['id'] ?? null) || ! is_array($data[$block['id']] ?? null)) {
                continue;
            }
            $goals = $data[$block['id']];
            $total = count($goals);
            $missed = count(array_filter($goals, static fn (mixed $goal): bool => is_array($goal) && ($goal['met'] ?? false) !== true));

            return $total > 0 && $missed * 2 > $total ? [$missed, $total] : null;
        }

        return null;
    }

    /**
     * @return Reason
     */
    private function reason(string $key, string $text, int $weight, string $action): array
    {
        return ['key' => $key, 'text' => $text, 'weight' => $weight, 'action' => $action];
    }
}
