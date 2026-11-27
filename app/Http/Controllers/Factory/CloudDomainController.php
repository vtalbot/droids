<?php

namespace App\Http\Controllers\Factory;

use App\Cloud\LaravelCloud;
use App\Http\Controllers\Factory\Concerns\CallsServices;
use App\Integrations\Dns;
use App\Models\Project;
use App\Models\ServiceKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custom domains of a project's Laravel Cloud environments, and the DNS
 * records Cloud asks for, put into Cloudflare in one click.
 */
class CloudDomainController extends Controller
{
    use CallsServices;

    private const HOST = '/^(?=.{1,253}$)(\*\.)?(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/';

    /** Domains looked up per environment, for their DNS records. */
    private const PER_ENV = 10;

    public function index(Project $project): JsonResponse
    {
        return response()->json($this->panel($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $request->merge(['name' => strtolower(trim((string) $request->input('name'), " \t\n\r\0\x0B."))]);
        $data = $request->validate([
            'environment' => ['required', 'string', Rule::in(array_keys($this->envs($project)))],
            'name' => ['required', 'string', 'max:253', 'regex:'.self::HOST],
            'dns' => ['boolean'],
        ], ['name.regex' => 'Use a domain name like example.com or app.example.com.']);

        $domain = $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->createDomain($data['environment'], $data['name']));
        $result = ($data['dns'] ?? true) && ServiceKey::for('cloudflare') ? $this->push($domain) : null;

        return response()->json([...$this->panel($project), 'result' => $result], 201);
    }

    public function verify(Project $project, string $domain): JsonResponse
    {
        $this->owned($project, $domain);
        $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->verifyDomain($domain));

        return response()->json($this->panel($project));
    }

    /** Put the domain's DNS records into Cloudflare. */
    public function dns(Project $project, string $domain): JsonResponse
    {
        $resource = $this->owned($project, $domain);

        return response()->json([...$this->panel($project), 'result' => $this->push($resource)]);
    }

    public function destroy(Project $project, string $domain): JsonResponse
    {
        $this->owned($project, $domain);
        $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection()->deleteDomain($domain));

