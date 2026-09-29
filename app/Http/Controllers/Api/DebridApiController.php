<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Services\RealDebridService;
use App\Services\XenForoAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DebridApiController extends Controller
{
    protected RealDebridService $rdService;

    public function __construct(RealDebridService $rdService)
    {
        $this->rdService = $rdService;
    }

    /**
     * List user downloads
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $query = DebridDownload::query();

        if ($userId) {
            $query->where('user_id', $userId);
        }

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
     * Create/Request a Debrid Download
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        if ($user) {
            /** @var XenForoAuthService $authService */
            $authService = app(XenForoAuthService::class);
            if (! $authService->checkUserGroupPermission($user)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'status' => 'error',
                    'message' => 'Üyelik grubunuz bu işlemi gerçekleştirmek için yetkilendirilmemiştir. Oturumunuz kapatıldı.',
                ], 403);
            }
        }

        $request->validate([
            'link' => 'required|url',
        ]);

        $userId = $user?->id;
        $link = trim($request->input('link'));
        $linkHash = md5($link);

        // 1. Check if the user ALREADY has this link in their list
        $userExisting = DebridDownload::when($userId, function ($q) use ($userId) {
            return $q->where('user_id', $userId);
        })->where('link_hash', $linkHash)->first();

        if ($userExisting) {
            if ($userExisting->status === 'completed' && $userExisting->is_cached) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Link already in your downloads list and cached on server.',
                    'cached' => true,
                    'data' => $userExisting,
                ], 200);
            }

            if (in_array($userExisting->status, ['pending', 'unrestricting', 'downloading'])) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Download already in progress on server.',
                    'cached' => false,
                    'data' => $userExisting,
                ], 202);
            }
        }

        // 2. Check if ANY active or completed download exists in DB (from another user)
        $activeGlobal = DebridDownload::where('link_hash', $linkHash)
            ->whereIn('status', ['completed', 'downloading', 'unrestricting', 'pending'])
            ->orderBy('id', 'desc')
            ->first();

        if ($activeGlobal) {
            $download = DebridDownload::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'original_link' => $link,
                'link_hash' => $linkHash,
                'debrid_id' => $activeGlobal->debrid_id,
                'debrid_link' => $activeGlobal->debrid_link,
                'filename' => $activeGlobal->filename,
                'filesize' => $activeGlobal->filesize,
                'downloaded_bytes' => $activeGlobal->downloaded_bytes,
                'status' => $activeGlobal->status,
                'mime_type' => $activeGlobal->mime_type,
                'storage_path' => $activeGlobal->storage_path,
                'user_ip' => $request->ip(),
                'use_remote' => $activeGlobal->use_remote,
            ]);

            if ($activeGlobal->status === 'completed' && $activeGlobal->is_cached) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Link previously completed by another user. Added to your list and cached.',
                    'cached' => true,
                    'data' => $download,
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Link currently downloading on server. Added to your list.',
                'cached' => false,
                'data' => $download,
            ], 202);
        }

        // 3. Brand new link -> create record & dispatch job
        $download = DebridDownload::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'original_link' => $link,
            'link_hash' => $linkHash,
            'status' => 'pending',
            'user_ip' => $request->ip(),
            'use_remote' => ($request->has('remote') || $request->has('use_remote'))
                ? ($request->boolean('remote') || $request->boolean('use_remote'))
                : config('services.realdebrid.use_remote', true),
        ]);

        if (config('queue.default') === 'sync') {
            ProcessDebridDownloadJob::dispatchAfterResponse($download);
        } else {
            ProcessDebridDownloadJob::dispatch($download);
        }

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
        $userId = auth()->id();
        $query = DebridDownload::where('uuid', $uuid);
        if ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });
        }
        $download = $query->first();

        if (! $download) {
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
        $userId = auth()->id();
        $query = DebridDownload::where('uuid', $uuid);
        if ($userId) {
            $query->where('user_id', $userId);
        }
        $download = $query->first();

        if (! $download) {
            return response()->json([
                'status' => 'error',
                'message' => 'Download record not found.',
            ], 404);
        }

        $storagePath = $download->storage_path;
        $linkHash = $download->link_hash;
        $downloadUuid = $download->uuid;

        // 1. Mark as cancelled before deletion so active loop notices
        $download->update(['status' => 'cancelled']);
        $download->delete();

        // 2. Only delete physical file and cancel job if NO OTHER active user record uses this link_hash
        $otherActiveCount = DebridDownload::where('link_hash', $linkHash)
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($otherActiveCount === 0) {
            // Signal cancellation to any active background download jobs
            Cache::put("cancel_download_{$downloadUuid}", true, now()->addMinutes(10));

            if (empty($storagePath)) {
                $storagePath = DebridDownload::where('link_hash', $linkHash)
                    ->whereNotNull('storage_path')
                    ->value('storage_path');
            }

            // Delete physical storage file and folder
            if (! empty($storagePath)) {
                $fullPath = Storage::disk('public')->path($storagePath);
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $dir = dirname($storagePath);
                if ($dir && $dir !== '.' && Storage::disk('public')->exists($dir)) {
                    Storage::disk('public')->deleteDirectory($dir);
                }
            }

            // Clean up any remaining cancelled records for this link_hash
            DebridDownload::where('link_hash', $linkHash)->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Download cancelled and record removed successfully.',
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

        if (empty($originalLink) || ! filter_var($originalLink, FILTER_VALIDATE_URL)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Geçersiz indirme adresi veya ID.',
            ], 422);
        }

        $userId = $request->user()?->id ?? auth()->id();
        $linkHash = md5($originalLink);

        // Check if current user already has record
        $userExisting = DebridDownload::when($userId, function ($q) use ($userId) {
            return $q->where('user_id', $userId);
        })->where('link_hash', $linkHash)->first();

        // Check if ANY active/completed global download exists
        $activeGlobal = DebridDownload::where('link_hash', $linkHash)
            ->whereIn('status', ['completed', 'downloading', 'unrestricting', 'pending'])
            ->orderBy('id', 'desc')
            ->first();

        if ($activeGlobal && ! $userExisting && $userId) {
            $existing = DebridDownload::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'original_link' => $originalLink,
                'link_hash' => $linkHash,
                'debrid_id' => $activeGlobal->debrid_id,
                'debrid_link' => $activeGlobal->debrid_link,
                'filename' => $activeGlobal->filename,
                'filesize' => $activeGlobal->filesize,
                'downloaded_bytes' => $activeGlobal->downloaded_bytes,
                'status' => $activeGlobal->status,
                'mime_type' => $activeGlobal->mime_type,
                'storage_path' => $activeGlobal->storage_path,
                'user_ip' => $request->ip(),
                'use_remote' => $activeGlobal->use_remote,
            ]);
        } else {
            $existing = $userExisting ?: $activeGlobal;
        }

        // Case 1: Already cached on local server -> Serve/Redirect to local file
        if ($existing && $existing->status === 'completed' && ! empty($existing->storage_path)) {
            $fullPath = Storage::disk('public')->path($existing->storage_path);
            if (file_exists($fullPath)) {
                $existing->increment('download_count');

                return redirect()->to(route('downloads.file', ['uuid' => $existing->uuid]));
            }
        }

        // Case 2: New link -> Create record and unrestrict synchronously
        if (! $existing) {
            $existing = DebridDownload::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'original_link' => $originalLink,
                'link_hash' => $linkHash,
                'status' => 'pending',
                'user_ip' => $request->ip(),
                'use_remote' => true,
            ]);
        }

        if (empty($existing->debrid_link)) {
            $unrestrictResult = $this->rdService->unrestrictLink($originalLink, null, true);

            if (! $unrestrictResult['success']) {
                $existing->update([
                    'status' => 'failed',
                    'error_message' => $unrestrictResult['message'] ?? 'Real-Debrid link dönüştürülemedi.',
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Real-Debrid Hatası: '.($unrestrictResult['message'] ?? 'Dönüştürülemedi'),
                ], 400);
            }

            $data = $unrestrictResult['data'];
            DebridDownload::where('link_hash', $linkHash)->where('status', '!=', 'cancelled')->update([
                'debrid_id' => $data['id'] ?? null,
                'debrid_link' => $data['download_link'],
                'filename' => $data['filename'] ?? 'file_'.$existing->uuid,
                'filesize' => $data['filesize'] ?? 0,
                'mime_type' => $data['mime_type'] ?? null,
            ]);
            $existing->refresh();

            // Dispatch background caching job
            ProcessDebridDownloadJob::dispatch($existing);
        }

        // Redirect IDM / Browser to Real-Debrid unrestricted download URL
        if (! empty($existing->debrid_link)) {
            return redirect()->away($existing->debrid_link);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'İndirme adresi üretilemedi.',
        ], 500);
    }
}
