<?php

namespace App\Factory;

use App\Http\Controllers\Factory\DroidController;
use App\Models\Droid;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Runs\Conversation;
use Illuminate\Support\Collection;

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

    /** The droid that triages a project: its Triager, else its Planner, else its first droid. */
    public static function triager(Project $project): ?Droid
    {
        return Droid::where('project_id', $project->id)->orderByRaw("role = 'Triager' desc")->orderByRaw("role = 'Planner' desc")->orderBy('unit')->first();
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

    /** Give Ready cards to the project's idle builders, highest priority first, the card's engine first. */
    public function build(Project $project): int
    {
        $idle = Droid::where('project_id', $project->id)->whereIn('role', DroidController::BUILDING_ROLES)->where('status', 'idle')->whereNull('task_id')->orderBy('unit')->get();
        if ($idle->isEmpty()) {
            return 0;
        }
        $assigned = 0;
        // Highest priority first, then the order on the board.
        $cards = $project->tasks()->where('column', 'ready')->whereNull('builder_id')->orderByRaw(Task::BY_PRIORITY)->orderBy('position')->get();
        foreach ($cards as $task) {
            /** @var Task $task */
            $droid = $this->builderFor($task, $idle);
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

    /**
     * The idle droid for a card: a specialist its labels call for (a Fixer for
     * bugs, Docs for docs), else a Builder; on the card's engine when possible.
     *
     * @param  Collection<int, Droid>  $idle
     */
    private function builderFor(Task $task, Collection $idle): ?Droid
    {
        $roles = collect($task->labels ?? [])->map(fn (string $l) => DroidController::LABEL_ROLES[strtolower($l)] ?? null)->filter()->unique()->values()->all();
        foreach ([...$roles, 'Builder'] as $role) {
            $pool = $idle->where('role', $role);
            $droid = $pool->first(fn (Droid $d) => $task->engine === null || $d->provider === $task->engine) ?? $pool->first();
            if ($droid) {
                return $droid;
            }
        }

        return null;
    }
}
