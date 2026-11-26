<?php

namespace App\Factory;

use App\Models\Activity;
use App\Models\Droid;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Puts a droid on a task as its builder or reviewer. A builder moves the task
 * to In progress and releases the in-progress task it was working on before.
 */
class AssignDroid
{
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

                $droid->update([
                    'status' => 'working',
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                    'progress' => 0,
                    'minutes' => 0,
                    'step' => "Queued: clone {$task->project->repo} and read #{$task->number}",
                ]);
            } else {
                $task->update(['reviewer_id' => $droid->id]);

                if ($droid->status === 'idle') {
                    $droid->update([
                        'status' => 'working',
                        'task_id' => $task->id,
                        'project_id' => $task->project_id,
                        'progress' => 0,
                        'minutes' => 0,
                        'step' => "Waiting to review #{$task->number}",
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
