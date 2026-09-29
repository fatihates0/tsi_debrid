<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenForoAuthService
{
    /**
     * Authenticate user credentials directly against XenForo MySQL database.
     *
     * @param  string  $login  Username or Email
     * @param  string  $password  User password
     *
     * @throws RuntimeException
     */
    public function authenticate(string $login, string $password): User
    {
        try {
            // Search user by username or email in XenForo's user table
            $xfUser = DB::connection('xenforo')
                ->table('user')
                ->where('username', $login)
                ->orWhere('email', $login)
                ->first();

            if (! $xfUser) {
                throw new RuntimeException('Girdiğiniz kullanıcı adı/e-posta veya şifre hatalı.');
            }

            // Retrieve authentication hash data from user_authenticate table
            $authRecord = DB::connection('xenforo')
                ->table('user_authenticate')
                ->where('user_id', $xfUser->user_id)
                ->first();

            if (! $authRecord || empty($authRecord->data)) {
                throw new RuntimeException('XenForo kullanıcı şifre doğrulama verisi bulunamadı.');
            }

            // Unserialize XenForo authentication payload
            $data = @unserialize($authRecord->data);
            $hash = $data['hash'] ?? null;

            if (! $hash || ! password_verify($password, $hash)) {
                throw new RuntimeException('Girdiğiniz kullanıcı adı/e-posta veya şifre hatalı.');
            }

            return $this->syncUser([
                'user_id' => $xfUser->user_id,
                'username' => $xfUser->username,
                'email' => $xfUser->email,
                'user_group_id' => $xfUser->user_group_id ?? null,
                'avatar_date' => $xfUser->avatar_date ?? 0,
            ]);

        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }

            Log::error('XenForo DB Auth Error', ['error' => $e->getMessage()]);
            throw new RuntimeException('XenForo veritabanına bağlanılamadı: '.$e->getMessage());
        }
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
        if (! empty($xenForoUser['avatar_date']) && $xenForoUser['avatar_date'] > 0) {
            $avatarGroup = floor($xenforoId / 1000);
            $forumUrl = rtrim(config('services.xenforo.url', 'https://turkcesesindir.com'), '/');
            $avatarUrl = "{$forumUrl}/data/avatars/m/{$avatarGroup}/{$xenforoId}.jpg?{$xenForoUser['avatar_date']}";
        }

        $userGroupId = $xenForoUser['user_group_id'] ?? null;

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
                'avatar_url' => $avatarUrl ?: $user->avatar_url,
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
