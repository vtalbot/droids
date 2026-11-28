<?php

namespace App\Factory;

use App\Models\Activity;
use App\Models\Droid;
use App\Models\Task;
use App\Runs\RunQueue;
use Illuminate\Support\Facades\DB;

/**
 * Puts a droid on a task as its builder or reviewer. A builder moves the task
 * to In progress, releases the in-progress task it was working on before and,
 * on a linked repo, queues a run for a worker to pick up. A reviewer starts on
 * the task's open pull request, or waits for the builder to open one.
 */
class AssignDroid
{
    public function __construct(private RunQueue $runs) {}

    public function __invoke(Task $task, Droid $droid, string $as): void
    {
        DB::transaction(function () use ($task, $droid, $as) {
            $task->loadMissing('project');

            if ($as === 'builder') {
                $previous = $droid->task;
                if ($previous && $previous->isNot($task) && $previous->column === 'progress' && $previous->builder_id === $droid->id) {
                    $previous->update(['builder_id' => null, 'column' => 'ready']);
                }

                if ($task->reviewer_id === $droid->id) {
                    $task->reviewer_id = null;
                }
                $task->builder_id = $droid->id;
                if (in_array($task->column, ['backlog', 'ready'], true)) {
                    $task->column = 'progress';
                }
                $task->save();

                $linked = $task->project->isLinked();
                $droid->update([
                    'status' => 'working',
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                    'progress' => 0,
                    'minutes' => 0,
                    'step' => $linked
                        ? "Queued: waiting for a worker to pick up #{$task->number}"
                        : "Queued: clone {$task->project->repo} and read #{$task->number}",
                ]);

                // Real runs need the repo linked to the GitHub App: the worker clones and pushes with its token.
                if ($linked) {
                    $this->runs->queue($droid, $task);
                }
            } else {
                $task->update(['reviewer_id' => $droid->id]);

                // With a pull request open on a linked repo, the review starts now; otherwise when the builder opens one.
                $queued = $this->runs->queueReview($droid, $task, takeOver: true);
                if (! $queued && $droid->status === 'idle') {
                    $droid->update([
                        'status' => 'working',
                        'task_id' => $task->id,
                        'project_id' => $task->project_id,
                        'progress' => 0,
                        'minutes' => 0,
                        'step' => "Waiting for #{$task->number}'s pull request",
                    ]);
                }
            }

            Activity::create([
                'droid_id' => $droid->id,
                'text' => ($as === 'builder' ? 'Started' : 'Will review')." #{$task->number} {$task->title}",
            ]);
        });
    }
}
