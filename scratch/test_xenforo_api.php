<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$apiKey = env('XENFORO_API_KEY');
$url = 'https://turkcesesindir.com/api/auth/';

$headersToTest = [
    'X-Api-Key',
    'X-API-KEY',
    'x-api-key',
    'X_Api_Key',
    'X_API_KEY',
    'HTTP_X_API_KEY',
    'HTTP-X-API-KEY',
    'X-Ff-Api-Key',
    'Api-Key',
    'API-KEY',
];

foreach ($headersToTest as $h) {
    $res = Http::withHeaders([$h => $apiKey])->withoutVerifying()->post($url, ['login' => 'test', 'password' => 'test']);
    $data = json_decode($res->body(), true);
    $code = $data['errors'][0]['code'] ?? 'OK';
    $msg = $data['errors'][0]['message'] ?? 'SUCCESS';
    echo sprintf("%-20s => %s (%s)\n", $h, $code, $msg);
}
