<?php

use App\GitHub\RepositorySync;
use App\Models\Project;
use Illuminate\Foundation\Inspiring;
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
