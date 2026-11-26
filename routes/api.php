<?php

use App\Http\Controllers\Api\WorkerController;
use Illuminate\Support\Facades\Route;

// Endpoints for `php artisan droids:work`, authenticated with a worker token.
Route::prefix('worker')->middleware(['worker', 'throttle:120,1'])->group(function () {
    Route::post('claim', [WorkerController::class, 'claim']);
    Route::post('runs/{run}/progress', [WorkerController::class, 'progress']);
    Route::post('runs/{run}/token', [WorkerController::class, 'token']);
    Route::post('runs/{run}/finish', [WorkerController::class, 'finish']);
});
