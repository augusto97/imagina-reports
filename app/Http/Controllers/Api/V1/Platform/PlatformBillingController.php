<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform payment credentials (SaaS Fase 2). Secrets are stored encrypted and never
 * returned in plaintext — the response only signals whether each is configured. Platform-
 * admin only (route middleware).
 */
final class PlatformBillingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present(PlatformSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'mercadopago_access_token' => ['sometimes', 'nullable', 'string'],
            'paypal_client_id' => ['sometimes', 'nullable', 'string'],
            'paypal_secret' => ['sometimes', 'nullable', 'string'],
            'billing_sandbox' => ['sometimes', 'boolean'],
        ]);

        $settings = PlatformSetting::current();

        // A present secret sets (or clears, when blank) it; absent leaves it as-is.
        foreach (['mercadopago_access_token', 'paypal_client_id', 'paypal_secret'] as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);
                $settings->putSecret($key, is_string($value) ? $value : null);
            }
        }
        if ($request->has('billing_sandbox')) {
            $settings->put('billing_sandbox', $request->boolean('billing_sandbox'));
        }

        $settings->save();

        return response()->json($this->present($settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformSetting $settings): array
    {
        return [
            'mercadopago_configured' => $settings->hasSecret('mercadopago_access_token'),
            'paypal_configured' => $settings->hasSecret('paypal_client_id') && $settings->hasSecret('paypal_secret'),
            'billing_sandbox' => $settings->get('billing_sandbox') !== false,
        ];
    }
}
