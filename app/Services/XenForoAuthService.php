<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenForoAuthService
{
    protected string $baseUrl;

    protected string $apiKey;

    protected bool $verifySsl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.xenforo.url', 'https://turkcesesindir.com'), '/');
        $this->apiKey = config('services.xenforo.api_key', '');
        $this->verifySsl = (bool) config('services.xenforo.verify_ssl', true);
    }

    /**
     * Authenticate user credentials against XenForo REST API.
     *
     * @param  string  $login  Username or Email
     * @param  string  $password  User password
     *
     * @throws RuntimeException
     */
    public function authenticate(string $login, string $password): User
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('XenForo API Key tanımlanmamış. Lütfen .env dosyasına XENFORO_API_KEY değerini ekleyin.');
        }

        $apiUrl = $this->baseUrl.'/api/auth/';

        try {
            $response = Http::withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
                ->withoutVerifying() // Flexible for dev/self-signed SSL if needed, controlled by config
                ->post($apiUrl, [
                    'login' => $login,
                    'password' => $password,
                ]);
        } catch (\Throwable $e) {
            Log::error('XenForo Auth Connection Error', [
                'url' => $apiUrl,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('XenForo sunucusuna bağlanılamadı: '.$e->getMessage());
        }

        if ($response->failed()) {
            $data = $response->json();
            $errorMessage = 'Giriş bilgileri hatalı veya XenForo API erişiminde sorun oluştu.';

            if (! empty($data['errors']) && is_array($data['errors'])) {
                $firstError = reset($data['errors']);
                if (is_array($firstError) && isset($firstError['message'])) {
                    $errorMessage = $firstError['message'];
                } elseif (is_string($firstError)) {
                    $errorMessage = $firstError;
                }
            } elseif ($response->status() === 401 || $response->status() === 403) {
                $errorMessage = 'XenForo API anahtarı geçersiz veya yetkisiz erişim (HTTP '.$response->status().').';
            }

            Log::warning('XenForo Auth Failed', [
                'status' => $response->status(),
                'response' => $data,
            ]);

            throw new RuntimeException($errorMessage);
        }

        $data = $response->json();

        // XenForo returns user data in $data['user'] or $data if direct
        $userData = $data['user'] ?? null;

        if (! $userData || empty($userData['user_id'])) {
            throw new RuntimeException('XenForo\'dan geçerli kullanıcı bilgisi alınamadı.');
        }

        return $this->syncUser($userData);
    }

    /**
     * Synchronize XenForo user with local Laravel users table.
     */
    public function syncUser(array $xenForoUser): User
    {
        $xenforoId = $xenForoUser['user_id'];
        $username = $xenForoUser['username'] ?? 'User_'.$xenforoId;
        $email = ! empty($xenForoUser['email']) ? $xenForoUser['email'] : ($username.'@turkcesesindir.com');

        $avatarUrl = null;
        if (! empty($xenForoUser['avatar_urls'])) {
            $avatarUrl = $xenForoUser['avatar_urls']['m']
                ?? $xenForoUser['avatar_urls']['o']
                ?? $xenForoUser['avatar_urls']['s']
                ?? null;
        }

        $userGroupId = $xenForoUser['user_group_id'] ?? null;

        // Try finding by xenforo_id first, then email, then username
        $user = User::where('xenforo_id', $xenforoId)
            ->orWhere('email', $email)
            ->orWhere('username', $username)
            ->first();

        if ($user) {
            $user->update([
                'xenforo_id' => $xenforoId,
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'avatar_url' => $avatarUrl,
                'user_group_id' => $userGroupId,
            ]);
        } else {
            $user = User::create([
                'xenforo_id' => $xenforoId,
                'name' => $username,
                'username' => $username,
                'email' => $email,
                'avatar_url' => $avatarUrl,
                'user_group_id' => $userGroupId,
            ]);
        }

        return $user;
    }
}
