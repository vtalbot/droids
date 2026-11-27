<?php

namespace App\Http\Controllers\Factory;

use App\Models\Droid;
use App\Models\Project;
use App\Models\Run;
use App\Runs\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A creative droid prefills a form; the answer lands as a suggestion the form picks up. */
class SuggestController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'form' => ['required', Rule::in(array_keys(Conversation::SUGGEST_FORMS))],
            'values' => ['sometimes', 'array'],
            'values.*' => ['nullable', 'string', 'max:200'],
            'hint' => ['nullable', 'string', 'max:1000'],
        ]);
        $droid = self::droid($project);
        if (! $droid) {
            return response()->json(['message' => 'Assemble a droid first, ideally a Copywriter: it suggests names and wording.'], 422);
        }

        $topic = "suggest:{$project->slug}:{$data['form']}";
        Run::where('topic', $topic)->whereIn('status', Run::ACTIVE)
            ->update(['status' => 'cancelled', 'error' => 'Replaced by a newer request', 'finished_at' => now()]);

        $fields = array_keys(Conversation::SUGGEST_FORMS[$data['form']]['fields']);
        $current = [];
        foreach ($fields as $key) {
            $v = $data['values'][$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $current[] = "{$key}: ".trim($v);
            }
        }
        $current = implode('; ', $current);
        Run::create([
            'droid_id' => $droid->id,
            'project_id' => $project->id,
            'kind' => 'suggest',
            'topic' => $topic,
            'input' => trim('Suggest values for this form.'.($current ? " What is there now: {$current}." : '').(isset($data['hint']) ? " {$data['hint']}" : '')),
            'status' => 'queued',
        ]);

        return $this->state([], 201);
    }

    /** A Copywriter on the project, else any Copywriter, else the project's Planner or first droid. */
    public static function droid(Project $project): ?Droid
    {
        return Droid::where('role', 'Copywriter')->orderByRaw('case when project_id = ? then 0 else 1 end', [$project->id])->orderBy('unit')->first()
            ?? Droid::where('project_id', $project->id)->orderByRaw("role = 'Planner' desc")->orderBy('unit')->first()
            ?? Droid::where('role', '!=', 'HR')->orderBy('unit')->first();
    }
}
