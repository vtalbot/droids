<?php

namespace App\Http\Controllers\Factory;

use App\Models\DesignFile;
use App\Models\Project;
use App\Runs\Memory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Design and Memory tabs: read a project's design files or remove one, and edit its shared memory. */
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

    /** The person edits the shared memory the droids keep. */
    public function memory(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['memory' => ['present', 'nullable', 'string', 'max:'.Memory::MAX_BYTES]]);
        $project->forceFill(['memory' => trim((string) $data['memory']) === '' ? null : $data['memory'], 'memory_droid_id' => null, 'memory_updated_at' => now()])->save();

        return $this->state();
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200']]);
        $project->designFiles()->where('path', $data['path'])->delete();

        return $this->state();
    }
}
