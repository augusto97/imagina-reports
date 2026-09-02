<?php

declare(strict_types=1);

namespace App\Connectors\Support;

use App\Connectors\Exceptions\DiscoveryFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

/**
 * Turns a provider's error response into something a person can act on.
 *
 * Google, Meta and the OAuth endpoints all bury their message in a different key, so this
 * reads the handful of shapes they use and falls back to the status code. Deliberately
 * only the provider's own message: never the request, which carries the token.
 */
trait DescribesApiErrors
{
    /** The provider's own error text, or '' when it didn't send one we recognise. */
    private function providerError(mixed $json): string
    {
        if (! is_array($json)) {
            return '';
        }

        // error.message → Google APIs and the Meta Graph API.
        // error_description / error → OAuth 2 token endpoints.
        // message → a few REST APIs (MainWP, WooCommerce).
        foreach (['error.message', 'error_description', 'error', 'message'] as $key) {
            $value = Arr::get($json, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A discovery failure that names the provider, the status and — when there is one —
     * what the provider said, so the panel can show it instead of a generic apology.
     */
    private function discoveryFailed(string $provider, Response $response): DiscoveryFailed
    {
        $detail = $this->providerError($response->json());

        return DiscoveryFailed::because(
            $detail === ''
                ? "{$provider} respondió HTTP {$response->status()} al listar las cuentas."
                : "{$provider} respondió HTTP {$response->status()}: {$detail}",
        );
    }
}
