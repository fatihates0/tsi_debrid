<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenForoAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('turkcesesindir.com');
    }

    public function test_user_can_authenticate_via_xenforo_api(): void
    {
        config(['services.xenforo.api_key' => 'test-super-user-key']);

        Http::fake([
            '*api/auth*' => Http::response([
                'success' => true,
                'user' => [
                    'user_id' => 101,
                    'username' => 'TestForumUser',
                    'email' => 'forumuser@turkcesesindir.com',
                    'avatar_urls' => [
                        'm' => 'https://turkcesesindir.com/data/avatars/m/0/101.jpg',
                    ],
                    'user_group_id' => 2,
                ],
            ], 200),
        ]);

        $response = $this->post('/login', [
            'login' => 'TestForumUser',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'xenforo_id' => 101,
            'username' => 'TestForumUser',
            'email' => 'forumuser@turkcesesindir.com',
        ]);
    }

    public function test_authentication_fails_with_invalid_credentials(): void
    {
        config(['services.xenforo.api_key' => 'test-super-user-key']);

        Http::fake([
            '*api/auth*' => Http::response([
                'errors' => [
                    [
                        'code' => 'incorrect_password',
                        'message' => 'Belirtilen şifre yanlış.',
                    ],
                ],
            ], 400),
        ]);

        $response = $this->post('/login', [
            'login' => 'WrongUser',
            'password' => 'wrongpass',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create([
            'xenforo_id' => 202,
            'username' => 'LoggedUser',
        ]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
