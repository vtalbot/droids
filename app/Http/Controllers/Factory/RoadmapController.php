<?php

namespace App\Http\Controllers\Factory;

use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    public function storeMilestone(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'due_on' => ['nullable', 'date'],
            'goal' => ['nullable', 'string', 'max:300'],
        ]);

        $project->milestones()->create([
            ...$data,
            'state' => 'planned',
            'position' => (int) $project->milestones()->max('position') + 1,
        ]);

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
