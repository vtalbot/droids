<?php

namespace App\GitHub;

use App\Models\GithubApp;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to GitHub as the factory's GitHub App: signs App JWTs, exchanges them
 * for installation tokens and hands out authenticated REST/GraphQL clients.
 */
class GitHub
{
    public const API = 'https://api.github.com';

    public function app(): GithubApp
    {
        return GithubApp::current() ?? throw new GitHubNotConfigured;
    }

    public function configured(): bool
    {
        return GithubApp::current() !== null;
    }

    /** A short-lived RS256 JWT that authenticates as the App itself. */
    public function jwt(): string
    {
        $app = $this->app();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64((string) json_encode([
            'iat' => now()->subSeconds(60)->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => $app->client_id ?: (string) $app->app_id,
        ]));

        $key = openssl_pkey_get_private($app->private_key);
        if ($key === false || ! openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GitHubNotConfigured('The GitHub App private key is not valid.');
        }

        return "{$header}.{$payload}.".$b64($signature);
    }

    public function asApp(): PendingRequest
    {
        return $this->request()->withToken($this->jwt());
    }

    public function installationToken(int $installationId): string
    {
        return Cache::remember("github.installation-token.{$installationId}", now()->addMinutes(50), function () use ($installationId) {
            return (string) $this->asApp()->post("/app/installations/{$installationId}/access_tokens")->json('token');
        });
    }

    public function forInstallation(int $installationId): PendingRequest
    {
        return $this->request()->withToken($this->installationToken($installationId));
    }

    public function forProject(Project $project): PendingRequest
    {
        $installation = $project->installation ?? throw new GitHubNotConfigured("{$project->repo} is not connected to the GitHub App yet.");

        return $this->forInstallation($installation->installation_id);
    }

    /**
     * Finish the App manifest flow: trade the temporary code for the App's credentials.
     *
     * @return array<string, mixed>
     */
    public function convertManifest(string $code): array
    {
        return $this->request()->post("/app-manifests/{$code}/conversions")->json();
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        $secret = GithubApp::current()?->webhook_secret;
        if (! $secret || ! $signature) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'droids-factory'])
            ->timeout(20)
            ->throw();
    }
}
