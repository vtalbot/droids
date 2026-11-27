<?php

namespace App\Integrations;

/**
 * Puts DNS records another service asked for (Laravel Cloud, Resend) into
 * the Cloudflare zone that holds them. A record already there is left
 * alone, and one that would clash with a different value is reported
 * rather than overwritten.
 */
class Dns
{
    /** Types Cloudflare allows only once per name. */
    private const SINGLE = ['CNAME'];

    public function __construct(private Cloudflare $cloudflare) {}

    public static function fromSettings(): self
    {
        return new self(Cloudflare::fromSettings());
    }

    /**
     * Records from another service, made comparable: absolute names, upper-case types.
     *
     * @param  iterable<mixed>  $records  items with type, name and value (or content), and maybe priority and ttl
     * @param  string  $domain  the domain relative names belong to
     * @return list<array{type: string, name: string, content: string, priority: int|null}>
     */
    public static function normalize(iterable $records, string $domain): array
    {
        $out = [];
        foreach ($records as $r) {
            if (! is_array($r)) {
                continue;
            }
            $type = strtoupper((string) ($r['type'] ?? ''));
            $content = trim((string) ($r['value'] ?? $r['content'] ?? ''));
            if ($type === '' || $content === '') {
                continue;
            }
            $priority = $r['priority'] ?? null;
            $out[] = [
                'type' => $type,
                'name' => self::absolute((string) ($r['name'] ?? ''), $domain),
                'content' => $content,
                'priority' => is_numeric($priority) ? (int) $priority : ($type === 'MX' ? 10 : null),
            ];
        }

        return $out;
    }

    /** "send" under example.com is send.example.com; "" and "@" are the domain itself. */
    public static function absolute(string $name, string $domain): string
    {
        $name = strtolower(trim(rtrim($name, '.')));
        $domain = strtolower(rtrim($domain, '.'));
        if ($name === '' || $name === '@') {
            return $domain;
        }

        return $name === $domain || str_ends_with($name, ".{$domain}") ? $name : "{$name}.{$domain}";
    }

    /**
     * Each record with whether Cloudflare already has it.
     *
     * @param  list<array{type: string, name: string, content: string, priority: int|null}>  $records
     * @return list<array{type: string, name: string, content: string, priority: int|null, state: string}> state: present, missing, conflict or no-zone
     */
    public function check(array $records): array
    {
        $zones = [];
        $out = [];
        foreach ($records as $r) {
            $zone = $zones[$r['name']] ??= $this->cloudflare->zoneFor($r['name']);
            $out[] = [...$r, 'state' => $zone ? $this->state($r, $this->existing($zone['id'])) : 'no-zone'];
        }

        return $out;
    }

    /**
     * Add the records Cloudflare is missing.
     *
     * @param  list<array{type: string, name: string, content: string, priority: int|null}>  $records
     * @return array{added: int, present: int, conflicts: list<string>, unzoned: list<string>}
     */
    public function apply(array $records): array
    {
        $result = ['added' => 0, 'present' => 0, 'conflicts' => [], 'unzoned' => []];
        foreach ($records as $r) {
            $zone = $this->cloudflare->zoneFor($r['name']);
            if (! $zone) {
                $result['unzoned'][] = $r['name'];

                continue;
            }
            $state = $this->state($r, $this->existing($zone['id']));
            if ($state === 'present') {
                $result['present']++;
            } elseif ($state === 'conflict') {
                $result['conflicts'][] = "{$r['type']} {$r['name']}";
            } else {
                $this->cloudflare->create($zone['id'], ['type' => $r['type'], 'name' => $r['name'], 'content' => $r['content'], 'priority' => $r['priority']]);
                unset($this->cache[$zone['id']]);
                $result['added']++;
            }
        }

        return $result;
    }

    /** @var array<string, list<array{id: string, type: string, name: string, content: string, ttl: int, proxied: bool, priority: int|null}>> */
    private array $cache = [];

    /** @return list<array{id: string, type: string, name: string, content: string, ttl: int, proxied: bool, priority: int|null}> */
    private function existing(string $zone): array
    {
        return $this->cache[$zone] ??= $this->cloudflare->records($zone);
    }

    /**
     * @param  array{type: string, name: string, content: string, priority: int|null}  $r
     * @param  list<array{id: string, type: string, name: string, content: string, ttl: int, proxied: bool, priority: int|null}>  $existing
     */
    private function state(array $r, array $existing): string
    {
        $same = fn (string $a, string $b) => strtolower(trim($a, '". ')) === strtolower(trim($b, '". '));
        foreach ($existing as $e) {
            if (strtolower($e['name']) !== $r['name']) {
                continue;
            }
            if ($e['type'] === $r['type'] && $same($e['content'], $r['content'])) {
                return 'present';
            }
            // A CNAME can't share its name, and a DKIM key holds one value.
            if (in_array($r['type'], self::SINGLE, true) || in_array($e['type'], self::SINGLE, true) || ($e['type'] === $r['type'] && str_contains($r['name'], '._domainkey.'))) {
                return 'conflict';
            }
        }

        return 'missing';
    }
}
