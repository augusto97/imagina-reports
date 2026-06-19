<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    public function test_it_negotiates_a_supported_locale_from_the_header(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        (new SetLocale)->handle($request, fn (Request $r): Response => new Response);

        $this->assertSame('en', App::getLocale());
        $this->assertSame('What we did this month', __('report.whats_done'));
    }

    public function test_translations_exist_for_every_supported_locale(): void
    {
        foreach (SetLocale::SUPPORTED as $locale) {
            App::setLocale($locale);
            $this->assertIsString(__('report.ready_subject'));
            $this->assertStringNotContainsString('report.', __('report.ready_subject'));
        }
    }
}
