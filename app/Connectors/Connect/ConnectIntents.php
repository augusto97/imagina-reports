<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The pending half of a one-click connection: who is connecting what, for which site, and
 * where to send the browser afterwards. Started by the panel or by a client's connection
 * link, consumed once by the public callback through the nonce the provider round-trips.
 */
final class ConnectIntents
{
    /** How long a started connect intent stays valid before the nonce expires. */
    public const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $input
     * @return string The one-time nonce.
     */
    public function create(string $type, int $siteId, int $agencyId, array $input, string $returnUrl, ?int $inviteId = null): string
    {
        $nonce = Str::random(48);

        Cache::put($this->key($nonce), [
            'type' => $type,
            'site_id' => $siteId,
            'agency_id' => $agencyId,
            'input' => $input,
            'return_url' => $returnUrl,
            'invite_id' => $inviteId,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $nonce;
    }

    /**
     * Take the intent (single use).
     *
     * @return array<string, mixed>|null
     */
    public function pull(string $nonce): ?array
    {
        if ($nonce === '') {
            return null;
        }

        $intent = Cache::pull($this->key($nonce));

        /** @var array<string, mixed>|null */
        return is_array($intent) ? $intent : null;
    }

    private function key(string $nonce): string
    {
        return "connect:intent:{$nonce}";
    }
}
