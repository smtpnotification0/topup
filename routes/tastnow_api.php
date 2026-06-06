<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TastnowSyncController;

Route::prefix('tastnow')->middleware('tastnow.auth')->group(function () {
    Route::post('sync-product',   [TastnowSyncController::class, 'syncProduct']);
    Route::post('order-callback', [TastnowSyncController::class, 'orderCallback']);
});
