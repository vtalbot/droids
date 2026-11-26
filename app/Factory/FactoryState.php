<?php

namespace App\Factory;

use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\GithubApp;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Task;
use App\Models\Worker;
use Illuminate\Support\Collection;

/**
 * Builds the client-side factory store the UI renders from: projects, droids,
 * board issues, PRs, roadmap, subscriptions and schedule, activity and chats.
 * Keys use slugs ("store", "cl07") and "project:number" for tasks.
 */
class FactoryState
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $projects = Project::with(['milestones.features.tasks'])->orderBy('id')->get();
        $droids = Droid::with(['project', 'task'])->orderBy('id')->get();
        $tasks = Task::with(['project', 'builder', 'reviewer'])->orderBy('position')->orderBy('id')->get();

        $slugs = $projects->pluck('slug', 'id');
        $droidSlugs = $droids->pluck('slug', 'id');

        return [
            'projects' => $projects->map(fn (Project $p) => $this->project($p))->values(),
            'droids' => $droids->map(fn (Droid $d) => [
                'id' => $d->slug,
                'unit' => $d->unit,
                'name' => $d->name,
                'provider' => $d->provider,
                'model' => $d->model,
                'role' => $d->role,
                'status' => $d->status,
                'project' => $d->project?->slug,
                'task' => $d->task?->number,
                'step' => $d->step,
                'progress' => $d->progress,
                'minutes' => $d->minutes,
                'cost' => $d->cost,
                'seed' => $d->seed,
            ])->values(),
            'issues' => $tasks->map(fn (Task $t) => array_filter([
                'n' => $t->number,
                'project' => $slugs[$t->project_id],
                'title' => $t->title,
                'col' => $t->column,
                'labels' => $t->labels ?? [],
                'builder' => $t->builder_id ? $droidSlugs[$t->builder_id] : null,
                'reviewer' => $t->reviewer_id ? $droidSlugs[$t->reviewer_id] : null,
                'engine' => $t->engine,
            ], fn ($v) => $v !== null))->values(),
            'prs' => $this->pullRequests($slugs),
            'roadmap' => $projects->mapWithKeys(fn (Project $p) => [$p->slug => $p->milestones->map(fn (Milestone $m) => $this->milestone($m))->values()]),
            'subs' => $this->subscriptions(),
            'paused' => Subscription::where('enabled', false)->pluck('slug')->mapWithKeys(fn ($s) => [$s => true]),
            'schedule' => SubscriptionSchedule::all()->mapWithKeys(fn (SubscriptionSchedule $s) => [$s->provider => $s->grid]),
            'feed' => Activity::latest()->latest('id')->limit(30)->get()->map(fn (Activity $a) => [
                'droid' => $droidSlugs[$a->droid_id],
                'text' => $a->text,
                'min' => (int) $a->created_at?->diffInMinutes(now(), true),
            ])->values(),
            'chats' => $this->chats($droidSlugs),
            'stats' => $projects->filter(fn (Project $p) => $p->stats !== null)->mapWithKeys(fn (Project $p) => [$p->slug => $p->stats]),
            'cloud' => $projects->mapWithKeys(fn (Project $p) => [$p->slug => $p->cloud_snapshot]),
            'github' => ['configured' => GithubApp::current() !== null],
            'runs' => $this->runs($slugs),
            'asks' => Run::whereIn('kind', Run::ASK_KINDS)->whereIn('status', Run::ACTIVE)->get(['topic', 'kind', 'status'])
                ->mapWithKeys(fn (Run $r) => [(string) $r->topic => ['kind' => $r->kind, 'status' => $r->status]]),
            'workers' => [
                'total' => Worker::count(),
                'online' => Worker::where('last_seen_at', '>', now()->subMinutes(2))->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function project(Project $p): array
    {
        $milestone = $p->milestones->firstWhere('state', 'active') ?? $p->milestones->firstWhere('state', 'planned');
        $tasks = $milestone
            ? $milestone->features->flatMap(fn ($f) => $f->tasks)
            : $p->tasks()->get();

        return [
            'id' => $p->slug,
            'name' => $p->name,
            'repo' => $p->repo,
            'board' => $p->board,
            'color' => $p->color,
            'linked' => $p->isLinked(),
            'synced' => $p->synced_at?->diffForHumans(),
            'milestone' => $milestone->name ?? 'No milestone',
            'due' => $milestone?->due_on?->format('M j') ?? '',
            'done' => $tasks->where('column', 'done')->count(),
            'total' => $tasks->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function milestone(Milestone $m): array
    {
        return [
            'id' => 'm'.$m->id,
            'name' => $m->name,
            'due' => $m->due_on?->format('M j') ?? '',
            'state' => $m->state,
            'goal' => $m->goal ?? '',
            'features' => $m->features->map(fn ($f) => [
                'id' => 'f'.$f->id,
                'name' => $f->name,
                'tasks' => $f->tasks->map(fn (Task $t) => array_filter([
                    'n' => $t->number,
                    'title' => $t->title,
                    'done' => $t->column === 'done' ?: null,
                ], fn ($v) => $v !== null))->values(),
            ])->values(),
        ];
    }

    /**
     * The latest run of each task, keyed like PRs ("project:number").
     *
     * @param  Collection<int, string>  $slugs
     * @return array<string, mixed>
     */
    private function runs(Collection $slugs): array
    {
        $out = [];
        $latest = Run::with(['task', 'droid', 'subscription'])->whereIn('id', Run::selectRaw('max(id)')->whereNotNull('task_id')->groupBy('task_id'))->get();
        foreach ($latest as $run) {
            if (! $run->task) {
                continue;
            }
            $out[$slugs[$run->task->project_id].':'.$run->task->number] = [
                'id' => $run->id,
                'status' => $run->status,
                'droid' => $run->droid->slug,
                'subscription' => $run->subscription?->name,
                'branch' => $run->branch,
                'pr' => $run->pr_number,
                'error' => $run->error,
                'cost' => $run->cost,
                'started' => $run->started_at?->diffForHumans(),
                'log' => $run->log ? mb_substr($run->log, -6000) : '',
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, string>  $slugs
     * @return array<string, mixed>
     */
    private function pullRequests(Collection $slugs): array
    {
        $out = [];
        foreach (PullRequest::with('task')->orderBy('id')->get() as $pr) {
            if (! $pr->task) {
                continue;
            }
            $out[$slugs[$pr->task->project_id].':'.$pr->task->number] = [
                'n' => $pr->number,
                'title' => $pr->title,
                'branch' => $pr->branch,
                'state' => $pr->state,
                'add' => $pr->additions,
                'del' => $pr->deletions,
                'commits' => $pr->commits,
                'files' => $pr->files,
                'checks' => $pr->checks ?? [],
                'reviews' => $pr->reviews ?? [],
                'url' => $pr->url,
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function subscriptions(): array
    {
        $workers = Worker::orderBy('name')->get(['name', 'subscriptions']);

        return Subscription::orderBy('id')->get()->map(function (Subscription $s) use ($workers) {
            $on = [];
            foreach ($s->usesApiKey() ? [] : $workers as $w) {
                if (in_array($s->slug, $w->subscriptions ?? [], true)) {
                    $on[] = $w->name;
                }
            }

            return $this->subscription($s, $on);
        })->values()->all();
    }

    /**
     * @param  list<string>  $signedInOn  names of workers that reported a login for this subscription
     * @return array<string, mixed>
     */
    private function subscription(Subscription $s, array $signedInOn): array
    {
        return array_filter([
            'id' => $s->slug,
            'provider' => $s->provider,
            'name' => $s->name,
            'account' => $s->account ?? '',
            'auth' => $signedInOn ? 'Signed in on '.implode(', ', $signedInOn) : ($s->auth ?? ''),
            'api' => $s->usesApiKey(),
            'signedInOn' => $signedInOn,
            'color' => $s->color,
            'windows' => $s->windows ?? [],
            'week' => $s->week ?? [],
            'spend' => $s->spend_cap !== null ? ['used' => $s->spend_used ?? 0.0, 'cap' => $s->spend_cap] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  Collection<int, string>  $droidSlugs
     * @return array<string, list<array<string, mixed>>>
     */
    private function chats(Collection $droidSlugs): array
    {
        $out = [];
        foreach (ChatMessage::orderBy('id')->get() as $m) {
            $out[$m->topic ?? $droidSlugs[$m->droid_id]][] = ['id' => $m->id, 'kind' => $m->kind, ...$m->payload];
        }

        return $out;
    }
}
