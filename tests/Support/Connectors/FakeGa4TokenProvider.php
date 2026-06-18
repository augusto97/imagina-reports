<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use App\Connectors\Ga4\Ga4TokenProvider;
use Throwable;

/**
 * Stub token provider so GA4 connector tests never authenticate against Google.
 */
final class FakeGa4TokenProvider implements Ga4TokenProvider
{
    public function __construct(
        private readonly string $token = 'fake-token',
        private readonly ?Throwable $throws = null,
    ) {}

    public function accessToken(array $serviceAccount): string
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->token;
    }
}
