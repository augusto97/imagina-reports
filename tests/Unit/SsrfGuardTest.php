<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Http\SsrfGuard;
use PHPUnit\Framework\TestCase;

class SsrfGuardTest extends TestCase
{
    public function test_it_blocks_private_reserved_and_loopback_targets(): void
    {
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://127.0.0.1:6379'));
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://10.0.0.5/'));
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://192.168.1.10/wp-json'));
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://172.16.4.4/'));
        $this->assertTrue(SsrfGuard::isBlockedUrl('http://[::1]/'));
        $this->assertTrue(SsrfGuard::isBlockedHost('127.0.0.1'));
        $this->assertTrue(SsrfGuard::isBlockedHost('localhost'));
    }

    public function test_it_allows_public_targets(): void
    {
        $this->assertFalse(SsrfGuard::isBlockedUrl('https://8.8.8.8/'));
        $this->assertFalse(SsrfGuard::isBlockedUrl('https://api.mercadopago.com/preapproval'));
        $this->assertFalse(SsrfGuard::isBlockedHost('93.184.216.34')); // example.com's public IP
    }

    public function test_it_does_not_block_an_unparseable_url(): void
    {
        // Can't identify a host → allow; the HTTP client will fail it normally.
        $this->assertFalse(SsrfGuard::isBlockedUrl('not a url'));
        $this->assertFalse(SsrfGuard::isBlockedHost(''));
    }
}
