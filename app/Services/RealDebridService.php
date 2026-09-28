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

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])->timeout(10)->get($this->baseUrl . 'user');

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

            return [
                'success' => false,
                'message' => 'Real-Debrid API Hatası (' . $response->status() . '): ' . ($response->json('error') ?? $response->body()),
            ];
        } catch (Exception $e) {
            Log::error('RealDebrid getUserInfo Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage(),
            ];
        }
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

        try {
            $payload = [
                'link' => trim($link),
                'remote' => $remote ? 1 : 0,
            ];

            if ($password) {
                $payload['password'] = $password;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])->asForm()->timeout(15)->post($this->baseUrl . 'unrestrict/link', $payload);

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
            return [
                'success' => false,
                'message' => 'Real-Debrid Unrestrict Hatası (' . $response->status() . '): ' . $errorMsg,
            ];
        } catch (Exception $e) {
            Log::error('RealDebrid unrestrictLink Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'İstek hatası: ' . $e->getMessage(),
            ];
        }
    }
}
