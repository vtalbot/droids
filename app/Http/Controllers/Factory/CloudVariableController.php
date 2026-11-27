<?php

namespace App\Http\Controllers\Factory;

use App\Cloud\LaravelCloud;
use App\Http\Controllers\Factory\Concerns\CallsServices;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The environment variables of a project's Laravel Cloud environments. */
class CloudVariableController extends Controller
{
    use CallsServices;

    public const KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function index(Project $project, string $environment): JsonResponse
    {
        $this->environment($project, $environment);

        return $this->list($environment);
    }

    public function update(Request $request, Project $project, string $environment): JsonResponse
    {
        $this->environment($project, $environment);
        $data = $request->validate([
            'variables' => ['required', 'array', 'min:1', 'max:100'],
            'variables.*.key' => ['required', 'string', 'max:255', 'regex:'.self::KEY],
            'variables.*.value' => ['present', 'nullable', 'string', 'max:10000'],
        ], ['variables.*.key.regex' => 'Use letters, digits and underscores, not starting with a digit.']);
        $variables = array_values(array_map(fn (array $v) => ['key' => $v['key'], 'value' => (string) ($v['value'] ?? '')], $data['variables']));

        $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->setVariables($environment, $variables));

        return $this->list($environment);
    }

    public function destroy(Request $request, Project $project, string $environment): JsonResponse
    {
        $this->environment($project, $environment);
        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:100'],
            'keys.*' => ['string', 'max:255', 'regex:'.self::KEY],
        ]);

        $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->deleteVariables($environment, array_values($data['keys'])));

        return $this->list($environment);
    }

    private function list(string $environment): JsonResponse
    {
        return response()->json(['variables' => $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->variables($environment))]);
    }

    /** The environment's name, when it belongs to the project's Cloud application. */
    private function environment(Project $project, string $environment): string
    {
        foreach ($project->cloud_snapshot['envs'] ?? [] as $env) {
            if ((string) $env['id'] === $environment) {
                return (string) $env['name'];
            }
        }
        abort(404, 'That environment is not part of this project\'s Laravel Cloud application.');
    }
}
