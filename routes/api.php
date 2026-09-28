<?php

use App\Http\Controllers\Api\DebridApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/downloads', [DebridApiController::class, 'index']);
    Route::post('/downloads', [DebridApiController::class, 'store']);
    Route::get('/downloads/{uuid}', [DebridApiController::class, 'show']);
    Route::delete('/downloads/{uuid}', [DebridApiController::class, 'destroy']);
    Route::get('/account/status', [DebridApiController::class, 'accountStatus']);
});

// IDM Direct Download API Route
Route::get('/indir/{link?}', [DebridApiController::class, 'directDownload'])->where('link', '.*');
