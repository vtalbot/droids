<?php

namespace App\Http\Controllers\Factory;

use App\GitHub\GitHub;
use App\GitHub\RepositorySync;
use App\GitHub\RepositoryWriter;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\PullRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class GitHubController extends Controller
{
    /** Repositories the App is installed on, for the Connect repo picker. */
    public function repos(GitHub $github): JsonResponse
    {
        if (! $github->configured()) {
            return response()->json(['configured' => false, 'repos' => []]);
        }

        $repos = $this->github(fn () => Cache::remember('github.repos', now()->addMinutes(2), function () use ($github) {
            $out = [];
            foreach (GithubInstallation::whereNull('suspended_at')->get() as $installation) {
                $list = $github->forInstallation($installation->installation_id)
                    ->get('/installation/repositories', ['per_page' => 100])->json('repositories') ?? [];
                foreach ($list as $r) {
                    $out[] = ['repo' => $r['full_name'], 'private' => (bool) $r['private'], 'installation' => $installation->installation_id];
                }
            }

            return $out;
        }));

        $linked = Project::pluck('repo')->all();

        return response()->json([
            'configured' => true,
            'repos' => array_values(array_filter($repos, fn ($r) => ! in_array($r['repo'], $linked, true))),
        ]);
    }

    public function sync(Project $project, RepositorySync $sync): JsonResponse
    {
        $this->github(fn () => $sync->sync($project));

        return $this->state();
    }

    public function merge(Project $project, int $number, RepositoryWriter $writer): JsonResponse
    {
        $pr = $this->pull($project, $number);
        if ($pr->state !== 'approved') {
            return response()->json(['message' => 'Only approved pull requests can be merged from here.'], 422);
        }
        $this->github(fn () => $writer->merge($project, $pr));

        return $this->state();
    }

    public function rerun(Project $project, int $number, RepositoryWriter $writer): JsonResponse
    {
        $count = $this->github(fn () => $writer->rerunFailed($project, $this->pull($project, $number)));

        return $this->state(['rerun' => $count]);
    }

    private function pull(Project $project, int $number): PullRequest
    {
        return PullRequest::where('number', $number)
            ->whereHas('task', fn ($q) => $q->where('project_id', $project->id))
            ->firstOrFail();
    }
}
