<?php

namespace App\Worker;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The worker's side of the worker API on the Droids server. */
class Client
{
    public const VERSION = '1';

    private function http(): PendingRequest
    {
        $server = rtrim((string) config('droids.worker.server'), '/');
        $token = (string) config('droids.worker.token');
        if ($server === '' || $token === '') {
            throw new RuntimeException('Set DROIDS_SERVER and DROIDS_WORKER_TOKEN in .env first. Create the token in Droids under Settings > Workers.');
        }

        return Http::baseUrl($server.'/api/worker')->withToken($token)->acceptJson()->timeout(60);
    }

    /**
     * @param  list<string>  $subscriptions
     * @return array<string, mixed>|null
     */
    public function claim(array $subscriptions): ?array
    {
        return $this->http()->throw()->post('claim', ['subscriptions' => $subscriptions, 'version' => self::VERSION])->json('run');
    }

    /** Report progress. Returns false when the server cancelled the run. */
    public function progress(int $run, ?string $step, ?float $progress, string $log): bool
    {
        $response = $this->http()->post("runs/{$run}/progress", ['step' => $step, 'progress' => $progress, 'log' => $log]);

        return ! ($response->status() === 409 || $response->json('cancelled') === true);
    }

    public function token(int $run): string
    {
        return (string) $this->http()->throw()->post("runs/{$run}/token")->json('token');
    }

    /** @param array<string, mixed> $result */
    public function finish(int $run, array $result): void
    {
        $this->http()->post("runs/{$run}/finish", $result);
    }
}
