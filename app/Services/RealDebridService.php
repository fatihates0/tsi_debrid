<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class RealDebridService
{
    protected string $baseUrl;
    protected string $apiToken;

    public function __construct(?string $apiToken = null)
    {
        $this->baseUrl = config('services.realdebrid.base_url', 'https://api.real-debrid.com/rest/1.0/');
        $this->apiToken = $apiToken ?? config('services.realdebrid.api_token', '');
    }

    /**
     * Set dynamic API token (e.g. from UI settings or request)
     */
    public function setToken(string $token): self
    {
        $this->apiToken = trim($token);
        return $this;
    }

    public function hasToken(): bool
    {
        return !empty($this->apiToken);
    }

    /**
     * Get list of configured proxies.
     * Priority:
     * 1. .env (REAL_DEBRID_PROXY) if set
     * 2. public/proxies.txt file (1 proxy per line)
     * 3. Empty list (direct connection)
     */
    public static function getProxyList(): array
    {
        $envProxy = config('services.realdebrid.proxy');
        if (!empty($envProxy)) {
            $trimmed = trim($envProxy);
            if (!preg_match('#^[a-z0-9]+://#i', $trimmed)) {
                $trimmed = 'http://' . $trimmed;
            }
            return [$trimmed];
        }

        $txtPath = public_path('proxies.txt');
        if (file_exists($txtPath)) {
            $lines = file($txtPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $proxies = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!empty($trimmed) && !str_starts_with($trimmed, '#')) {
                    if (!preg_match('#^[a-z0-9]+://#i', $trimmed)) {
                        $trimmed = 'http://' . $trimmed;
                    }
                    $proxies[] = $trimmed;
                }
            }
            if (!empty($proxies)) {
                return $proxies;
            }
        }

        return [];
    }

    /**
     * Build ordered candidate proxy list for API calls
     */
    public static function getCandidateProxiesForApi(): array
    {
        $proxies = self::getProxyList();
        if (empty($proxies)) {
            return [null];
        }

        $candidates = [];

        // 1. Prioritize last verified working proxy
        $workingProxy = \Illuminate\Support\Facades\Cache::get('last_working_rd_proxy');
        if ($workingProxy && in_array($workingProxy, $proxies, true)) {
            $candidates[] = $workingProxy;
        }

        // 2. Add candidates from list (up to 8 candidates to find a working one fast)
        foreach ($proxies as $p) {
            if (!in_array($p, $candidates, true)) {
                $candidates[] = $p;
            }
            if (count($candidates) >= 8) {
                break;
            }
        }

        // 3. Fallback to direct connection only if server IP is not known to be blocked
        if (!\Illuminate\Support\Facades\Cache::has('rd_direct_ip_blocked')) {
            $candidates[] = null;
        }

        return $candidates;
    }

    /**
     * Get Real-Debrid User Information & Premium Status
     */
    public function getUserInfo(): array
    {
        if (!$this->hasToken()) {
            return [
                'success' => false,
                'message' => 'Real-Debrid API Token ayarlanmamış.',
            ];
        }

        // Cache user info for 30s to prevent spamming RD API and avoiding gateway timeouts
        return \Illuminate\Support\Facades\Cache::remember('rd_user_info', 30, function () {
            $candidates = self::getCandidateProxiesForApi();
            $lastError = 'Bağlantı kurulamadı.';

            foreach ($candidates as $proxy) {
                try {
                    $options = [
                        'force_ip_resolve' => 'v4',
                        'connect_timeout' => 2.5,
                    ];
                    if ($proxy) {
                        $options['proxy'] = $proxy;
                    }

                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiToken,
                    ])->withOptions($options)->timeout(5)->get($this->baseUrl . 'user');

                    if ($response->successful()) {
                        if ($proxy) {
                            \Illuminate\Support\Facades\Cache::put('last_working_rd_proxy', $proxy, now()->addHours(2));
                        }

                        $data = $response->json();
                        return [
                            'success' => true,
                            'data' => [
                                'id' => $data['id'] ?? null,
                                'username' => $data['username'] ?? 'Bilinmiyor',
                                'email' => $data['email'] ?? '',
                                'points' => $data['points'] ?? 0,
                                'type' => $data['type'] ?? 'free',
                                'premium_seconds' => $data['premium'] ?? 0,
                                'expiration' => $data['expiration'] ?? null,
                            ],
                        ];
                    }

                    $errorMsg = $response->json('error') ?? $response->body();
                    $lastError = 'Real-Debrid API Hatası (' . $response->status() . '): ' . $errorMsg;

                    $isIpBlocked = str_contains(strtolower((string) $errorMsg), 'ip_not_allowed');
                    if ($isIpBlocked && $proxy === null) {
                        \Illuminate\Support\Facades\Cache::put('rd_direct_ip_blocked', true, now()->addHours(6));
                    }

                    $isRetryable = $isIpBlocked
                        || in_array($response->status(), [402, 403, 407, 502, 503, 504])
                        || str_contains(strtolower((string) $errorMsg), 'proxy');

                    if ($proxy && $isRetryable) {
                        Log::warning("Proxy {$proxy} getUserInfo failed ({$response->status()} - {$errorMsg}). Retrying with next proxy...");
                        continue;
                    }

                    return [
                        'success' => false,
                        'message' => $lastError,
                    ];
                } catch (\Throwable $e) {
                    $lastError = 'Bağlantı hatası: ' . $e->getMessage();
                    Log::warning("Proxy " . ($proxy ?: 'Direct') . " getUserInfo exception: " . $e->getMessage() . ". Retrying with next proxy...");
                }
            }

            return [
                'success' => false,
                'message' => $lastError,
            ];
        });
    }

    /**
     * Unrestrict a link (Mega.nz, Rapidgator, etc.)
     * 
     * @param string $link
     * @param string|null $password
     * @param bool $remote Bypasses dedicated server / VPS hoster restrictions (remote=1)
     */
    public function unrestrictLink(string $link, ?string $password = null, bool $remote = true): array
    {
        if (!$this->hasToken()) {
            return [
                'success' => false,
                'message' => 'Real-Debrid API Token tanımlı değil.',
            ];
        }

        $candidates = self::getCandidateProxiesForApi();
        $lastError = 'Bağlantı kurulamadı.';

        foreach ($candidates as $proxy) {
            try {
                $options = [
                    'force_ip_resolve' => 'v4',
                    'connect_timeout' => 3.0,
                ];
                if ($proxy) {
                    $options['proxy'] = $proxy;
                }

                $payload = [
                    'link' => trim($link),
                    'remote' => $remote ? 1 : 0,
                ];

                if ($password) {
                    $payload['password'] = $password;
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ])->withOptions($options)->asForm()->timeout(8)->post($this->baseUrl . 'unrestrict/link', $payload);

                if ($response->successful()) {
                    if ($proxy) {
                        \Illuminate\Support\Facades\Cache::put('last_working_rd_proxy', $proxy, now()->addHours(2));
                    }

                    $data = $response->json();
                    return [
                        'success' => true,
                        'data' => [
                            'id' => $data['id'] ?? null,
                            'filename' => $data['filename'] ?? 'downloaded_file',
                            'mime_type' => $data['mimeType'] ?? null,
                            'filesize' => $data['filesize'] ?? 0,
                            'original_link' => $data['link'] ?? $link,
                            'host' => $data['host'] ?? null,
                            'download_link' => $data['download'] ?? null,
                            'streamable' => (bool) ($data['streamable'] ?? 0),
                        ],
                    ];
                }

                $errorMsg = $response->json('error') ?? $response->body();
                $lastError = 'Real-Debrid Unrestrict Hatası (' . $response->status() . '): ' . $errorMsg;

                // Automatic fallback if Remote Traffic (remote=1) quota is exhausted
                if ($remote && str_contains(strtolower((string) $errorMsg), 'traffic_exhausted')) {
                    Log::info("RealDebrid Remote Traffic exhausted for link {$link}, automatically falling back to standard unrestrict (remote=0)");
                    return $this->unrestrictLink($link, $password, false);
                }

                $isIpBlocked = str_contains(strtolower((string) $errorMsg), 'ip_not_allowed');
                if ($isIpBlocked && $proxy === null) {
                    \Illuminate\Support\Facades\Cache::put('rd_direct_ip_blocked', true, now()->addHours(6));
                }

                $isRetryable = $isIpBlocked
                    || in_array($response->status(), [402, 403, 407, 502, 503, 504])
                    || str_contains(strtolower((string) $errorMsg), 'proxy');

                if ($proxy && $isRetryable) {
                    Log::warning("Proxy {$proxy} unrestrictLink failed ({$response->status()} - {$errorMsg}). Retrying with next proxy...");
                    continue;
                }

                return [
                    'success' => false,
                    'message' => $lastError,
                ];
            } catch (\Throwable $e) {
                $lastError = 'İstek hatası: ' . $e->getMessage();
                Log::warning("Proxy " . ($proxy ?: 'Direct') . " unrestrictLink exception: " . $e->getMessage() . ". Retrying with next proxy...");
            }
        }

        return [
            'success' => false,
            'message' => $lastError,
        ];
    }
}
