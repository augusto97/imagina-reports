<?php

declare(strict_types=1);

namespace App\Services\Notices;

use App\Enums\SourceEvent;
use App\Mail\SourceActivityMail;
use App\Models\Agency;
use App\Models\AppNotification;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\NotificationPrefs;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tells the team what happened to a client's connection: a notice in each person's bell and,
 * if they asked for it, an email — only for the events and sites each person chose
 * (NotificationPrefs). Whoever did it isn't told about their own action. Connected, recovered
 * and removed also go out as webhooks / Slack (`source.failed` is sent by NotifySourceFailure).
 *
 * @phpstan-type Notice array{
 *     agency_id: int,
 *     site_id: int|null,
 *     site: string,
 *     data_source_id: int|null,
 *     type: string,
 *     label: string,
 *     actor_user_id: int|null,
 *     actor: string|null,
 *     by_client: bool,
 *     again: bool,
 *     error: string|null,
 *     reconnectable: bool,
 * }
 */
final readonly class SourceNotifier
{
    public function __construct(private WebhookDispatcher $webhooks) {}

    /**
     * The facts of a notice about this source, read before anything else changes or deletes it.
     *
     * @return Notice
     */
    public static function describe(DataSource $source, ?User $actor = null, bool $byClient = false, bool $again = false): array
    {
        $source->loadMissing('site');

        return [
            'agency_id' => $source->agency_id,
            'site_id' => $source->site_id,
            'site' => $source->site !== null ? $source->site->name : 'un sitio',
            'data_source_id' => $source->id,
            'type' => $source->type->value,
            'label' => $source->type->label(),
            'actor_user_id' => $actor?->id,
            'actor' => $actor !== null ? ($actor->name !== '' ? $actor->name : $actor->email) : null,
            'by_client' => $byClient,
            'again' => $again,
            'error' => $source->last_error,
            'reconnectable' => false,
        ];
    }

    /**
     * @param  Notice  $notice
     * @param  (Closure(): Mailable)|null  $mail  the email for this event (one fresh instance per person); null = the standard one
     */
    public function notify(SourceEvent $event, array $notice, ?Closure $mail = null): void
    {
        $agency = Agency::query()->find($notice['agency_id']);
        if ($agency === null) {
            return;
        }

        [$title, $body] = $this->text($event, $notice);
        $link = $this->link($event, $notice);

        $people = User::query()->where('agency_id', $agency->id)->orderBy('id')->get();
        foreach ($people as $person) {
            if ($notice['actor_user_id'] !== null && $person->id === $notice['actor_user_id']) {
                continue;
            }

            $prefs = NotificationPrefs::for($person, $agency);

            if (NotificationPrefs::wants($prefs, $event, NotificationPrefs::APP, $notice['site_id'])) {
                $this->store($person, $event, $notice, $title, $body, $link);
            }

            if ($person->email !== '' && NotificationPrefs::wants($prefs, $event, NotificationPrefs::EMAIL, $notice['site_id'])) {
                $mailable = $mail !== null
                    ? $mail()
                    : new SourceActivityMail($event, $title, $body, $notice['site'], $this->absolute($link), $agency);
                Mail::to($person->email)->send($mailable);
            }
        }

        if ($event !== SourceEvent::Failed) {
            $this->webhooks->dispatch($agency->id, $event->webhook(), [
                'data_source_id' => $notice['data_source_id'],
                'site_id' => $notice['site_id'],
                'site' => $notice['site'],
                'type' => $notice['type'],
                'label' => $notice['label'],
                'by' => $this->who($notice),
                'again' => $notice['again'],
            ]);
        }
    }

    /**
     * Title and one line of detail, written for the person reading the bell.
     *
     * @param  Notice  $notice
     * @return array{0: string, 1: string}
     */
    public function text(SourceEvent $event, array $notice): array
    {
        $label = $notice['label'];
        $site = $notice['site'];
        $who = $this->who($notice);

        return match ($event) {
            SourceEvent::Connected => [
                ($who !== null ? $who : 'Alguien').' '.($notice['again'] ? 'volvió a conectar ' : 'conectó ').$label.' en '.$site,
                $notice['by_client']
                    ? 'Lo hizo desde su enlace de conexión. Los datos llegan con la próxima sincronización.'
                    : 'Los datos llegan con la próxima sincronización.',
            ],
            SourceEvent::Failed => [
                $label.' dejó de funcionar en '.$site,
                $notice['error'] !== null && $notice['error'] !== ''
                    ? Str::limit($notice['error'], 220)
                    : 'La conexión dejó de traer datos.',
            ],
            SourceEvent::Recovered => [
                $label.' volvió a funcionar en '.$site,
                'Los datos vuelven a llegar con normalidad.',
            ],
            SourceEvent::Removed => [
                ($who !== null ? $who : 'Alguien').' quitó '.$label.' de '.$site,
                'También se borraron los datos que había guardado esa fuente.',
            ],
        };
    }

    /**
     * @param  Notice  $notice
     */
    private function who(array $notice): ?string
    {
        if ($notice['by_client']) {
            return 'El cliente';
        }

        return $notice['actor'] !== null && $notice['actor'] !== '' ? $notice['actor'] : null;
    }

    /**
     * Where the notice takes you in the panel: the site's connections.
     *
     * @param  Notice  $notice
     */
    private function link(SourceEvent $event, array $notice): ?string
    {
        if ($notice['site_id'] === null) {
            return null;
        }

        $reconnect = $event === SourceEvent::Failed && $notice['reconnectable'] && $notice['data_source_id'] !== null
            ? '&reconnect='.$notice['data_source_id']
            : '';

        return '#/clients?site='.$notice['site_id'].$reconnect;
    }

    private function absolute(?string $link): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/').'/admin'.($link ?? '');
    }

    /**
     * @param  Notice  $notice
     */
    private function store(User $person, SourceEvent $event, array $notice, string $title, string $body, ?string $link): void
    {
        AppNotification::query()->withoutGlobalScopes()->create([
            'agency_id' => $notice['agency_id'],
            'user_id' => $person->id,
            'kind' => $event->value,
            'title' => Str::limit($title, 250),
            'body' => $body,
            'site_id' => $notice['site_id'],
            'data_source_id' => $notice['data_source_id'],
            'link' => $link,
        ]);

        // Keep the bell short: drop what is beyond the newest KEEP.
        $cutoff = AppNotification::query()->withoutGlobalScopes()
            ->where('user_id', $person->id)
            ->orderByDesc('id')
            ->skip(AppNotification::KEEP)
            ->value('id');
        if (is_int($cutoff)) {
            AppNotification::query()->withoutGlobalScopes()->where('user_id', $person->id)->where('id', '<=', $cutoff)->delete();
        }
    }
}
