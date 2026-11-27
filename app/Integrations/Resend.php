<?php

namespace App\Integrations;

use App\Models\ServiceKey;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the Resend API (https://resend.com/docs/api-reference):
 * the sending and receiving domains of the person's own Resend account.
 */
class Resend
{
    public const URL = 'https://api.resend.com';

    public function __construct(private string $key) {}

    public static function fromSettings(): self
    {
        $key = ServiceKey::for('resend') ?? throw NotConnected::service('Resend');

        return new self($key->token);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::URL)->withToken($this->key)->acceptJson()->timeout(20)->throw();
    }

    /** @return list<array<string, mixed>> */
    public function domains(): array
    {
        $list = $this->http()->get('/domains')->json('data');

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** @return array<string, mixed>|null the account's domain with that name */
    public function findDomain(string $name): ?array
    {
        foreach ($this->domains() as $d) {
            if (strtolower((string) ($d['name'] ?? '')) === strtolower($name)) {
                return $this->domain((string) $d['id']);
            }
        }

        return null;
    }

    /** @return array<string, mixed> with its DNS records */
    public function domain(string $id): array
    {
        return $this->http()->get("/domains/{$id}")->json() ?? [];
    }

    /** @return array<string, mixed> the new domain, with the DNS records it needs */
    public function createDomain(string $name, bool $receiving, string $region = 'us-east-1'): array
    {
        return $this->http()->post('/domains', [
            'name' => $name,
            'region' => $region,
            'capabilities' => ['sending' => 'enabled', 'receiving' => $receiving ? 'enabled' : 'disabled'],
        ])->json() ?? [];
    }

    public function setReceiving(string $id, bool $receiving): void
    {
        $this->http()->patch("/domains/{$id}", ['capabilities' => ['receiving' => $receiving ? 'enabled' : 'disabled']]);
    }

    public function verifyDomain(string $id): void
    {
        $this->http()->post("/domains/{$id}/verify");
    }
}
