<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Connectors\Connect\ConnectRegistry;
use App\Enums\DataSourceStatus;
use App\Enums\SourceEvent;
use App\Mail\SourceFailedMail;
use App\Models\DataSource;
use App\Services\Notices\SourceNotifier;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * A connection just stopped working (Etapa 5): tell the agency before the month-end report
 * comes out with holes in it — in each team member's bell and by email, as each one chose
 * (NotificationPrefs), and as `source.failed` (webhooks and Slack). Says whether the client can
 * fix it from a link, which is the usual case for an expired Google/Meta authorisation.
 */
final class NotifySourceFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $dataSourceId) {}

    public function handle(WebhookDispatcher $webhooks, ConnectRegistry $connect, SourceNotifier $notifier): void
    {
        $source = DataSource::query()->withoutGlobalScopes()->with(['agency', 'site.client'])->find($this->dataSourceId);

        // Recovered before we got to it, or gone: nothing to say.
        if ($source === null || $source->status !== DataSourceStatus::Error || $source->agency === null) {
            return;
        }

        $reconnectable = $connect->for($source->type->value) !== null;

        $notice = SourceNotifier::describe($source);
        $notice['reconnectable'] = $reconnectable;
        $notifier->notify(SourceEvent::Failed, $notice, static fn (): SourceFailedMail => new SourceFailedMail($source, $reconnectable));

        $webhooks->dispatch($source->agency_id, 'source.failed', [
            'data_source_id' => $source->id,
            'site_id' => $source->site_id,
            'site' => $source->site?->name,
            'type' => $source->type->value,
            'label' => $source->type->label(),
            'error' => Str::limit((string) $source->last_error, 280),
            'reconnectable' => $reconnectable,
        ]);
    }
}
