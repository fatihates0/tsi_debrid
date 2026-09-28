<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Services\RealDebridService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DebridApiController extends Controller
{
    protected RealDebridService $rdService;

    public function __construct(RealDebridService $rdService)
    {
        $this->rdService = $rdService;
    }

    /**
     * List all proxy downloads
     */
    public function index(Request $request): JsonResponse
    {
        $query = DebridDownload::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $downloads = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $downloads,
        ]);
    }

    /**
     * Create/Request a Debrid Proxy Download
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'link' => 'required|url',
        ]);

        $link = trim($request->input('link'));
        $linkHash = md5($link);

        // Check cache to protect Real-Debrid account
        $existing = DebridDownload::where('link_hash', $linkHash)->first();

        if ($existing) {
            if ($existing->status === 'completed' && $existing->is_cached) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Link already cached on server. Returning proxy download URL.',
                    'cached' => true,
                    'data' => $existing,
                ], 200);
            }

            if (in_array($existing->status, ['pending', 'unrestricting', 'downloading'])) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Download already in progress on server.',
                    'cached' => false,
                    'data' => $existing,
                ], 202);
            }
        }

        $download = DebridDownload::create([
            'uuid' => (string) Str::uuid(),
            'original_link' => $link,
            'link_hash' => $linkHash,
            'status' => 'pending',
            'user_ip' => $request->ip(),
            'use_remote' => $request->boolean('remote', $request->boolean('use_remote', true)),
        ]);

        ProcessDebridDownloadJob::dispatch($download);

        return response()->json([
            'status' => 'success',
            'message' => 'Download queued. Real-Debrid will be queried once by the server.',
            'cached' => false,
            'data' => $download,
        ], 201);
    }

    /**
     * Get details and live progress of a download
     */
    public function show(string $uuid): JsonResponse
    {
        $download = DebridDownload::where('uuid', $uuid)->first();

        if (!$download) {
            return response()->json([
                'status' => 'error',
                'message' => 'Download record not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $download,
        ]);
    }

    /**
     * Get Real-Debrid Account API status
     */
    public function accountStatus(): JsonResponse
    {
        $info = $this->rdService->getUserInfo();
        return response()->json($info);
    }

    /**
     * Delete cached download record and files
     */
    public function destroy(string $uuid): JsonResponse
    {
        $download = DebridDownload::where('uuid', $uuid)->first();

        if (!$download) {
            return response()->json([
                'status' => 'error',
                'message' => 'Download record not found.',
            ], 404);
        }

        if (!empty($download->storage_path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($download->storage_path);
        }

        $download->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Download and cached file removed successfully.',
        ]);
    }

    /**
     * IDM / Direct Stream Download API
     * Supports:
     * - GET /api/indir/https://mega.nz/file/xyz#123
     * - GET /api/indir?link=https://mega.nz/file/xyz#123
     */
    public function directDownload(Request $request, ?string $link = null)
    {
        $originalLink = $link ?: $request->query('link') ?: $request->query('url');

        if (empty($originalLink)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lütfen geçerli bir indirme linki girin. Örnek: /api/indir/https://mega.nz/file/... veya ?link=https://mega.nz/...',
            ], 400);
        }

        // Repair potential double slash stripping by web servers/proxies (e.g., http:/mega.nz -> http://mega.nz)
        $originalLink = preg_replace('#^(https?):/+#i', '$1://', trim($originalLink));

        if (!filter_var($originalLink, FILTER_VALIDATE_URL)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Geçersiz URL formatı.',
            ], 422);
        }

        $linkHash = md5($originalLink);
        $existing = DebridDownload::where('link_hash', $linkHash)->first();

        // Case 1: Already cached on local server -> Serve/Redirect to local file
        if ($existing && $existing->status === 'completed' && !empty($existing->storage_path)) {
            $fullPath = storage_path('app/public/' . $existing->storage_path);
            if (file_exists($fullPath)) {
                $existing->increment('download_count');
                return redirect()->to(route('downloads.file', ['uuid' => $existing->uuid]));
            }
        }

        // Case 2: New link -> Create record and unrestrict synchronously
        if (!$existing) {
            $existing = DebridDownload::create([
                'uuid' => (string) Str::uuid(),
                'original_link' => $originalLink,
                'link_hash' => $linkHash,
                'status' => 'pending',
                'user_ip' => $request->ip(),
                'use_remote' => true,
            ]);
        }

        if (empty($existing->debrid_link)) {
            $unrestrictResult = $this->rdService->unrestrictLink($originalLink, null, true);

            if (!$unrestrictResult['success']) {
                $existing->update([
                    'status' => 'failed',
                    'error_message' => $unrestrictResult['message'] ?? 'Real-Debrid link dönüştürülemedi.',
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Real-Debrid Hatası: ' . ($unrestrictResult['message'] ?? 'Dönüştürülemedi'),
                ], 400);
            }

            $data = $unrestrictResult['data'];
            $existing->update([
                'debrid_id' => $data['id'] ?? null,
                'debrid_link' => $data['download_link'],
                'filename' => $data['filename'] ?? 'file_' . $existing->uuid,
                'filesize' => $data['filesize'] ?? 0,
                'mime_type' => $data['mime_type'] ?? null,
            ]);

            // Dispatch background caching job
            ProcessDebridDownloadJob::dispatch($existing);
        }

        // Redirect IDM / Browser to Real-Debrid unrestricted download URL
        if (!empty($existing->debrid_link)) {
            return redirect()->away($existing->debrid_link);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'İndirme adresi üretilemedi.',
        ], 500);
    }
}
