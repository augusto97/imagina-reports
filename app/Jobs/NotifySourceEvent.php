<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SourceEvent;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Notices\SourceNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A connection was connected, works again or was removed: tell the team in the background, so
 * the click (or the client's connection link) never waits on emails. The facts are captured when
 * it happens — a removed source no longer exists by the time this runs.
 *
 * @phpstan-import-type Notice from SourceNotifier
 */
final class NotifySourceEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  Notice  $notice
     */
    public function __construct(public readonly SourceEvent $event, public readonly array $notice) {}

    /**
     * A source is connected and ready to bring data: tell the team once, even when the
     * authorization and the account pick (GA4 property, ad account…) land a moment apart.
     */
    public static function connected(DataSource $source, ?User $actor, bool $byClient = false, bool $again = false): void
    {
        if (! Cache::add('source-connected:'.$source->id, true, now()->addMinutes(10))) {
            return;
        }

        self::dispatch(SourceEvent::Connected, SourceNotifier::describe($source, $actor, $byClient, $again));
    }

    public function handle(SourceNotifier $notifier): void
    {
        $notifier->notify($this->event, $this->notice);
    }
}
