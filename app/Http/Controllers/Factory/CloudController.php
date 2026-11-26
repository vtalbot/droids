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
                default => 'Laravel Cloud: '.($e->response->json('message') ?: "error {$e->response->status()}"),
            };
        } catch (ConnectionException) {
            $message = 'Could not reach Laravel Cloud.';
        }

        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }
}
