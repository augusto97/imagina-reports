<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $agency->id, 'password' => 'old-password-123']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_changes_the_password_with_the_correct_current_one(): void
    {
        $user = $this->actingUser();

        $this->putJson('/api/v1/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()?->password ?? ''));
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = $this->actingUser();

        $this->putJson('/api/v1/user/password', [
            'current_password' => 'WRONG',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()?->password ?? ''));
    }

    public function test_it_requires_confirmation_and_minimum_length(): void
    {
        $this->actingUser();

        $this->putJson('/api/v1/user/password', [
            'current_password' => 'old-password-123',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
