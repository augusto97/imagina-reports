<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportMessageKind;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyReportMessage;
use App\Models\Report;
use App\Models\ReportMessage;
use App\Support\PeriodLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The agency's side of a report's conversation (Etapa 4): read what the client asked (to the
 * agency and to the AI), answer, or set a question aside. Tenant-scoped by route binding.
 */
final class ReportMessageController extends Controller
{
    public function index(Report $report): JsonResponse
    {
        return response()->json([
            'data' => $report->messages()->with('user:id,name')->get()->map(static fn (ReportMessage $message): array => $message->present(true))->all(),
        ]);
    }

    public function reply(Request $request, Report $report): JsonResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.required' => 'Escribe la respuesta.']);

        $message = ReportMessage::query()->create([
            'agency_id' => $report->agency_id,
            'report_id' => $report->id,
            'kind' => ReportMessageKind::Agency,
            'user_id' => $request->user()?->id,
            'body' => $request->string('body')->trim()->toString(),
        ]);
        $this->markHandled($report);

        NotifyReportMessage::dispatch($message->id);

        return response()->json(['data' => $message->load('user:id,name')->present(true)], 201);
    }

    /** «Marcar como resuelta»: answered by phone, or nothing to add. */
    public function handled(Report $report): JsonResponse
    {
        $this->markHandled($report);

        return response()->json(null, 204);
    }

    /**
     * Client questions still waiting for the agency, one row per report — for Inicio and the
     * reports list.
     */
    public function inbox(): JsonResponse
    {
        $pending = ReportMessage::query()
            ->where('kind', ReportMessageKind::Client->value)
            ->whereNull('handled_at')
            ->with('report:id,report_definition_id,period_start,period_end', 'report.definition:id,site_id,name', 'report.definition.site:id,name')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $rows = [];
        foreach ($pending->groupBy('report_id') as $messages) {
            /** @var ReportMessage $latest */
            $latest = $messages->first();
            $report = $latest->report;
            if ($report === null) {
                continue;
            }
            $rows[] = [
                'report_id' => $report->id,
                'report_definition_id' => $report->report_definition_id,
                'site' => $report->definition?->site !== null ? $report->definition->site->name : ($report->definition !== null ? $report->definition->name : ''),
                'period' => PeriodLabel::for($report->period_start, $report->period_end, 'es'),
                'count' => $messages->count(),
                'author' => $latest->author_name,
                'excerpt' => Str::limit($latest->body, 140),
                'created_at' => $latest->created_at->toIso8601String(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    private function markHandled(Report $report): void
    {
        ReportMessage::query()
            ->where('report_id', $report->id)
            ->where('kind', ReportMessageKind::Client->value)
            ->whereNull('handled_at')
            ->update(['handled_at' => now()]);
    }
}
