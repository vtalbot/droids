<?php

namespace App\Http\Controllers\Factory;

use App\Models\DesignFile;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Design tab: read a project's design files, or remove one. */
class DesignController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['files' => $project->designFiles()->with('droid')->get()->map(fn (DesignFile $f) => [
            'path' => $f->path,
            'content' => $f->content,
            'by' => $f->droid?->slug,
            'updated' => $f->updated_at?->diffForHumans(),
        ])->values()]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200']]);
        $project->designFiles()->where('path', $data['path'])->delete();

        return $this->state();
    }
}
