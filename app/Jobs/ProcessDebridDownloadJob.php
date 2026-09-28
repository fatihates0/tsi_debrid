<?php

namespace App\Jobs;

use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;

class ProcessDebridDownloadJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 7200; // 2 hours max per job
    public int $tries = 1;

    protected DebridDownload $download;

    public function __construct(DebridDownload $download)
    {
        $this->download = $download;
    }

    public function handle(RealDebridService $rdService): void
    {
        $download = $this->download->fresh();

        if (!$download) {
            return;
        }

        try {
            // Step 1: Unrestrict link if not already done
            if (empty($download->debrid_link)) {
                $download->update(['status' => 'unrestricting']);
                $useRemote = $download->use_remote ?? config('services.realdebrid.use_remote', true);
                $unrestrictResult = $rdService->unrestrictLink($download->original_link, null, $useRemote);

                if (!$unrestrictResult['success']) {
                    $download->update([
                        'status' => 'failed',
                        'error_message' => $unrestrictResult['message'] ?? 'Link dönüştürülemedi.',
                    ]);
                    return;
                }

                $data = $unrestrictResult['data'];
                $download->update([
                    'debrid_id' => $data['id'] ?? null,
                    'debrid_link' => $data['download_link'],
                    'filename' => $data['filename'] ?? 'file_' . $download->uuid,
                    'filesize' => $data['filesize'] ?? 0,
                    'mime_type' => $data['mime_type'] ?? null,
                ]);
            }

            // Step 2: Download file to local storage
            $download->update(['status' => 'downloading']);

            $safeFilename = sanitize_filename($download->filename ?: 'file_' . $download->uuid);
            $relativeDir = 'downloads/' . $download->uuid;
            $relativeFilePath = $relativeDir . '/' . $safeFilename;

            // Ensure storage directory exists
            Storage::disk('public')->makeDirectory($relativeDir);

            $fullStoragePath = storage_path('app/public/' . $relativeFilePath);

            $debridUrl = $download->debrid_link;
            $totalSize = $download->filesize;

            $lastUpdate = time();
            $downloadedSoFar = 0;

            // Stream download using Guzzle sink with progress callback
            $client = new GuzzleClient([
                'verify' => false,
                RequestOptions::TIMEOUT => 7200,
                RequestOptions::CONNECT_TIMEOUT => 30,
            ]);

            $response = $client->request('GET', $debridUrl, [
                'sink' => $fullStoragePath,
                'progress' => function ($downloadTotal, $downloadedBytes) use ($download, &$lastUpdate, &$downloadedSoFar) {
                    $downloadedSoFar = $downloadedBytes;
                    $now = time();
                    // Throttle DB updates to once per second to avoid DB locks
                    if ($now - $lastUpdate >= 1 || ($downloadTotal > 0 && $downloadedBytes >= $downloadTotal)) {
                        $lastUpdate = $now;
                        $updateData = ['downloaded_bytes' => $downloadedBytes];
                        if ($downloadTotal > 0 && $download->filesize <= 0) {
                            $updateData['filesize'] = $downloadTotal;
                        }
                        $download->update($updateData);
                    }
                },
            ]);

            if ($response->getStatusCode() === 200 && file_exists($fullStoragePath)) {
                $actualFileSize = filesize($fullStoragePath);
                $download->update([
                    'status' => 'completed',
                    'filesize' => $actualFileSize ?: $totalSize,
                    'downloaded_bytes' => $actualFileSize ?: $totalSize,
                    'storage_path' => $relativeFilePath,
                    'filename' => $safeFilename,
                ]);
            } else {
                throw new Exception('Sunucu indirme isteğine ' . $response->getStatusCode() . ' yanıtı verdi.');
            }

        } catch (Exception $e) {
            Log::error("ProcessDebridDownloadJob Error (UUID: {$download->uuid}): " . $e->getMessage());
            $download->update([
                'status' => 'failed',
                'error_message' => 'İndirme hatası: ' . $e->getMessage(),
            ]);
        }
    }
}

/**
 * Helper function to sanitize filenames
 */
if (!function_exists('sanitize_filename')) {
    function sanitize_filename(string $filename): string
    {
        $filename = preg_replace('/[^\w\-\.\ \(\)\[\]]/u', '_', $filename);
        return trim($filename, '. ') ?: 'file_' . uniqid();
    }
}
