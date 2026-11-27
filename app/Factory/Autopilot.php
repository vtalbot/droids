<?php

namespace App\Factory;

use App\Models\Droid;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Runs\Conversation;

/**
 * Moves cards along on projects that turned it on: the Planner triages the
 * Ideas column, and idle builders take cards from Ready for droids.
 */
class Autopilot
{
    public function __construct(private AssignDroid $assign) {}

    /** @return array{triage: int, assigned: int} what it started */
    public function tick(): array
    {
        $out = ['triage' => 0, 'assigned' => 0];
        foreach (Project::where('auto_triage', true)->orWhere('auto_build', true)->get() as $project) {
            if ($project->auto_triage && $this->triage($project)) {
                $out['triage']++;
            }
            if ($project->auto_build) {
                $out['assigned'] += $this->build($project);
            }
        }

        return $out;
    }

    /** The droid that triages a project: its Planner, else its first droid. */
    public static function triager(Project $project): ?Droid
    {
        return Droid::where('project_id', $project->id)->orderByRaw("role = 'Planner' desc")->orderBy('unit')->first();
    }

    /** Queue a triage run when Ideas has cards to look at and none is running. */
    public function triage(Project $project): bool
    {
        $topic = "triage:{$project->slug}";
        if (Run::where('topic', $topic)->whereIn('status', Run::ACTIVE)->exists()) {
            return false;
        }
        $droid = self::triager($project);
        if (! $droid || Conversation::triageCandidates($project)->isEmpty()) {
            return false;
        }
        Run::create([
            'droid_id' => $droid->id,
            'project_id' => $project->id,
            'kind' => 'triage',
            'topic' => $topic,
            'input' => 'Triage the cards in Ideas.',
            'status' => 'queued',
        ]);

        return true;
    }

    /** Give Ready cards to the project's idle builders, the card's engine first. */
    public function build(Project $project): int
    {
        $idle = Droid::where('project_id', $project->id)->where('role', 'Builder')->where('status', 'idle')->whereNull('task_id')->orderBy('unit')->get();
        if ($idle->isEmpty()) {
            return 0;
        }
        $assigned = 0;
        $cards = $project->tasks()->where('column', 'ready')->whereNull('builder_id')->orderBy('position')->get();
        foreach ($cards as $task) {
            /** @var Task $task */
            $droid = $idle->first(fn (Droid $d) => $task->engine === null || $d->provider === $task->engine) ?? $idle->first();
            if (! $droid) {
                break;
            }
            ($this->assign)($task, $droid, 'builder');
            $idle = $idle->reject(fn (Droid $d) => $d->is($droid))->values();
            $assigned++;

            $reviewer = Droid::where('project_id', $project->id)->where('role', 'Reviewer')->where('status', 'idle')->whereNull('task_id')->first();
            if ($reviewer && ! $task->fresh()?->reviewer_id) {
                ($this->assign)($task->fresh() ?? $task, $reviewer, 'reviewer');
            }
        }

        return $assigned;
    }
}
