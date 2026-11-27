<?php

namespace App\Integrations;

use App\Models\ServiceKey;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** A small client for the Cloudflare DNS API (https://api.cloudflare.com/client/v4). */
class Cloudflare
{
    public const URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(private string $token) {}

    public static function fromSettings(): self
    {
        $key = ServiceKey::for('cloudflare') ?? throw NotConnected::service('Cloudflare');

        return new self($key->token);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::URL)->withToken($this->token)->acceptJson()->timeout(20)->throw();
    }

    /** Whether the token is active. */
    public function verify(): bool
    {
        return $this->http()->get('/user/tokens/verify')->json('result.status') === 'active';
    }

    /**
     * The zone that holds this host name: example.com for mail.example.com.
     *
     * @return array{id: string, name: string}|null
     */
    public function zoneFor(string $host): ?array
    {
        $labels = explode('.', strtolower(trim($host, '.')));
        while (count($labels) >= 2) {
            $name = implode('.', $labels);
            $zone = $this->http()->get('/zones', ['name' => $name])->json('result.0');
            if (is_array($zone) && isset($zone['id'])) {
                return ['id' => (string) $zone['id'], 'name' => (string) ($zone['name'] ?? $name)];
            }
            array_shift($labels);
        }

        return null;
    }

    /** @return list<array{id: string, type: string, name: string, content: string, ttl: int, proxied: bool, priority: int|null}> */
    public function records(string $zone): array
    {
        $out = [];
        foreach ($this->http()->get("/zones/{$zone}/dns_records", ['per_page' => 500])->json('result') ?? [] as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'type' => (string) $r['type'],
                'name' => (string) $r['name'],
                'content' => (string) $r['content'],
                'ttl' => (int) ($r['ttl'] ?? 1),
                'proxied' => (bool) ($r['proxied'] ?? false),
                'priority' => isset($r['priority']) ? (int) $r['priority'] : null,
            ];
        }

        return $out;
    }

    /** @param  array{type: string, name: string, content: string, ttl?: int, proxied?: bool, priority?: int|null}  $record */
    public function create(string $zone, array $record): void
    {
        $this->http()->post("/zones/{$zone}/dns_records", array_filter([
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'ttl' => $record['ttl'] ?? 1,
            'proxied' => $record['proxied'] ?? false,
            'priority' => $record['priority'] ?? null,
        ], fn ($v) => $v !== null));
    }

    public function delete(string $zone, string $record): void
    {
        $this->http()->delete("/zones/{$zone}/dns_records/{$record}");
    }
}
