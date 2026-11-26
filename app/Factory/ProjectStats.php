<?php

namespace App\Factory;

use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Run;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * The project charts (burnup, throughput, PR cycle time, spend, CI) computed
 * from what Droids recorded, for projects that have no stored stats.
 *
 * Tasks keep no closing time, so a done task counts as closed when it was last
 * updated, and a merged pull request as merged when it was last synced.
 */
class ProjectStats
{
    private const WEEKS = 8;

    /** @return array{weeks: list<string>, burn: list<array{scope: int, done: int}>, thru: list<array{claude: int, codex: int}>, cycle: list<float>, spend: list<array{claude: float, codex: float}>, ci: float} */
    public function for(Project $project): array
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(self::WEEKS - 1);
        $ends = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $ends[] = $start->addWeeks($i + 1);
        }
        $week = function (?CarbonImmutable $at) use ($start): ?int {
            if (! $at || $at->lt($start)) {
                return null;
            }
            $i = (int) floor($start->diffInDays($at) / 7);

            return $i < self::WEEKS ? $i : null;
        };

        $tasks = Task::with('builder')->where('project_id', $project->id)->get();
        $prs = PullRequest::whereIn('task_id', $tasks->pluck('id'))->get();
        $runs = Run::with('droid')->where(fn ($q) => $q->where('project_id', $project->id)->orWhereIn('task_id', $tasks->pluck('id')))
            ->where('created_at', '>=', $start)->get();

        $burn = [];
        foreach ($ends as $end) {
            $burn[] = [
                'scope' => $tasks->filter(fn (Task $t) => (bool) $t->created_at?->lt($end))->count(),
                'done' => $tasks->filter(fn (Task $t) => $t->column === 'done' && (bool) $t->updated_at?->lt($end))->count(),
            ];
        }

        $closed = ['claude' => array_fill(0, self::WEEKS, 0), 'codex' => array_fill(0, self::WEEKS, 0)];
        foreach ($tasks->where('column', 'done') as $t) {
            if (($i = $week($t->updated_at?->toImmutable())) !== null) {
                $closed[($t->builder->provider ?? $t->engine) === 'codex' ? 'codex' : 'claude'][$i]++;
            }
        }

        $hours = array_fill(0, self::WEEKS, []);
        foreach ($prs->where('state', 'merged') as $pr) {
            if (($i = $week($pr->updated_at?->toImmutable())) !== null && $pr->created_at) {
                $hours[$i][] = $pr->created_at->diffInMinutes($pr->updated_at, true) / 60;
            }
        }

        $cost = ['claude' => array_fill(0, self::WEEKS, 0.0), 'codex' => array_fill(0, self::WEEKS, 0.0)];
        foreach ($runs as $r) {
            if (($i = $week($r->created_at?->toImmutable())) !== null) {
                $cost[$r->droid->provider === 'codex' ? 'codex' : 'claude'][$i] += $r->cost;
            }
        }
        $thru = [];
        $spend = [];
        $cycle = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $thru[] = ['claude' => $closed['claude'][$i], 'codex' => $closed['codex'][$i]];
            $spend[] = ['claude' => round($cost['claude'][$i], 2), 'codex' => round($cost['codex'][$i], 2)];
            $cycle[] = $this->median($hours[$i]);
        }

        $checked = $prs->filter(fn (PullRequest $pr) => ! empty($pr->checks));
        $green = $checked->filter(fn (PullRequest $pr) => collect($pr->checks)->doesntContain('state', 'failure'))->count();

        return [
            'weeks' => array_map(fn (CarbonImmutable $e) => $e->subDay()->startOfWeek()->format('M j'), $ends),
            'burn' => $burn,
            'thru' => $thru,
            'cycle' => $cycle,
            'spend' => $spend,
            'ci' => $checked->count() ? round($green / $checked->count(), 2) : 0.0,
        ];
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return round(count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
