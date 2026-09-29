<?php

use App\Http\Controllers\Api\DebridApiController;
use App\Http\Controllers\DebridDownloadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/downloads', [DebridApiController::class, 'index']);
    Route::post('/downloads', [DebridApiController::class, 'store']);
    Route::get('/downloads/{uuid}', [DebridApiController::class, 'show']);
    Route::delete('/downloads/{uuid}', [DebridApiController::class, 'destroy']);
    Route::get('/account/status', [DebridApiController::class, 'accountStatus']);
});

// IDM Direct Download API Route
Route::match(['get', 'head'], '/indir/{link?}', [DebridApiController::class, 'directDownload'])->where('link', '.*');

// Cron Job Endpoint for 7-day cache cleanup
Route::match(['get', 'post'], '/cron/clean-cache', [DebridDownloadController::class, 'cleanExpiredCache']);
