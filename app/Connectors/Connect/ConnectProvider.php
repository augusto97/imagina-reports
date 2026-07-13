<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use App\Connectors\ConfigField;
use Illuminate\Http\Request;

/**
 * A "connect with one click" flow for a data-source type (the alternative to the manual
 * configSchema form). The client authorizes on the provider's own screen — WooCommerce's
 * `/wc-auth` approve page, or a Google/Meta OAuth consent — and we store the resulting
 * credentials automatically, so they never copy-paste JSON or tokens.
 *
 * Kept deliberately provider-agnostic: WooCommerce POSTs keys to our callback, an OAuth2
 * provider GETs back a `code`. Both are normalized to a {@see ConnectCallback}.
 */
interface ConnectProvider
{
    /** The data-source type this connects, e.g. 'woocommerce'. */
    public function type(): string;

    /** Button label shown in the UI, e.g. "Conectar mi tienda WooCommerce". */
    public function label(): string;

    /**
     * Fields the client must fill BEFORE starting the flow (e.g. the store URL for
     * WooCommerce). Empty for pure OAuth providers, where nothing is needed up front.
     *
     * @return list<ConfigField>
     */
    public function startFields(): array;

    /**
     * The provider URL to redirect the client's browser to, to authorize.
     *
     * @param  array<string, mixed>  $input  Validated startFields() input.
     * @param  string  $nonce  One-time token identifying this connect intent (round-tripped).
     * @param  string  $callbackUrl  Our public endpoint the provider sends the result to.
     * @param  string  $returnUrl  Where to send the client's browser once done.
     */
    public function redirectUrl(array $input, string $nonce, string $callbackUrl, string $returnUrl): string;

    /**
     * Translate the provider's callback request into a normalized {@see ConnectCallback},
     * or null when the request isn't a valid/authorized completion (so the controller can
     * reject it). Providers must NOT persist anything here.
     */
    public function parseCallback(Request $request): ?ConnectCallback;
}
