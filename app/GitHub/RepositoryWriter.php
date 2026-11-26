<?php

namespace App\GitHub;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Task;

/** Writes factory actions back to a linked GitHub repository. */
class RepositoryWriter
{
    public function __construct(private GitHub $github, private RepositorySync $sync) {}

    /**
     * Open an issue and mirror it as a task. GitHub assigns the number.
     *
     * @param  list<string>  $labels
     */
    public function createIssue(Project $project, string $title, array $labels = [], ?Milestone $milestone = null): Task
    {
        [$owner, $repo] = $project->ownerAndName();
        $issue = $this->github->forProject($project)->post("/repos/{$owner}/{$repo}/issues", array_filter([
            'title' => $title,
            'labels' => $labels ?: null,
            'milestone' => $milestone?->github_number,
        ]))->json();

        return $this->sync->upsertIssue($project, $issue);
    }

    public function createMilestone(Project $project, string $name, ?string $dueOn, ?string $goal): int
    {
        [$owner, $repo] = $project->ownerAndName();

        return (int) $this->github->forProject($project)->post("/repos/{$owner}/{$repo}/milestones", array_filter([
            'title' => $name,
            'description' => $goal,
            'due_on' => $dueOn ? $dueOn.'T23:59:59Z' : null,
        ]))->json('number');
    }

    public function merge(Project $project, PullRequest $pr): void
    {
        [$owner, $repo] = $project->ownerAndName();
        $this->github->forProject($project)->put("/repos/{$owner}/{$repo}/pulls/{$pr->number}/merge", array_filter([
            'merge_method' => 'squash',
            'sha' => $pr->head_sha,
        ]));
        $this->sync->refreshPull($project, $pr->number);
    }

    /** Re-run the failed GitHub Actions jobs behind a PR's checks. Returns how many were re-run. */
    public function rerunFailed(Project $project, PullRequest $pr): int
    {
        [$owner, $repo] = $project->ownerAndName();
        $http = $this->github->forProject($project);
        $count = 0;
        foreach ($pr->checks ?? [] as $check) {
            if ($check['state'] === 'fail' && ! empty($check['job'])) {
                $http->send('POST', "/repos/{$owner}/{$repo}/actions/jobs/{$check['job']}/rerun");
                $count++;
            }
        }
        if ($count) {
            $this->sync->refreshPull($project, $pr->number);
        }

        return $count;
    }
}
