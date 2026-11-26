<?php

use App\Http\Controllers\Factory;
use App\Http\Controllers\FactoryController;
use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hangar')->name('home');

Route::post('github/webhook', GitHubWebhookController::class)->name('github.webhook');

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

        Route::get('cloud/applications', [Factory\CloudController::class, 'applications'])->name('cloud.applications');
        Route::post('projects/{project:slug}/cloud', [Factory\CloudController::class, 'link'])->name('cloud.link');
        Route::delete('projects/{project:slug}/cloud', [Factory\CloudController::class, 'unlink'])->name('cloud.unlink');
        Route::post('projects/{project:slug}/cloud/refresh', [Factory\CloudController::class, 'refresh'])->name('cloud.refresh');
        Route::post('projects/{project:slug}/cloud/deploy', [Factory\CloudController::class, 'deploy'])->name('cloud.deploy');
        Route::get('projects/{project:slug}/cloud/deployments/{deployment}/logs', [Factory\CloudController::class, 'deploymentLogs'])->name('cloud.deployment-logs');
        Route::get('projects/{project:slug}/cloud/metrics', [Factory\CloudController::class, 'metrics'])->name('cloud.metrics');

        Route::get('projects/{project:slug}/design', [Factory\DesignController::class, 'index'])->name('design.index');
        Route::delete('projects/{project:slug}/design', [Factory\DesignController::class, 'destroy'])->name('design.destroy');
        Route::put('projects/{project:slug}/memory', [Factory\DesignController::class, 'memory'])->name('memory.update');

        Route::get('github/repos', [Factory\GitHubController::class, 'repos'])->name('github.repos');
        Route::post('projects/{project:slug}/sync', [Factory\GitHubController::class, 'sync'])->name('projects.sync');
        Route::post('projects/{project:slug}/pulls/{number}/merge', [Factory\GitHubController::class, 'merge'])->whereNumber('number')->name('pulls.merge');
        Route::post('projects/{project:slug}/pulls/{number}/rerun', [Factory\GitHubController::class, 'rerun'])->whereNumber('number')->name('pulls.rerun');

        Route::post('droids', [Factory\DroidController::class, 'store'])->name('droids.store');
        Route::patch('droids/{droid:slug}', [Factory\DroidController::class, 'update'])->name('droids.update');
        Route::post('droids/{droid:slug}/ask', [Factory\ChatController::class, 'ask'])->name('droids.ask');
        Route::patch('chat/{message}', [Factory\ChatController::class, 'update'])->name('chat.update');

        Route::post('subscriptions', [Factory\SubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::patch('subscriptions/{subscription:slug}', [Factory\SubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::put('schedules/{provider}', [Factory\SubscriptionController::class, 'schedule'])->name('schedules.update');
    });
});

require __DIR__.'/settings.php';
