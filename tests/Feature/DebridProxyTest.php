<?php

namespace Tests\Feature;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DebridProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_load_dashboard_page()
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('DEBRID PROXY');
        $response->assertSee('Anti-Ban');
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

        // Pre-populate database with a completed cached record
        DebridDownload::create([
            'uuid' => 'test-uuid-1234',
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
}
