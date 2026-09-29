<?php

namespace App\Jobs;

use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\RequestOptions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDebridDownloadJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 hours max per job

    public int $tries = 1;

    protected DebridDownload $download;

    public function __construct(DebridDownload $download)
    {
        $this->download = $download;
    }

    public function handle(RealDebridService $rdService): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $downloadUuid = $this->download->uuid;

        // Check if download was cancelled before the job started
        if (Cache::has("cancel_download_{$downloadUuid}")) {
            Cache::forget("cancel_download_{$downloadUuid}");

            return;
        }

        $download = $this->download->fresh();

        if (! $download || $download->status === 'cancelled') {
            return;
        }

        try {
            $linkHash = $download->link_hash;

            // Step 1: Unrestrict link if not already done
            if (empty($download->debrid_link)) {
                DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update(['status' => 'unrestricting']);
                $useRemote = $download->use_remote ?? config('services.realdebrid.use_remote', true);

                $unrestrictResult = $rdService->unrestrictLink($download->original_link, null, $useRemote);

                if (! $unrestrictResult['success']) {
                    $errMsg = $unrestrictResult['message'] ?? 'Link dönüştürülemedi.';

                    DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update([
                        'status' => 'failed',
                        'error_message' => $errMsg,
                    ]);

                    return;
                }

                $data = $unrestrictResult['data'];

                DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update([
                    'debrid_id' => $data['id'] ?? null,
                    'debrid_link' => $data['download_link'],
                    'filename' => $data['filename'] ?? 'file_'.$download->uuid,
                    'filesize' => $data['filesize'] ?? 0,
                    'mime_type' => $data['mime_type'] ?? null,
                ]);

                $download = $download->fresh();
            }

            if (Cache::has("cancel_download_{$downloadUuid}")) {
                Cache::forget("cancel_download_{$downloadUuid}");

                return;
            }

            // Step 2: Download file to local storage
            DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update(['status' => 'downloading']);

            $safeFilename = sanitize_filename($download->filename ?: 'file_'.$download->uuid);
            $relativeDir = 'downloads/'.$download->uuid;
            $relativeFilePath = $relativeDir.'/'.$safeFilename;

            // Ensure storage directory exists
            Storage::disk('public')->makeDirectory($relativeDir);
            $fullStoragePath = Storage::disk('public')->path($relativeFilePath);

            $debridUrl = $download->debrid_link;

            if (empty($debridUrl)) {
                throw new Exception('Real-Debrid indirme adresi (debrid_link) boş veya geçersiz.');
            }
            $totalSize = (int) ($download->filesize ?? 0);

            // Ensure sufficient free disk space exists before starting download
            if ($totalSize > 0) {
                DebridDownload::ensureFreeDiskSpace($totalSize);
            }

            $lastUpdate = time();
            $downloadedSoFar = 0;

            $downloadSuccess = false;
            $lastException = null;
            $maxAttempts = 2;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $orderedProxies = RealDebridService::getCandidateProxiesForApi();

                foreach ($orderedProxies as $proxyIndex => $proxy) {
                    if (Cache::has("cancel_download_{$downloadUuid}")) {
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
                            RequestOptions::READ_TIMEOUT => 7200,
                            RequestOptions::CONNECT_TIMEOUT => $proxy ? 10.0 : 15.0,
                            'force_ip_resolve' => 'v4',
                        ];

                        if ($proxy) {
                            $guzzleConfig['proxy'] = $proxy;
                        }

                        $client = new GuzzleClient($guzzleConfig);

                        $response = $client->request('GET', $debridUrl, [
                            'sink' => $fullStoragePath,
                            'progress' => function ($downloadTotal, $downloadedBytes) use (
                                $download,
                                $downloadUuid,
                                $linkHash,
                                &$lastUpdate,
                                &$downloadedSoFar
                            ) {
                                $downloadedSoFar = $downloadedBytes;
                                $now = time();

                                if (Cache::has("cancel_download_{$downloadUuid}")) {
                                    throw new \RuntimeException('DOWNLOAD_CANCELLED_BY_USER');
                                }

                                if ($now - $lastUpdate >= 1 || ($downloadTotal > 0 && $downloadedBytes >= $downloadTotal)) {
                                    $lastUpdate = $now;
                                    $fresh = $download->fresh();
                                    if (! $fresh || $fresh->status === 'cancelled') {
                                        throw new \RuntimeException('DOWNLOAD_CANCELLED_BY_USER');
                                    }
                                    $updateData = ['downloaded_bytes' => $downloadedBytes];
                                    if ($downloadTotal > 0 && $download->filesize <= 0) {
                                        $updateData['filesize'] = $downloadTotal;
                                    }
                                    DebridDownload::where('link_hash', $linkHash)->where('status', 'downloading')->update($updateData);
                                }
                            },
                        ]);

                        if ($response->getStatusCode() === 200 && file_exists($fullStoragePath)) {
                            if ($proxy) {
                                Cache::put('last_working_rd_proxy', $proxy, now()->addHours(2));
                            }

                            $actualFileSize = filesize($fullStoragePath);
                            DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update([
                                'status' => 'completed',
                                'filesize' => $actualFileSize ?: $totalSize,
                                'downloaded_bytes' => $actualFileSize ?: $totalSize,
                                'storage_path' => $relativeFilePath,
                                'filename' => $safeFilename,
                            ]);
                            $downloadSuccess = true;
                            break 2;
                        }
                    } catch (\Throwable $e) {
                        if ($e->getMessage() === 'DOWNLOAD_CANCELLED_BY_USER' || str_contains($e->getMessage(), 'DOWNLOAD_CANCELLED_BY_USER')) {
                            if (file_exists($fullStoragePath)) {
                                @unlink($fullStoragePath);
                            }
                            if (Storage::disk('public')->exists($relativeDir)) {
                                Storage::disk('public')->deleteDirectory($relativeDir);
                            }
                            Cache::forget("cancel_download_{$downloadUuid}");

                            return;
                        }

                        $lastException = $e;
                        $proxyLabel = RealDebridService::getDisplayProxy($proxy);
                        Log::warning("[INDIRME_PROXY_HATASI] Proxy [{$proxyLabel}] başarısız (UUID: {$downloadUuid}): ".$e->getMessage().'. Sıradaki deneniyor...');

                        Cache::forget('last_working_rd_proxy');
                        if ($proxy) {
                            Cache::put('rd_proxy_blocked_'.md5($proxy), true, now()->addHours(1));
                        }

                        // Handle BadResponseException (including 503, 502, 504, 403, 404, etc.)
                        if ($e instanceof BadResponseException) {
                            $statusCode = $e->getResponse()?->getStatusCode();
                            if (in_array($statusCode, [401, 403, 404, 410, 416, 500, 502, 503, 504])) {
                                try {
                                    $useRemote = $download->use_remote ?? config('services.realdebrid.use_remote', true);
                                    $refreshResult = $rdService->unrestrictLink($download->original_link, null, $useRemote);
                                    if (! $refreshResult['success']) {
                                        $refreshResult = $rdService->unrestrictLink($download->original_link, null, false);
                                    }
                                    if ($refreshResult['success'] && ! empty($refreshResult['data']['download_link'])) {
                                        $debridUrl = $refreshResult['data']['download_link'];
                                        DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update(['debrid_link' => $debridUrl]);
                                    }
                                } catch (\Throwable $re) {
                                    Log::debug('Failed to re-unrestrict on link error: '.$re->getMessage());
                                }
                            }
                        }

                        if (file_exists($fullStoragePath)) {
                            @unlink($fullStoragePath);
                        }
                    }
                }

                // If attempt 1 failed, re-unrestrict link once with fresh candidates before attempt 2
                if (! $downloadSuccess && $attempt < $maxAttempts) {
                    try {
                        $useRemote = $download->use_remote ?? config('services.realdebrid.use_remote', true);
                        $refreshResult = $rdService->unrestrictLink($download->original_link, null, $useRemote);
                        if (! $refreshResult['success']) {
                            $refreshResult = $rdService->unrestrictLink($download->original_link, null, false);
                        }
                        if ($refreshResult['success'] && ! empty($refreshResult['data']['download_link'])) {
                            $debridUrl = $refreshResult['data']['download_link'];
                            DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update(['debrid_link' => $debridUrl]);
                        }
                    } catch (\Throwable $re) {
                        Log::debug('Failed to re-unrestrict on retry attempt: '.$re->getMessage());
                    }
                }
            }

            if (! $downloadSuccess) {
                throw $lastException ?: new Exception('İndirme tüm proxy kanallarında ve doğrudan bağlantıda başarısız oldu.');
            }

        } catch (\Throwable $e) {
            if ($e->getMessage() === 'DOWNLOAD_CANCELLED_BY_USER' || str_contains($e->getMessage(), 'DOWNLOAD_CANCELLED_BY_USER')) {
                Log::info("ProcessDebridDownloadJob: Cancelled and halted cleanly (UUID: {$downloadUuid})");
                if (isset($fullStoragePath) && file_exists($fullStoragePath)) {
                    @unlink($fullStoragePath);
                }
                Cache::forget("cancel_download_{$downloadUuid}");

                return;
            }

            $rawError = $e->getMessage();
            if (str_contains($rawError, '503 Service Unavailable') || str_contains($rawError, '503')) {
                $userFriendlyError = 'Real-Debrid CDN sunucusu geçici olarak yanıt vermiyor (503 Service Unavailable). Lütfen birkaç dakika sonra tekrar deneyin.';
            } else {
                $userFriendlyError = 'İndirme hatası: '.trim(preg_replace('/<!DOCTYPE.*$/is', '', $rawError));
            }

            Log::error("ProcessDebridDownloadJob Error (UUID: {$downloadUuid}): ".$rawError);
            $fresh = $download->fresh();
            if ($fresh && $fresh->status !== 'cancelled') {
                DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update([
                    'status' => 'failed',
                    'error_message' => $userFriendlyError,
                ]);
            }
        }
    }
}

/**
 * Helper function to sanitize filenames
 */
if (! function_exists('sanitize_filename')) {
    function sanitize_filename(string $filename): string
    {
        $filename = preg_replace('/[^\w\-\.\ \(\)\[\]]/u', '_', $filename);

        return trim($filename, '. ') ?: 'file_'.uniqid();
    }
}
