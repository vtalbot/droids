<?php

namespace App\Runs;

use App\GitHub\GitHub;
use App\GitHub\RepositoryWriter;
use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\Project;
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

    /** How many times a reviewer's requested changes go back to the builder before a person steps in. */
    public const MAX_FIX_ROUNDS = 2;

    public function __construct(
        private SubscriptionPicker $picker,
        private GitHub $github,
        private RepositoryWriter $writer,
        private Prompt $prompt,
        private Conversation $conversation,
        private Review $review,
        private Design $design,
        private Memory $memory,
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
        Run::where('droid_id', $droid->id)->whereIn('kind', Run::TASK_KINDS)->whereIn('status', Run::ACTIVE)
            ->update(['status' => 'cancelled', 'error' => $reason, 'finished_at' => now()]);
    }

    /** Queue a review of the task's open pull request, or return null when there is none to review yet. */
    public function queueReview(Droid $reviewer, Task $task): ?Run
    {
        $task->loadMissing(['project', 'pullRequest']);
        $pr = $task->pullRequest;
        if (! $pr || in_array($pr->state, ['merged', 'closed'], true) || ! $task->project->isLinked()) {
            return null;
        }

        $this->cancelFor($reviewer, 'Replaced by a newer review');
        $reviewer->update([
            'status' => 'working', 'task_id' => $task->id, 'project_id' => $task->project_id,
            'step' => "Queued: review PR #{$pr->number}", 'progress' => 0, 'minutes' => 0,
        ]);

        return Run::create([
            'droid_id' => $reviewer->id,
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'kind' => 'review',
            'status' => 'queued',
            'branch' => $pr->branch,
            'pr_number' => $pr->number,
        ]);
    }

    /** Send a reviewer's requested changes back to the builder, within the round limit. */
    private function queueFix(Task $task, Run $review, string $feedback): ?Run
    {
        $builder = $task->builder;
        $rounds = Run::where('task_id', $task->id)->where('kind', 'fix')->whereNotIn('status', ['cancelled'])->count();
        if (! $builder || $rounds >= self::MAX_FIX_ROUNDS) {
            return null;
        }

        $this->cancelFor($builder, 'Replaced by a fix after review');
        $builder->update([
            'status' => 'working', 'task_id' => $task->id, 'project_id' => $task->project_id,
            'step' => "Queued: address the review on PR #{$review->pr_number}", 'progress' => 0, 'minutes' => 0,
        ]);

        return Run::create([
            'droid_id' => $builder->id,
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'kind' => 'fix',
            'status' => 'queued',
            'branch' => $review->branch,
            'pr_number' => $review->pr_number,
            'input' => $feedback,
        ]);
    }

    /**
     * Record what the person said in a conversation and queue the droid's answer.
     * A newer question in the same conversation replaces one still waiting.
     */
    public function ask(Droid $droid, string $kind, ?Project $project, string $text, ?string $intro = null): Run
    {
        $topic = Conversation::topic($kind, $droid, $project);
        $stored = Conversation::storedTopic($topic);

        return DB::transaction(function () use ($droid, $kind, $project, $text, $intro, $topic, $stored) {
            Run::where('topic', $topic)->whereIn('status', Run::ACTIVE)
                ->update(['status' => 'cancelled', 'error' => 'Replaced by a newer question', 'finished_at' => now()]);

            $fresh = ! ChatMessage::where('droid_id', $droid->id)->where('topic', $stored)->exists();
            if ($fresh && $intro !== null && $intro !== '') {
                ChatMessage::create(['droid_id' => $droid->id, 'topic' => $stored, 'kind' => 'agent', 'payload' => ['text' => $intro]]);
            }
            ChatMessage::create(['droid_id' => $droid->id, 'topic' => $stored, 'kind' => 'user', 'payload' => ['text' => $text]]);

            return Run::create([
                'droid_id' => $droid->id,
                // Reflect works from the droid's history and recruiting from the whole factory, so they skip the clone.
                'project_id' => in_array($kind, ['reflect', 'recruit'], true) ? null : ($project->id ?? $droid->project_id),
                'kind' => $kind,
                'topic' => $topic,
                'input' => $text,
                'status' => 'queued',
            ]);
        });
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

        // Conversations go first: someone is waiting on the answer.
        $runs = Run::with(['droid', 'task.project.installation', 'project.installation'])->where('status', 'queued')
            ->orderByRaw("kind in ('build', 'fix', 'review')")->orderBy('id')->limit(20)->get();
        foreach ($runs as $run) {
            $subscription = $this->picker->pick($run->droid->provider, $signedIn);
            if (! $subscription || (! $run->isConversation() && ! $run->task?->project->isLinked())) {
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

            $run->refresh()->load(['droid', 'task.project.installation', 'project.installation', 'subscription']);
            if (! $run->isConversation()) {
                $run->droid->update(['status' => 'working', 'step' => "Cloning {$this->projectOf($run)?->repo}", 'progress' => 0.05]);
            }

            return $this->payload($run, $subscription);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(Run $run, Subscription $subscription): array
    {
        $project = $this->projectOf($run);
        $linked = $project !== null && $project->isLinked();

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'repo' => $linked ? $project->repo : null,
            'branch' => $run->branch,
            'clone_token' => $linked ? $this->github->freshInstallationToken($project->installation->installation_id ?? 0) : null,
            'provider' => $run->droid->provider,
            'model' => $this->model($run->droid),
            'subscription' => [
                'slug' => $subscription->slug,
                'name' => $subscription->name,
                'api_key' => $subscription->api_key,
            ],
            'prompt' => match (true) {
                $run->isBuild() => $this->prompt->build($run),
                $run->isReview() => $this->review->prompt($run),
                default => $this->conversation->prompt($run),
            },
            'commit_message' => match (true) {
                $run->kind === 'fix' && $run->task !== null => "fix: address review on #{$run->task->number}",
                $run->kind === 'build' && $run->task !== null => "feat: {$run->task->title} (#{$run->task->number})",
                default => null,
            },
            'droid' => ['name' => $run->droid->name, 'unit' => $run->droid->unit],
            // Reflect has no project; everything else works next to the project's design.
            'design' => $this->design->files($project),
            'memory' => $this->memory->content($project),
        ];
    }

    /** The project a run works in: its task's for a build, its own for a conversation. */
    private function projectOf(Run $run): ?Project
    {
        return $run->task->project ?? $run->project;
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
        return $this->github->freshInstallationToken($this->projectOf($run)?->installation->installation_id ?? 0);
    }

    public function progress(Run $run, ?string $step, ?float $progress, ?string $log): void
    {
        if ($log !== null && $log !== '') {
            $run->appendLog($log);
        }
        $run->heartbeat_at = now();
        $run->save();
        $run->worker?->forceFill(['last_seen_at' => now()])->save();
        if ($run->isConversation()) {
            return;
        }

        $run->droid->update(array_filter([
            'step' => $step !== null ? Str::limit($step, 180) : null,
            'progress' => $progress !== null ? max(0.0, min(1.0, $progress)) : null,
            'minutes' => (int) $run->started_at?->diffInMinutes(now(), true),
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array{status: string, summary?: string|null, error?: string|null, pushed?: bool, cost?: float|int|null, tokens_in?: int|null, tokens_out?: int|null, limited_until?: string|null, log?: string|null, design?: list<array{path: string, content: string}>|null, memory?: string|null, memory_base?: string|null}  $result
     */
    public function finish(Run $run, array $result): Run
    {
        return DB::transaction(function () use ($run, $result) {
            $run->load(['droid', 'task.project', 'task.pullRequest', 'task.builder', 'task.reviewer', 'project', 'subscription']);
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
            $project = $this->projectOf($run);
            if ($result['status'] === 'succeeded' && $project && isset($result['memory'], $result['memory_base'])) {
                $this->memory->save($project, $droid, $result['memory_base'], $result['memory']);
            }

            match ($result['status']) {
                'limited' => $this->limited($run, $result['limited_until'] ?? null, $result['error'] ?? null),
                'succeeded' => $this->succeeded($run, (bool) ($result['pushed'] ?? false), $result['design'] ?? null),
                default => $this->failed($run, $result['error'] ?? 'The run failed.'),
            };

            if (! $run->isConversation()) {
                $droid->minutes = (int) $run->started_at?->diffInMinutes(now(), true);
            }
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
        if ($run->isConversation()) {
            return;
        }
        $run->droid->fill(['status' => 'working', 'step' => "{$run->subscription?->name} hit its limit, waiting for another subscription", 'progress' => 0]);
    }

    /** @param list<array{path: string, content: string}>|null $design the design folder a designer run sent back */
    private function succeeded(Run $run, bool $pushed, ?array $design = null): void
    {
        $run->fill(['status' => 'succeeded', 'finished_at' => now()]);
        if ($run->isConversation()) {
            $saved = $run->kind === 'design' && $run->project && $design !== null
                ? $this->design->save($run, $run->project, $design)
                : null;
            if ($saved && ($saved['changed'] !== [] || $saved['removed'] !== [])) {
                Activity::create(['droid_id' => $run->droid_id, 'text' => 'Updated the design for '.$run->project->name.': '.implode(', ', [...$saved['changed'], ...$saved['removed']])]);
            }
            $this->conversation->answer($run, $saved);

            return;
        }
        $droid = $run->droid;
        $task = $run->task;
        if (! $task) {
            return;
        }
        if ($run->isReview()) {
            $this->reviewed($run, $task);

            return;
        }

        if (! $pushed) {
            $droid->fill(['status' => 'needs', 'step' => Str::limit('Finished without changes: '.($run->summary ?: 'nothing to commit'), 180), 'progress' => 1]);
            Activity::create(['droid_id' => $droid->id, 'text' => "Finished #{$task->number} without changes"]);

            return;
        }

        if ($run->kind === 'fix') {
            Activity::create(['droid_id' => $droid->id, 'text' => "Pushed review fixes to PR #{$run->pr_number} for #{$task->number}"]);
        } else {
            $body = "Closes #{$task->number}\n\n".($run->summary ?: '')."\n\n---\nBuilt by {$droid->unit} {$droid->name} on {$run->subscription?->name}.";
            $pr = $this->writer->openPullRequest($task->project, $task, (string) $run->branch, trim($body));
            $run->pr_number = $pr?->number;
            Activity::create(['droid_id' => $droid->id, 'text' => $pr ? "Opened PR #{$pr->number} for #{$task->number} {$task->title}" : "Pushed {$run->branch} for #{$task->number}"]);
        }

        $task->update(['column' => 'review']);
        $droid->fill(['status' => 'idle', 'task_id' => null, 'step' => '', 'progress' => 0]);

        // The reviewer takes it from here.
        $reviewer = $task->reviewer;
        if ($reviewer && $reviewer->isNot($droid)) {
            $droid->save();
            $this->queueReview($reviewer, $task->load('pullRequest'));
        }
    }

    /** Post the reviewer's verdict on the pull request, and send requested changes back to the builder. */
    private function reviewed(Run $run, Task $task): void
    {
        $verdict = $this->review->post($run, $task);
        $droid = $run->droid;
        $droid->fill(['status' => 'idle', 'task_id' => null, 'step' => '', 'progress' => 0]);

        if ($verdict['state'] !== 'changes') {
            Activity::create(['droid_id' => $droid->id, 'text' => "Reviewed PR #{$run->pr_number} for #{$task->number}: ".($verdict['state'] === 'approved' ? 'looks good to merge' : 'left comments')]);

            return;
        }

        $fix = $this->queueFix($task, $run, $verdict['feedback']);
        Activity::create(['droid_id' => $droid->id, 'text' => $fix
            ? "Asked for changes on PR #{$run->pr_number}, sent back to {$task->builder?->name}"
            : "Asked for changes on PR #{$run->pr_number} for #{$task->number}: it needs you"]);
    }

    private function failed(Run $run, string $error): void
    {
        $run->fill(['status' => 'failed', 'error' => Str::limit($error, 2000), 'finished_at' => now()]);
        if ($run->isConversation()) {
            $this->conversation->failed($run, Str::limit($error, 500));

            return;
        }
        $run->droid->fill(['status' => 'blocked', 'step' => Str::limit($error, 180)]);
        Activity::create(['droid_id' => $run->droid_id, 'text' => 'Blocked on #'.($run->task->number ?? '?').': '.Str::limit($error, 120)]);
    }

    /** Fail runs whose worker stopped reporting (closed laptop, crash). */
    public function sweep(): int
    {
        $stale = Run::with(['droid', 'task'])->where('status', 'running')
            ->where('heartbeat_at', '<', now()->subMinutes((int) config('droids.run_timeout')))->get();

        foreach ($stale as $run) {
            DB::transaction(function () use ($run) {
                $this->failed($run, $run->isConversation()
                    ? 'The worker stopped reporting. Start it again, then ask again.'
                    : 'The worker stopped reporting. Start it again, then reassign the droid.');
                $run->droid->save();
                $run->save();
            });
        }

        return $stale->count();
    }
}
