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
        $response = $this->get('/dl/' . $download->uuid);
        $response->assertStatus(200);
        $response->assertHeader('Content-Length', (string) $expectedSize);
        $response->assertHeader('Accept-Ranges', 'bytes');
        $this->assertEquals($content, $response->streamedContent());

        // 2. HEAD request (IDM size pre-check)
        $headResponse = $this->call('HEAD', '/dl/' . $download->uuid);
        $headResponse->assertStatus(200);
        $headResponse->assertHeader('Content-Length', (string) $expectedSize);
        $headResponse->assertHeader('Accept-Ranges', 'bytes');

        // 3. IDM Range 0-0 probe (1-byte probe to check total size and range support)
        $probeResponse = $this->get('/dl/' . $download->uuid, [
            'Range' => 'bytes=0-0',
        ]);
        $probeResponse->assertStatus(206);
        $probeResponse->assertHeader('Content-Range', "bytes 0-0/{$expectedSize}");
        $probeResponse->assertHeader('Content-Length', '1');
        $this->assertEquals(substr($content, 0, 1), $probeResponse->streamedContent());

        // 4. Partial byte range chunk request (IDM multi-threaded download)
        $chunkResponse = $this->get('/dl/' . $download->uuid, [
            'Range' => 'bytes=0-9',
        ]);
        $chunkResponse->assertStatus(206);
        $chunkResponse->assertHeader('Content-Range', "bytes 0-9/{$expectedSize}");
        $chunkResponse->assertHeader('Content-Length', '10');
        $this->assertEquals(substr($content, 0, 10), $chunkResponse->streamedContent());
    }

    public function test_proxy_speed_service_normalizes_and_handles_proxies()
    {
        $speedService = new \App\Services\ProxySpeedService();

        $this->assertEquals('http://1.2.3.4:8080', $speedService->normalizeProxy('1.2.3.4:8080'));
        $this->assertEquals('http://1.2.3.4:8080', $speedService->normalizeProxy('http://1.2.3.4:8080'));
        $this->assertEquals('socks5://1.2.3.4:1080', $speedService->normalizeProxy('socks5://1.2.3.4:1080'));
        $this->assertNull($speedService->normalizeProxy(''));
        $this->assertNull($speedService->normalizeProxy(null));

        // When given empty proxy list, returns direct fallback
        $result = $speedService->findFastProxy('https://example.com/testfile', []);
        $this->assertNull($result['proxy']);
        $this->assertTrue($result['qualified']);
    }
}
