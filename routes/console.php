<?php

use App\Cloud\CloudSync;
use App\Factory\Autopilot;
use App\GitHub\RepositorySync;
use App\Models\CloudConnection;
use App\Models\Project;
use App\Runs\RunQueue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('github:sync {project? : Project slug; all linked projects when omitted}', function (RepositorySync $sync) {
    $query = Project::whereNotNull('github_installation_id');
    if ($slug = $this->argument('project')) {
        $query->where('slug', $slug);
    }

    $failed = 0;
    foreach ($query->get() as $project) {
        try {
            $sync->sync($project);
            $this->info("Synced {$project->repo}");
        } catch (Throwable $e) {
            report($e);
            $failed++;
            $this->error("{$project->repo}: {$e->getMessage()}");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Pull issues, milestones, pull requests and checks from GitHub');

Schedule::command('github:sync')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('droids:sweep', function (RunQueue $runs) {
    $count = $runs->sweep();
    $this->info($count ? "Marked {$count} stalled run(s) as failed." : 'No stalled runs.');
})->purpose('Fail droid runs whose worker stopped reporting');

Schedule::command('droids:sweep')->everyFiveMinutes();

// Projects with autopilot on: triage Ideas, hand Ready cards to idle builders.
Artisan::command('droids:autopilot', function (Autopilot $autopilot) {
    $done = $autopilot->tick();
    $this->info("Started {$done['triage']} triage run(s), assigned {$done['assigned']} card(s), {$done['unblock']} unblock look(s).");
})->purpose('Triage Ideas and give Ready cards to idle builders on projects that turned it on');

Schedule::command('droids:autopilot')->everyMinute()->withoutOverlapping();

// Refresh linked Laravel Cloud apps for the Cloud panel.
Artisan::command('cloud:sync', function (CloudSync $sync) {
    if (! CloudConnection::current()) {
        return;
    }
    foreach (Project::whereNotNull('cloud_application_id')->get() as $project) {
        try {
            $sync->sync($project);
        } catch (RequestException|ConnectionException $e) {
            $this->warn("{$project->name}: {$e->getMessage()}");
        }
    }
})->purpose('Refresh Laravel Cloud snapshots for linked projects');

Schedule::command('cloud:sync')->everyFiveMinutes()->withoutOverlapping();
