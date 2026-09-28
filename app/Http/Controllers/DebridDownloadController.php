<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'use_remote' => $request->boolean('use_remote', true),
        ]);

        // Dispatch job to queue or sync depending on config
        ProcessDebridDownloadJob::dispatch($download);

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
     * Direct local download to user (Proxy file serving)
     */
    public function downloadFile(string $uuid): BinaryFileResponse
    {
        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();

        if ($download->status !== 'completed' || empty($download->storage_path)) {
            abort(404, 'Dosya henüz hazır değil veya sunucuda bulunamadı.');
        }

        $fullPath = storage_path('app/public/' . $download->storage_path);

        if (!file_exists($fullPath)) {
            abort(404, 'Fiziksel dosya disk üzerinde bulunamadı.');
        }

        // Increment download count tracker
        $download->increment('download_count');

        return response()->download($fullPath, $download->filename, [
            'Content-Type' => $download->mime_type ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Delete cached download file
     */
    public function destroy(string $uuid)
    {
        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();

        if (!empty($download->storage_path)) {
            Storage::disk('public')->delete($download->storage_path);
            $dir = dirname($download->storage_path);
            if ($dir && $dir !== '.' && Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->deleteDirectory($dir);
            }
        }

        $download->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Dosya önbelleği ve kaydı başarıyla silindi.',
            ]);
        }

        return redirect()->route('dashboard')->with('success', 'Dosya ve önbellek silindi.');
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
