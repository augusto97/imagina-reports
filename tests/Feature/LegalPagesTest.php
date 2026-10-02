<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_links_the_privacy_policy_and_terms(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false);
    }

    public function test_the_privacy_policy_carries_the_google_limited_use_disclosure(): void
    {
        config(['mail.from.address' => 'hola@example.com']);

        $this->get('/privacidad')
            ->assertOk()
            ->assertSee('Limited Use')
            ->assertSee('analytics.readonly')
            ->assertSee('hola@example.com');
    }

    public function test_the_terms_page_is_public(): void
    {
        $this->get('/terminos')->assertOk()->assertSee('Términos del servicio');
    }
}