        return response()->json($this->panel($project));
    }

    /**
     * The DNS records a Cloud domain asks for, from its dns_records.
     *
     * Cloud names them by purpose: ownership (a TXT on _cf-custom-hostname),
     * routing (an A or CNAME on the domain) and SSL validation (on _acme-challenge).
     *
     * @param  array<string, mixed>  $resource
     * @return list<array{type: string, name: string, content: string, priority: int|null}>
     */
    public static function records(array $resource): array
    {
        $a = $resource['attributes'] ?? $resource;
        $name = strtolower((string) ($a['name'] ?? ''));
        if ($name === '') {
            return [];
        }
        $raw = [];
        $sets = [[$name, $a['dns_records'] ?? null]];
        foreach (['www' => "www.{$name}", 'wildcard' => "*.{$name}"] as $key => $host) {
            if (is_array($a[$key] ?? null)) {
                $sets[] = [$host, $a[$key]['dns_records'] ?? null];
            }
        }
        foreach ($sets as [$host, $dns]) {
            if (! is_array($dns)) {
                continue;
            }
            foreach (is_array($dns['ssl'] ?? null) ? $dns['ssl'] : [] as $r) {
                if (is_array($r)) {
                    $raw[] = ['type' => $r['type'] ?? '', 'name' => Dns::absolute((string) ($r['name'] ?? ''), $host), 'value' => $r['value'] ?? ''];
                }
            }
            $origin = is_string($dns['origin'] ?? null) ? trim($dns['origin']) : '';
            $cname = is_string($dns['origin_cname'] ?? null) ? trim($dns['origin_cname']) : '';
            if ($cname !== '') {
                $raw[] = ['type' => 'CNAME', 'name' => $host, 'value' => $cname];
            } elseif ($origin !== '') {
                $raw[] = ['type' => filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : (filter_var($origin, FILTER_VALIDATE_IP) ? 'A' : 'CNAME'), 'name' => $host, 'value' => $origin];
            }
            if (is_string($dns['pre_verification'] ?? null) && trim($dns['pre_verification']) !== '' && ($a['hostname_status'] ?? null) !== 'verified') {
                $raw[] = ['type' => 'TXT', 'name' => '_cf-custom-hostname.'.ltrim($host, '*.'), 'value' => trim($dns['pre_verification'])];
            }
            $acme = '_acme-challenge.'.ltrim($host, '*.');
            if (is_string($dns['dcv'] ?? null) && trim($dns['dcv']) !== '' && ! in_array($acme, array_column($raw, 'name'), true)) {
                $raw[] = ['type' => 'CNAME', 'name' => $acme, 'value' => trim($dns['dcv'])];
            }
        }

        // One record per type, name and value.
        $unique = [];
        foreach (Dns::normalize($raw, $name) as $r) {
            $unique[$r['type'].' '.$r['name'].' '.strtolower($r['content'])] = $r;
        }

        return array_values($unique);
    }

    /** @return array<string, mixed> */
    private function panel(Project $project): array
    {
        $panel = ['linked' => $project->cloud_application_id !== null, 'cloudflare' => ServiceKey::for('cloudflare') !== null, 'domains' => [], 'error' => null];
        if (! $panel['linked']) {
            return $panel;
        }
        [$domains, $panel['error']] = $this->quietly('Laravel Cloud', function () use ($project) {
            $cloud = LaravelCloud::fromConnection();
            $out = [];
            foreach ($this->envs($project) as $id => $envName) {
                foreach (array_slice($cloud->domains($id), 0, self::PER_ENV) as $d) {
                    $full = $cloud->domain((string) $d['id']);
                    $out[] = ['id' => (string) $d['id'], 'environment' => $id, 'environmentName' => $envName, ...$this->describe($full ?: $d)];
                }
            }

            return $out;
        });
        $panel['domains'] = $domains ?? [];

        if ($panel['cloudflare'] && $panel['domains']) {
            [$checked, $error] = $this->quietly('Cloudflare', function () use ($panel) {
                $dns = Dns::fromSettings();

                return array_map(fn (array $d) => [...$d, 'records' => $dns->check($d['records'])], $panel['domains']);
            });
            $panel['domains'] = $checked ?? $panel['domains'];
            $panel['error'] ??= $error;
        }

        return $panel;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function describe(array $resource): array
    {
        $a = $resource['attributes'] ?? [];

        return [
            'name' => (string) ($a['name'] ?? ''),
            'hostname' => (string) ($a['hostname_status'] ?? 'pending'),
            'ssl' => (string) ($a['ssl_status'] ?? 'pending'),
            'origin' => (string) ($a['origin_status'] ?? 'pending'),
            'action' => $a['action_required'] ?? null,
            'records' => array_map(fn (array $r) => [...$r, 'state' => null], self::records($resource)),
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{added: int, present: int, conflicts: list<string>, unzoned: list<string>}
     */
    private function push(array $resource): array
    {
        return $this->attempt('Cloudflare', fn () => Dns::fromSettings()->apply(self::records($resource)));
    }

    /**
     * The domain, when one of the project's environments holds it.
     *
     * @return array<string, mixed>
     */
    private function owned(Project $project, string $domain): array
    {
        $envs = $this->envs($project);
        $cloud = $this->attempt('Laravel Cloud', fn () => LaravelCloud::fromConnection());
        foreach (array_keys($envs) as $env) {
            $ids = array_map(fn (array $d) => (string) $d['id'], $this->attempt('Laravel Cloud', fn () => $cloud->domains($env)));
            if (in_array($domain, $ids, true)) {
                return $this->attempt('Laravel Cloud', fn () => $cloud->domain($domain));
            }
        }
        abort(404, 'That domain is not on this project\'s Laravel Cloud environments.');
    }

    /** @return array<string, string> environment ids and names */
    private function envs(Project $project): array
    {
        abort_unless($project->cloud_application_id !== null, 404, 'This project is not linked to Laravel Cloud.');
        $out = [];
        foreach ($project->cloud_snapshot['envs'] ?? [] as $env) {
            $out[(string) $env['id']] = (string) $env['name'];
        }

        return $out;
    }
}
