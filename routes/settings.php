<?php

use App\Http\Controllers\Settings\CloudController;
use App\Http\Controllers\Settings\GitHubController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ServiceController;
use App\Http\Controllers\Settings\WorkerController;
/* @chisel-password-confirmation */
use Illuminate\Auth\Middleware\RequirePassword;
/* @end-chisel-password-confirmation */
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        /* @chisel-password-confirmation */
        ->middleware(RequirePassword::class)
        /* @end-chisel-password-confirmation */
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/github', [GitHubController::class, 'edit'])->name('github.edit');
    Route::get('settings/github/create', [GitHubController::class, 'create'])->name('github.app.create');
    Route::get('settings/github/created', [GitHubController::class, 'created'])->name('github.app.created');
    Route::get('settings/github/installed', [GitHubController::class, 'installed'])->name('github.installed');
    Route::post('settings/github/sync', [GitHubController::class, 'sync'])->name('github.sync');

    Route::get('settings/cloud', [CloudController::class, 'edit'])->name('cloud.edit');
    Route::post('settings/cloud', [CloudController::class, 'store'])->name('cloud.store');
    Route::delete('settings/cloud', [CloudController::class, 'destroy'])->name('cloud.destroy');

    Route::get('settings/services', [ServiceController::class, 'edit'])->name('services.edit');
    Route::post('settings/services', [ServiceController::class, 'store'])->name('services.store');
    Route::delete('settings/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::get('settings/workers', [WorkerController::class, 'edit'])->name('workers.edit');
    Route::post('settings/workers', [WorkerController::class, 'store'])->name('workers.store');
    Route::delete('settings/workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
});

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
