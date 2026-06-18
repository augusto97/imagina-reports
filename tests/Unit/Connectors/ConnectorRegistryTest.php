<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\UnknownConnectorException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Connectors\FakeConnector;

class ConnectorRegistryTest extends TestCase
{
    public function test_it_registers_and_resolves_connectors_by_key(): void
    {
        $registry = new ConnectorRegistry;
        $connector = new FakeConnector('mainwp', 'MainWP');

        $registry->register($connector);

        $this->assertTrue($registry->has('mainwp'));
        $this->assertSame($connector, $registry->get('mainwp'));
        $this->assertSame(['mainwp'], $registry->keys());
        $this->assertCount(1, $registry->all());
    }

    public function test_registering_the_same_key_replaces_the_connector(): void
    {
        $registry = new ConnectorRegistry;
        $registry->register(new FakeConnector('ga4'));
        $second = new FakeConnector('ga4');
        $registry->register($second);

        $this->assertCount(1, $registry->all());
        $this->assertSame($second, $registry->get('ga4'));
    }

    public function test_resolving_an_unknown_key_throws(): void
    {
        $registry = new ConnectorRegistry;

        $this->expectException(UnknownConnectorException::class);

        $registry->get('nope');
    }
}
