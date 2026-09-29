<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class XenForoAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure in-memory xenforo connection for testing
        config(['database.connections.xenforo' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::connection('xenforo')->create('user', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('username');
            $table->string('email');
            $table->integer('user_group_id')->default(2);
            $table->integer('avatar_date')->default(0);
        });

        Schema::connection('xenforo')->create('user_authenticate', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('scheme_class')->default('XF\Authentication\Core12');
            $table->binary('data');
        });
    }

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

    public function test_user_can_authenticate_via_xenforo_database(): void
    {
        $passwordHash = password_hash('secret123', PASSWORD_BCRYPT);
        $authPayload = serialize(['hash' => $passwordHash]);

        DB::connection('xenforo')->table('user')->insert([
            'user_id' => 101,
            'username' => 'TestForumUser',
            'email' => 'forumuser@turkcesesindir.com',
            'user_group_id' => 2,
        ]);

        DB::connection('xenforo')->table('user_authenticate')->insert([
            'user_id' => 101,
            'scheme_class' => 'XF\Authentication\Core12',
            'data' => $authPayload,
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

    public function test_authentication_fails_with_invalid_password(): void
    {
        $passwordHash = password_hash('correctpass', PASSWORD_BCRYPT);
        $authPayload = serialize(['hash' => $passwordHash]);

        DB::connection('xenforo')->table('user')->insert([
            'user_id' => 102,
            'username' => 'WrongUser',
            'email' => 'wrong@turkcesesindir.com',
        ]);

        DB::connection('xenforo')->table('user_authenticate')->insert([
            'user_id' => 102,
            'data' => $authPayload,
        ]);

        $response = $this->post('/login', [
            'login' => 'WrongUser',
            'password' => 'wrongpass',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_user_group_restriction_blocks_unallowed_groups(): void
    {
        config(['services.xenforo.allowed_groups' => '3,4']);

        $passwordHash = password_hash('secret123', PASSWORD_BCRYPT);
        $authPayload = serialize(['hash' => $passwordHash]);

        DB::connection('xenforo')->table('user')->insert([
            'user_id' => 103,
            'username' => 'NormalMember',
            'email' => 'normal@turkcesesindir.com',
            'user_group_id' => 2,
        ]);

        DB::connection('xenforo')->table('user_authenticate')->insert([
            'user_id' => 103,
            'data' => $authPayload,
        ]);

        $response = $this->post('/login', [
            'login' => 'NormalMember',
            'password' => 'secret123',
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
