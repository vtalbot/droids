<?php

namespace App\Http\Controllers\Factory;

use App\Cloud\CloudNotConnected;
use App\Cloud\CloudSync;
use App\Cloud\LaravelCloud;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Cloud panel: link a project to a Laravel Cloud application, refresh it, deploy, read logs and metrics. */
class CloudController extends Controller
{
    /** Regions Laravel Cloud can host an application in. */
    public const REGIONS = [
        'us-east-1', 'us-east-2', 'ca-central-1', 'eu-central-1', 'eu-west-1', 'eu-west-2',
        'eu-west-3', 'me-central-1', 'ap-southeast-1', 'ap-southeast-2', 'ap-northeast-1',
    ];

    /** Applications the saved token can see, with the ones built from this repo first. */
    public function applications(Request $request): JsonResponse
    {
        $repo = (string) $request->query('repo', '');
        $apps = $this->cloud(fn () => LaravelCloud::fromConnection()->applications());

        $list = array_map(fn (array $a) => [
            'id' => (string) $a['id'],
            'name' => (string) ($a['attributes']['name'] ?? ''),
            'region' => (string) ($a['attributes']['region'] ?? ''),
            'repo' => $a['attributes']['repository']['full_name'] ?? null,
        ], $apps);
        usort($list, fn (array $a, array $b) => [$b['repo'] === $repo, $a['name']] <=> [$a['repo'] === $repo, $b['name']]);

        return response()->json(['applications' => $list]);
    }

    public function link(Request $request, Project $project, CloudSync $sync): JsonResponse
    {
        $data = $request->validate(['application' => ['required', 'string', 'max:100']]);

        $snapshot = $this->cloud(fn () => $sync->snapshot($data['application']));
        $project->update(['cloud_application_id' => $data['application'], 'cloud_snapshot' => $snapshot, 'cloud_synced_at' => now()]);

        return $this->state();
    }

    /** Create a Laravel Cloud application from the project's repository and link it. */
    public function create(Request $request, Project $project, CloudSync $sync): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40', "regex:/^[\\p{Latin}0-9 _.,'\\-&()!#+:@]+$/u"],
            'region' => ['required', Rule::in(self::REGIONS)],
        ], ['name.regex' => 'Use letters, numbers, spaces and simple punctuation.']);

        $id = $this->cloud(function () use ($project, $data) {
            $app = LaravelCloud::fromConnection()->createApplication($project->repo, $data['name'], $data['region']);

            return (string) ($app['id'] ?? '');
        });
        abort_if($id === '', 502, 'Laravel Cloud did not return the new application.');

        $snapshot = $this->cloud(fn () => $sync->snapshot($id));
        $project->update(['cloud_application_id' => $id, 'cloud_snapshot' => $snapshot, 'cloud_synced_at' => now()]);

        return $this->state([], 201);
    }

    public function unlink(Project $project): JsonResponse
    {
        $project->update(['cloud_application_id' => null, 'cloud_snapshot' => null, 'cloud_synced_at' => null]);

        return $this->state();
    }

    public function refresh(Project $project, CloudSync $sync): JsonResponse
    {
        $this->linked($project);
        $this->cloud(fn () => $sync->sync($project));

        return $this->state();
    }

    public function deploy(Request $request, Project $project, CloudSync $sync): JsonResponse
    {
        $this->linked($project);
        $envs = array_column($project->cloud_snapshot['envs'] ?? [], 'id');
        $data = $request->validate(['environment' => ['required', 'string', Rule::in($envs)]]);

        $this->cloud(function () use ($data, $project, $sync) {
            LaravelCloud::fromConnection()->deploy($data['environment']);
            $sync->sync($project);
        });

        return $this->state([], 201);
    }

    public function deploymentLogs(Project $project, string $deployment): JsonResponse
    {
        $this->linked($project);
        $known = in_array($deployment, array_column($project->cloud_snapshot['deployments'] ?? [], 'id'), true);
        abort_unless($known, 404);

        $logs = $this->cloud(fn () => LaravelCloud::fromConnection()->deploymentLogs($deployment));
        $steps = [];
        foreach (['build', 'deploy'] as $phase) {
            foreach ($logs[$phase]['steps'] ?? [] as $step) {
                $steps[] = [
                    'phase' => $phase,
                    'step' => (string) ($step['description'] ?? $step['step'] ?? ''),
                    'status' => (string) ($step['status'] ?? ''),
                    'output' => (string) ($step['output'] ?? ''),
                ];
            }
        }

        return response()->json(['steps' => $steps]);
    }

    public function metrics(Request $request, Project $project, CloudSync $sync): JsonResponse
    {
        $this->linked($project);
        $data = $request->validate(['period' => ['required', Rule::in(['6h', '24h', '3d', '7d', '30d'])]]);
        $env = $project->cloud_snapshot['envs'][0]['id'] ?? abort(404);

        return response()->json($sync->series($this->cloud(fn () => LaravelCloud::fromConnection()->metrics($env, $data['period']))));
    }

    private function linked(Project $project): void
    {
        abort_unless($project->cloud_application_id !== null, 404, 'This project is not linked to Laravel Cloud.');
    }

    /**
     * Run a Cloud API call and turn its failures into a message the UI can show.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function cloud(callable $call): mixed
    {
        try {
            return $call();
        } catch (CloudNotConnected $e) {
            $message = $e->getMessage();
        } catch (RequestException $e) {
            $message = match ($e->response->status()) {
                401 => 'Laravel Cloud rejected the API token. It may have expired: add a new one in Settings > Laravel Cloud.',
                403 => 'The Laravel Cloud API token is not allowed to do that. Check its permissions in Laravel Cloud.',
                429 => 'Laravel Cloud is rate limiting requests. Try again in a minute.',
                default => 'Laravel Cloud: '.($this->firstError($e->response->json('errors')) ?: $e->response->json('message') ?: "error {$e->response->status()}"),
            };
        } catch (ConnectionException) {
            $message = 'Could not reach Laravel Cloud.';
        }

        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }

    /** The first validation message of a Laravel Cloud 422, if any. */
    private function firstError(mixed $errors): string
    {
        foreach (is_array($errors) ? $errors : [] as $messages) {
            $first = is_array($messages) ? ($messages[0] ?? '') : $messages;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return '';
    }
}
