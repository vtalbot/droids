<?php

namespace App\Http\Controllers\Factory;

use App\Cloud\CloudSync;
use App\Cloud\LaravelCloud;
use App\Http\Controllers\Factory\Concerns\CallsServices;
use App\Integrations\Dns;
use App\Integrations\Resend;
use App\Models\CloudConnection;
use App\Models\Project;
use App\Models\ServiceKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Email tab: sending through Laravel Cloud's Resend integration,
 * receiving through the person's own Resend account, and the DNS records
 * both ask for, put into Cloudflare.
 */
class EmailController extends Controller
{
    use CallsServices;

    public function show(Project $project): JsonResponse
    {
        return response()->json($this->panel($project));
    }

    /**
     * Set up email in one go: send from a Cloud environment, add the domain to
     * Resend when receiving is wanted, and put every DNS record into Cloudflare.
     */
    public function setup(Request $request, Project $project, CloudSync $sync): JsonResponse
    {
        $domain = $this->domain($project);
        $envs = $this->envs($project);
        $data = $request->validate([
            'environment' => [Rule::requiredIf($envs !== []), 'nullable', 'string', Rule::in(array_keys($envs))],
            'from_address' => ['required', 'email', 'max:255', 'regex:/@'.preg_quote($domain, '/').'$/i'],
            'from_name' => ['required', 'string', 'max:255'],
            'receiving' => ['boolean'],
        ], ['from_address.regex' => "Send from an address at {$domain}."]);
        $steps = [];

        if ($envs !== [] && $data['environment']) {
            $attached = ($envs[$data['environment']]['email'] ?? null) !== null;
            $this->attempt('Laravel Cloud', function () use ($data, $attached, $project, $sync) {
                LaravelCloud::fromConnection()->resend($data['environment'], $data['from_address'], $data['from_name'], $attached);
                $sync->sync($project);
            });
            $steps[] = "{$envs[$data['environment']]['name']} sends as {$data['from_address']}. Deploy it to apply.";
        }

        if (($data['receiving'] ?? false) || ServiceKey::for('resend')) {
            $resend = $this->resendDomain($project, true, (bool) ($data['receiving'] ?? false));
            if ($resend) {
                $steps[] = ($data['receiving'] ?? false) ? "Receiving is on for {$domain} in Resend." : "{$domain} is in your Resend account.";
            }
        }

        if (ServiceKey::for('cloudflare')) {
            $steps[] = $this->describe($this->attempt('Cloudflare', fn () => Dns::fromSettings()->apply($this->records($project))));
        } else {
            $steps[] = 'Connect Cloudflare in Settings > DNS & email to add the DNS records for you.';
        }

        return response()->json([...$this->panel($project), 'result' => $steps]);
    }

    public function receiving(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->resendDomain($project, true, $data['enabled']);
        $steps = [$data['enabled'] ? 'Receiving is on.' : 'Receiving is off.'];
        if ($data['enabled'] && ServiceKey::for('cloudflare')) {
            $steps[] = $this->describe($this->attempt('Cloudflare', fn () => Dns::fromSettings()->apply($this->records($project))));
        }

        return response()->json([...$this->panel($project), 'result' => $steps]);
    }

    public function dns(Project $project): JsonResponse
    {
        $result = $this->attempt('Cloudflare', fn () => Dns::fromSettings()->apply($this->records($project)));

        return response()->json([...$this->panel($project), 'result' => [$this->describe($result)]]);
    }

    public function verify(Project $project): JsonResponse
    {
        $id = $project->resend_domain_id ?? $this->fail('Add the domain to Resend first.');
        $this->attempt('Resend', fn () => Resend::fromSettings()->verifyDomain($id));

        return response()->json([...$this->panel($project), 'result' => ['Resend is checking the DNS records. It can take a few minutes.']]);
    }

    /** @return array<string, mixed> */
    private function panel(Project $project): array
    {
        $domain = $project->domain;
        $panel = [
            'domain' => $domain,
            'cloud' => $project->cloud_application_id !== null,
            'cloudflare' => ServiceKey::for('cloudflare') !== null,
            'resendKey' => ServiceKey::for('resend') !== null,
            'envs' => array_values(array_map(fn (array $e) => ['id' => $e['id'], 'name' => $e['name'], 'email' => $e['email'] ?? null], $this->envs($project))),
            'sending' => null,
            'resend' => null,
            'errors' => [],
        ];
        if (! $domain) {
            return $panel;
        }

        if ($panel['cloud'] && CloudConnection::current()) {
            [$sending, $error] = $this->quietly('Laravel Cloud', fn () => $this->cloudSendingDomain($domain));
            $panel['sending'] = $sending;
            if ($error) {
                $panel['errors'][] = $error;
            }
        }
        if ($panel['resendKey']) {
            [$resend, $error] = $this->quietly('Resend', fn () => $this->resendDomain($project, false));
            $panel['resend'] = $resend;
            if ($error) {
                $panel['errors'][] = $error;
            }
        }

        if ($panel['cloudflare']) {
            [$checked, $error] = $this->quietly('Cloudflare', function () use ($panel) {
                $dns = Dns::fromSettings();
                foreach (['sending', 'resend'] as $key) {
                    if ($panel[$key]) {
                        $panel[$key]['records'] = $dns->check($this->plain($panel[$key]['records']));
                    }
                }

                return $panel;
            });
            $panel = $checked ?? $panel;
            if ($error) {
                $panel['errors'][] = $error;
            }
        }

        return $panel;
    }

