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
            $value = $plan?->getAttribute($key);

            return is_int($value) ? $value : null;
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

    public function hasFeature(Agency $agency, string $feature): bool
    {
        // No plan assigned yet → permissive (nothing blocks the owner's first steps).
        if ($agency->plan === null && ($agency->plan_overrides['features'] ?? null) === null) {
            return true;
        }

        return (bool) ($this->limits($agency)['features'][$feature] ?? false);
    }

    private function withinLimit(?int $limit, int $used): bool
    {
        return $limit === null || $used < $limit;
    }
}
