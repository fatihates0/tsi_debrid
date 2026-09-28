<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProxySpeedService
{
    public const DEFAULT_MIN_SPEED_MBPS = 50.0;
    public const PROBE_DURATION_SECONDS = 3.0;
    public const PROBE_MAX_BYTES = 15728640; // 15 MB max probe chunk

    /**
     * Find a proxy from the list that meets or exceeds the minimum speed (default 50 Mbps).
     *
     * @param string $testUrl Direct download URL to benchmark against
     * @param array $proxies List of candidate proxies
     * @param float|null $minSpeedMbps Minimum speed in Mbps (defaults to config or 50.0)
     * @return array [
     *     'proxy' => string|null,
     *     'speed_mbps' => float,
     *     'qualified' => bool,
     * ]
     */
    public function findFastProxy(string $testUrl, array $proxies, ?float $minSpeedMbps = null): array
    {
        $minSpeed = $minSpeedMbps ?? (float) config('services.realdebrid.min_proxy_speed_mbps', self::DEFAULT_MIN_SPEED_MBPS);

        // Normalize proxy list
        $normalizedProxies = array_values(array_filter(array_map([$this, 'normalizeProxy'], $proxies)));

        if (empty($normalizedProxies)) {
            return [
                'proxy' => null,
                'speed_mbps' => 0.0,
                'qualified' => true,
            ];
        }

        // 1. Check if we have a cached winning proxy that is still valid
        $cachedProxy = Cache::get('fastest_debrid_proxy');
        if ($cachedProxy && in_array($cachedProxy, $normalizedProxies, true)) {
            Log::info("Testing cached fast proxy: {$cachedProxy}...");
            $cachedSpeed = $this->measureProxySpeed($cachedProxy, $testUrl);
            if ($cachedSpeed >= $minSpeed) {
                Log::info("Cached proxy {$cachedProxy} verified at {$cachedSpeed} Mbps (>= {$minSpeed} Mbps threshold).");
                return [
                    'proxy' => $cachedProxy,
                    'speed_mbps' => $cachedSpeed,
                    'qualified' => true,
                ];
            }
            Cache::forget('fastest_debrid_proxy');
        }

        $fastestProxy = null;
        $maxSpeed = 0.0;

        // Test at most 6 proxies during speed probe to keep benchmarking quick (<15s)
        $candidates = array_slice($normalizedProxies, 0, 6);

        Log::info("Probing " . count($candidates) . " candidate proxies for minimum speed: {$minSpeed} Mbps...");

        foreach ($candidates as $proxy) {
            $speed = $this->measureProxySpeed($proxy, $testUrl);

            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
                $fastestProxy = $proxy;
            }

            if ($speed >= $minSpeed) {
                Log::info("Proxy {$proxy} QUALIFIED with {$speed} Mbps (>= {$minSpeed} Mbps). Selected for download!");
                // Cache the verified fast proxy for 20 minutes
                Cache::put('fastest_debrid_proxy', $proxy, now()->addMinutes(20));
                return [
                    'proxy' => $proxy,
                    'speed_mbps' => $speed,
                    'qualified' => true,
                ];
            }

            Log::info("Proxy {$proxy} speed: {$speed} Mbps (< {$minSpeed} Mbps threshold). Trying next...");
        }

        // None reached minSpeed -> Use fastest candidate found
        if ($fastestProxy) {
            Log::warning("No proxy reached {$minSpeed} Mbps. Using fastest candidate: {$fastestProxy} at {$maxSpeed} Mbps.");
        } else {
            Log::warning("All candidate proxies failed speed test. Falling back to direct connection.");
        }

        return [
            'proxy' => $fastestProxy,
            'speed_mbps' => $maxSpeed,
            'qualified' => ($maxSpeed >= $minSpeed),
        ];
    }

    /**
     * Measure download speed through a proxy in Mbps.
     */
    public function measureProxySpeed(?string $proxy, string $url): float
    {
        try {
            $guzzleConfig = [
                'verify' => false,
                RequestOptions::TIMEOUT => 5,
                RequestOptions::CONNECT_TIMEOUT => 2.0, // Skip unresponsive proxies in <= 2 seconds
                'force_ip_resolve' => 'v4',
                RequestOptions::HEADERS => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Range' => 'bytes=0-' . (self::PROBE_MAX_BYTES - 1),
                ],
                RequestOptions::STREAM => true,
            ];

            if ($proxy) {
                $guzzleConfig['proxy'] = $proxy;
            }

            $client = new GuzzleClient($guzzleConfig);
            $response = $client->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200 && $statusCode !== 206) {
                return 0.0;
            }

            $body = $response->getBody();
            $bytesDownloaded = 0;
            $startTime = microtime(true);

            while (!$body->eof()) {
                $chunk = $body->read(131072); // 128 KB buffer
                $bytesDownloaded += strlen($chunk);
                $elapsed = microtime(true) - $startTime;

                if ($elapsed >= self::PROBE_DURATION_SECONDS || $bytesDownloaded >= self::PROBE_MAX_BYTES) {
                    break;
                }
            }

            $elapsed = microtime(true) - $startTime;
            if ($elapsed <= 0.01 || $bytesDownloaded <= 0) {
                return 0.0;
            }

            // Calculate Megabits per second (Mbps)
            $bytesPerSec = $bytesDownloaded / $elapsed;
            return round(($bytesPerSec * 8) / 1000000, 2);
        } catch (Throwable $e) {
            Log::debug("Proxy " . ($proxy ?: 'Direct') . " speed probe failed: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Normalize proxy address format.
     */
    public function normalizeProxy(?string $proxy): ?string
    {
        if (!$proxy) {
            return null;
        }

        $trimmed = trim($proxy);
        if (empty($trimmed)) {
            return null;
        }

        if (!preg_match('#^[a-z0-9]+://#i', $trimmed)) {
            $trimmed = 'http://' . $trimmed;
        }

        return $trimmed;
    }
}
