<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\MetricSet;

/**
 * Optional capability: a connector whose source pushes its already-aggregated data
 * to Imagina Reports instead of being polled (CLAUDE.md §9 — CrowdSec push model).
 *
 * Each client VPS runs the engine's CLI locally and POSTs the result outbound to the
 * ingest endpoint, so no inbound port is ever opened on the client server. The connector
 * stays the single normalizer: it maps that raw payload to the same metric bag fetch()
 * would produce, and the ingest controller stores it as a snapshot like any other sync.
 */
interface ReceivesPushedData
{
    /**
     * Normalize a pushed raw payload (aggregated at the source) into a MetricSet.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function fromPushedPayload(array $payload): MetricSet;
}
