<?php

namespace App\GitHub;

use App\Models\Droid;
use App\Models\Feature;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Pulls a linked repository's issues, milestones, pull requests, checks and
 * reviews into the factory. Webhooks call the single-item upserts directly.
 */
class RepositorySync
{
    public function __construct(private GitHub $github) {}

    public function sync(Project $project): void
    {
        [$owner, $repo] = $project->ownerAndName();
        $http = $this->github->forProject($project);

        foreach ($http->get("/repos/{$owner}/{$repo}/milestones", ['state' => 'all', 'per_page' => 100])->json() as $m) {
            $this->upsertMilestone($project, $m);
        }

        for ($page = 1; $page <= 3; $page++) {
            $issues = $http->get("/repos/{$owner}/{$repo}/issues", ['state' => 'all', 'per_page' => 100, 'sort' => 'updated', 'page' => $page])->json();
            foreach ($issues as $issue) {
                if (! isset($issue['pull_request'])) {
                    $this->upsertIssue($project, $issue);
                }
            }
            if (count($issues) < 100) {
                break;
            }
        }

        $pulls = $http->get("/repos/{$owner}/{$repo}/pulls", ['state' => 'all', 'per_page' => 50, 'sort' => 'updated', 'direction' => 'desc'])->json();
        foreach ($pulls as $pull) {
            $this->upsertPull($project, $pull, withDetails: $pull['state'] === 'open');
        }

        $this->ensureActiveMilestone($project);
        $project->forceFill(['synced_at' => now()])->save();
    }

    /** @param array<string, mixed> $m */
    public function upsertMilestone(Project $project, array $m): Milestone
    {
        $milestone = Milestone::firstOrNew(['project_id' => $project->id, 'github_number' => $m['number']]);
        $milestone->fill([
            'name' => $m['title'],
            'goal' => $m['description'] ?: $milestone->goal,
            'due_on' => isset($m['due_on']) ? Carbon::parse($m['due_on'])->toDateString() : null,
        ]);
        if ($m['state'] === 'closed') {
            $milestone->state = 'done';
        } elseif (! $milestone->exists || $milestone->state === 'done') {
            $milestone->state = 'planned';
        }
        if (! $milestone->exists) {
            $milestone->position = (int) $project->milestones()->max('position') + 1;
        }
        $milestone->save();

        return $milestone;
    }

    /** @param array<string, mixed> $issue */
    public function upsertIssue(Project $project, array $issue): Task
    {
        $task = Task::firstOrNew(['project_id' => $project->id, 'number' => $issue['number']]);
        $closed = $issue['state'] === 'closed';

        if (! $task->exists) {
            $task->column = $closed ? 'done' : 'backlog';
            $task->position = (int) $project->tasks()->max('position') + 1;
        } elseif ($closed) {
            $task->column = 'done';
        } elseif ($task->column === 'done') {
            $task->column = 'backlog'; // reopened
        }

        // "Blocked by #12" in the issue body links the cards; an edit without it keeps links set here.
        if (($blockers = array_values(array_diff(Task::blockersIn($issue['body'] ?? null), [(int) $issue['number']]))) !== []) {
            $task->blocked_by = $blockers;
        }
        $task->fill([
            'title' => $issue['title'],
            'labels' => array_values(array_map(fn ($l) => is_array($l) ? $l['name'] : $l, $issue['labels'] ?? [])),
            'github_id' => $issue['id'],
            'state' => $issue['state'],
            'url' => $issue['html_url'] ?? null,
        ]);

        if (! $task->feature_id && isset($issue['milestone']['number'])) {
            $milestone = $this->upsertMilestone($project, $issue['milestone']);
            $task->feature_id = Feature::firstOrCreate(
                ['milestone_id' => $milestone->id, 'name' => 'Unsorted'],
                ['position' => 999],
            )->id;
        }

        $task->save();

        if ($closed) {
            Droid::where('task_id', $task->id)->update(['status' => 'idle', 'task_id' => null, 'step' => '', 'progress' => 0]);
        }

        return $task;
    }

