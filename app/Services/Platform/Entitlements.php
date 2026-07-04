<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Agency;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Report;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Resolves and enforces a plan's limits for an agency (SaaS Fase 1). Effective limits =
 * the agency's plan, overlaid with per-agency `plan_overrides`. A null limit = unlimited.
 * All counts run WITHOUT the tenant scope so they're correct regardless of the caller's
 * context (platform panel or the agency itself).
 *
 * @phpstan-type Limits array{max_sites: int|null, max_data_sources: int|null, max_clients: int|null, max_users: int|null, max_reports_per_month: int|null, allowed_connectors: list<string>|null, features: array<string, bool>}
 */
final class Entitlements
{
    /**
     * The agency's effective limits (plan defaults overlaid with per-agency overrides).
     *
     * @return Limits
     */
    public function limits(Agency $agency): array
    {
        $plan = $agency->plan;
        $overrides = $agency->plan_overrides ?? [];

        $limit = static function (string $key) use ($plan, $overrides): ?int {
            if (array_key_exists($key, $overrides)) {
                return is_numeric($overrides[$key]) ? (int) $overrides[$key] : null;
            }
            // No plan assigned → nothing is allowed (0). Never "unlimited" by default; a
            // plan-less agency must be given a plan before it can create anything.
            if ($plan === null) {
                return 0;
            }
            $value = $plan->getAttribute($key);

            return is_int($value) ? $value : null; // a plan's null = unlimited
        };

        $allowedRaw = $overrides['allowed_connectors'] ?? ($plan !== null ? $plan->allowed_connectors : null);
        $featuresRaw = $overrides['features'] ?? ($plan !== null ? $plan->features : null);

        $allowed = null;
        if (is_array($allowedRaw)) {
            $allowed = [];
            foreach ($allowedRaw as $connector) {
                if (is_string($connector) && $connector !== '') {
                    $allowed[] = $connector;
                }
            }
        }

        $features = [];
        if (is_array($featuresRaw)) {
            foreach ($featuresRaw as $key => $value) {
                if (is_string($key)) {
                    $features[$key] = (bool) $value;
                }
            }
        }

        return [
            'max_sites' => $limit('max_sites'),
            'max_data_sources' => $limit('max_data_sources'),
            'max_clients' => $limit('max_clients'),
            'max_users' => $limit('max_users'),
            'max_reports_per_month' => $limit('max_reports_per_month'),
            'allowed_connectors' => $allowed,
            'features' => $features,
        ];
    }

    /**
     * Current usage counts for the agency (tenant-independent).
     *
     * @return array{sites: int, data_sources: int, clients: int, users: int, reports_this_month: int}
     */
    public function usage(Agency $agency): array
    {
        return [
            'sites' => $this->countFor($agency, Site::class),
            'data_sources' => $this->countFor($agency, DataSource::class),
            'clients' => $this->countFor($agency, Client::class),
            'users' => User::query()->where('agency_id', $agency->id)->count(),
            'reports_this_month' => Report::query()
                ->withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('created_at', '>=', Date::now()->startOfMonth())
                ->count(),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function countFor(Agency $agency, string $model): int
    {
        return $model::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->count();
    }

    /**
     * Usage for EVERY agency in a fixed number of grouped queries (5 total) instead of 5
     * counts per agency — so the platform panel's agency list stays flat regardless of how
     * many agencies exist (PERF-4). Keyed by agency_id; agencies with no rows default to 0.
     *
     * @return array<int, array{sites: int, data_sources: int, clients: int, users: int, reports_this_month: int}>
     */
    public function usageForAll(): array
    {
        $sites = $this->groupCount(Site::class);
        $dataSources = $this->groupCount(DataSource::class);
        $clients = $this->groupCount(Client::class);
        $users = $this->groupCount(User::class);
        $reports = $this->groupCount(Report::class, Date::now()->startOfMonth());

        $ids = array_unique(array_merge(
            array_keys($sites),
            array_keys($dataSources),
            array_keys($clients),
            array_keys($users),
            array_keys($reports),
        ));

        $usage = [];
        foreach ($ids as $id) {
            $usage[$id] = [
                'sites' => $sites[$id] ?? 0,
                'data_sources' => $dataSources[$id] ?? 0,
                'clients' => $clients[$id] ?? 0,
                'users' => $users[$id] ?? 0,
                'reports_this_month' => $reports[$id] ?? 0,
            ];
        }

        return $usage;
    }

    /**
     * COUNT(*) grouped by agency_id for one model, optionally filtered to rows created since
     * a moment (used for reports-this-month).
     *
     * @param  class-string<Model>  $model
     * @return array<int, int>
     */
    private function groupCount(string $model, ?\DateTimeInterface $since = null): array
    {
        $query = $model::query()->withoutGlobalScopes();

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $map = [];
        foreach ($query->groupBy('agency_id')->selectRaw('agency_id, count(*) as aggregate')->get() as $row) {
            $agencyId = $row->getAttribute('agency_id');
            $count = $row->getAttribute('aggregate');

            if (is_numeric($agencyId) && is_numeric($count)) {
                $map[(int) $agencyId] = (int) $count;
            }
        }

        return $map;
    }

    public function canAddSite(Agency $agency): bool
    {
        return $this->withinLimit($this->limits($agency)['max_sites'], $this->usage($agency)['sites']);
    }

    public function canAddClient(Agency $agency): bool
    {
        return $this->withinLimit($this->limits($agency)['max_clients'], $this->usage($agency)['clients']);
    }

    public function canAddUser(Agency $agency): bool
    {
        return $this->withinLimit($this->limits($agency)['max_users'], $this->usage($agency)['users']);
    }

    public function canAddDataSource(Agency $agency, ?string $connector = null): bool
    {
        if (! $this->withinLimit($this->limits($agency)['max_data_sources'], $this->usage($agency)['data_sources'])) {
            return false;
        }

        return $connector === null || $this->allowsConnector($agency, $connector);
    }

    public function canGenerateReport(Agency $agency): bool
    {
        return $this->withinLimit($this->limits($agency)['max_reports_per_month'], $this->usage($agency)['reports_this_month']);
    }

    public function allowsConnector(Agency $agency, string $connector): bool
    {
        $allowed = $this->limits($agency)['allowed_connectors'];

        return $allowed === null || in_array($connector, $allowed, true);
    }

    public function hasFeature(?Agency $agency, string $feature): bool
    {
        // Off by default: a feature is granted only by the plan (or an override). A missing
        // agency (shouldn't happen, but the relation is nullable) has no features.
        if ($agency === null) {
            return false;
        }

        return (bool) ($this->limits($agency)['features'][$feature] ?? false);
    }

    private function withinLimit(?int $limit, int $used): bool
    {
        return $limit === null || $used < $limit;
    }
}
