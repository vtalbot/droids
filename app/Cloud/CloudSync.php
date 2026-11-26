<?php

namespace App\Cloud;

use App\Models\CloudConnection;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads a project's Laravel Cloud application into the snapshot the Cloud
 * panel shows: environments and their instances, recent deployments, the
 * default environment's CPU and memory, and its latest log lines.
 */
class CloudSync
{
    /** Deployments kept per environment. */
    private const DEPLOYMENTS = 10;

    /** Log lines kept for the default environment. */
    private const LOG_LINES = 60;

    private const COLORS = ['green', 'yellow', 'blue', 'purple', 'orange', 'cyan', 'red', 'gray'];

    public function __construct(private ?LaravelCloud $cloud = null) {}

    private function cloud(): LaravelCloud
    {
        return $this->cloud ??= LaravelCloud::fromConnection();
    }

    public function sync(Project $project): void
    {
        if (! $project->cloud_application_id) {
            return;
        }

        $project->update(['cloud_snapshot' => $this->snapshot((string) $project->cloud_application_id), 'cloud_synced_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function snapshot(string $applicationId): array
    {
        $application = $this->cloud()->application($applicationId);
        $app = $application['data'];
        $attributes = $app['attributes'] ?? [];
        $organization = $this->find($application['included'], 'organizations', $app['relationships']['organization']['data']['id'] ?? null);
        $defaultId = $app['relationships']['defaultEnvironment']['data']['id'] ?? null;

        $list = $this->cloud()->environments($applicationId);
        $envs = [];
        foreach ($list['data'] as $i => $env) {
            $envs[] = $this->environment($env, $list['included'], $i, (string) ($attributes['region'] ?? ''));
        }
        // The default environment leads: the panel treats the first one as production.
        usort($envs, fn (array $a, array $b) => ($b['id'] === $defaultId) <=> ($a['id'] === $defaultId));

        $deployments = [];
        foreach ($envs as $env) {
            $page = $this->cloud()->deployments($env['id']);
            foreach (array_slice($page['data'], 0, self::DEPLOYMENTS) as $d) {
                $deployments[] = $this->deployment($d, $env['id'], $page['included']);
            }
        }
        usort($deployments, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        $primary = $envs[0]['id'] ?? null;
        $metrics = $primary ? $this->quietly(fn () => $this->series($this->cloud()->metrics($primary, '24h')), []) : [];

        return [
            'linked' => true,
            'id' => $applicationId,
            'app' => (string) ($attributes['name'] ?? 'Application'),
            'org' => (string) ($organization['attributes']['name'] ?? CloudConnection::current()->organization_name ?? ''),
            'region' => (string) ($attributes['region'] ?? ''),
            'repo' => $attributes['repository']['full_name'] ?? null,
            'envs' => $envs,
            'deployments' => $deployments,
            'cpu' => $metrics['cpu'] ?? [],
            'mem' => $metrics['mem'] ?? [],
            'times' => $metrics['times'] ?? [],
            'logs' => $primary ? $this->quietly(fn () => $this->logLines($primary), []) : [],
            'synced' => now()->toIso8601String(),
        ];
    }

    /**
     * CPU and memory series for a period, as percentages averaged across replicas.
     *
     * @param  array<string, mixed>  $data
     * @return array{cpu: list<float>, mem: list<float>, times: list<string>}
     */
    public function series(array $data): array
    {
        $points = fn (string $key) => is_array($data[$key]['data'] ?? null) ? $data[$key]['data'] : [];
        $mean = function (mixed $y): float {
            $values = array_filter(is_array($y) ? $y : [$y], 'is_numeric');

            return $values ? round(array_sum($values) / count($values), 1) : 0.0;
        };

        return [
            'cpu' => array_values(array_map(fn (array $p) => $mean($p['y'] ?? []), $points('cpu_usage'))),
            'mem' => array_values(array_map(fn (array $p) => $mean($p['y'] ?? []), $points('memory_usage'))),
            'times' => array_values(array_map(fn (array $p) => (string) ($p['x'] ?? ''), $points('cpu_usage'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  list<array<string, mixed>>  $included
     * @return array<string, mixed>
     */
    private function environment(array $env, array $included, int $index, string $region): array
    {
        $a = $env['attributes'] ?? [];
        $rel = $env['relationships'] ?? [];
        $branch = $this->find($included, 'branches', $rel['branch']['data']['id'] ?? null);
        $domain = $this->find($included, 'domains', $rel['primaryDomain']['data']['id'] ?? null);

        $instances = [];
        foreach ($rel['instances']['data'] ?? [] as $ref) {
            $i = $this->find($included, 'instances', $ref['id'] ?? null)['attributes'] ?? null;
            if (! $i) {
                continue;
            }
            $instances[] = [
                'name' => (string) ($i['name'] ?? 'instance'),
                'type' => ($i['type'] ?? 'app') === 'app' ? 'App' : 'Worker',
                'size' => (string) ($i['size'] ?? ''),
                'min' => (int) ($i['min_replicas'] ?? 1),
                'max' => (int) ($i['max_replicas'] ?? 1),
                'scaling' => in_array($i['scaling_type'] ?? '', ['auto', 'custom', 'none'], true) ? $i['scaling_type'] : 'none',
                'queue' => ($i['type'] ?? '') === 'queue' || ($i['type'] ?? '') === 'managed_queue' ? 'queue' : null,
            ];
        }

        return [
            'id' => (string) $env['id'],
            'name' => (string) ($a['name'] ?? 'environment'),
            'color' => in_array($a['color'] ?? null, self::COLORS, true) ? $a['color'] : self::COLORS[$index % count(self::COLORS)],
            'status' => in_array($a['status'] ?? null, ['deploying', 'running', 'hibernating', 'stopped'], true) ? $a['status'] : 'stopped',
            'domain' => (string) ($domain['attributes']['name'] ?? $a['vanity_domain'] ?? ''),
            'branch' => (string) ($branch['attributes']['name'] ?? ''),
            'php' => (string) ($a['php_major_version'] ?? ''),
            'octane' => (bool) ($a['uses_octane'] ?? false),
            'hibernation' => (bool) ($a['uses_hibernation'] ?? false),
            'pushToDeploy' => (bool) ($a['uses_push_to_deploy'] ?? false),
            'region' => $region,
            'instances' => $instances,
            'probes' => [],
            'scheduled' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  list<array<string, mixed>>  $included
     * @return array<string, mixed>
     */
    private function deployment(array $d, string $env, array $included): array
    {
        $a = $d['attributes'] ?? [];
        $started = isset($a['started_at']) ? Carbon::parse($a['started_at']) : null;
        $finished = isset($a['finished_at']) ? Carbon::parse($a['finished_at']) : null;
        $initiator = $this->find($included, 'users', $d['relationships']['initiator']['data']['id'] ?? null);
        $seconds = $started && $finished ? (int) $started->diffInSeconds($finished, true) : null;

        return array_filter([
            'id' => (string) $d['id'],
            'env' => $env,
            'status' => (string) ($a['status'] ?? 'pending'),
            'commit' => substr((string) ($a['commit_hash'] ?? ''), 0, 7),
            'message' => Str::limit(strtok((string) ($a['commit_message'] ?? ''), "\n") ?: 'Deployment', 140),
            'author' => (string) ($a['commit_author'] ?? ''),
            'branch' => (string) ($a['branch_name'] ?? ''),
            'started' => $started?->diffForHumans() ?? 'queued',
            'at' => $started?->toIso8601String() ?? now()->toIso8601String(),
            'duration' => $seconds !== null ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : '',
            'via' => $initiator ? 'Deployed by '.($initiator['attributes']['name'] ?? 'a teammate') : 'Push to deploy',
            'failure' => isset($a['failure_reason']) && $a['failure_reason'] !== '' ? Str::limit((string) $a['failure_reason'], 4000) : null,
        ], fn ($v) => $v !== null);
    }

    /** @return list<string> */
    private function logLines(string $environment): array
    {
        $lines = [];
        foreach ($this->cloud()->logs($environment, now()->subHour(), now()) as $log) {
            $time = isset($log['logged_at']) ? Carbon::parse($log['logged_at'])->format('H:i:s') : '';
            $level = strtoupper(match ($log['level'] ?? 'info') {
                'warning' => 'warn',
                default => (string) ($log['level'] ?? 'info'),
            });
            $lines[] = trim("{$time} {$level} ".Str::limit(str_replace("\n", ' ', (string) ($log['message'] ?? '')), 300));
        }

        return array_slice($lines, -self::LOG_LINES);
    }

    /**
     * @param  list<array<string, mixed>>  $included
     * @return array<string, mixed>|null
     */
    private function find(array $included, string $type, ?string $id): ?array
    {
        foreach ($included as $item) {
            if (($item['type'] ?? null) === $type && (string) ($item['id'] ?? '') === (string) $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Logs and metrics are extras: a token without those permissions still syncs the rest.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  T  $fallback
     * @return T
     */
    private function quietly(callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (RequestException|ConnectionException) {
            return $fallback;
        }
    }
}
