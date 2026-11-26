<?php

namespace App\Http\Controllers\Factory;

use App\GitHub\RepositoryWriter;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    public function storeMilestone(Request $request, Project $project, RepositoryWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'due_on' => ['nullable', 'date'],
            'goal' => ['nullable', 'string', 'max:300'],
        ]);

        $number = $project->isLinked()
            ? $this->github(fn () => $writer->createMilestone($project, $data['name'], $data['due_on'] ?? null, $data['goal'] ?? null))
            : null;

        // A webhook for the new GitHub milestone may land first, so match on its number.
        $milestone = $number
            ? $project->milestones()->firstOrNew(['github_number' => $number])
            : $project->milestones()->make();
        $milestone->fill([
            ...$data,
            'state' => $milestone->exists ? $milestone->state : 'planned',
            'position' => $milestone->exists ? $milestone->position : (int) $project->milestones()->max('position') + 1,
        ])->save();

        return $this->state([], 201);
    }

    public function storeFeature(Request $request, Milestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $milestone->features()->create([
            'name' => $data['name'],
            'position' => (int) $milestone->features()->max('position') + 1,
        ]);

        return $this->state([], 201);
    }
}
