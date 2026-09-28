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
}
