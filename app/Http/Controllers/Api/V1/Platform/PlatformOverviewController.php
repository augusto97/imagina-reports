<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\MetricSnapshot;
use App\Models\Report;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide vital signs for the operator's landing view: how many agencies exist, how
 * many are actually active, what they're consuming, and what's failing right now. Everything
 * is an aggregate query (no per-agency loops), so it stays cheap as the platform grows.
 */
final class PlatformOverviewController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'agencies' => [
                'total' => Agency::query()->count(),
                'active' => Agency::query()->where('status', 'active')->count(),
                'suspended' => Agency::query()->where('status', 'suspended')->count(),
                // Signed up in the last 30 days — the growth signal.
                'new_this_month' => Agency::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'users' => [
                'total' => User::query()->whereNotNull('agency_id')->count(),
                'with_two_factor' => User::query()->whereNotNull('agency_id')->whereNotNull('two_factor_confirmed_at')->count(),
            ],
            'workload' => [
                'clients' => Client::query()->withoutGlobalScopes()->count(),
                'sites' => Site::query()->withoutGlobalScopes()->count(),
                'data_sources' => DataSource::query()->withoutGlobalScopes()->count(),
                'reports_this_month' => Report::query()->withoutGlobalScopes()
                    ->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'health' => [
                // Connectors currently in error: the operator's support queue.
                'failing_sources' => DataSource::query()->withoutGlobalScopes()->where('status', 'error')->count(),
                'snapshots' => MetricSnapshot::query()->withoutGlobalScopes()->count(),
                'storage_bytes' => (int) MetricSnapshot::query()->withoutGlobalScopes()->sum(DB::raw('LENGTH(payload)')),
            ],
            'billing' => [
                'active_subscriptions' => Subscription::query()->where('status', SubscriptionStatus::Active)->count(),
                'past_due' => Subscription::query()->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Pending])->count(),
            ],
        ]);
    }
}
