<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DebridDownloadController extends Controller
{
    protected RealDebridService $rdService;

    public function __construct(RealDebridService $rdService)
    {
        $this->rdService = $rdService;
    }

    /**
     * Dashboard View
     */
    public function index()
    {
        $downloads = DebridDownload::orderBy('created_at', 'desc')->paginate(15);

        $stats = [
            'total_downloads' => DebridDownload::count(),
            'completed_downloads' => DebridDownload::where('status', 'completed')->count(),
            'total_bytes_cached' => DebridDownload::where('status', 'completed')->sum('filesize'),
            'total_saved_rd_requests' => DebridDownload::where('status', 'completed')->sum('download_count'),
        ];

        return view('dashboard', compact('downloads', 'stats'));
    }

    /**
     * Store / Submit Link for Proxy Caching
     */
    public function store(Request $request)
    {
        $request->validate([
            'link' => 'required|url',
        ], [
            'link.required' => 'Lütfen geçerli bir indirme bağlantısı girin.',
            'link.url' => 'Geçerli bir URL formatı olmalıdır.',
        ]);

        $originalLink = trim($request->input('link'));
        $linkHash = md5($originalLink);

        // CHECK PROXY CACHE (ACCOUNT BAN PREVENTION KEY LOGIC)
        $existing = DebridDownload::where('link_hash', $linkHash)->first();

        if ($existing) {
            // Case 1: Already completed and file exists on server
            if ($existing->status === 'completed' && $existing->is_cached) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Bu dosya daha önce Real-Debrid ile indirilmiş! Doğrudan sunucu önbelleğinden sunuluyor.',
                        'cached' => true,
                        'data' => $existing,
                    ]);
                }

                return redirect()->route('dashboard')->with('success', '⚡ Dosya önbellekte hazır! Real-Debrid yeniden çağrılmadı.');
            }

            // Case 2: Currently active download in progress
            if (in_array($existing->status, ['pending', 'unrestricting', 'downloading'])) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Bu dosya şu an sunucuda indiriliyor...',
                        'cached' => false,
                        'data' => $existing,
                    ]);
                }

                return redirect()->route('dashboard')->with('info', '⌛ Dosya şu an sunucumuza indiriliyor, canlı durum tablosundan takip edebilirsiniz.');
            }
        }

        // Case 3: New link submission -> Create download record & dispatch Job
        $download = DebridDownload::create([
            'uuid' => (string) Str::uuid(),
            'original_link' => $originalLink,
            'link_hash' => $linkHash,
            'status' => 'pending',
            'user_ip' => $request->ip(),
            'use_remote' => $request->has('use_remote') ? $request->boolean('use_remote') : config('services.realdebrid.use_remote', true),
        ]);

        // Dispatch job to queue (or dispatchAfterResponse if sync to prevent 504 Gateway Timeout)
        $queueDriver = config('queue.default');
        Log::info("[INDIRME_KAYDI_OLUSTU] Yeni indirme talebi eklendi (UUID: {$download->uuid}) | Queue Driver: {$queueDriver} | Link: {$originalLink}");

        if ($queueDriver === 'sync') {
            ProcessDebridDownloadJob::dispatchAfterResponse($download);
        } else {
            ProcessDebridDownloadJob::dispatch($download);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'İndirme talebi alındı. Real-Debrid üzerinden sunucuya aktarılıyor.',
                'cached' => false,
                'data' => $download,
            ], 201);
        }

        return redirect()->route('dashboard')->with('success', '🚀 İndirme başlatıldı! Real-Debrid hesabınız riske atılmadan 1 defa indirilecek.');
    }

    /**
     * Poll download status / details
     */
    public function show(string $uuid)
    {
        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $download,
        ]);
    }

    /**
     * List all downloads for AJAX polling
     */
    public function listAjax()
    {
        $downloads = DebridDownload::orderBy('created_at', 'desc')->limit(30)->get();

        return response()->json([
            'success' => true,
            'data' => $downloads,
        ]);
    }

    /**
     * Direct local download to user (Proxy file serving with Range & HEAD support for IDM)
     */
    public function downloadFile(Request $request, string $uuid): Response
    {
        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();

        if ($download->status !== 'completed' || empty($download->storage_path)) {
            abort(404, 'Dosya henüz hazır değil veya sunucuda bulunamadı.');
        }

        $fullPath = Storage::disk('public')->path($download->storage_path);

        if (! file_exists($fullPath)) {
            abort(404, 'Fiziksel dosya disk üzerinde bulunamadı.');
        }

        // Increment download count tracker on initial download (not on range chunks or HEAD)
        $rangeHeader = $request->header('Range');
        if (! $request->isMethod('HEAD') && (! $rangeHeader || str_starts_with($rangeHeader, 'bytes=0-'))) {
            $download->increment('download_count');
        }

        $fileSize = filesize($fullPath);
        $filename = $download->filename ?: basename($fullPath);
        $mimeType = $download->mime_type ?: 'application/octet-stream';

        $start = 0;
        $end = $fileSize > 0 ? $fileSize - 1 : 0;
        $isRange = false;

        if ($rangeHeader && preg_match('/bytes=(\d+)-(\d*)?/i', $rangeHeader, $matches)) {
            $isRange = true;
            $start = (int) $matches[1];
            if (isset($matches[2]) && $matches[2] !== '') {
                $end = min((int) $matches[2], $fileSize > 0 ? $fileSize - 1 : (int) $matches[2]);
            }
        }

        $length = $fileSize > 0 ? max(0, ($end - $start) + 1) : 0;

        $encodedFilename = rawurlencode($filename);
        $contentDisposition = 'attachment; filename="'.addslashes($filename).'"; filename*=UTF-8\'\''.$encodedFilename;

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $contentDisposition,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, private',
        ];

        // 1. HEAD request - used by IDM and download managers for pre-fetch size inspection
        if ($request->isMethod('HEAD')) {
            if ($fileSize > 0) {
                $headers['Content-Length'] = (string) $fileSize;
            }

            return response('', 200, $headers);
        }

        // 2. Partial Range request (IDM 0-0 probe, multi-threaded range chunks, resumed downloads)
        if ($isRange) {
            $headers['Content-Length'] = (string) $length;
            if ($fileSize > 0) {
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
            }
            $statusCode = 206;
        } else {
            // 3. Normal full GET request
            $headers['Content-Length'] = (string) $fileSize;
            $statusCode = 200;
        }

        return new StreamedResponse(function () use ($fullPath, $start, $length) {
            if (! app()->environment('testing')) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
            }

            $stream = fopen($fullPath, 'rb');
            if ($stream) {
                fseek($stream, $start);
                $remaining = $length;
                $bufferSize = 1048576; // 1 MB chunk buffer

                while (! feof($stream) && $remaining > 0) {
                    $readSize = min($bufferSize, $remaining);
                    $data = fread($stream, $readSize);
                    if ($data === false) {
                        break;
                    }
                    echo $data;
                    flush();
                    $remaining -= strlen($data);
                }
                fclose($stream);
            }
        }, $statusCode, $headers);
    }

    /**
     * Delete cached download file / Cancel ongoing download
     */
    public function destroy(string $uuid)
    {
        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();

        // 1. Signal cancellation to any active background download jobs
        Cache::put("cancel_download_{$uuid}", true, now()->addMinutes(10));

        // 2. Mark as cancelled before deletion so active loop notices
        $download->update(['status' => 'cancelled']);

        // 3. Delete physical storage file and folder
        if (! empty($download->storage_path)) {
            $fullPath = Storage::disk('public')->path($download->storage_path);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            $dir = dirname($download->storage_path);
            if ($dir && $dir !== '.' && Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->deleteDirectory($dir);
            }
        }

        $download->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'İndirme iptal edildi ve dosya kaydı silindi.',
            ]);
        }

        return redirect()->route('dashboard')->with('success', 'İndirme iptal edildi ve dosya silindi.');
    }

    /**
     * Check Real-Debrid API status
     */
    public function rdStatus()
    {
        $info = $this->rdService->getUserInfo();

        return response()->json($info);
    }
}
