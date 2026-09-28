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
            'use_remote' => ($request->has('remote') || $request->has('use_remote'))
                ? ($request->boolean('remote') || $request->boolean('use_remote'))
                : config('services.realdebrid.use_remote', true),
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
     * Delete cached download record and files / Cancel ongoing download
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

        // 1. Signal cancellation to any active background download jobs
        \Illuminate\Support\Facades\Cache::put("cancel_download_{$uuid}", true, now()->addMinutes(10));

        // 2. Mark as cancelled before deletion so active loop notices
        $download->update(['status' => 'cancelled']);

        // 3. Delete physical storage file and folder
        if (!empty($download->storage_path)) {
            $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($download->storage_path);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            $dir = dirname($download->storage_path);
            if ($dir && $dir !== '.' && \Illuminate\Support\Facades\Storage::disk('public')->exists($dir)) {
                \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory($dir);
            }
        }

        $download->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Download cancelled and cached file removed successfully.',
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
        $rawInput = $link ?: $request->query('link') ?: $request->query('url') ?: $request->query('b64');

        if (empty($rawInput)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lütfen geçerli bir indirme linki veya ID girin. Örnek: /api/indir/15 veya /api/indir/file/ID/KEY',
            ], 400);
        }

        $rawInput = trim($rawInput);
        $originalLink = null;

        // 1. Short ID or UUID lookup (e.g. /api/indir/15 or /api/indir/uuid)
        if (is_numeric($rawInput) || Str::isUuid($rawInput)) {
            $record = DebridDownload::where('id', $rawInput)->orWhere('uuid', $rawInput)->first();
            if ($record) {
                $originalLink = $record->original_link;
            }
        }

        // 2. Mega Slash Auto-Conversion (Replaces / with # for Mega links to bypass IDM # stripping)
        if (empty($originalLink)) {
            // New Mega file format: file/ID/KEY or mega.nz/file/ID/KEY
            if (preg_match('#(?:mega\.nz/)?file/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)#i', $rawInput, $matches)) {
                $originalLink = "https://mega.nz/file/{$matches[1]}#{$matches[2]}";
            }
            // Mega folder format: folder/ID/KEY
            elseif (preg_match('#(?:mega\.nz/)?folder/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)#i', $rawInput, $matches)) {
                $originalLink = "https://mega.nz/folder/{$matches[1]}#{$matches[2]}";
            }
            // Old Mega format: !ID!KEY or mega.co.nz/!ID!KEY
            elseif (preg_match('#(?:mega\.co\.nz/)?!?([a-zA-Z0-9_-]+)!([a-zA-Z0-9_-]+)#i', $rawInput, $matches)) {
                $originalLink = "https://mega.co.nz/#!{$matches[1]}!{$matches[2]}";
            }
        }

        // 3. Base64 decode check
        if (empty($originalLink)) {
            $decoded = @base64_decode($rawInput, true);
            if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_URL)) {
                $originalLink = $decoded;
            }
        }

        // 4. Raw URL / URL Decode / Standard URL Repair (Rapidgator, Turbobit, 1fichier etc.)
        if (empty($originalLink)) {
            $urlDecoded = rawurldecode($rawInput);
            if (filter_var($urlDecoded, FILTER_VALIDATE_URL)) {
                $originalLink = $urlDecoded;
            } else {
                $originalLink = preg_replace('#^(https?):/+#i', '$1://', $rawInput);
            }
        }

        if (empty($originalLink) || !filter_var($originalLink, FILTER_VALIDATE_URL)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Geçersiz indirme adresi veya ID.',
            ], 422);
        }

        $linkHash = md5($originalLink);
        $existing = DebridDownload::where('link_hash', $linkHash)->first();

        // Case 1: Already cached on local server -> Serve/Redirect to local file
        if ($existing && $existing->status === 'completed' && !empty($existing->storage_path)) {
            $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($existing->storage_path);
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
