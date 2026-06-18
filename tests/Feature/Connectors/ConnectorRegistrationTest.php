<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\ConnectorRegistry;
use App\Enums\DataSourceType;
use Tests\TestCase;

class ConnectorRegistrationTest extends TestCase
{
    public function test_the_implemented_connectors_are_registered(): void
    {
        $registry = app(ConnectorRegistry::class);

        $this->assertTrue($registry->has(DataSourceType::MainWp->value));
        $this->assertTrue($registry->has(DataSourceType::Ga4->value));
    }
}
