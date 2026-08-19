<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_login(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertCreated();

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_credentials_and_unauthenticated_task_access_are_rejected(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => 'password']);

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('first');
        $second = $user->createToken('second');

        $this->withToken($first->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->accessToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $second->accessToken->getKey()]);
    }
}
