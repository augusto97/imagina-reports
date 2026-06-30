<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create(['agency_id' => Agency::factory()->create()->id]));
    }

    public function test_it_uploads_an_image_and_returns_a_url(): void
    {
        Storage::fake('public');
        $this->actAsUser();

        $response = $this->post('/api/v1/uploads/image', ['image' => UploadedFile::fake()->image('logo.png')]);

        $response->assertOk();
        $this->assertIsString($response->json('url'));
        $this->assertCount(1, Storage::disk('public')->files('uploads'));
    }

    public function test_it_rejects_a_non_image(): void
    {
        Storage::fake('public');
        $this->actAsUser();

        $this->post('/api/v1/uploads/image', ['image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/uploads/image')->assertUnauthorized();
    }
}
