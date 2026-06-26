<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateReportSharingRequest;
use App\Http\Resources\ReportDefinitionResource;
use App\Models\ReportDefinition;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manages a report definition's sharing & privacy (CLAUDE.md §10/Etapa D): visibility,
 * an optional password, and the embed-domain allowlist. Scoped to the agency via the
 * route-model binding (AgencyScope), so one agency can't touch another's settings.
 */
final class ReportSharingController extends Controller
{
    public function update(UpdateReportSharingRequest $request, ReportDefinition $reportDefinition): ReportDefinitionResource
    {
        $visibilityValue = $request->validated('visibility');
        $visibility = ReportVisibility::from(is_string($visibilityValue) ? $visibilityValue : 'public');
        $domains = $request->validated('embed_domains');
        $password = $request->validated('password');

        $attributes = [
            'visibility' => $visibility,
            'embed_domains' => $this->normalizeDomains(is_array($domains) ? $domains : null),
        ];

        // A new password is hashed only when provided. Leaving the field out keeps the
        // current one; switching away from "password" visibility clears it.
        if ($visibility !== ReportVisibility::Password) {
            $attributes['password_hash'] = null;
        } elseif (is_string($password) && $password !== '') {
            $attributes['password_hash'] = Hash::make($password);
        }

        // Publishing the live dashboard mints a stable token on first enable; disabling
        // keeps the token so re-enabling reuses the same URL.
        if ($request->has('dashboard_enabled')) {
            $enabled = (bool) $request->validated('dashboard_enabled');
            $attributes['dashboard_enabled'] = $enabled;

            if ($enabled && ($reportDefinition->dashboard_token === null || $reportDefinition->dashboard_token === '')) {
                $attributes['dashboard_token'] = Str::random(48);
            }
        }

        $reportDefinition->forceFill($attributes)->save();

        return new ReportDefinitionResource($reportDefinition);
    }

    /**
     * @param  array<array-key, mixed>|null  $domains
     * @return array<int, string>|null
     */
    private function normalizeDomains(?array $domains): ?array
    {
        if ($domains === null) {
            return null;
        }

        $clean = [];
        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            // Keep just the host: strip scheme, path, and surrounding whitespace.
            $host = trim($domain);
            $host = (string) preg_replace('#^https?://#i', '', $host);
            $host = explode('/', $host)[0];
            $host = strtolower(trim($host));

            if ($host !== '' && ! in_array($host, $clean, true)) {
                $clean[] = $host;
            }
        }

        return $clean;
    }
}
