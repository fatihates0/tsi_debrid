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
            return [trim($envProxy)];
        }

        $txtPath = public_path('proxies.txt');
        if (file_exists($txtPath)) {
            $lines = file($txtPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $proxies = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!empty($trimmed) && !str_starts_with($trimmed, '#')) {
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

        $proxies = self::getProxyList();
        if (empty($proxies)) {
            $proxies = [null]; // direct connection fallback
        }

        $lastError = 'Bağlantı kurulamadı.';

        foreach ($proxies as $proxy) {
            try {
                $options = ['force_ip_resolve' => 'v4'];
                if ($proxy) {
                    $options['proxy'] = $proxy;
                }

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ])->withOptions($options)->timeout(10)->get($this->baseUrl . 'user');

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'data' => [
                            'id' => $data['id'] ?? null,
                            'username' => $data['username'] ?? 'Bilinmiyor',
                            'email' => $data['email'] ?? '',
                            'points' => $data['points'] ?? 0,
                            'type' => $data['type'] ?? 'free', // 'premium' or 'free'
                            'premium_seconds' => $data['premium'] ?? 0,
                            'expiration' => $data['expiration'] ?? null,
                        ],
                    ];
                }

                $errorMsg = $response->json('error') ?? $response->body();
                $lastError = 'Real-Debrid API Hatası (' . $response->status() . '): ' . $errorMsg;

                if ($proxy && (in_array($response->status(), [402, 407, 502, 503, 504]) || str_contains(strtolower((string) $errorMsg), 'proxy'))) {
                    Log::warning("Proxy {$proxy} getUserInfo failed ({$response->status()}). Retrying with next proxy...");
                    continue;
                }

                return [
                    'success' => false,
                    'message' => $lastError,
                ];
            } catch (Exception $e) {
                $lastError = 'Bağlantı hatası: ' . $e->getMessage();
                Log::warning("Proxy " . ($proxy ?: 'Direct') . " getUserInfo exception: " . $e->getMessage() . ". Retrying with next proxy...");
            }
        }

        return [
            'success' => false,
            'message' => $lastError,
        ];
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

        $proxies = self::getProxyList();
        if (empty($proxies)) {
            $proxies = [null]; // direct connection fallback
        }

        $lastError = 'Bağlantı kurulamadı.';

        foreach ($proxies as $proxy) {
            try {
                $options = ['force_ip_resolve' => 'v4'];
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
                ])->withOptions($options)->asForm()->timeout(15)->post($this->baseUrl . 'unrestrict/link', $payload);

                if ($response->successful()) {
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

                if ($proxy && (in_array($response->status(), [402, 407, 502, 503, 504]) || str_contains(strtolower((string) $errorMsg), 'proxy'))) {
                    Log::warning("Proxy {$proxy} unrestrictLink failed ({$response->status()}). Retrying with next proxy...");
                    continue;
                }

                return [
                    'success' => false,
                    'message' => $lastError,
                ];
            } catch (Exception $e) {
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
