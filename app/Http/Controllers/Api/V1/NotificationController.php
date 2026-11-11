<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SourceEvent;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AppNotification;
use App\Models\Site;
use App\Models\User;
use App\Support\NotificationPrefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's bell (what happened to the clients' connections) and their choices of
 * what reaches them, by email or not, and from which sites.
 */
final class NotificationController extends Controller
{
    /** How many the bell shows. */
    private const LIMIT = 40;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $items = AppNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (AppNotification $notice): array => $notice->present())
            ->values();

        $unread = AppNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count();

        return response()->json(['data' => $items, 'unread' => $unread]);
    }

    /** Mark some (`ids`) or all of the person's notices as read. */
    public function read(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['integer']]);
        $ids = $request->input('ids');

        $query = AppNotification::query()->where('user_id', $user->id)->whereNull('read_at');
        if (is_array($ids)) {
            $query->whereIn('id', array_values(array_filter($ids, is_numeric(...))));
        }
        $query->update(['read_at' => now()]);

        return response()->json(['unread' => AppNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count()]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->present($this->user($request)));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $events = array_map(static fn (SourceEvent $event): string => $event->value, SourceEvent::cases());
        $request->validate([
            'events' => ['sometimes', 'array'],
            'events.*' => ['array'],
            'events.*.app' => ['sometimes', 'boolean'],
            'events.*.email' => ['sometimes', 'boolean'],
            'sites' => ['present', 'nullable', 'array', 'max:2000'],
            'sites.*' => ['integer'],
        ]);
        $input = ['events' => $request->input('events'), 'sites' => $request->input('sites')];
        foreach (array_keys(is_array($input['events']) ? $input['events'] : []) as $key) {
            abort_unless(in_array($key, $events, true), 422, 'Ese aviso no existe.');
        }

        // Only this account's sites can be chosen (the scope filters other agencies' out).
        $siteIds = [];
        foreach (Site::query()->pluck('id') as $id) {
            if (is_numeric($id)) {
                $siteIds[] = (int) $id;
            }
        }
        $clean = NotificationPrefs::clean($input, $siteIds);

        // Keep what the screen didn't send (e.g. an event it doesn't show yet).
        $stored = $user->notification_prefs ?? [];
        $storedEvents = is_array($stored['events'] ?? null) ? $stored['events'] : [];
        foreach ($clean['events'] as $key => $channels) {
            $storedEvents[$key] = array_merge(is_array($storedEvents[$key] ?? null) ? $storedEvents[$key] : [], $channels);
        }

        $user->forceFill(['notification_prefs' => ['events' => $storedEvents, 'sites' => $clean['sites']]])->save();

        return response()->json($this->present($user));
    }

    /**
     * @return array{events: list<array{key: string, label: string, help: string, app: bool, email: bool}>, sites: list<int>|null, email: string}
     */
    private function present(User $user): array
    {
        $prefs = NotificationPrefs::for($user, $user->agency_id !== null ? Agency::query()->find($user->agency_id) : null);

        $events = [];
        foreach (SourceEvent::cases() as $event) {
            $events[] = [
                'key' => $event->value,
                'label' => $event->label(),
                'help' => $event->help(),
                'app' => $prefs['events'][$event->value]['app'],
                'email' => $prefs['events'][$event->value]['email'],
            ];
        }

        return ['events' => $events, 'sites' => $prefs['sites'], 'email' => $user->email];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