    /** @param array<string, mixed> $pull */
    public function upsertPull(Project $project, array $pull, bool $withDetails = true): ?PullRequest
    {
        $task = $this->taskFor($project, $pull);
        if (! $task) {
            return null;
        }

        [$owner, $repo] = $project->ownerAndName();
        $http = $this->github->forProject($project);

        if ($withDetails && ! isset($pull['additions'])) {
            $pull = $http->get("/repos/{$owner}/{$repo}/pulls/{$pull['number']}")->json();
        }

        $pr = PullRequest::firstOrNew(['task_id' => $task->id, 'number' => $pull['number']]);
        $reviews = $pr->reviews ?? [];
        $checks = $pr->checks ?? [];

        if ($withDetails) {
            // Reviewer droids post as comments; their verdicts live only here, so keep them.
            $droidReviews = array_values(array_filter($reviews, fn (array $r) => ! empty($r['droid'])));
            $reviews = [...$this->reviews($http->get("/repos/{$owner}/{$repo}/pulls/{$pull['number']}/reviews", ['per_page' => 100])->json()), ...$droidReviews];
            $checks = $this->checks($http->get("/repos/{$owner}/{$repo}/commits/{$pull['head']['sha']}/check-runs", ['per_page' => 100])->json('check_runs') ?? []);
        }

        $pr->fill([
            'github_id' => $pull['id'],
            'title' => $pull['title'],
            'branch' => $pull['head']['ref'],
            'head_sha' => $pull['head']['sha'],
            'url' => $pull['html_url'] ?? null,
            'state' => $this->prState($pull, $reviews),
            'additions' => $pull['additions'] ?? $pr->additions ?? 0,
            'deletions' => $pull['deletions'] ?? $pr->deletions ?? 0,
            'commits' => $pull['commits'] ?? $pr->commits ?? 0,
            'files' => $pull['changed_files'] ?? $pr->files ?? 0,
            'checks' => $checks,
            'reviews' => $reviews,
        ])->save();

        if ($pr->state === 'merged') {
            $task->update(['column' => 'done']);
        } elseif ($pull['state'] === 'open' && in_array($task->column, ['backlog', 'ready', 'progress'], true) && ! $pull['draft']) {
            $task->update(['column' => 'review']);
        }

        return $pr;
    }

    /** Re-read one pull request (after a review or a check run changed). */
    public function refreshPull(Project $project, int $number): ?PullRequest
    {
        [$owner, $repo] = $project->ownerAndName();

        return $this->upsertPull($project, $this->github->forProject($project)->get("/repos/{$owner}/{$repo}/pulls/{$number}")->json());
    }

    /** @param array<string, mixed> $pull */
    private function taskFor(Project $project, array $pull): ?Task
    {
        $numbers = [];
        if (preg_match_all('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#(\d+)/i', (string) ($pull['body'] ?? ''), $m)) {
            $numbers = array_map('intval', $m[1]);
        }
        if (preg_match('/(?:^|\/)(\d+)(?:-[\w-]*)?$/', (string) ($pull['head']['ref'] ?? ''), $m)) {
            $numbers[] = (int) $m[1];
        }

        foreach ($numbers as $n) {
            if ($task = $project->tasks()->where('number', $n)->first()) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @return list<array{name: string, state: string, dur?: string, job?: int|null, url?: string|null}>
     */
    private function checks(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            $state = match (true) {
                $run['status'] === 'queued' || $run['status'] === 'pending' || $run['status'] === 'waiting' => 'pending',
                $run['status'] !== 'completed' => 'running',
                in_array($run['conclusion'], ['success', 'neutral', 'skipped'], true) => 'pass',
                default => 'fail',
            };
            $check = ['name' => (string) $run['name'], 'state' => $state];
            if ($state === 'pass' || $state === 'fail') {
                $secs = Carbon::parse($run['started_at'])->diffInSeconds(Carbon::parse($run['completed_at']), true);
                $check['dur'] = $secs >= 60 ? sprintf('%dm %02ds', intdiv((int) $secs, 60), (int) $secs % 60) : ((int) $secs).'s';
            }
            $check['url'] = $run['html_url'] ?? null;
            $check['job'] = preg_match('#/job/(\d+)#', (string) ($run['details_url'] ?? ''), $m) ? (int) $m[1] : null;
            $out[] = $check;
        }

        return $out;
    }

    /**
     * Latest review per reviewer, plus reviewers still requested.
     *
     * @param  array<int, array<string, mixed>>  $reviews
     * @return list<array{by: string, state: string}>
     */
    private function reviews(array $reviews): array
    {
        $latest = [];
        foreach ($reviews as $r) {
            $state = match ($r['state']) {
                'APPROVED' => 'approved',
                'CHANGES_REQUESTED' => 'changes',
                'COMMENTED' => 'commented',
                default => null,
            };
            if ($state && ($state !== 'commented' || ! isset($latest[$r['user']['login']]))) {
                $latest[$r['user']['login']] = $state;
            }
        }

        return array_map(fn ($by, $state) => ['by' => (string) $by, 'state' => $state], array_keys($latest), $latest);
    }

    /**
     * @param  array<string, mixed>  $pull
     * @param  list<array{by: string, state: string}>  $reviews
     */
    private function prState(array $pull, array $reviews): string
    {
        $states = array_column($reviews, 'state');

        return match (true) {
            ! empty($pull['merged_at']) || ! empty($pull['merged']) => 'merged',
            $pull['state'] === 'closed' => 'closed',
            (bool) $pull['draft'] => 'draft',
            in_array('changes', $states, true) => 'changes',
            in_array('approved', $states, true) => 'approved',
            default => 'open',
        };
    }

    private function ensureActiveMilestone(Project $project): void
    {
        if ($project->milestones()->where('state', 'active')->exists()) {
            return;
        }
        $project->milestones()->where('state', 'planned')
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('position')
            ->first()?->update(['state' => 'active']);
    }
}
