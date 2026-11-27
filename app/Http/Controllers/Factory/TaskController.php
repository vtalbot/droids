<?php

namespace App\Http\Controllers\Factory;

use App\Factory\AssignDroid;
use App\GitHub\RepositoryWriter;
use App\Models\Droid;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
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

                $project->tasks()->create([
                    'number' => $next,
                    'title' => $t['title'],
                    'column' => 'backlog',
                    'labels' => $t['labels'] ?? [],
                    'engine' => $t['engine'] ?? null,
                    'feature_id' => $feature?->id,
                    'position' => $position++,
                ]);
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
            'column' => ['required', Rule::in(Task::COLUMNS)],
        ]);

        $task = $project->tasks()->where('number', $number)->firstOrFail();
        $task->update(['column' => $data['column']]);

        if ($data['column'] === 'done') {
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

        $assign($task, $droid, $data['as']);

        return $this->state();
    }
}