    /**
     * The sending domain in the organization's Laravel Cloud Resend account that covers the project's domain.
     *
     * @return array<string, mixed>|null
     */
    private function cloudSendingDomain(string $domain): ?array
    {
        foreach (LaravelCloud::fromConnection()->sendingDomains() as $d) {
            $a = $d['attributes'] ?? [];
            $name = strtolower((string) ($a['name'] ?? ''));
            if ($name !== '' && ($name === $domain || str_ends_with($domain, ".{$name}") || str_ends_with($name, ".{$domain}"))) {
                return [
                    'name' => $name,
                    'status' => (string) ($a['status'] ?? 'not_started'),
                    'records' => array_map(fn (array $r) => [...$r, 'state' => null], Dns::normalize(is_array($a['records'] ?? null) ? $a['records'] : [], $name)),
                ];
            }
        }

        return null;
    }

    /**
     * The project's domain in the person's Resend account: found, or made when asked.
     *
     * @return array<string, mixed>|null
     */
    private function resendDomain(Project $project, bool $create, ?bool $receiving = null): ?array
    {
        $domain = $this->domain($project);

        return $this->attempt('Resend', function () use ($project, $domain, $create, $receiving) {
            $resend = Resend::fromSettings();
            $found = $project->resend_domain_id ? rescue(fn () => $resend->domain((string) $project->resend_domain_id), null, false) : null;
            $found = is_array($found) && isset($found['id']) ? $found : $resend->findDomain($domain);
            if (! $found && $create) {
                $found = $resend->createDomain($domain, (bool) $receiving);
            } elseif ($found && $receiving !== null && $this->receives($found) !== $receiving) {
                $resend->setReceiving((string) $found['id'], $receiving);
                $found = $resend->domain((string) $found['id']);
            }
            if (! $found) {
                return null;
            }
            if ($project->resend_domain_id !== (string) $found['id']) {
                $project->update(['resend_domain_id' => (string) $found['id']]);
            }
            $name = (string) ($found['name'] ?? $domain);

            return [
                'id' => (string) $found['id'],
                'name' => $name,
                'status' => (string) ($found['status'] ?? 'not_started'),
                'receiving' => $this->receives($found),
                'records' => array_map(fn (array $r) => [...$r, 'state' => null], Dns::normalize(is_array($found['records'] ?? null) ? $found['records'] : [], $name)),
            ];
        });
    }

    /** @param array<string, mixed> $domain */
    private function receives(array $domain): bool
    {
        return ($domain['capabilities']['receiving'] ?? null) === 'enabled';
    }

    /**
     * Every DNS record email needs: Cloud's sending domain, then the Resend account's.
     *
     * @return list<array{type: string, name: string, content: string, priority: int|null}>
     */
    private function records(Project $project): array
    {
        $panel = $this->panel($project);

        return $this->plain([...$panel['sending']['records'] ?? [], ...$panel['resend']['records'] ?? []]);
    }

    /**
     * @param  iterable<mixed>  $records  records as the panel shows them
     * @return list<array{type: string, name: string, content: string, priority: int|null}>
     */
    private function plain(iterable $records): array
    {
        $out = [];
        foreach ($records as $r) {
            if (is_array($r)) {
                $out[] = ['type' => (string) $r['type'], 'name' => (string) $r['name'], 'content' => (string) $r['content'], 'priority' => isset($r['priority']) ? (int) $r['priority'] : null];
            }
        }

        return $out;
    }

    /** @param array{added: int, present: int, conflicts: list<string>, unzoned: list<string>} $result */
    private function describe(array $result): string
    {
        $parts = ["Added {$result['added']} DNS record".($result['added'] === 1 ? '' : 's').' to Cloudflare'.($result['present'] ? ", {$result['present']} already there" : '').'.'];
        if ($result['conflicts']) {
            $parts[] = 'Left alone because a different record holds the name: '.implode(', ', $result['conflicts']).'.';
        }
        if ($result['unzoned']) {
            $parts[] = 'No Cloudflare zone for '.implode(', ', array_unique($result['unzoned'])).'.';
        }

        return implode(' ', $parts);
    }

    private function domain(Project $project): string
    {
        return $project->domain ?? $this->fail('Set the project\'s domain in its DNS tab first.');
    }

    /** @return array<string, array{id: string, name: string, email: mixed}> */
    private function envs(Project $project): array
    {
        $out = [];
        foreach ($project->cloud_application_id ? $project->cloud_snapshot['envs'] ?? [] : [] as $env) {
            $out[(string) $env['id']] = ['id' => (string) $env['id'], 'name' => (string) $env['name'], 'email' => $env['email'] ?? null];
        }

        return $out;
    }
}
