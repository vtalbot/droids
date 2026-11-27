<?php

namespace App\Http\Controllers\Factory;

use App\Models\Activity;
use App\Models\Droid;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DroidController extends Controller
{
    public const ROLES = ['Builder', 'Reviewer', 'Planner', 'Designer', 'Tester', 'Docs', 'Triager', 'Fixer', 'Copywriter', 'HR'];

    /**
     * What each role does and the model that fits it. Light roles run well on
     * the small models, which keeps the big ones free for building and review.
     */
    public const ROLE_GUIDE = [
        'Builder' => ['builds Ready cards and opens a pull request for each', 'Sonnet 5.5, Opus 5.5 or GPT-5.1-Codex'],
        'Reviewer' => ['reviews pull requests and sends changes back to the builder', 'Opus 5.5, ideally on the other engine than the builder'],
        'Planner' => ['plans tasks and milestones with the person in the project discussion', 'Opus 5.5 or Sonnet 5.5'],
        'Designer' => ['keeps the design files of the project up to date', 'Opus 5.5'],
        'Tester' => ['takes Ready cards labelled test and writes the missing tests', 'Sonnet 5.5 or GPT-5.1-Codex-Mini'],
        'Docs' => ['takes Ready cards labelled docs and writes the documentation', 'Haiku 5.5 or GPT-5.1-Codex-Mini'],
        'Triager' => ['triages the Ideas column: acceptance criteria, priority and questions', 'Haiku 5.5'],
        'Fixer' => ['takes small Ready cards labelled bug or chore', 'GPT-5.1-Codex-Mini or Haiku 5.5'],
        'Copywriter' => ['suggests names, sender addresses and wording to prefill forms', 'Haiku 5.5'],
        'HR' => ['proposes new droids to hire', 'Sonnet 5.5'],
    ];

    /** Card labels a specialist takes from Ready before a Builder does. */
    public const LABEL_ROLES = [
        'bug' => 'Fixer', 'chore' => 'Fixer', 'fix' => 'Fixer',
        'docs' => 'Docs', 'documentation' => 'Docs',
        'test' => 'Tester', 'tests' => 'Tester', 'testing' => 'Tester',
    ];

    /** Roles that build cards from Ready. */
    public const BUILDING_ROLES = ['Builder', 'Fixer', 'Docs', 'Tester'];

    /** The models offered per engine, as the UI labels them. */
    public const MODELS = [
        'claude' => ['Opus 5.5', 'Sonnet 5.5', 'Haiku 5.5'],
        'codex' => ['GPT-5.1-Codex', 'GPT-5.1-Codex-Mini'],
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'provider' => ['required', Rule::in(['claude', 'codex'])],
            'model' => ['required', 'string', 'max:60'],
            'role' => ['required', Rule::in(self::ROLES)],
            'project' => ['nullable', 'string', 'exists:projects,slug'],
            'autonomy' => ['required', Rule::in(['ask', 'pr', 'merge'])],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'seed' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $droid = DB::transaction(function () use ($data) {
            $prefix = $data['provider'] === 'claude' ? 'CL' : 'CX';
            $next = Droid::where('unit', 'like', "{$prefix}-%")->pluck('unit')
                ->map(fn (string $u) => (int) substr($u, 3))->max() + 1;
            $unit = sprintf('%s-%02d', $prefix, $next);

            $droid = Droid::create([
                'slug' => strtolower(str_replace('-', '', $unit)),
                'unit' => $unit,
                'name' => $data['name'],
                'provider' => $data['provider'],
                'model' => $data['model'],
                'role' => $data['role'],
                'status' => 'idle',
                'project_id' => isset($data['project']) ? Project::where('slug', $data['project'])->value('id') : null,
                'autonomy' => $data['autonomy'],
                'instructions' => $data['instructions'] ?? null,
                'seed' => $data['seed'] ?? Droid::count() + 1,
            ]);
            Activity::create(['droid_id' => $droid->id, 'text' => 'rolled off the assembly line']);

            return $droid;
        });

        return $this->state(['droid' => $droid->slug], 201);
    }

    public function update(Request $request, Droid $droid): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:40'],
            'model' => ['sometimes', 'string', Rule::in(self::MODELS[$droid->provider] ?? [])],
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'autonomy' => ['sometimes', Rule::in(['ask', 'pr', 'merge'])],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'add_rules' => ['sometimes', 'array', 'max:20'],
            'add_rules.*' => ['string', 'max:300'],
            'project' => ['sometimes', 'nullable', 'string', 'exists:projects,slug'],
        ]);

        if (array_key_exists('project', $data)) {
            $project = $data['project'] !== null ? Project::where('slug', $data['project'])->first() : null;
            if ($project?->id !== $droid->project_id) {
                abort_if($droid->task_id !== null, 422, "{$droid->unit} {$droid->name} is on an issue. Unassign it first, then move it to another project.");
                $from = $droid->project;
                $droid->project_id = $project?->id;
                Activity::create(['droid_id' => $droid->id, 'text' => $project ? "joined {$project->name}" : "left {$from?->name}"]);
            }
        }

        $changed = array_intersect_key($data, array_flip(['name', 'model', 'role', 'autonomy', 'instructions']));
        $droid->fill($changed);
        if (array_intersect(array_keys($changed), ['name', 'role']) !== [] && $droid->isDirty(['name', 'role'])) {
            Activity::create(['droid_id' => $droid->id, 'text' => "is now {$droid->name}, {$droid->role}"]);
        }

        if (! empty($data['add_rules'])) {
            $rules = implode("\n", array_map(fn (string $r) => "- {$r}", $data['add_rules']));
            $droid->instructions = trim(($droid->instructions ?? '')."\n\nFrom a retrospective:\n".$rules);
            Activity::create(['droid_id' => $droid->id, 'text' => 'Updated its instructions after a retrospective']);
        }

        $droid->save();

        return $this->state();
    }
}
