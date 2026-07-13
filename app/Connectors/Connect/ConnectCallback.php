<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

/**
 * The result of parsing a connect-provider callback: the one-time `nonce` that ties it
 * back to the pending connect intent (so we know which site/agency it belongs to), plus
 * the `config` and `credentials` to store on the created data source. Providers never
 * touch the database — they only translate their callback into this normalized shape.
 */
final readonly class ConnectCallback
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public string $nonce,
        public array $config = [],
        public array $credentials = [],
    ) {}
}
