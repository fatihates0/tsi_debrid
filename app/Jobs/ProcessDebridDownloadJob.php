<?php

namespace App\Jobs;

use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
        $downloadUuid = $this->download->uuid;

        // Check if download was cancelled before the job started
        if (Cache::has("cancel_download_{$downloadUuid}")) {
            Log::info("ProcessDebridDownloadJob: Cancelled before starting (UUID: {$downloadUuid})");
            Cache::forget("cancel_download_{$downloadUuid}");
            return;
        }

        $download = $this->download->fresh();

        if (!$download || $download->status === 'cancelled') {
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

            if (Cache::has("cancel_download_{$downloadUuid}")) {
                Log::info("ProcessDebridDownloadJob: Cancelled after unrestricting (UUID: {$downloadUuid})");
                Cache::forget("cancel_download_{$downloadUuid}");
                return;
            }

            // Step 2: Download file to local storage
            $download->update(['status' => 'downloading']);

            $safeFilename = sanitize_filename($download->filename ?: 'file_' . $download->uuid);
            $relativeDir = 'downloads/' . $download->uuid;
            $relativeFilePath = $relativeDir . '/' . $safeFilename;

            // Ensure storage directory exists
            Storage::disk('public')->makeDirectory($relativeDir);

            $fullStoragePath = Storage::disk('public')->path($relativeFilePath);

            $debridUrl = $download->debrid_link;
            $totalSize = $download->filesize;

            $lastUpdate = time();
            $downloadedSoFar = 0;

            $proxies = RealDebridService::getProxyList();
            $speedService = app(\App\Services\ProxySpeedService::class);
            $minSpeedMbps = (float) config('services.realdebrid.min_proxy_speed_mbps', 50.0);

            // Detect and benchmark proxies: find one capable of >= 50 Mbps speed
            $fastProxyResult = $speedService->findFastProxy($debridUrl, $proxies, $minSpeedMbps);
            $chosenProxy = $fastProxyResult['proxy'];

            // Build prioritized proxy list: verified fast proxy first, then remaining candidates, then direct
            $orderedProxies = [];
            if ($chosenProxy !== null) {
                $orderedProxies[] = $chosenProxy;
            }
            foreach ($proxies as $p) {
                if ($p !== $chosenProxy) {
                    $orderedProxies[] = $p;
                }
            }
            $orderedProxies[] = null; // direct connection fallback

            $downloadSuccess = false;
            $lastException = null;

            foreach ($orderedProxies as $proxy) {
                if (Cache::has("cancel_download_{$downloadUuid}")) {
                    Log::info("ProcessDebridDownloadJob: Cancelled before starting proxy transfer (UUID: {$downloadUuid})");
                    if (file_exists($fullStoragePath)) {
                        @unlink($fullStoragePath);
                    }
                    if (Storage::disk('public')->exists($relativeDir)) {
                        Storage::disk('public')->deleteDirectory($relativeDir);
                    }
                    Cache::forget("cancel_download_{$downloadUuid}");
                    return;
                }

                try {
                    $guzzleConfig = [
                        'verify' => false,
                        RequestOptions::TIMEOUT => 7200,
                        RequestOptions::CONNECT_TIMEOUT => 30,
                        'force_ip_resolve' => 'v4',
                    ];

                    if ($proxy) {
                        $guzzleConfig['proxy'] = $proxy;
                    }

                    $client = new GuzzleClient($guzzleConfig);

                    $response = $client->request('GET', $debridUrl, [
                        'sink' => $fullStoragePath,
                        'progress' => function ($downloadTotal, $downloadedBytes) use ($download, $downloadUuid, &$lastUpdate, &$downloadedSoFar) {
                            $downloadedSoFar = $downloadedBytes;
                            $now = time();

                            // Instant abort check during download
                            if (Cache::has("cancel_download_{$downloadUuid}")) {
                                throw new \RuntimeException('DOWNLOAD_CANCELLED_BY_USER');
                            }

                            // Throttle DB updates to once per second to avoid DB locks
                            if ($now - $lastUpdate >= 1 || ($downloadTotal > 0 && $downloadedBytes >= $downloadTotal)) {
                                $lastUpdate = $now;
                                $fresh = $download->fresh();
                                if (!$fresh || $fresh->status === 'cancelled') {
                                    throw new \RuntimeException('DOWNLOAD_CANCELLED_BY_USER');
                                }
                                $updateData = ['downloaded_bytes' => $downloadedBytes];
                                if ($downloadTotal > 0 && $download->filesize <= 0) {
                                    $updateData['filesize'] = $downloadTotal;
                                }
                                $fresh->update($updateData);
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
                        $downloadSuccess = true;
                        break;
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() === 'DOWNLOAD_CANCELLED_BY_USER' || str_contains($e->getMessage(), 'DOWNLOAD_CANCELLED_BY_USER')) {
                        Log::info("ProcessDebridDownloadJob: User requested cancellation, instantly killing socket and cleaning files (UUID: {$downloadUuid})");
                        if (file_exists($fullStoragePath)) {
                            @unlink($fullStoragePath);
                        }
                        if (Storage::disk('public')->exists($relativeDir)) {
                            Storage::disk('public')->deleteDirectory($relativeDir);
                        }
                        Cache::forget("cancel_download_{$downloadUuid}");
                        return; // Stop job immediately without retrying proxies!
                    }

                    $lastException = $e;
                    Log::warning("ProcessDebridDownloadJob proxy " . ($proxy ?: 'Direct') . " download failed: " . $e->getMessage() . ". Retrying with next proxy...");
                    if (file_exists($fullStoragePath)) {
                        @unlink($fullStoragePath);
                    }
                }
            }

            if (!$downloadSuccess) {
                throw $lastException ?: new Exception('İndirme tüm proxy kanallarında başarısız oldu.');
            }

        } catch (Exception $e) {
            if ($e->getMessage() === 'DOWNLOAD_CANCELLED_BY_USER' || str_contains($e->getMessage(), 'DOWNLOAD_CANCELLED_BY_USER')) {
                Log::info("ProcessDebridDownloadJob: Cancelled and halted cleanly (UUID: {$downloadUuid})");
                if (isset($fullStoragePath) && file_exists($fullStoragePath)) {
                    @unlink($fullStoragePath);
                }
                Cache::forget("cancel_download_{$downloadUuid}");
                return;
            }

            Log::error("ProcessDebridDownloadJob Error (UUID: {$downloadUuid}): " . $e->getMessage());
            $fresh = $download->fresh();
            if ($fresh && $fresh->status !== 'cancelled') {
                $fresh->update([
                    'status' => 'failed',
                    'error_message' => 'İndirme hatası: ' . $e->getMessage(),
                ]);
            }
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
