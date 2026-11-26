<?php

use App\Http\Controllers\Factory;
use App\Http\Controllers\FactoryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hangar')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('{view}', FactoryController::class)
        ->whereIn('view', FactoryController::VIEWS)
        ->name('factory');

    // JSON writes from the factory UI. Each answers with the fresh store.
    Route::prefix('factory')->name('factory.')->group(function () {
        Route::get('state', Factory\StateController::class)->name('state');

        Route::post('projects', [Factory\ProjectController::class, 'store'])->name('projects.store');
        Route::post('projects/{project:slug}/tasks', [Factory\TaskController::class, 'store'])->name('tasks.store');
        Route::patch('projects/{project:slug}/tasks/{number}', [Factory\TaskController::class, 'update'])->whereNumber('number')->name('tasks.update');
        Route::post('projects/{project:slug}/tasks/{number}/assign', [Factory\TaskController::class, 'assign'])->whereNumber('number')->name('tasks.assign');
        Route::post('projects/{project:slug}/milestones', [Factory\RoadmapController::class, 'storeMilestone'])->name('milestones.store');
        Route::post('milestones/{milestone}/features', [Factory\RoadmapController::class, 'storeFeature'])->name('features.store');

        Route::post('droids', [Factory\DroidController::class, 'store'])->name('droids.store');
        Route::patch('droids/{droid:slug}', [Factory\DroidController::class, 'update'])->name('droids.update');

        Route::post('subscriptions', [Factory\SubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::patch('subscriptions/{subscription:slug}', [Factory\SubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::put('schedules/{provider}', [Factory\SubscriptionController::class, 'schedule'])->name('schedules.update');
    });
});

require __DIR__.'/settings.php';
