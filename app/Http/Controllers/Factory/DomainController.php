<?php

namespace App\Http\Controllers\Factory;

use App\Integrations\Cloudflare;
use App\Integrations\NotConnected;
use App\Models\Project;
use App\Models\ServiceKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The DNS tab: a project's domain and its DNS records on Cloudflare. */
class DomainController extends Controller
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SRV'];

    private const HOST = '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    public function show(Project $project): JsonResponse
    {
        return response()->json($this->panel($project));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain'), " \t\n\r\0\x0B."))]);
        $data = $request->validate(['domain' => ['nullable', 'string', 'max:253', 'regex:'.self::HOST]],
            ['domain.regex' => 'Use a domain name like example.com or mail.example.com.']);
        $domain = $data['domain'] ?: null;

        $project->update(['domain' => $domain]);

        return response()->json($this->panel($project));
    }

    public function storeRecord(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'name' => ['required', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:4096'],
            'ttl' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'proxied' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535', Rule::requiredIf($request->input('type') === 'MX')],
        ]);
        $zone = $this->zone($project);

        $this->call(fn () => Cloudflare::fromSettings()->create($zone['id'], [
            'type' => $data['type'],
            'name' => $this->fqdn(trim($data['name']), $zone['name']),
            'content' => trim($data['content']),
            'ttl' => $data['ttl'] ?? 1,
            'proxied' => in_array($data['type'], ['A', 'AAAA', 'CNAME'], true) && ($data['proxied'] ?? false),
            'priority' => $data['priority'] ?? null,
        ]));

        return response()->json($this->panel($project), 201);
    }

    public function destroyRecord(Project $project, string $record): JsonResponse
    {
        $zone = $this->zone($project);
        $this->call(fn () => Cloudflare::fromSettings()->delete($zone['id'], $record));

        return response()->json($this->panel($project));
    }

    /** @return array<string, mixed> */
    private function panel(Project $project): array
    {
        $panel = [
            'domain' => $project->domain,
            'cloudflare' => ServiceKey::for('cloudflare') !== null,
            'zone' => null,
            'dns' => [],
            'error' => null,
        ];
        if (! $project->domain) {
            return $panel;
        }
        if (! $panel['cloudflare']) {
            return $panel;
        }

        try {
            $cloudflare = Cloudflare::fromSettings();
            $panel['zone'] = $cloudflare->zoneFor($project->domain);
            if ($panel['zone']) {
                $panel['dns'] = $cloudflare->records($panel['zone']['id']);
            } else {
                $panel['error'] = "No Cloudflare zone holds {$project->domain}, or the token can't read it.";
            }
        } catch (RequestException|ConnectionException $e) {
            $panel['error'] = $this->message($e);
        }

        return $panel;
    }

    /** A record name made absolute: "send" in example.com is send.example.com; "@" is the zone itself. */
    private function fqdn(string $name, string $zone): string
    {
        $name = strtolower(rtrim($name, '.'));
        if ($name === '' || $name === '@') {
            return $zone;
        }

        return $name === $zone || str_ends_with($name, ".{$zone}") ? $name : "{$name}.{$zone}";
    }

    /** @return array{id: string, name: string} */
    private function zone(Project $project): array
    {
        $domain = $project->domain ?? $this->fail('Set the project\'s domain first.');
        $zone = $this->call(fn () => Cloudflare::fromSettings()->zoneFor($domain));

        return $zone ?? $this->fail("No Cloudflare zone holds {$domain}, or the token can't read it.");
    }

    /**
     * Run an API call and turn its failures into a message the UI can show.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (NotConnected $e) {
            $this->fail($e->getMessage());
        } catch (RequestException|ConnectionException $e) {
            $this->fail($this->message($e));
        }
    }

    private function message(RequestException|ConnectionException $e): string
    {
        $service = 'Cloudflare';
        if ($e instanceof ConnectionException) {
            return "Could not reach {$service}.";
        }
        $json = $e->response->json();
        $detail = $json['errors'][0]['message'] ?? $json['message'] ?? null;

        return match ($e->response->status()) {
            401 => "{$service} rejected the API token. Add a new one in Settings > DNS.",
            403 => "The {$service} token is not allowed to do that. Check its permissions.",
            default => "{$service}: ".(is_string($detail) && $detail !== '' ? $detail : "error {$e->response->status()}"),
        };
    }

    private function fail(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }
}
