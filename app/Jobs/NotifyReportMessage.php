<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReportMessageKind;
use App\Mail\ClientQuestionMail;
use App\Mail\QuestionAnsweredMail;
use App\Models\Report;
use App\Models\ReportMessage;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\AgencyMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tells the other side that a report's conversation moved (Etapa 4): a client question reaches
 * the agency (email + Slack/webhook `report.question`), and an agency reply reaches the client
 * who asked — only if they left an email. Queued so the portal answers instantly.
 */
final class NotifyReportMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $messageId) {}

    public function handle(WebhookDispatcher $webhooks): void
    {
        $message = ReportMessage::query()->withoutGlobalScopes()->with('user')->find($this->messageId);
        $report = $message !== null ? Report::query()->withoutGlobalScopes()->with(['agency', 'definition.site'])->find($message->report_id) : null;

        if ($message === null || $report === null || $report->agency === null) {
            return;
        }

        match ($message->kind) {
            ReportMessageKind::Client => $this->toAgency($message, $report, $webhooks),
            ReportMessageKind::Agency => $this->toClient($message, $report),
            ReportMessageKind::Ai => null,
        };
    }

    private function toAgency(ReportMessage $message, Report $report, WebhookDispatcher $webhooks): void
    {
        $agency = $report->agency;
        $recipients = $agency !== null ? AgencyMail::inbox($agency) : [];
        if ($agency !== null && $recipients !== [] && AgencyMail::wants($agency, AgencyMail::CLIENT_QUESTIONS)) {
            Mail::to($recipients)->send(new ClientQuestionMail($message, $report));
        }

        $site = $report->definition?->site;
        $webhooks->dispatch($report->agency_id, 'report.question', [
            'report_id' => $report->id,
            'site_id' => $site?->id,
            'site' => $site?->name,
            'author' => $message->author_name,
            'excerpt' => Str::limit($message->body, 280),
        ]);
    }

    private function toClient(ReportMessage $reply, Report $report): void
    {
        // Whoever asked since the agency's previous reply — those are the questions this answers.
        $previousReply = ReportMessage::query()->withoutGlobalScopes()
            ->where('report_id', $report->id)
            ->where('kind', ReportMessageKind::Agency->value)
            ->where('id', '<', $reply->id)
            ->max('id');

        $emails = ReportMessage::query()->withoutGlobalScopes()
            ->where('report_id', $report->id)
            ->where('kind', ReportMessageKind::Client->value)
            ->where('id', '<', $reply->id)
            ->when(is_numeric($previousReply), static fn (Builder $query): Builder => $query->where('id', '>', is_numeric($previousReply) ? (int) $previousReply : 0))
            ->whereNotNull('author_email')
            ->pluck('author_email')
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->unique()
            ->take(5)
            ->values();

        $agency = $report->agency;
        if ($agency === null) {
            return;
        }
        foreach ($emails as $email) {
            AgencyMail::toClient($agency, (string) $email, new QuestionAnsweredMail($reply, $report));
        }
    }
}
