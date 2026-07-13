<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\ConfigField;
use App\Connectors\ConfigFieldType;
use App\Enums\DataSourceType;
use Illuminate\Http\Request;

/**
 * One-click WooCommerce connection via the store's native REST API auth endpoint
 * (`/wc-auth/v1/authorize`). The client enters their store URL, approves on their OWN
 * WordPress ("Imagina Reports wants read access"), and WooCommerce POSTs the generated
 * read-only consumer key/secret straight to our callback — no manual key creation.
 *
 * Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/#authentication-endpoint
 * Unlike Google/Meta this needs NO third-party app review — it's the client's own store.
 */
final class WooCommerceConnect implements ConnectProvider
{
    private const APP_NAME = 'Imagina Reports';

    public function type(): string
    {
        return DataSourceType::WooCommerce->value;
    }

    public function label(): string
    {
        return 'Conectar mi tienda WooCommerce';
    }

    public function startFields(): array
    {
        return [
            new ConfigField('store_url', 'URL de la tienda', ConfigFieldType::Url, help: 'La URL de tu tienda WooCommerce con HTTPS, p. ej. https://tutienda.com. Aprobarás el acceso de solo lectura en tu propio WordPress.'),
        ];
    }

    public function redirectUrl(array $input, string $nonce, string $callbackUrl, string $returnUrl): string
    {
        $store = $this->normalizeStore(is_string($input['store_url'] ?? null) ? $input['store_url'] : '');

        $query = http_build_query([
            'app_name' => self::APP_NAME,
            'scope' => 'read',
            'user_id' => $nonce,
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
        ]);

        return "{$store}/wc-auth/v1/authorize?{$query}";
    }

    public function nonceFromCallback(Request $request): string
    {
        return $this->str($request->input('user_id'));
    }

    public function callbackIsBrowserRedirect(): bool
    {
        // WooCommerce POSTs the keys server-to-server, then redirects the browser itself.
        return false;
    }

    public function parseCallback(Request $request, string $callbackUrl): ?ConnectCallback
    {
        $nonce = $this->str($request->input('user_id'));
        $key = $this->str($request->input('consumer_key'));
        $secret = $this->str($request->input('consumer_secret'));
        $permissions = $this->str($request->input('key_permissions'));

        // A denied/incomplete approval, or one that didn't grant read — reject it so we never
        // store a source we can't actually read from.
        if ($nonce === '' || $key === '' || $secret === '' || ! str_contains($permissions, 'read')) {
            return null;
        }

        return new ConnectCallback(
            nonce: $nonce,
            config: [],
            credentials: ['consumer_key' => $key, 'consumer_secret' => $secret],
        );
    }

    /** Normalize the store URL: force https and drop any trailing slash. */
    private function normalizeStore(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        // Upgrade http → https (the callback must be HTTPS and mixed schemes fail the flow).
        if (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        return rtrim($url, '/');
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
