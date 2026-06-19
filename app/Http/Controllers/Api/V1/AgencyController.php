<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgencyRequest;
use App\Models\Agency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

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

        $agency->save();

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
            'ai_key_set' => $agency->anthropicKey() !== null,
        ];
    }
}
