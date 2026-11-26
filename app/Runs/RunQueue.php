<?php

namespace App\Runs;

use App\GitHub\GitHub;
use App\GitHub\RepositoryWriter;
use App\Models\Activity;
use App\Models\Droid;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The life of a droid run: queued when a builder is put on a task, claimed by
 * a worker on a subscription the schedule allows, then finished with a pushed
 * branch (and a pull request), a failure, or a usage limit that sends it back
 * to the queue for another subscription.
 */
class RunQueue
{
    /** How many times a run goes back to the queue after hitting usage limits. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private SubscriptionPicker $picker,
        private GitHub $github,
        private RepositoryWriter $writer,
        private Prompt $prompt,
    ) {}

    public function queue(Droid $droid, Task $task): Run
    {
        $this->cancelFor($droid, 'Reassigned');

        return Run::create([
            'droid_id' => $droid->id,
            'task_id' => $task->id,
            'kind' => 'build',
            'status' => 'queued',
            'branch' => 'droids/'.$task->number.'-'.Str::limit(Str::slug($task->title), 40, ''),
        ]);
    }

    public function cancelFor(Droid $droid, string $reason): void
    {
        Run::where('droid_id', $droid->id)->whereIn('status', Run::ACTIVE)
            ->update(['status' => 'cancelled', 'error' => $reason, 'finished_at' => now()]);
    }

    /**
     * Hand the oldest runnable job to a worker, or null when there is nothing to do.
     *
     * @param  list<string>  $signedIn  subscription slugs the worker has a CLI login for
     * @return array<string, mixed>|null
     */
    public function claim(Worker $worker, array $signedIn): ?array
    {
        $worker->forceFill(['last_seen_at' => now(), 'subscriptions' => $signedIn])->save();

        $runs = Run::with(['droid', 'task.project.installation'])->where('status', 'queued')->orderBy('id')->limit(20)->get();
        foreach ($runs as $run) {
            $subscription = $this->picker->pick($run->droid->provider, $signedIn);
            if (! $subscription || ! $run->task->project->isLinked()) {
                continue;
            }

            $claimed = Run::whereKey($run->id)->where('status', 'queued')->update([
                'status' => 'running',
                'worker_id' => $worker->id,
                'subscription_id' => $subscription->id,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'attempts' => $run->attempts + 1,
                'error' => null,
            ]);
            if (! $claimed) {
                continue; // another worker got it first
            }

            $run->refresh()->load(['droid', 'task.project.installation', 'subscription']);
            $run->droid->update(['status' => 'working', 'step' => "Cloning {$run->task->project->repo}", 'progress' => 0.05]);

            return $this->payload($run, $subscription);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(Run $run, Subscription $subscription): array
    {
        $project = $run->task->project;
        $installation = $project->installation->installation_id ?? 0;

        return [
            'id' => $run->id,
            'repo' => $project->repo,
            'branch' => $run->branch,
            'clone_token' => $this->github->freshInstallationToken($installation),
            'provider' => $run->droid->provider,
            'model' => $this->model($run->droid),
            'subscription' => [
                'slug' => $subscription->slug,
                'name' => $subscription->name,
                'api_key' => $subscription->api_key,
            ],
            'prompt' => $this->prompt->build($run),
            'commit_message' => "feat: {$run->task->title} (#{$run->task->number})",
            'droid' => ['name' => $run->droid->name, 'unit' => $run->droid->unit],
        ];
    }

    /** Map the model label shown in the UI to the CLI's model name. */
    private function model(Droid $droid): string
    {
        if ($droid->provider === 'claude') {
            return match (true) {
                str_starts_with($droid->model, 'Opus') => 'opus',
                str_starts_with($droid->model, 'Haiku') => 'haiku',
                default => 'sonnet',
            };
        }

        return Str::lower($droid->model);
    }

    /** A new push token for a run that is still going. */
    public function token(Run $run): string
    {
        return $this->github->freshInstallationToken($run->task->project->installation->installation_id ?? 0);
    }

    public function progress(Run $run, ?string $step, ?float $progress, ?string $log): void
    {
        if ($log !== null && $log !== '') {
            $run->appendLog($log);
        }
        $run->heartbeat_at = now();
        $run->save();
        $run->worker?->forceFill(['last_seen_at' => now()])->save();

        $run->droid->update(array_filter([
            'step' => $step !== null ? Str::limit($step, 180) : null,
            'progress' => $progress !== null ? max(0.0, min(1.0, $progress)) : null,
            'minutes' => (int) $run->started_at?->diffInMinutes(now(), true),
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array{status: string, summary?: string|null, error?: string|null, pushed?: bool, cost?: float|int|null, tokens_in?: int|null, tokens_out?: int|null, limited_until?: string|null, log?: string|null}  $result
     */
    public function finish(Run $run, array $result): Run
    {
        return DB::transaction(function () use ($run, $result) {
            $run->load(['droid', 'task.project', 'subscription']);
            if (! empty($result['log'])) {
                $run->appendLog($result['log']);
            }
            $run->fill([
                'summary' => $result['summary'] ?? null,
                'cost' => (float) ($result['cost'] ?? 0),
                'tokens_in' => (int) ($result['tokens_in'] ?? 0),
                'tokens_out' => (int) ($result['tokens_out'] ?? 0),
                'heartbeat_at' => now(),
            ]);
            $droid = $run->droid;
            $droid->cost = $droid->cost + $run->cost;

            match ($result['status']) {
                'limited' => $this->limited($run, $result['limited_until'] ?? null, $result['error'] ?? null),
                'succeeded' => $this->succeeded($run, (bool) ($result['pushed'] ?? false)),
                default => $this->failed($run, $result['error'] ?? 'The run failed.'),
            };

            $droid->minutes = (int) $run->started_at?->diffInMinutes(now(), true);
            $droid->save();
            $run->save();

            return $run;
        });
    }

    private function limited(Run $run, ?string $until, ?string $error): void
    {
        $run->subscription?->forceFill(['limited_until' => $until ? now()->parse($until) : now()->addHour()])->save();

        if ($run->attempts >= self::MAX_ATTEMPTS) {
            $this->failed($run, $error ?: 'Every subscription for this engine is at its usage limit.');

            return;
        }

        $run->fill(['status' => 'queued', 'worker_id' => null, 'subscription_id' => null, 'started_at' => null, 'error' => $error]);
        $run->droid->fill(['status' => 'working', 'step' => "{$run->subscription?->name} hit its limit, waiting for another subscription", 'progress' => 0]);
    }

    private function succeeded(Run $run, bool $pushed): void
    {
        $run->fill(['status' => 'succeeded', 'finished_at' => now()]);
        $droid = $run->droid;
        $task = $run->task;

        if (! $pushed) {
            $droid->fill(['status' => 'needs', 'step' => Str::limit('Finished without changes: '.($run->summary ?: 'nothing to commit'), 180), 'progress' => 1]);
            Activity::create(['droid_id' => $droid->id, 'text' => "Finished #{$task->number} without changes"]);

            return;
        }

        $body = "Closes #{$task->number}\n\n".($run->summary ?: '')."\n\n---\nBuilt by {$droid->unit} {$droid->name} on {$run->subscription?->name}.";
        $pr = $this->writer->openPullRequest($task->project, $task, (string) $run->branch, trim($body));
        $run->pr_number = $pr?->number;

        $task->update(['column' => 'review']);
        $droid->fill(['status' => 'idle', 'task_id' => null, 'step' => '', 'progress' => 0]);
        Activity::create(['droid_id' => $droid->id, 'text' => $pr ? "Opened PR #{$pr->number} for #{$task->number} {$task->title}" : "Pushed {$run->branch} for #{$task->number}"]);
    }

    private function failed(Run $run, string $error): void
    {
        $run->fill(['status' => 'failed', 'error' => Str::limit($error, 2000), 'finished_at' => now()]);
        $run->droid->fill(['status' => 'blocked', 'step' => Str::limit($error, 180)]);
        Activity::create(['droid_id' => $run->droid_id, 'text' => "Blocked on #{$run->task->number}: ".Str::limit($error, 120)]);
    }

    /** Fail runs whose worker stopped reporting (closed laptop, crash). */
    public function sweep(): int
    {
        $stale = Run::with(['droid', 'task'])->where('status', 'running')
            ->where('heartbeat_at', '<', now()->subMinutes((int) config('droids.run_timeout')))->get();

        foreach ($stale as $run) {
            DB::transaction(function () use ($run) {
                $this->failed($run, 'The worker stopped reporting. Start it again, then reassign the droid.');
                $run->droid->save();
                $run->save();
            });
        }

        return $stale->count();
    }
}
