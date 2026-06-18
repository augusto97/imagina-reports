<?php

declare(strict_types=1);

namespace Tests\Support\Connectors;

use App\Connectors\Google\GoogleTokenProvider;
use Throwable;

/**
 * Stub token provider so GA4/GSC connector tests never authenticate against Google.
 */
final class FakeGoogleTokenProvider implements GoogleTokenProvider
{
    public function __construct(
        private readonly string $token = 'fake-token',
        private readonly ?Throwable $throws = null,
    ) {}

    public function accessToken(array $serviceAccount, string $scope): string
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->token;
    }
}
