<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportMessageKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientMessageRequest;
use App\Jobs\NotifyReportMessage;
use App\Models\Report;
use App\Models\ReportMessage;
use App\Reports\ReportAssistant;
use App\Reports\Sharing\PublicReportGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's side of a report's conversation (Etapa 4), behind the report's public token and
 * the same sharing rules as the portal itself: read the thread, send a question to the agency,
 * and ask the AI about the report.
 */
final class PublicReportConversationController extends Controller
{
    /** More than this many questions to the agency in a day from one report is not a person. */
    private const DAILY_QUESTIONS = 30;

    public function index(Request $request, string $token, ReportAssistant $assistant): JsonResponse
    {
        $report = $this->find($token);
        if (($denied = PublicReportGate::deny($report, $request)) !== null) {
            return $denied;
        }

        return response()->json([
            'messages' => $report->messages()->with('user:id,name')->get()->map(static fn (ReportMessage $message): array => $message->present())->all(),
            'ai_available' => $assistant->available($report),
            'agency' => $report->agency?->name,
        ]);
    }

    public function store(StoreClientMessageRequest $request, string $token): JsonResponse
    {
        $report = $this->find($token);
        if (($denied = PublicReportGate::deny($report, $request)) !== null) {
            return $denied;
        }

        // Honeypot filled → a bot. Answer like a success so it doesn't learn to skip the field.
        if (is_string($request->input('website')) && $request->input('website') !== '') {
            return response()->json(['message' => null], 201);
        }

        $today = ReportMessage::query()->withoutGlobalScopes()
            ->where('report_id', $report->id)
            ->where('kind', ReportMessageKind::Client->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($today >= self::DAILY_QUESTIONS) {
            return response()->json(['message' => 'Ya recibimos varias preguntas hoy. Te responderemos pronto.'], 429);
        }

        $email = $request->string('email')->trim()->lower()->toString();
        $message = ReportMessage::query()->create([
            'agency_id' => $report->agency_id,
            'report_id' => $report->id,
            'kind' => ReportMessageKind::Client,
            'author_name' => $request->string('name')->trim()->toString(),
            'author_email' => $email !== '' ? $email : null,
            'body' => $request->string('body')->trim()->toString(),
        ]);

        NotifyReportMessage::dispatch($message->id);

        return response()->json(['message' => $message->present()], 201);
    }

    public function ask(Request $request, string $token, ReportAssistant $assistant): JsonResponse
    {
        $report = $this->find($token);
        if (($denied = PublicReportGate::deny($report, $request)) !== null) {
            return $denied;
        }

        $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'name' => ['nullable', 'string', 'max:80'],
        ], [
            'question.required' => 'Escribe tu pregunta.',
            'question.max' => 'La pregunta es muy larga (máximo 500 caracteres).',
        ]);

        if (! $assistant->available($report)) {
            return response()->json(['message' => 'Las respuestas automáticas no están disponibles. Envía tu pregunta a la agencia.'], 403);
        }
        if ($assistant->exhausted($report)) {
            return response()->json(['message' => 'Por hoy ya no hay más respuestas automáticas. Envía tu pregunta a la agencia y te responderá.'], 429);
        }

        $name = $request->string('name')->trim()->toString();
        $message = $assistant->ask($report, $request->string('question')->trim()->toString(), $name !== '' ? $name : null);

        if ($message === null) {
            return response()->json(['message' => 'No pudimos responder ahora. Envía tu pregunta a la agencia y te responderá.'], 503);
        }

        return response()->json(['message' => $message->present()], 201);
    }

    private function find(string $token): Report
    {
        return Report::query()
            ->withoutGlobalScopes()
            ->with(['agency', 'definition.site'])
            ->where('public_token', $token)
            ->firstOrFail();
    }
}
