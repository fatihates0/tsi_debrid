<?php

namespace Tests\Feature;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Models\User;
use App\Services\RealDebridService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DebridProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_it_can_load_dashboard_page()
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('DEBRID');
    }

    public function test_it_creates_a_new_download_job_on_link_submission()
    {
        Queue::fake();

        $link = 'https://mega.nz/file/testfile123#secretkey';

        $response = $this->post('/downloads', [
            'link' => $link,
        ]);

        $response->assertRedirect('/');

        $this->assertDatabaseHas('debrid_downloads', [
            'original_link' => $link,
            'link_hash' => md5($link),
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessDebridDownloadJob::class);
    }

    public function test_it_prevents_duplicate_real_debrid_calls_for_cached_files()
    {
        Queue::fake();
        Storage::fake('public');

        $link = 'https://mega.nz/file/alreadycached#key';
        $linkHash = md5($link);
        $storagePath = 'downloads/test-uuid-1234/cached_movie.mp4';

        // Create dummy physical file in faked storage
        Storage::disk('public')->put($storagePath, 'dummy video content');

        $user = auth()->user();

        // Pre-populate database with a completed cached record
        DebridDownload::create([
            'uuid' => 'test-uuid-1234',
            'user_id' => $user->id,
            'original_link' => $link,
            'link_hash' => $linkHash,
            'filename' => 'cached_movie.mp4',
            'filesize' => 10485760,
            'downloaded_bytes' => 10485760,
            'status' => 'completed',
            'storage_path' => $storagePath,
        ]);

        // Submit the same link via API
        $response = $this->postJson('/api/v1/downloads', [
            'link' => $link,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'cached' => true,
        ]);

        // No new job should be pushed because it's already cached!
        Queue::assertNotPushed(ProcessDebridDownloadJob::class);

        // Database should still only have 1 record
        $this->assertEquals(1, DebridDownload::where('link_hash', $linkHash)->count());
    }

    public function test_it_serves_download_file_with_correct_content_length_headers()
    {
        Storage::fake('public');

        $storagePath = 'downloads/test-uuid-5678/sample.rar';
        $content = 'Sample RAR binary content for size check';
        Storage::disk('public')->put($storagePath, $content);
        $expectedSize = strlen($content);

        $download = DebridDownload::create([
            'uuid' => 'test-uuid-5678',
            'original_link' => 'https://example.com/file',
            'link_hash' => md5('https://example.com/file'),
            'filename' => 'sample.rar',
            'filesize' => $expectedSize,
            'downloaded_bytes' => $expectedSize,
            'status' => 'completed',
            'storage_path' => $storagePath,
            'mime_type' => 'application/x-rar-compressed',
        ]);

        // 1. Full GET request
        $response = $this->get('/dl/'.$download->uuid);
        $response->assertStatus(200);
        $response->assertHeader('Content-Length', (string) $expectedSize);
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertEquals($content, $response->streamedContent());

        // 2. HEAD request (IDM size pre-check)
        $headResponse = $this->call('HEAD', '/dl/'.$download->uuid);
        $headResponse->assertStatus(200);
        $headResponse->assertHeader('Content-Length', (string) $expectedSize);
        $headResponse->assertHeader('Accept-Ranges', 'bytes');

        // 3. IDM Range 0-0 probe (1-byte probe to check total size and range support)
        $probeResponse = $this->get('/dl/'.$download->uuid, [
            'Range' => 'bytes=0-0',
        ]);
        $probeResponse->assertStatus(206);
        $probeResponse->assertHeader('Content-Range', "bytes 0-0/{$expectedSize}");
        $probeResponse->assertHeader('Content-Length', '1');
        $this->assertEquals(substr($content, 0, 1), $probeResponse->streamedContent());

        // 4. Partial byte range chunk request (IDM multi-threaded download)
        $chunkResponse = $this->get('/dl/'.$download->uuid, [
            'Range' => 'bytes=0-9',
        ]);
        $chunkResponse->assertStatus(206);
        $chunkResponse->assertHeader('Content-Range', "bytes 0-9/{$expectedSize}");
        $chunkResponse->assertHeader('Content-Length', '10');
        $this->assertEquals(substr($content, 0, 10), $chunkResponse->streamedContent());
    }

    public function test_proxy_list_is_parsed_from_proxies_file()
    {
        $proxies = RealDebridService::getProxyList();
        $this->assertIsArray($proxies);

        $candidates = RealDebridService::getCandidateProxiesForApi();
        $this->assertIsArray($candidates);
        $this->assertNotEmpty($candidates);
    }

    public function test_cancelling_download_sets_cache_flag_and_cleans_up()
    {
        Storage::fake('public');
        Cache::flush();

        $storagePath = 'downloads/cancel-test-uuid/partial_movie.rar';
        Storage::disk('public')->put($storagePath, 'partial download data');

        $user = auth()->user();

        $download = DebridDownload::create([
            'uuid' => 'cancel-test-uuid',
            'user_id' => $user->id,
            'original_link' => 'https://mega.nz/file/testcancel#key',
            'link_hash' => md5('https://mega.nz/file/testcancel#key'),
            'status' => 'downloading',
            'storage_path' => $storagePath,
        ]);

        $response = $this->deleteJson('/downloads/'.$download->uuid);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Cancellation flag must be set in Cache so background worker halts Guzzle transfer
        $this->assertTrue(Cache::has('cancel_download_cancel-test-uuid'));

        // Database record must be deleted
        $this->assertDatabaseMissing('debrid_downloads', ['uuid' => 'cancel-test-uuid']);

        // Physical file must be deleted
        Storage::disk('public')->assertMissing($storagePath);
    }

    public function test_user_sees_only_their_own_downloads_on_dashboard_and_ajax_list()
    {
        $user1 = auth()->user();
        $user2 = User::factory()->create();

        $dl1 = DebridDownload::create([
            'uuid' => 'user1-dl',
            'user_id' => $user1->id,
            'original_link' => 'https://mega.nz/file/user1link#key',
            'link_hash' => md5('https://mega.nz/file/user1link#key'),
            'filename' => 'user1_file.rar',
            'status' => 'completed',
        ]);

        $dl2 = DebridDownload::create([
            'uuid' => 'user2-dl',
            'user_id' => $user2->id,
            'original_link' => 'https://mega.nz/file/user2link#key',
            'link_hash' => md5('https://mega.nz/file/user2link#key'),
            'filename' => 'user2_file.rar',
            'status' => 'completed',
        ]);

        // 1. User 1 AJAX list should only show User 1's download
        $response1 = $this->getJson('/downloads/ajax-list');
        $response1->assertStatus(200);
        $response1->assertJsonCount(1, 'data');
        $response1->assertJsonPath('data.0.uuid', 'user1-dl');

        // 2. User 2 AJAX list should only show User 2's download
        $this->actingAs($user2);
        $response2 = $this->getJson('/downloads/ajax-list');
        $response2->assertStatus(200);
        $response2->assertJsonCount(1, 'data');
        $response2->assertJsonPath('data.0.uuid', 'user2-dl');
    }

    public function test_user_submitting_existing_active_link_attaches_same_link_without_re_downloading()
    {
        Queue::fake();
        Storage::fake('public');

        $user1 = auth()->user();
        $user2 = User::factory()->create();

        $link = 'https://mega.nz/file/sharedfile#key';
        $linkHash = md5($link);
        $storagePath = 'downloads/shared-uuid/movie.mp4';
        Storage::disk('public')->put($storagePath, 'movie content');

        // User 1 already downloaded this link
        $existing = DebridDownload::create([
            'uuid' => 'shared-uuid',
            'user_id' => $user1->id,
            'original_link' => $link,
            'link_hash' => $linkHash,
            'debrid_link' => 'https://real-debrid.com/dl/movie.mp4',
            'filename' => 'movie.mp4',
            'filesize' => 5000,
            'downloaded_bytes' => 5000,
            'status' => 'completed',
            'storage_path' => $storagePath,
        ]);

        // User 2 submits the exact same link
        $this->actingAs($user2);
        $response = $this->postJson('/downloads', ['link' => $link]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'cached' => true,
        ]);

        // No new download job should be dispatched
        Queue::assertNotPushed(ProcessDebridDownloadJob::class);

        // User 2 should now have a new record attached with the same storage_path and completed status
        $this->assertDatabaseHas('debrid_downloads', [
            'user_id' => $user2->id,
            'link_hash' => $linkHash,
            'status' => 'completed',
            'storage_path' => $storagePath,
        ]);

        $this->assertEquals(2, DebridDownload::where('link_hash', $linkHash)->count());
    }

    public function test_deleting_shared_download_removes_user_record_but_preserves_file_for_other_users()
    {
        Storage::fake('public');

        $user1 = auth()->user();
        $user2 = User::factory()->create();

        $link = 'https://mega.nz/file/sharedfile2#key';
        $linkHash = md5($link);
        $storagePath = 'downloads/shared-uuid-2/file.rar';
        Storage::disk('public')->put($storagePath, 'rar data');

        $dl1 = DebridDownload::create([
            'uuid' => 'dl-user1',
            'user_id' => $user1->id,
            'original_link' => $link,
            'link_hash' => $linkHash,
            'status' => 'completed',
            'storage_path' => $storagePath,
        ]);

        $dl2 = DebridDownload::create([
            'uuid' => 'dl-user2',
            'user_id' => $user2->id,
            'original_link' => $link,
            'link_hash' => $linkHash,
            'status' => 'completed',
            'storage_path' => $storagePath,
        ]);

        // User 1 deletes their record
        $response = $this->deleteJson('/downloads/'.$dl1->uuid);
        $response->assertStatus(200);

        // User 1 record removed
        $this->assertDatabaseMissing('debrid_downloads', ['uuid' => 'dl-user1']);

        // User 2 record still exists
        $this->assertDatabaseHas('debrid_downloads', ['uuid' => 'dl-user2']);

        // Physical file preserved because User 2 still has it!
        Storage::disk('public')->assertExists($storagePath);
    }
}
