<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgencyRequest;
use App\Models\Agency;
use App\Services\Platform\Entitlements;
use App\Services\SnapshotRetentionService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated agency's own settings (CLAUDE.md §11.1): white-label branding
 * (name, brand color, default locale, logo) and the Anthropic API key for the AI
 * builder — editable from the UI so the operator never needs SSH. The key is stored
 * encrypted and never returned; the response only signals whether one is configured.
 */
final class AgencyController extends Controller
{
    public function show(TenantContext $tenant): JsonResponse
    {
        return response()->json($this->present($this->current($tenant)));
    }

    public function update(UpdateAgencyRequest $request, TenantContext $tenant): JsonResponse
    {
        $agency = $this->current($tenant);
        $validated = $request->validated();

        $agency->name = $request->string('name')->toString();

        $brandColor = $validated['brand_color'] ?? null;
        $agency->brand_color = is_string($brandColor) && $brandColor !== '' ? $brandColor : null;

        $locale = $validated['default_locale'] ?? null;
        $agency->default_locale = is_string($locale) && $locale !== '' ? $locale : $agency->default_locale;

        // anthropic_key absent → leave as-is; present (even empty) → set/clear it.
        if (array_key_exists('anthropic_key', $validated)) {
            $key = $validated['anthropic_key'];
            $agency->setAnthropicKey(is_string($key) ? $key : null);
        }

        // Webhook endpoints/secret live in the settings JSON (read by the dispatcher, §8).
        // Absent → leave as-is; present → replace. Empty secret clears it.
        $settings = $agency->settings ?? [];

        if (array_key_exists('webhook_urls', $validated) && is_array($validated['webhook_urls'])) {
            $settings['webhook_urls'] = array_values(array_filter(
                array_map(static fn ($url): string => is_string($url) ? trim($url) : '', $validated['webhook_urls']),
                static fn (string $url): bool => $url !== '',
            ));
        }

        if (array_key_exists('webhook_secret', $validated)) {
            $secret = $validated['webhook_secret'];
            if (is_string($secret) && $secret !== '') {
                $settings['webhook_secret'] = $secret;
            } else {
                unset($settings['webhook_secret']);
            }
        }

        $agency->settings = $settings;
        $agency->save();

        return response()->json($this->present($agency));
    }

    /**
     * What a retention prune would free for this agency right now (CLAUDE.md §5), so the
     * settings UI can show the impact before the daily job runs / a manual prune.
     */
    public function retentionPreview(TenantContext $tenant, SnapshotRetentionService $retention): JsonResponse
    {
        return response()->json($retention->preview($this->current($tenant)));
    }

    /** Run the retention prune for this agency now; returns how many snapshots were deleted. */
    public function pruneSnapshots(TenantContext $tenant, SnapshotRetentionService $retention): JsonResponse
    {
        $deleted = $retention->pruneAgency($this->current($tenant));

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Send a `ping` test event to the configured webhook endpoints (§8) so the operator
     * can confirm the integration is wired up before relying on the real events.
     */
    public function testWebhooks(TenantContext $tenant, WebhookDispatcher $webhooks): JsonResponse
    {
        $agency = $this->current($tenant);
        $count = count($this->webhookUrls($agency));

        if ($count === 0) {
            return response()->json(['message' => 'No hay endpoints configurados.', 'sent' => 0], 422);
        }

        $webhooks->dispatch($agency->id, 'ping', [
            'message' => 'Webhook de prueba de Imagina Reports.',
            'agency_id' => $agency->id,
        ]);

        return response()->json(['sent' => $count]);
    }

    /**
     * Upload/replace the agency logo (white-label, §11.5). Stored on the public disk;
     * the report payload exposes its URL for the portal and the printed PDF.
     */
    public function uploadLogo(Request $request, TenantContext $tenant): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp', 'max:1024'],
        ]);

        $agency = $this->current($tenant);

        $path = $request->file('logo')?->store('logos', 'public');

        if (is_string($path)) {
            $agency->logo_path = $path;
            $agency->save();
        }

        return response()->json($this->present($agency));
    }

    private function current(TenantContext $tenant): Agency
    {
        return Agency::query()->findOrFail($tenant->id());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Agency $agency): array
    {
        return [
            'id' => $agency->id,
            'name' => $agency->name,
            'brand_color' => $agency->brand_color,
            'default_locale' => $agency->default_locale,
            'logo_path' => $agency->logo_path,
            'logo_url' => $agency->logoUrl(),
            'ai_key_set' => $agency->anthropicKey() !== null,
            'snapshot_retention_months' => $agency->snapshot_retention_months,
            'calculated_metrics' => $agency->calculated_metrics ?? [],
            'webhook_urls' => $this->webhookUrls($agency),
            'webhook_secret_set' => is_string($agency->settings['webhook_secret'] ?? null) && $agency->settings['webhook_secret'] !== '',
            'plan' => $agency->plan !== null ? ['name' => $agency->plan->name, 'slug' => $agency->plan->slug] : null,
            'status' => $agency->status,
            'limits' => app(Entitlements::class)->limits($agency),
            'usage' => app(Entitlements::class)->usage($agency),
        ];
    }

    /**
     * @return list<string>
     */
    private function webhookUrls(Agency $agency): array
    {
        $urls = $agency->settings['webhook_urls'] ?? [];

        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($url): string => is_string($url) ? $url : '', $urls),
            static fn (string $url): bool => $url !== '',
        ));
    }
}
