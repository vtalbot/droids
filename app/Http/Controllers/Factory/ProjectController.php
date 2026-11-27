<?php

namespace App\Http\Controllers\Factory;

use App\GitHub\RepositorySync;
use App\Models\GithubInstallation;
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

    public function store(Request $request, RepositorySync $sync): JsonResponse
    {
        $data = $request->validate([
            'repo' => ['required', 'string', 'max:140', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', 'unique:projects,repo'],
            'board' => ['nullable', 'string', 'max:120'],
            'installation' => ['nullable', 'integer', 'exists:github_installations,installation_id'],
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
            'board' => $data['board'] ?? 'Roadmap',
            'color' => self::COLORS[Project::count() % count(self::COLORS)],
            'github_installation_id' => isset($data['installation'])
                ? GithubInstallation::where('installation_id', $data['installation'])->value('id')
                : null,
        ]);

        $warning = null;
        if ($project->isLinked()) {
            try {
                $sync->sync($project);
            } catch (\Throwable $e) {
                report($e);
                $warning = 'Connected, but the first sync with GitHub failed. Try Sync again from the board.';
            }
        }

        return $this->state(['project' => $project->slug, 'warning' => $warning], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'auto_triage' => ['sometimes', 'boolean'],
            'auto_build' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $project->update($data);

        return $this->state();
    }
}
