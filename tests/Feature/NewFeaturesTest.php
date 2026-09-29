<?php

namespace Tests\Feature;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_group_permission_check_logs_out_restricted_users(): void
    {
        config(['services.xenforo.allowed_groups' => '3,4']);

        $user = User::factory()->create([
            'username' => 'testuser',
            'user_group_id' => 99, // Restricted group!
        ]);

        $response = $this->actingAs($user)->postJson('/downloads', [
            'link' => 'https://mega.nz/file/test#123',
        ]);

        $response->assertStatus(403);
        $this->assertGuest();
    }

    public function test_cron_clean_expired_cache_deletes_files_older_than_7_days(): void
    {
        $oldDownload = DebridDownload::create([
            'uuid' => 'old-download-123',
            'original_link' => 'https://mega.nz/file/old#123',
            'link_hash' => md5('https://mega.nz/file/old#123'),
            'status' => 'completed',
            'filesize' => 1024,
            'storage_path' => 'downloads/old-download-123/file.bin',
        ]);
        DebridDownload::where('id', $oldDownload->id)->update(['created_at' => now()->subDays(8)]);

        Storage::disk('public')->put('downloads/old-download-123/file.bin', 'content');

        $newDownload = DebridDownload::create([
            'uuid' => 'new-download-456',
            'original_link' => 'https://mega.nz/file/new#123',
            'link_hash' => md5('https://mega.nz/file/new#123'),
            'status' => 'completed',
            'filesize' => 2048,
            'storage_path' => 'downloads/new-download-456/file.bin',
            'created_at' => now()->subDays(2),
        ]);

        Storage::disk('public')->put('downloads/new-download-456/file.bin', 'content');

        $response = $this->getJson('/cron/clean-cache');
        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'deleted_count' => 1]);

        $this->assertDatabaseMissing('debrid_downloads', ['id' => $oldDownload->id]);
        $this->assertDatabaseHas('debrid_downloads', ['id' => $newDownload->id]);
        Storage::disk('public')->assertMissing('downloads/old-download-123/file.bin');
        Storage::disk('public')->assertExists('downloads/new-download-456/file.bin');
    }

    public function test_superuser_login_and_full_access(): void
    {
        config(['services.superuser.username' => 'admin']);
        config(['services.superuser.password' => 'secret123']);

        $loginResponse = $this->post('/login', [
            'login' => 'admin',
            'password' => 'secret123',
        ]);

        $loginResponse->assertRedirect('/');
        $this->assertAuthenticated();

        /** @var User $user */
        $user = auth()->user();
        $this->assertTrue($user->isSuperUser());

        $dashboardResponse = $this->get('/');
        $dashboardResponse->assertStatus(200);
        $dashboardResponse->assertSee('SUPERUSER');
        $dashboardResponse->assertSee('Superuser Yönetim Paneli');
    }

    public function test_superuser_can_delete_user_and_associated_downloads(): void
    {
        config(['services.superuser.username' => 'admin']);

        $superuser = User::factory()->create([
            'username' => 'admin',
        ]);

        $normalUser = User::factory()->create([
            'username' => 'tobedeleted',
        ]);

        $userDownload = DebridDownload::create([
            'uuid' => 'user-dl-789',
            'user_id' => $normalUser->id,
            'original_link' => 'https://mega.nz/file/del#123',
            'link_hash' => md5('https://mega.nz/file/del#123'),
            'status' => 'completed',
            'filesize' => 512,
            'storage_path' => 'downloads/user-dl-789/file.bin',
        ]);

        Storage::disk('public')->put('downloads/user-dl-789/file.bin', 'content');

        // Non-superuser gets 403
        $response1 = $this->actingAs($normalUser)->deleteJson("/users/{$normalUser->id}");
        $response1->assertStatus(403);

        // Superuser deletes user
        $response2 = $this->actingAs($superuser)->deleteJson("/users/{$normalUser->id}");
        $response2->assertStatus(200);
        $response2->assertJson(['success' => true]);

        $this->assertDatabaseMissing('users', ['id' => $normalUser->id]);
        $this->assertDatabaseMissing('debrid_downloads', ['id' => $userDownload->id]);
        Storage::disk('public')->assertMissing('downloads/user-dl-789/file.bin');
    }

    public function test_superuser_can_retry_failed_download(): void
    {
        Queue::fake();

        config(['services.superuser.username' => 'admin']);

        $superuser = User::factory()->create(['username' => 'admin']);
        $normalUser = User::factory()->create(['username' => 'user1']);

        $failedDownload = DebridDownload::create([
            'uuid' => 'failed-dl-999',
            'user_id' => $normalUser->id,
            'original_link' => 'https://mega.nz/file/fail#123',
            'link_hash' => md5('https://mega.nz/file/fail#123'),
            'status' => 'failed',
            'error_message' => 'Something failed',
        ]);

        // Non-superuser gets 403
        $res1 = $this->actingAs($normalUser)->postJson("/downloads/{$failedDownload->uuid}/retry");
        $res1->assertStatus(403);

        // Superuser retries
        $res2 = $this->actingAs($superuser)->postJson("/downloads/{$failedDownload->uuid}/retry");
        $res2->assertStatus(200);
        $res2->assertJson(['success' => true]);

        $this->assertDatabaseHas('debrid_downloads', [
            'id' => $failedDownload->id,
            'status' => 'pending',
            'error_message' => null,
        ]);

        Queue::assertPushed(ProcessDebridDownloadJob::class);
    }
}
