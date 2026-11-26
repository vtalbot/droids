<?php

namespace App\Http\Controllers\Factory;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    private const COLORS = [
        'oklch(0.6 0.1 220)', 'oklch(0.58 0.15 145)', 'oklch(0.62 0.16 330)',
        'oklch(0.66 0.14 85)', 'oklch(0.55 0.17 280)', 'oklch(0.6 0.13 190)',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'repo' => ['required', 'string', 'max:140', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', 'unique:projects,repo'],
            'board' => ['required', 'string', 'max:120'],
        ], ['repo.regex' => 'Use the owner/repo form, like vtalbot/droids.']);

        $name = Str::afterLast($data['repo'], '/');
        $slug = $base = Str::slug($name) ?: 'project';
        for ($i = 2; Project::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $project = Project::create([
            'slug' => $slug,
            'name' => Str::headline($name),
            'repo' => $data['repo'],
            'board' => $data['board'],
            'color' => self::COLORS[Project::count() % count(self::COLORS)],
        ]);

        return $this->state(['project' => $project->slug], 201);
    }
}
