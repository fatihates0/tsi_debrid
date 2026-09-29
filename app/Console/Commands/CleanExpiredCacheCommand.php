<?php

namespace App\Console\Commands;

use App\Models\DebridDownload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('cache:clean-expired')]
#[Description('Clean cached downloads and DB records older than 7 days')]
class CleanExpiredCacheCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
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

        $this->info("{$deletedCount} adet 7 günü aşkın önbelleklenmiş dosya ve veritabanı kaydı silindi.");

        return Command::SUCCESS;
    }
}
