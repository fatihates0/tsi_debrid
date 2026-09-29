<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDebridDownloadJob;
use App\Models\DebridDownload;
use App\Models\User;
use App\Services\RealDebridService;
use App\Services\XenForoAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        /** @var User|null $user */
        $user = auth()->user();
        $isSuperUser = $user?->isSuperUser() ?? false;

        $query = DebridDownload::with('user');
        if (! $isSuperUser && $user) {
            $query->where('user_id', $user->id);
        }

        $downloads = $query->orderBy('created_at', 'desc')->paginate(15);

        $statsQuery = DebridDownload::query();
        if (! $isSuperUser && $user) {
            $statsQuery->where('user_id', $user->id);
        }

        // Calculate actual server disk usage (sum of unique completed files by link_hash)
        $uniqueCompletedQuery = DebridDownload::where('status', 'completed')
            ->select('link_hash', DB::raw('MAX(filesize) as actual_size'))
            ->groupBy('link_hash');

        if (! $isSuperUser && $user) {
            $uniqueCompletedQuery->where('user_id', $user->id);
        }

        $totalBytesCached = $uniqueCompletedQuery->get()->sum('actual_size');

        // Calculate unique completed physical files count
        $completedFilesQuery = DebridDownload::where('status', 'completed');
        if (! $isSuperUser && $user) {
            $completedFilesQuery->where('user_id', $user->id);
        }
        $completedDownloadsCount = $completedFilesQuery->distinct('link_hash')->count('link_hash');

        $storagePath = Storage::disk('public')->path('');
        $freeDiskSpace = @disk_free_space($storagePath);
        $totalDiskSpace = @disk_total_space($storagePath);

        $stats = [
            'total_downloads' => (clone $statsQuery)->count(),
            'completed_downloads' => $completedDownloadsCount,
            'total_bytes_cached' => $totalBytesCached,
            'total_saved_rd_requests' => (clone $statsQuery)->where('status', 'completed')->sum('download_count'),
            'active_connections' => (clone $statsQuery)->whereIn('status', ['pending', 'unrestricting', 'downloading'])->count(),
            'free_disk_space' => $freeDiskSpace !== false ? (int) $freeDiskSpace : 0,
            'total_disk_space' => $totalDiskSpace !== false ? (int) $totalDiskSpace : 0,
        ];

        $userStats = [];
        if ($isSuperUser) {
            $allUsers = User::withCount([
                'debridDownloads as total_cached' => function ($q) {
                    $q->where('status', 'completed');
                },
                'debridDownloads as active_downloads' => function ($q) {
                    $q->whereIn('status', ['pending', 'unrestricting', 'downloading']);
                },
            ])->get();

            foreach ($allUsers as $u) {
                $userUniqueFiles = $u->debridDownloads()
                    ->where('status', 'completed')
                    ->select('link_hash', DB::raw('MAX(filesize) as actual_size'))
                    ->groupBy('link_hash')
                    ->get();

                $userStats[] = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $u->avatar_url,
                    'total_cached' => $u->total_cached,
                    'active_downloads' => $u->active_downloads,
                    'total_bytes' => $userUniqueFiles->sum('actual_size'),
                    'last_ip' => $u->debridDownloads()->latest()->value('user_ip') ?? 'N/A',
                ];
            }
        }

        return view('dashboard', compact('downloads', 'stats', 'isSuperUser', 'userStats'));
    }

    /**
     * Store / Submit Link for Proxy Caching
     */
    public function store(Request $request, XenForoAuthService $authService)
    {
        /** @var User|null $user */
        $user = auth()->user();

        // 1. Check user group permission - logout immediately if group is restricted
        if ($user && ! $authService->checkUserGroupPermission($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Üyelik grubunuz bu işlemi gerçekleştirmek için yetkilendirilmemiştir. Oturumunuz kapatıldı.',
                    'redirect' => route('login'),
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'login' => 'Üyelik grubunuz yetkili olmadığı için oturumunuz kapatıldı.',
            ]);
        }

        $request->validate([
            'link' => 'required|url',
        ], [
            'link.required' => 'Lütfen geçerli bir indirme bağlantısı girin.',
            'link.url' => 'Geçerli bir URL formatı olmalıdır.',
        ]);

        $userId = $user?->id;
        $originalLink = trim($request->input('link'));
        $linkHash = md5($originalLink);

        // 1. Check if the current user ALREADY has this link in their list
        $userExisting = DebridDownload::when($userId, function ($q) use ($userId) {
            return $q->where('user_id', $userId);
        })->where('link_hash', $linkHash)->first();

        if ($userExisting) {
            if ($userExisting->status === 'completed' && $userExisting->is_cached) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Bu bağlantı zaten indirme listenizde mevcut ve önbellekte hazır!',
                        'cached' => true,
                        'data' => $userExisting,
                    ]);
                }

                return redirect()->route('dashboard')->with('success', '⚡ Bu bağlantı zaten listenizde mevcut ve önbellekte hazır!');
            }

            if (in_array($userExisting->status, ['pending', 'unrestricting', 'downloading'])) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Bu dosya şu an sunucuda indiriliyor...',
                        'cached' => false,
                        'data' => $userExisting,
                    ]);
                }

                return redirect()->route('dashboard')->with('info', '⌛ Dosya şu an sunucumuza indiriliyor, canlı durum tablosundan takip edebilirsiniz.');
            }
        }

        // 2. Check if ANY active or completed download exists in the DB for this link (from another user)
        $activeGlobal = DebridDownload::where('link_hash', $linkHash)
            ->whereIn('status', ['completed', 'downloading', 'unrestricting', 'pending'])
            ->orderBy('id', 'desc')
            ->first();

        if ($activeGlobal) {
            // Attach existing active/completed download to this user!
            $download = DebridDownload::create([
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

            if ($activeGlobal->status === 'completed' && $activeGlobal->is_cached) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Bu bağlantı daha önceden indirildiği için doğrudan hesabınıza eklendi ve hazır!',
                        'cached' => true,
                        'data' => $download,
                    ]);
                }

                return redirect()->route('dashboard')->with('success', '⚡ Dosya daha önceden indirildiği için doğrudan hesabınıza eklendi!');
            }

            // In progress by another user
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Bu bağlantı daha önceden başlatılmış, hesabınıza eklendi ve indirilmesi bekleniyor.',
                    'cached' => false,
                    'data' => $download,
                ]);
            }

            return redirect()->route('dashboard')->with('info', '⌛ Aktif indirme hesabınıza eklendi, tamamlandığında hazıracaktır.');
        }

        // 3. New link submission -> Create download record for this user & dispatch Job
        $download = DebridDownload::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'original_link' => $originalLink,
            'link_hash' => $linkHash,
            'status' => 'pending',
            'user_ip' => $request->ip(),
            'use_remote' => $request->has('use_remote') ? $request->boolean('use_remote') : config('services.realdebrid.use_remote', true),
        ]);

        $queueDriver = config('queue.default');

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
        /** @var User|null $user */
        $user = auth()->user();
        $isSuperUser = $user?->isSuperUser() ?? false;

        $query = DebridDownload::where('uuid', $uuid);
        if (! $isSuperUser && $user) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            });
        }
        $download = $query->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $download,
        ]);
    }

    /**
     * List user downloads for AJAX polling
     */
    public function listAjax()
    {
        /** @var User|null $user */
        $user = auth()->user();
        $isSuperUser = $user?->isSuperUser() ?? false;

        $query = DebridDownload::with('user');
        if (! $isSuperUser && $user) {
            $query->where('user_id', $user->id);
        }

        $downloads = $query->orderBy('created_at', 'desc')->limit(200)->get();

        $linkHashCounts = DebridDownload::select('link_hash', DB::raw('COUNT(*) as total_users'))
            ->groupBy('link_hash')
            ->pluck('total_users', 'link_hash');

        foreach ($downloads as $d) {
            $d->user_count = (int) ($linkHashCounts[$d->link_hash] ?? 1);
        }

        if (! $isSuperUser) {
            $downloads->makeHidden(['user_ip']);
        }

        return response()->json([
            'success' => true,
            'is_superuser' => $isSuperUser,
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
        /** @var User|null $user */
        $user = auth()->user();
        $isSuperUser = $user?->isSuperUser() ?? false;

        $query = DebridDownload::where('uuid', $uuid);
        if (! $isSuperUser && $user) {
            $query->where('user_id', $user->id);
        }
        $download = $query->firstOrFail();

        $storagePath = $download->storage_path;
        $linkHash = $download->link_hash;
        $downloadUuid = $download->uuid;

        // 1. Mark as cancelled before deletion so active loop notices
        $download->update(['status' => 'cancelled']);
        $download->delete();

        // 2. Only delete physical file and cancel job if NO OTHER active/completed user record uses this link_hash
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

        $msg = 'İndirme kaydı silindi.';

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        }

        return redirect()->route('dashboard')->with('success', $msg);
    }

    /**
     * Superuser endpoint to retry a failed download.
     * Clears error states, refreshes proxy caches, and re-dispatches ProcessDebridDownloadJob.
     */
    public function retry(Request $request, string $uuid)
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user || ! $user->isSuperUser()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu işlem için Superuser yetkisi gereklidir.',
            ], 403);
        }

        $download = DebridDownload::where('uuid', $uuid)->firstOrFail();
        $linkHash = $download->link_hash;

        // Clear proxy block cache to ensure fresh candidate proxies are tried
        RealDebridService::clearUserInfoCache();

        // Remove any partial physical download files if present
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

        // Reset failed download records matching link_hash back to pending
        DebridDownload::where('link_hash', $linkHash)->where('status', 'failed')->update([
            'status' => 'pending',
            'error_message' => null,
            'debrid_id' => null,
            'debrid_link' => null,
            'downloaded_bytes' => 0,
            'storage_path' => null,
        ]);

        $freshDownload = $download->fresh();

        // Dispatch background job to re-process download
        $queueDriver = config('queue.default');
        if ($queueDriver === 'sync') {
            ProcessDebridDownloadJob::dispatchAfterResponse($freshDownload);
        } else {
            ProcessDebridDownloadJob::dispatch($freshDownload);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'İndirme temizlendi ve yeniden başlatıldı!',
                'data' => $freshDownload,
            ]);
        }

        return redirect()->route('dashboard')->with('success', '🚀 İndirme temizlendi ve yeniden başlatıldı!');
    }

    /**
     * Superuser endpoint to delete a user and all their associated downloads/files from DB.
     */
    public function deleteUser(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user || ! $user->isSuperUser()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu işlem için Superuser yetkisi gereklidir.',
            ], 403);
        }

        if ($user->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'Kendi Superuser hesabınızı silemezsiniz.',
            ], 400);
        }

        $targetUser = User::find($id);

        if (! $targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı bulunamadı.',
            ], 404);
        }

        $userName = $targetUser->name ?: $targetUser->username;

        // Get all downloads belonging to this user
        $userDownloads = DebridDownload::where('user_id', $targetUser->id)->get();

        foreach ($userDownloads as $download) {
            $storagePath = $download->storage_path;
            $linkHash = $download->link_hash;
            $downloadUuid = $download->uuid;

            // Mark as cancelled and delete record
            $download->update(['status' => 'cancelled']);
            $download->delete();

            // Check if any other user still has a record for this link_hash
            $otherActiveCount = DebridDownload::where('link_hash', $linkHash)
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($otherActiveCount === 0) {
                Cache::put("cancel_download_{$downloadUuid}", true, now()->addMinutes(10));

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
            }
        }

        // Delete user record from DB
        $targetUser->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "{$userName} kullanıcısı ve kullanıcıya ait tüm indirme verileri veritabanından silindi.",
            ]);
        }

        return redirect()->route('dashboard')->with('success', "{$userName} kullanıcısı ve tüm verileri silindi.");
    }

    /**
     * Check Real-Debrid API status
     */
    public function rdStatus()
    {
        $info = $this->rdService->getUserInfo();

        return response()->json($info);
    }

    /**
     * Cron endpoint to clean cached files older than 7 days.
     */
    public function cleanExpiredCache(Request $request)
    {
        $cronSecret = config('services.cron.secret');
        if (! empty($cronSecret)) {
            $providedKey = $request->query('key') ?: $request->input('key') ?: $request->header('X-Cron-Key');
            if ($providedKey !== $cronSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Yetkisiz cron erişimi (Geçersiz Cron Key).',
                ], 403);
            }
        }

        $cutoffDate = now()->subDays(7);
        $expiredDownloads = DebridDownload::where('created_at', '<', $cutoffDate)->get();

        $deletedCount = 0;
        $freedBytes = 0;

        foreach ($expiredDownloads as $download) {
            $storagePath = $download->storage_path;
            $linkHash = $download->link_hash;
            $fileSize = $download->filesize ?? 0;

            $download->delete();
            $deletedCount++;
            $freedBytes += $fileSize;

            $otherCount = DebridDownload::where('link_hash', $linkHash)->count();
            if ($otherCount === 0 && ! empty($storagePath)) {
                $fullPath = Storage::disk('public')->path($storagePath);
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $dir = dirname($storagePath);
                if ($dir && $dir !== '.' && Storage::disk('public')->exists($dir)) {
                    Storage::disk('public')->deleteDirectory($dir);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "7 günden eski {$deletedCount} adet önbellek kaydı ve dosyası silindi.",
            'deleted_count' => $deletedCount,
            'freed_bytes' => $freedBytes,
            'freed_formatted' => formatBytes($freedBytes),
        ]);
    }
}
