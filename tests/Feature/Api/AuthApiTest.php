<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $password = 'secret-123', UserRole $role = UserRole::Owner): User
    {
        $agency = Agency::factory()->create();

        return User::factory()->create([
            'agency_id' => $agency->id,
            'email' => 'owner@agency.test',
            'password' => $password,
            'role' => $role,
        ]);
    }

    public function test_login_succeeds_and_returns_the_user(): void
    {
        $this->user();

        // Origin makes Sanctum treat this as a same-origin SPA request (stateful → session).
        $this->withHeader('Origin', (string) config('app.url'))
            ->postJson('/api/v1/login', ['email' => 'owner@agency.test', 'password' => 'secret-123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@agency.test')
            ->assertJsonPath('user.role', 'owner');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $this->user();

        $this->postJson('/api/v1/login', ['email' => 'owner@agency.test', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        Sanctum::actingAs($this->user(role: UserRole::Admin));

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@agency.test')
            ->assertJsonPath('user.role', 'admin')
            // The installed version travels with the profile so the admin UI can show it everywhere.
            ->assertJsonStructure(['user' => ['app_version']]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/logout')->assertOk();
    }
}
