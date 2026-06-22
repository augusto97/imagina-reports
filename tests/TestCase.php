<?php

declare(strict_types=1);

namespace Tests;

use App\Ai\AiClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeAiClient;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never hit the Claude API in tests (CLAUDE.md §14). The empty response makes the
        // per-period narrative a no-op by default; tests that exercise AI rebind their own
        // FakeAiClient with a canned response.
        $this->app->instance(AiClient::class, new FakeAiClient(''));
    }
}
