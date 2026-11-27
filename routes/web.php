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
        Route::patch('projects/{project:slug}', [Factory\ProjectController::class, 'update'])->name('projects.update');
        Route::post('projects/{project:slug}/tasks', [Factory\TaskController::class, 'store'])->name('tasks.store');
        Route::patch('projects/{project:slug}/tasks/{number}', [Factory\TaskController::class, 'update'])->whereNumber('number')->name('tasks.update');
        Route::post('projects/{project:slug}/tasks/{number}/assign', [Factory\TaskController::class, 'assign'])->whereNumber('number')->name('tasks.assign');
        Route::post('projects/{project:slug}/milestones', [Factory\RoadmapController::class, 'storeMilestone'])->name('milestones.store');
        Route::post('milestones/{milestone}/features', [Factory\RoadmapController::class, 'storeFeature'])->name('features.store');

        Route::get('cloud/applications', [Factory\CloudController::class, 'applications'])->name('cloud.applications');
        Route::post('projects/{project:slug}/cloud', [Factory\CloudController::class, 'link'])->name('cloud.link');
        Route::get('projects/{project:slug}/domain', [Factory\DomainController::class, 'show'])->name('domain.show');
        Route::put('projects/{project:slug}/domain', [Factory\DomainController::class, 'update'])->name('domain.update');
        Route::post('projects/{project:slug}/dns', [Factory\DomainController::class, 'storeRecord'])->name('dns.store');
        Route::delete('projects/{project:slug}/dns/{record}', [Factory\DomainController::class, 'destroyRecord'])->name('dns.destroy');
        Route::post('projects/{project:slug}/cloud/applications', [Factory\CloudController::class, 'create'])->name('cloud.create');
        Route::delete('projects/{project:slug}/cloud', [Factory\CloudController::class, 'unlink'])->name('cloud.unlink');
        Route::post('projects/{project:slug}/cloud/refresh', [Factory\CloudController::class, 'refresh'])->name('cloud.refresh');
        Route::put('projects/{project:slug}/cloud/email', [Factory\CloudController::class, 'email'])->name('cloud.email');
        Route::get('projects/{project:slug}/cloud/environments/{environment}/variables', [Factory\CloudVariableController::class, 'index'])->name('cloud.variables');
        Route::put('projects/{project:slug}/cloud/environments/{environment}/variables', [Factory\CloudVariableController::class, 'update'])->name('cloud.variables.update');
        Route::post('projects/{project:slug}/cloud/environments/{environment}/variables/delete', [Factory\CloudVariableController::class, 'destroy'])->name('cloud.variables.destroy');
        Route::get('projects/{project:slug}/cloud/domains', [Factory\CloudDomainController::class, 'index'])->name('cloud.domains');
        Route::post('projects/{project:slug}/cloud/domains', [Factory\CloudDomainController::class, 'store'])->name('cloud.domains.store');
        Route::post('projects/{project:slug}/cloud/domains/{domain}/verify', [Factory\CloudDomainController::class, 'verify'])->name('cloud.domains.verify');
        Route::post('projects/{project:slug}/cloud/domains/{domain}/dns', [Factory\CloudDomainController::class, 'dns'])->name('cloud.domains.dns');
        Route::delete('projects/{project:slug}/cloud/domains/{domain}', [Factory\CloudDomainController::class, 'destroy'])->name('cloud.domains.destroy');
        Route::post('projects/{project:slug}/suggest', [Factory\SuggestController::class, 'store'])->name('suggest');
        Route::get('projects/{project:slug}/email', [Factory\EmailController::class, 'show'])->name('email.show');
        Route::post('projects/{project:slug}/email/setup', [Factory\EmailController::class, 'setup'])->name('email.setup');
        Route::put('projects/{project:slug}/email/receiving', [Factory\EmailController::class, 'receiving'])->name('email.receiving');
        Route::post('projects/{project:slug}/email/dns', [Factory\EmailController::class, 'dns'])->name('email.dns');
        Route::post('projects/{project:slug}/email/verify', [Factory\EmailController::class, 'verify'])->name('email.verify');
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
        Route::delete('droids/{droid:slug}', [Factory\DroidController::class, 'destroy'])->name('droids.destroy');
        Route::post('droids/{droid:slug}/retry', [Factory\DroidController::class, 'retry'])->name('droids.retry');
        Route::post('droids/{droid:slug}/release', [Factory\DroidController::class, 'release'])->name('droids.release');
        Route::post('droids/{droid:slug}/ask', [Factory\ChatController::class, 'ask'])->name('droids.ask');
        Route::patch('chat/{message}', [Factory\ChatController::class, 'update'])->name('chat.update');

        Route::post('subscriptions', [Factory\SubscriptionController::class, 'store'])->name('subscriptions.store');
        Route::patch('subscriptions/{subscription:slug}', [Factory\SubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::put('schedules/{provider}', [Factory\SubscriptionController::class, 'schedule'])->name('schedules.update');
    });
});

require __DIR__.'/settings.php';
