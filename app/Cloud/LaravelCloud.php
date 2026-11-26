<?php

namespace App\Cloud;

use App\Models\CloudConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the Laravel Cloud API (https://cloud.laravel.com/api).
 * Responses are JSON:API: resources carry `attributes` and `relationships`,
 * and `include=` puts related resources in a top-level `included` list.
 */
class LaravelCloud
{
    public function __construct(private ?string $token = null) {}

    public static function fromConnection(): self
    {
        $connection = CloudConnection::current() ?? throw new CloudNotConnected;

        return new self($connection->token);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('droids.cloud.url'), '/'))
            ->withToken((string) $this->token)
            ->acceptJson()
            ->timeout(20)
            ->throw();
    }

    /** @return array{name: string, slug: string|null} */
    public function organization(): array
    {
        $attributes = $this->http()->get('/meta/organization')->json('data.attributes') ?? [];

        return ['name' => (string) ($attributes['name'] ?? 'Laravel Cloud'), 'slug' => $attributes['slug'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    public function applications(): array
    {
        return $this->all('/applications', ['include' => 'organization']);
    }

    /** @return array{data: array<string, mixed>, included: list<array<string, mixed>>} */
    public function application(string $id): array
    {
        $json = $this->http()->get("/applications/{$id}", ['include' => 'organization,defaultEnvironment'])->json();

        return ['data' => $json['data'] ?? [], 'included' => $json['included'] ?? []];
    }

    /** @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>} */
    public function environments(string $application): array
    {
        $json = $this->http()->get("/applications/{$application}/environments", ['include' => 'branch,primaryDomain,instances'])->json();

        return ['data' => $json['data'] ?? [], 'included' => $json['included'] ?? []];
    }

    /** @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>} */
    public function deployments(string $environment): array
    {
        $json = $this->http()->get("/environments/{$environment}/deployments", ['include' => 'initiator'])->json();

        return ['data' => $json['data'] ?? [], 'included' => $json['included'] ?? []];
    }

    /** @return array<string, mixed> the new deployment resource */
    public function deploy(string $environment): array
    {
        return $this->http()->post("/environments/{$environment}/deployments")->json('data') ?? [];
    }

    /** @return array<string, mixed> build and deploy steps */
    public function deploymentLogs(string $deployment): array
    {
        return $this->http()->get("/deployments/{$deployment}/logs")->json('data') ?? [];
    }

    /** @return array<string, mixed> cpu_usage, memory_usage, … series */
    public function metrics(string $environment, string $period): array
    {
        return $this->http()->get("/environments/{$environment}/metrics", ['period' => $period])->json('data') ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function logs(string $environment, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->http()->get("/environments/{$environment}/logs", [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'type' => 'all',
        ])->json('data') ?? [];
    }

    /**
     * Every page of a list endpoint, following `links.next`.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function all(string $path, array $query): array
    {
        $out = [];
        $json = $this->http()->get($path, $query)->json();
        for ($page = 0; $page < 10; $page++) {
            foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            $next = $json['links']['next'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }
            $json = $this->http()->get($next)->json();
        }

        return $out;
    }
}
