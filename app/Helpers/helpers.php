<?php

if (! function_exists('formatBytes')) {
    function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($bytes, 1024);
        $floor = floor($base);

        return round(pow(1024, $base - $floor), $precision).' '.($units[$floor] ?? 'B');
    }
}
