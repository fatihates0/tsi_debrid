<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DebridDownload extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'original_link',
        'link_hash',
        'debrid_id',
        'debrid_link',
        'filename',
        'filesize',
        'downloaded_bytes',
        'status',
        'mime_type',
        'storage_path',
        'error_message',
        'user_ip',
        'download_count',
        'use_remote',
    ];

    protected $casts = [
        'filesize' => 'integer',
        'downloaded_bytes' => 'integer',
        'download_count' => 'integer',
        'use_remote' => 'boolean',
    ];

    protected $appends = [
        'progress_percentage',
        'formatted_filesize',
        'formatted_downloaded',
        'download_url',
        'is_cached',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (!empty($model->original_link) && empty($model->link_hash)) {
                $model->link_hash = md5(trim($model->original_link));
            }
        });
    }

    public function getProgressPercentageAttribute(): int
    {
        if ($this->status === 'completed') {
            return 100;
        }
        if ($this->filesize <= 0) {
            return 0;
        }
        return min(100, (int) round(($this->downloaded_bytes / $this->filesize) * 100));
    }

    public function getFormattedFilesizeAttribute(): string
    {
        return $this->formatBytes($this->filesize);
    }

    public function getFormattedDownloadedAttribute(): string
    {
        return $this->formatBytes($this->downloaded_bytes);
    }

    public function getDownloadUrlAttribute(): ?string
    {
        if ($this->status !== 'completed' || empty($this->filename)) {
            return null;
        }
        return route('downloads.file', ['uuid' => $this->uuid]);
    }

    public function getIsCachedAttribute(): bool
    {
        return $this->status === 'completed' && !empty($this->storage_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($this->storage_path);
    }

    private function formatBytes(?int $bytes, int $precision = 2): string
    {
        $bytes = $bytes ?? 0;
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($bytes, 1024);
        $floor = floor($base);
        return round(pow(1024, $base - $floor), $precision) . ' ' . ($units[$floor] ?? 'B');
    }
}
