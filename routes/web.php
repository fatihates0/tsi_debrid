<?php

use App\Http\Controllers\Api\DebridApiController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DebridDownloadController;
use Illuminate\Support\Facades\Route;

// Guest Routes (Authentication)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Authenticated Routes (Dashboard & Downloads Management)
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DebridDownloadController::class, 'index'])->name('dashboard');
    Route::redirect('/downloads', '/');
    Route::post('/downloads', [DebridDownloadController::class, 'store'])->name('downloads.store');
    Route::get('/downloads/ajax-list', [DebridDownloadController::class, 'listAjax'])->name('downloads.ajax_list');
    Route::get('/downloads/{uuid}', [DebridDownloadController::class, 'show'])->name('downloads.show');
    Route::post('/downloads/{uuid}/retry', [DebridDownloadController::class, 'retry'])->name('downloads.retry');
    Route::delete('/downloads/{uuid}', [DebridDownloadController::class, 'destroy'])->name('downloads.destroy');
    Route::delete('/users/{id}', [DebridDownloadController::class, 'deleteUser'])->name('users.destroy');
    Route::get('/rd-status', [DebridDownloadController::class, 'rdStatus'])->name('rd.status');
});

// Direct Download & Stream Endpoints (accessible for IDM / Download managers)
Route::match(['get', 'head'], '/dl/{uuid}', [DebridDownloadController::class, 'downloadFile'])->name('downloads.file');
Route::match(['get', 'head'], '/api/indir/{link?}', [DebridApiController::class, 'directDownload'])->where('link', '.*')->name('api.indir');

// Cron Job Endpoint for 7-day cache cleanup
Route::match(['get', 'post'], '/cron/clean-cache', [DebridDownloadController::class, 'cleanExpiredCache'])->name('cron.clean_cache');
Route::match(['get', 'post'], '/api/cron/clean-cache', [DebridDownloadController::class, 'cleanExpiredCache'])->name('cron.api_clean_cache');
