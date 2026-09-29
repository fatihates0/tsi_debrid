<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['xenforo_id', 'username', 'name', 'email', 'password', 'avatar_url', 'user_group_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function debridDownloads(): HasMany
    {
        return $this->hasMany(DebridDownload::class);
    }

    /**
     * Check if the user is superuser configured in env.
     */
    public function isSuperUser(): bool
    {
        $superUsername = config('services.superuser.username');
        if (empty($superUsername)) {
            return false;
        }

        return (strcasecmp((string) $this->username, (string) $superUsername) === 0)
            || (strcasecmp((string) $this->name, (string) $superUsername) === 0)
            || (strcasecmp((string) $this->email, (string) $superUsername) === 0);
    }
}
