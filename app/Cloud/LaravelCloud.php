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

    /**
     * Create an application from a GitHub repository. Cloud makes its default
     * environment; the first deploy is up to the person.
     *
     * @return array<string, mixed> the new application resource
     */
    public function createApplication(string $repository, string $name, string $region): array
    {
        return $this->http()->post('/applications', [
            'repository' => $repository,
            'name' => $name,
            'region' => $region,
            'source_control_provider_type' => 'github',
        ])->json('data') ?? [];
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

    /**
     * Send email from an environment through the organization's Resend
     * integration, or change who it sends as when it already does.
     */
    public function resend(string $environment, string $fromAddress, string $fromName, bool $attached): void
    {
        $body = ['from_address' => $fromAddress, 'from_name' => $fromName];
        $attached
            ? $this->http()->patch("/environments/{$environment}/resend", $body)
            : $this->http()->post("/environments/{$environment}/resend", $body);
    }

    /**
     * An environment's custom variables, as key and value.
     *
     * @return list<array{key: string, value: string}>
     */
    public function variables(string $environment): array
    {
        $attributes = $this->http()->get("/environments/{$environment}")->json('data.attributes') ?? [];
        // The API reference nests them under an unnamed key; accept either place.
        $list = $attributes['environment_variables'] ?? $attributes['']['environment_variables'] ?? [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $v) {
            if (is_array($v) && is_string($v['key'] ?? null)) {
                $out[] = ['key' => $v['key'], 'value' => (string) ($v['value'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Add variables, or update the ones whose key is already set.
     *
     * @param  list<array{key: string, value: string}>  $variables
     */
    public function setVariables(string $environment, array $variables): void
    {
        $this->http()->post("/environments/{$environment}/variables", ['method' => 'set', 'variables' => $variables]);
    }

    /** @param list<string> $keys */
    public function deleteVariables(string $environment, array $keys): void
    {
        $this->http()->post("/environments/{$environment}/variables/delete", ['keys' => $keys]);
    }

    /** @return list<array<string, mixed>> an environment's custom domains, without their DNS records */
    public function domains(string $environment): array
    {
        return $this->all("/environments/{$environment}/domains", []);
    }

    /** @return array<string, mixed> a domain with its DNS records */
    public function domain(string $domain): array
    {
        return $this->http()->get("/domains/{$domain}")->json('data') ?? [];
    }

    /** @return array<string, mixed> the new domain, with the DNS records it needs */
    public function createDomain(string $environment, string $name): array
    {
        return $this->http()->post("/environments/{$environment}/domains", ['name' => $name])->json('data') ?? [];
    }

    /** @return array<string, mixed> the domain after Cloud checked its DNS records */
    public function verifyDomain(string $domain): array
    {
        return $this->http()->post("/domains/{$domain}/verify")->json('data') ?? [];
    }

    public function deleteDomain(string $domain): void
    {
        $this->http()->delete("/domains/{$domain}");
    }

    /** @return list<array<string, mixed>> the organization's Resend sending domains, with their DNS records */
    public function sendingDomains(): array
    {
        return $this->all('/resend/domains', []);
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
