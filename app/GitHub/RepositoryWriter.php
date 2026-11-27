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
    public function createIssue(Project $project, string $title, array $labels = [], ?Milestone $milestone = null, ?string $body = null): Task
    {
        [$owner, $repo] = $project->ownerAndName();
        $issue = $this->github->forProject($project)->post("/repos/{$owner}/{$repo}/issues", array_filter([
            'title' => $title,
            'body' => $body ?: null,
            'labels' => $labels ?: null,
            'milestone' => $milestone?->github_number,
        ]))->json();

        return $this->sync->upsertIssue($project, $issue);
    }

    public function issueBody(Project $project, int $number): string
    {
        [$owner, $repo] = $project->ownerAndName();

        return trim((string) $this->github->forProject($project)->get("/repos/{$owner}/{$repo}/issues/{$number}")->json('body'));
    }

    /** Add a section to the end of an issue's description. */
    public function appendToIssue(Project $project, int $number, string $text): void
    {
        [$owner, $repo] = $project->ownerAndName();
        $body = $this->issueBody($project, $number);
        $this->github->forProject($project)->patch("/repos/{$owner}/{$repo}/issues/{$number}", [
            'body' => trim($body."\n\n".$text),
        ])->throw();
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

    /**
     * Post a review on a pull request, with optional inline comments on the new side of the diff.
     *
     * @param  list<array{path: string, line: int, body: string}>  $comments
     */
    public function createReview(Project $project, int $number, string $body, array $comments): void
    {
        [$owner, $repo] = $project->ownerAndName();
        $this->github->forProject($project)->post("/repos/{$owner}/{$repo}/pulls/{$number}/reviews", array_filter([
            'body' => $body,
            'event' => 'COMMENT',
            'comments' => array_map(fn (array $c) => [...$c, 'side' => 'RIGHT'], $comments) ?: null,
        ]))->throw();
    }

    /** Open a pull request for a droid's branch and mirror it on the task. */
    public function openPullRequest(Project $project, Task $task, string $branch, string $body): ?PullRequest
    {
        [$owner, $repo] = $project->ownerAndName();
        $http = $this->github->forProject($project);
        $base = (string) $http->get("/repos/{$owner}/{$repo}")->json('default_branch');

        $pull = $http->post("/repos/{$owner}/{$repo}/pulls", [
            'title' => $task->title,
            'head' => $branch,
            'base' => $base,
            'body' => $body,
        ])->json();

        return $this->sync->upsertPull($project, $pull);
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
