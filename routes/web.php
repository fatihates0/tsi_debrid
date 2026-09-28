<?php

use App\Http\Controllers\DebridDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DebridDownloadController::class, 'index'])->name('dashboard');
Route::post('/downloads', [DebridDownloadController::class, 'store'])->name('downloads.store');
Route::get('/downloads/ajax-list', [DebridDownloadController::class, 'listAjax'])->name('downloads.ajax_list');
Route::get('/downloads/{uuid}', [DebridDownloadController::class, 'show'])->name('downloads.show');
Route::get('/dl/{uuid}', [DebridDownloadController::class, 'downloadFile'])->name('downloads.file');
Route::delete('/downloads/{uuid}', [DebridDownloadController::class, 'destroy'])->name('downloads.destroy');
Route::get('/rd-status', [DebridDownloadController::class, 'rdStatus'])->name('rd.status');

// IDM & Direct Stream API Endpoint
Route::get('/api/indir/{link?}', [\App\Http\Controllers\Api\DebridApiController::class, 'directDownload'])->where('link', '.*')->name('api.indir');
