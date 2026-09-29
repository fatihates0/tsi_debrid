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
        // 0. Check Superuser credentials from .env
        $superUsername = config('services.superuser.username');
        $superPassword = config('services.superuser.password');

        if (! empty($superUsername) && ! empty($superPassword)) {
            if ((strcasecmp($login, $superUsername) === 0 || strcasecmp($login, $superUsername.'@admin.local') === 0) && $password === $superPassword) {
                return User::firstOrCreate(
                    ['username' => $superUsername],
                    [
                        'name' => $superUsername,
                        'email' => $superUsername.'@admin.local',
                        'password' => bcrypt($superPassword),
                        'user_group_id' => 1,
                    ]
                );
            }
        }

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

            // Check allowed user groups restriction from config/env
            $allowedGroupsConfig = config('services.xenforo.allowed_groups', '');
            if (! empty($allowedGroupsConfig)) {
                $allowedGroups = array_filter(array_map('intval', explode(',', (string) $allowedGroupsConfig)));

                if (! empty($allowedGroups)) {
                    $primaryGroupId = (int) ($xfUser->user_group_id ?? 0);
                    $secondaryGroupIds = array_filter(array_map('intval', explode(',', (string) ($xfUser->secondary_group_ids ?? ''))));

                    $userGroups = array_unique(array_merge([$primaryGroupId], $secondaryGroupIds));

                    $hasPermission = false;
                    foreach ($userGroups as $gid) {
                        if (in_array($gid, $allowedGroups, true)) {
                            $hasPermission = true;
                            break;
                        }
                    }

                    if (! $hasPermission) {
                        throw new RuntimeException('Üyelik grubunuz bu sisteme giriş yapmak için yetkilendirilmemiştir.');
                    }
                }
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
     * Check if a user belongs to an allowed XenForo user group.
     */
    public function checkUserGroupPermission(User $user): bool
    {
        if ($user->isSuperUser()) {
            return true;
        }

        $allowedGroupsConfig = config('services.xenforo.allowed_groups', '');
        if (empty($allowedGroupsConfig)) {
            return true;
        }

        $allowedGroups = array_filter(array_map('intval', explode(',', (string) $allowedGroupsConfig)));
        if (empty($allowedGroups)) {
            return true;
        }

        if ($user->xenforo_id) {
            try {
                $xfUser = DB::connection('xenforo')
                    ->table('user')
                    ->where('user_id', $user->xenforo_id)
                    ->first();

                if ($xfUser) {
                    $primaryGroupId = (int) ($xfUser->user_group_id ?? 0);
                    $secondaryGroupIds = array_filter(array_map('intval', explode(',', (string) ($xfUser->secondary_group_ids ?? ''))));
                    $userGroups = array_unique(array_merge([$primaryGroupId], $secondaryGroupIds));

                    if ((int) $user->user_group_id !== $primaryGroupId) {
                        $user->update(['user_group_id' => $primaryGroupId]);
                    }

                    foreach ($userGroups as $gid) {
                        if (in_array($gid, $allowedGroups, true)) {
                            return true;
                        }
                    }

                    return false;
                }
            } catch (\Throwable $e) {
                Log::warning('XenForo live group check error: '.$e->getMessage());
            }
        }

        if ($user->user_group_id) {
            return in_array((int) $user->user_group_id, $allowedGroups, true);
        }

        return false;
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
