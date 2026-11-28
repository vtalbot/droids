<?php

namespace App\Http\Controllers\Factory;

use App\Factory\AssignDroid;
use App\Factory\Autopilot;
use App\GitHub\RepositoryWriter;
use App\Models\Droid;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Runs\Review;
use App\Runs\RunQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /** Create one or more tasks (issues) in a project, optionally under a roadmap feature. */
    public function store(Request $request, Project $project, RepositoryWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.labels' => ['sometimes', 'array', 'max:10'],
            'tasks.*.labels.*' => ['string', 'max:40'],
            'tasks.*.engine' => ['sometimes', 'nullable', Rule::in(['claude', 'codex'])],
            'tasks.*.body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tasks.*.feature' => ['sometimes', 'nullable', 'integer'],
            // Or a milestone, with the feature named: it is made when missing.
            'tasks.*.milestone' => ['sometimes', 'nullable', 'integer'],
            'tasks.*.feature_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if ($project->isLinked()) {
            $numbers = $this->github(fn () => array_map(function (array $t) use ($project, $writer) {
                $feature = $this->featureFor($project, $t);
                $task = $writer->createIssue($project, $t['title'], $t['labels'] ?? [], $feature?->milestone, $t['body'] ?? null);
                $task->update(['engine' => $t['engine'] ?? null, 'feature_id' => $feature->id ?? $task->feature_id]);
                if ($blockers = Task::blockersIn($t['body'] ?? null)) {
                    $task->blockOn($blockers);
                }

                return $task->number;
            }, $data['tasks']));

            return $this->state(['numbers' => $numbers], 201);
        }

        $numbers = DB::transaction(function () use ($data, $project) {
            $next = (int) $project->tasks()->max('number') + 1;
            $position = (int) $project->tasks()->max('position') + 1;
            $numbers = [];

            foreach ($data['tasks'] as $t) {
                $feature = $this->featureFor($project, $t);

                $task = $project->tasks()->create([
                    'number' => $next,
                    'title' => $t['title'],
                    'column' => 'backlog',
                    'labels' => $t['labels'] ?? [],
                    'engine' => $t['engine'] ?? null,
                    'feature_id' => $feature?->id,
                    'position' => $position++,
                ]);
                if ($blockers = Task::blockersIn($t['body'] ?? null)) {
                    $task->blockOn($blockers);
                }
                $numbers[] = $next++;
            }

            return $numbers;
        });

        return $this->state(['numbers' => $numbers], 201);
    }

    /** @param array<string, mixed> $t */
    private function featureFor(Project $project, array $t): ?Feature
    {
        if (isset($t['feature'])) {
            return $this->feature($project, (int) $t['feature']);
        }
        if (! isset($t['milestone'])) {
            return null;
        }
        $milestone = $project->milestones()->findOrFail((int) $t['milestone']);
        $name = trim((string) ($t['feature_name'] ?? '')) ?: 'Unsorted';
        $feature = $milestone->features()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        return $feature ?? $milestone->features()->create([
            'name' => $name,
            'position' => (int) $milestone->features()->max('position') + 1,
        ]);
    }

    private function feature(Project $project, int $id): Feature
    {
        return Feature::whereKey($id)->whereHas('milestone', fn ($q) => $q->where('project_id', $project->id))->firstOrFail();
    }

    public function update(Request $request, Project $project, int $number): JsonResponse
    {
        $data = $request->validate([
            'column' => [Rule::requiredIf(! $request->hasAny(['priority', 'blocked_by'])), Rule::in(Task::COLUMNS)],
            'priority' => ['sometimes', 'nullable', Rule::in(Task::PRIORITIES)],
            'blocked_by' => ['sometimes', 'nullable', 'array', 'max:20'],
            'blocked_by.*' => ['integer', 'min:1'],
        ]);

        $task = $project->tasks()->where('number', $number)->firstOrFail();
        if (array_key_exists('priority', $data)) {
            $task->update(['priority' => Task::priorityValue($data['priority'])]);
        }
        if (array_key_exists('blocked_by', $data)) {
            $task->blockOn(array_values($data['blocked_by'] ?? []));
        }
        if (isset($data['column'])) {
            $task->update(['column' => $data['column']]);
        }

        if (($data['column'] ?? null) === 'done') {
            Droid::where('task_id', $task->id)->update(['status' => 'idle', 'task_id' => null, 'step' => '', 'progress' => 0]);
        }

        return $this->state();
    }

    public function assign(Request $request, Project $project, int $number, AssignDroid $assign): JsonResponse
    {
        $data = $request->validate([
            'droid' => ['required', 'string', 'exists:droids,slug'],
            'as' => ['required', Rule::in(['builder', 'reviewer'])],
        ]);

        $task = $project->tasks()->where('number', $number)->firstOrFail();
        $droid = Droid::where('slug', $data['droid'])->firstOrFail();

        if ($data['as'] === 'reviewer' && $task->builder_id === $droid->id) {
            return response()->json(['message' => 'A droid cannot review its own work.', 'errors' => ['droid' => ['A droid cannot review its own work.']]], 422);
        }

        if ($data['as'] === 'builder' && ($open = $task->openBlockers())) {
            $message = 'Blocked by '.implode(', ', array_map(fn (int $n) => "#{$n}", $open)).', which '.(count($open) === 1 ? 'is' : 'are').' not merged yet.';

            return response()->json(['message' => $message, 'errors' => ['droid' => [$message]]], 422);
        }

        $assign($task, $droid, $data['as']);

        return $this->state();
    }

    /** Send a card back to Ideas: its builder stops, and the triage droid looks at it again. */
    public function retriage(Project $project, int $number, RunQueue $runs, Autopilot $autopilot): JsonResponse
    {
        $task = $project->tasks()->where('number', $number)->firstOrFail();
        if (! in_array($task->column, ['backlog', 'ready', 'progress'], true)) {
            return response()->json(['message' => 'Only cards not in review yet can go back to triage.'], 422);
        }

        $builder = $task->builder;
        if ($builder && $builder->task_id === $task->id) {
            $runs->release($builder);
        }
        Run::where('task_id', $task->id)->whereIn('kind', Run::TASK_KINDS)->whereIn('status', Run::ACTIVE)
            ->update(['status' => 'cancelled', 'error' => 'Sent back to triage', 'finished_at' => now()]);
        $task->refresh()->update([
            'builder_id' => null, 'column' => 'backlog', 'triaged_at' => null,
            'question' => null, 'question_for' => null, 'answer' => null,
            'position' => (int) $project->tasks()->where('column', 'backlog')->max('position') + 1,
        ]);
        $started = $autopilot->triage($project);
        $droid = Autopilot::triager($project);
        $message = $droid
            ? "#{$task->number} is back in Ideas. ".($started ? "{$droid->unit} {$droid->name} will triage it next." : 'It is in the next triage.')
            : "#{$task->number} is back in Ideas. Add a Triager or Planner to the crew to triage it.";

        return $this->state(['message' => $message]);
    }

    /** Send the requested changes on a card's pull request back to its builder, as one more round. */
    public function fix(Project $project, int $number, RunQueue $runs, Review $review): JsonResponse
    {
        $task = $project->tasks()->where('number', $number)->with(['pullRequest', 'builder'])->firstOrFail();
        $pr = $task->pullRequest;
        $fail = fn (string $m) => response()->json(['message' => $m], 422);
        if (! $pr || ! in_array($pr->state, ['changes', 'open', 'approved'], true)) {
            return $fail('This card has no open pull request.');
        }
        if (! $task->builder) {
            return $fail('Assign a builder to the card first.');
        }
        if (RunQueue::busyElsewhere($task->builder, $task)) {
            return $fail("{$task->builder->name} is busy on another card. Try again when it is free, or unassign it there.");
        }
        if (Run::where('task_id', $task->id)->whereIn('kind', Run::TASK_KINDS)->whereIn('status', Run::ACTIVE)->exists()) {
            return $fail('A droid is already working on this card.');
        }
        $last = Run::where('task_id', $task->id)->where('kind', 'review')->where('status', 'succeeded')->latest('id')->first();
        $feedback = $last ? $review->feedback($last) : "Address the review comments on pull request #{$pr->number}.";
        $runs->queueFix($task, $pr, $feedback, extraRound: true);

        return $this->state(['message' => "{$task->builder->name} will address the review on PR #{$pr->number}."]);
    }
}
