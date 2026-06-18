<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SendWebhookJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWebhookJobTest extends TestCase
{
    public function test_it_posts_a_signed_payload_when_a_secret_is_set(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        (new SendWebhookJob('https://hook.test/in', 'anomaly.detected', ['report_id' => 7], 'shh'))->handle();

        Http::assertSent(function (Request $request): bool {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'shh');

            return $request->url() === 'https://hook.test/in'
                && str_contains($request->body(), '"event":"anomaly.detected"')
                && str_contains($request->body(), '"report_id":7')
                && $request->header('X-Imagina-Signature')[0] === $expected;
        });
    }

    public function test_it_posts_without_a_signature_when_no_secret(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        (new SendWebhookJob('https://hook.test/in', 'report.generated', ['report_id' => 7]))->handle();

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Imagina-Signature'));
    }
}
