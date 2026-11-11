<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SourceEvent;
use App\Models\Agency;
use App\Models\User;

/**
 * Each team member's notice choices, stored on `ir_users.notification_prefs`:
 *
 *   { "events": { "source_failed": { "app": true, "email": true }, … }, "sites": null | [12, 15] }
 *
 * Anything not stored falls back to a default: everything shows in the bell; owners and admins
 * also get emails (for failures, only if the agency hadn't switched that email off before these
 * per-person choices existed); collaborators get no emails until they ask for them. `sites` null
 * means every site of the account, including the ones added later.
 *
 * @phpstan-type Channels array{app: bool, email: bool}
 * @phpstan-type Prefs array{events: array<string, Channels>, sites: list<int>|null}
 */
final class NotificationPrefs
{
    public const APP = 'app';

    public const EMAIL = 'email';

    /**
     * The person's choices with the defaults filled in.
     *
     * @return Prefs
     */
    public static function for(User $user, ?Agency $agency): array
    {
        $stored = $user->notification_prefs ?? [];
        $storedEvents = is_array($stored['events'] ?? null) ? $stored['events'] : [];
        $privileged = $user->role->isPrivileged();

        $events = [];
        foreach (SourceEvent::cases() as $event) {
            $defaultEmail = $privileged && ($event !== SourceEvent::Failed || $agency === null || AgencyMail::wants($agency, AgencyMail::SOURCE_FAILURES));
            $saved = is_array($storedEvents[$event->value] ?? null) ? $storedEvents[$event->value] : [];
            $events[$event->value] = [
                'app' => is_bool($saved['app'] ?? null) ? $saved['app'] : true,
                'email' => is_bool($saved['email'] ?? null) ? $saved['email'] : $defaultEmail,
            ];
        }

        return ['events' => $events, 'sites' => self::siteList($stored['sites'] ?? null)];
    }

    /**
     * @param  Prefs  $prefs
     */
    public static function wants(array $prefs, SourceEvent $event, string $channel, ?int $siteId): bool
    {
        if (($prefs['events'][$event->value][$channel] ?? false) !== true) {
            return false;
        }

        // A notice about no particular site (none today) reaches everyone who wants the event.
        return $prefs['sites'] === null || $siteId === null || in_array($siteId, $prefs['sites'], true);
    }

    /**
     * Turn what the settings screen sent into something safe to store: only known events and
     * channels, and only site ids of this account.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $agencySiteIds
     * @return array{events: array<string, array<string, bool>>, sites: list<int>|null}
     */
    public static function clean(array $input, array $agencySiteIds): array
    {
        $events = [];
        $rawEvents = is_array($input['events'] ?? null) ? $input['events'] : [];
        foreach (SourceEvent::cases() as $event) {
            $raw = is_array($rawEvents[$event->value] ?? null) ? $rawEvents[$event->value] : [];
            foreach ([self::APP, self::EMAIL] as $channel) {
                if (is_bool($raw[$channel] ?? null)) {
                    $events[$event->value][$channel] = $raw[$channel];
                }
            }
        }

        $sites = self::siteList($input['sites'] ?? null);

        return [
            'events' => $events,
            'sites' => $sites === null ? null : array_values(array_intersect($sites, $agencySiteIds)),
        ];
    }

    /**
     * @return list<int>|null
     */
    private static function siteList(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
