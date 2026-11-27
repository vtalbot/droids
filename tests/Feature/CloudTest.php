<?php

namespace Tests\Feature;

use App\Models\CloudConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Laravel Cloud: the API token, linking a project, the synced snapshot, deploys, logs and metrics. */
class CloudTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://cloud.laravel.com/api';

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->project = Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red']);
    }

    private function connect(): void
    {
        CloudConnection::create(['token' => 'cloud-token', 'organization_name' => 'Talbot', 'organization_slug' => 'talbot']);
    }

    /** Fake responses for one application with a production and a staging environment. */
    private function fakeApplication(array $extra = []): void
    {
        Http::fake(array_merge([
            self::API.'/applications/app-1?*' => Http::response([
                'data' => ['id' => 'app-1', 'type' => 'applications', 'attributes' => ['name' => 'droids', 'region' => 'ca-central-1', 'repository' => ['full_name' => 'vtalbot/droids']],
                    'relationships' => ['organization' => ['data' => ['type' => 'organizations', 'id' => 'org-1']], 'defaultEnvironment' => ['data' => ['type' => 'environments', 'id' => 'env-prod']]]],
                'included' => [['type' => 'organizations', 'id' => 'org-1', 'attributes' => ['name' => 'Talbot']]],
            ]),
            self::API.'/applications/app-1/environments*' => Http::response([
                'data' => [
                    ['id' => 'env-stage', 'type' => 'environments', 'attributes' => ['name' => 'staging', 'status' => 'hibernating', 'vanity_domain' => 'droids-staging.laravel.cloud', 'php_major_version' => '8.4', 'uses_hibernation' => true],
                        'relationships' => ['branch' => ['data' => ['type' => 'branches', 'id' => 'br-2']], 'instances' => ['data' => []]]],
                    ['id' => 'env-prod', 'type' => 'environments', 'attributes' => ['name' => 'production', 'color' => 'blue', 'status' => 'running', 'php_major_version' => '8.4', 'uses_octane' => true, 'uses_push_to_deploy' => true, 'resend' => ['from_address' => 'hi@droids.dev', 'from_name' => 'Droids', 'key_name' => 'droids-production']],
                        'relationships' => ['branch' => ['data' => ['type' => 'branches', 'id' => 'br-1']], 'primaryDomain' => ['data' => ['type' => 'domains', 'id' => 'dom-1']],
                            'instances' => ['data' => [['type' => 'instances', 'id' => 'in-1'], ['type' => 'instances', 'id' => 'in-2']]]]],
                ],
                'included' => [
                    ['type' => 'branches', 'id' => 'br-1', 'attributes' => ['name' => 'main']],
                    ['type' => 'branches', 'id' => 'br-2', 'attributes' => ['name' => 'develop']],
                    ['type' => 'domains', 'id' => 'dom-1', 'attributes' => ['name' => 'droids.example.com']],
                    ['type' => 'instances', 'id' => 'in-1', 'attributes' => ['name' => 'web', 'type' => 'app', 'size' => 'flex.c-1vcpu-512mb', 'min_replicas' => 1, 'max_replicas' => 3, 'scaling_type' => 'auto']],
                    ['type' => 'instances', 'id' => 'in-2', 'attributes' => ['name' => 'jobs', 'type' => 'queue', 'size' => 'flex.c-1vcpu-256mb', 'min_replicas' => 1, 'max_replicas' => 1, 'scaling_type' => 'none']],
                ],
            ]),
            self::API.'/environments/env-prod/deployments*' => Http::response([
                'data' => [['id' => 'dep-2', 'type' => 'deployments', 'attributes' => [
                    'status' => 'deployment.succeeded', 'commit_hash' => 'abcdef1234567', 'commit_message' => "feat: dark mode\n\nLong body", 'commit_author' => 'vtalbot',
                    'branch_name' => 'main', 'started_at' => '2026-11-26T10:00:00Z', 'finished_at' => '2026-11-26T10:02:05Z',
                ], 'relationships' => ['initiator' => ['data' => ['type' => 'users', 'id' => 'u-1']]]]],
                'included' => [['type' => 'users', 'id' => 'u-1', 'attributes' => ['name' => 'Vincent']]],
            ]),
            self::API.'/environments/env-stage/deployments*' => Http::response([
                'data' => [['id' => 'dep-1', 'type' => 'deployments', 'attributes' => [
                    'status' => 'build.failed', 'commit_hash' => '1234567abc', 'commit_message' => 'fix: tests', 'branch_name' => 'develop',
                    'started_at' => '2026-11-26T09:00:00Z', 'finished_at' => '2026-11-26T09:01:00Z', 'failure_reason' => 'composer install failed',
                ]]],
                'included' => [],
            ]),
            self::API.'/environments/env-prod/metrics*' => Http::response(['data' => [
                'cpu_usage' => ['data' => [['x' => '2026-11-26T09:00:00Z', 'y' => [10, 20]], ['x' => '2026-11-26T10:00:00Z', 'y' => [30]]]],
                'memory_usage' => ['data' => [['x' => '2026-11-26T09:00:00Z', 'y' => [40, 50]], ['x' => '2026-11-26T10:00:00Z', 'y' => [60]]]],
            ]]),
            self::API.'/environments/env-prod/logs*' => Http::response(['data' => [
                ['logged_at' => '2026-11-26T10:05:00Z', 'level' => 'warning', 'message' => "Slow query\nselect *"],
            ]]),
        ], $extra));
    }

    private function linkProject(): void
    {
        $this->connect();
        $this->fakeApplication();
        $this->postJson('/factory/projects/droids/cloud', ['application' => 'app-1'])->assertOk();
        $this->project->refresh();
    }

    public function test_saving_a_token_checks_it_with_laravel_cloud(): void
    {
        Http::fake([self::API.'/meta/organization' => Http::response(['data' => ['attributes' => ['name' => 'Talbot', 'slug' => 'talbot']]])]);

        $this->post('/settings/cloud', ['token' => ' cloud-token '])->assertRedirect('/settings/cloud');

        $connection = CloudConnection::current();
        $this->assertSame('cloud-token', $connection?->token);
        $this->assertSame('Talbot', $connection->organization_name);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer cloud-token'));
        $this->assertStringNotContainsString('cloud-token', (string) $connection->getRawOriginal('token'));
    }

    public function test_a_rejected_token_is_not_saved(): void
    {
        Http::fake([self::API.'/meta/organization' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->post('/settings/cloud', ['token' => 'bad'])->assertSessionHasErrors('token');

        $this->assertNull(CloudConnection::current());
    }

    public function test_disconnecting_removes_the_token(): void
    {
        $this->connect();

        $this->delete('/settings/cloud')->assertRedirect('/settings/cloud');

        $this->assertNull(CloudConnection::current());
    }

    public function test_applications_from_the_project_repo_come_first(): void
    {
        $this->connect();
        Http::fake([self::API.'/applications*' => Http::response(['data' => [
            ['id' => 'a', 'attributes' => ['name' => 'alpha', 'region' => 'us-east-2', 'repository' => ['full_name' => 'vtalbot/other']]],
            ['id' => 'b', 'attributes' => ['name' => 'beta', 'region' => 'ca-central-1', 'repository' => ['full_name' => 'vtalbot/droids']]],
        ]])]);

        $this->getJson('/factory/cloud/applications?repo=vtalbot/droids')
            ->assertOk()
            ->assertJsonPath('applications.0.id', 'b')
            ->assertJsonPath('applications.1.id', 'a');
    }

    public function test_calls_without_a_token_ask_for_one(): void
    {
        $this->getJson('/factory/cloud/applications')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add a Laravel Cloud API token in Settings > Laravel Cloud first.');
    }

    public function test_an_expired_token_says_so(): void
    {
        $this->connect();
        Http::fake([self::API.'/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->getJson('/factory/cloud/applications')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'rejected the API token'));
    }

    public function test_linking_builds_the_snapshot(): void
    {
        $this->linkProject();

        $snapshot = $this->project->cloud_snapshot;
        $this->assertSame('app-1', $this->project->cloud_application_id);
        $this->assertNotNull($this->project->cloud_synced_at);
        $this->assertTrue($snapshot['linked']);
        $this->assertSame(['droids', 'Talbot', 'ca-central-1', 'vtalbot/droids'], [$snapshot['app'], $snapshot['org'], $snapshot['region'], $snapshot['repo']]);

        // The default environment comes first, even though the API listed it second.
        [$prod, $stage] = $snapshot['envs'];
        $this->assertSame(['env-prod', 'production', 'blue', 'running', 'droids.example.com', 'main'], [$prod['id'], $prod['name'], $prod['color'], $prod['status'], $prod['domain'], $prod['branch']]);
        $this->assertTrue($prod['octane'] && $prod['pushToDeploy']);
        $this->assertSame(['web', 'App', 1, 3, 'auto', null], [$prod['instances'][0]['name'], $prod['instances'][0]['type'], $prod['instances'][0]['min'], $prod['instances'][0]['max'], $prod['instances'][0]['scaling'], $prod['instances'][0]['queue']]);
        $this->assertSame(['Worker', 'queue'], [$prod['instances'][1]['type'], $prod['instances'][1]['queue']]);
        $this->assertSame(['hibernating', 'droids-staging.laravel.cloud', 'develop'], [$stage['status'], $stage['domain'], $stage['branch']]);

        // Newest deployment first.
        [$latest, $failed] = $snapshot['deployments'];
        $this->assertSame(['dep-2', 'env-prod', 'abcdef1', 'feat: dark mode', '2m 5s', 'Deployed by Vincent'], [$latest['id'], $latest['env'], $latest['commit'], $latest['message'], $latest['duration'], $latest['via']]);
        $this->assertArrayNotHasKey('failure', $latest);
        $this->assertSame(['build.failed', 'composer install failed', 'Push to deploy'], [$failed['status'], $failed['failure'], $failed['via']]);

        $this->assertEquals([15, 30], $snapshot['cpu']);
        $this->assertEquals([45, 60], $snapshot['mem']);
        $this->assertSame(['10:05:00 WARN Slow query select *'], $snapshot['logs']);
    }

    public function test_the_snapshot_reaches_the_factory_state(): void
    {
        $this->linkProject();

        $this->postJson('/factory/projects/droids/cloud/refresh')
            ->assertOk()
            ->assertJsonPath('store.cloudConnected', true)
            ->assertJsonPath('store.cloud.droids.app', 'droids');
    }

    public function test_logs_and_metrics_are_optional(): void
    {
        $this->connect();
        $this->fakeApplication([
            self::API.'/environments/env-prod/metrics*' => Http::response(['message' => 'Forbidden'], 403),
            self::API.'/environments/env-prod/logs*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $this->postJson('/factory/projects/droids/cloud', ['application' => 'app-1'])->assertOk();

        $snapshot = $this->project->refresh()->cloud_snapshot;
        $this->assertSame([], $snapshot['cpu']);
        $this->assertSame([], $snapshot['logs']);
        $this->assertCount(2, $snapshot['envs']);
    }

    public function test_unlinking_clears_the_snapshot(): void
    {
        $this->linkProject();

        $this->deleteJson('/factory/projects/droids/cloud')->assertOk();

        $this->project->refresh();
        $this->assertNull($this->project->cloud_application_id);
        $this->assertNull($this->project->cloud_snapshot);
    }

    public function test_deploying_starts_a_deployment_on_a_known_environment(): void
    {
        $this->linkProject();
        $this->postJson('/factory/projects/droids/cloud/deploy', ['environment' => 'env-prod'])->assertCreated();
        $this->postJson('/factory/projects/droids/cloud/deploy', ['environment' => 'env-other'])->assertUnprocessable();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API.'/environments/env-prod/deployments');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'env-other'));
    }

    public function test_deployment_logs_list_build_and_deploy_steps(): void
    {
        $this->linkProject();
        Http::fake([self::API.'/deployments/dep-1/logs' => Http::response(['data' => [
            'build' => ['steps' => [['step' => 'install', 'description' => 'Install dependencies', 'status' => 'failed', 'output' => 'composer install failed']]],
            'deploy' => ['steps' => []],
        ]])]);

        $this->getJson('/factory/projects/droids/cloud/deployments/dep-1/logs')
            ->assertOk()
            ->assertExactJson(['steps' => [['phase' => 'build', 'step' => 'Install dependencies', 'status' => 'failed', 'output' => 'composer install failed']]]);

        $this->getJson('/factory/projects/droids/cloud/deployments/dep-unknown/logs')->assertNotFound();
    }

    public function test_metrics_for_another_period(): void
    {
        $this->linkProject();

        $this->getJson('/factory/projects/droids/cloud/metrics?period=24h')
            ->assertOk()
            ->assertJsonPath('cpu', [15, 30])
            ->assertJsonPath('times.0', '2026-11-26T09:00:00Z');
        $this->getJson('/factory/projects/droids/cloud/metrics?period=1y')->assertUnprocessable();
    }

    public function test_unlinked_projects_have_no_cloud_routes(): void
    {
        $this->connect();

        $this->postJson('/factory/projects/droids/cloud/refresh')->assertNotFound();
        $this->getJson('/factory/projects/droids/cloud/metrics?period=24h')->assertNotFound();
    }

    public function test_the_sync_command_refreshes_linked_projects(): void
    {
        $this->linkProject();
        $this->project->update(['cloud_snapshot' => ['app' => 'stale']]);

        $this->artisan('cloud:sync')->assertSuccessful();

        $this->assertSame('droids', $this->project->refresh()->cloud_snapshot['app']);
    }

    public function test_an_application_can_be_created_from_the_project_repo(): void
    {
        $this->connect();
        $this->fakeApplication([
            self::API.'/applications' => Http::response(['data' => ['id' => 'app-1', 'type' => 'applications', 'attributes' => ['name' => 'Droids']]], 201),
        ]);

        $this->postJson('/factory/projects/droids/cloud/applications', ['name' => 'Droids', 'region' => 'ca-central-1'])->assertCreated();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API.'/applications'
            && $r['repository'] === 'vtalbot/droids' && $r['name'] === 'Droids' && $r['region'] === 'ca-central-1' && $r['source_control_provider_type'] === 'github');
        $this->assertSame('app-1', $this->project->refresh()->cloud_application_id);
        $this->assertTrue($this->project->cloud_snapshot['linked']);
    }

    public function test_creating_an_application_shows_cloud_validation_errors(): void
    {
        $this->connect();
        Http::fake([self::API.'/applications' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['repository' => ['The repository is not connected to Laravel Cloud.']]], 422)]);

        $this->postJson('/factory/projects/droids/cloud/applications', ['name' => 'Droids', 'region' => 'ca-central-1'])
            ->assertUnprocessable()->assertJsonPath('message', 'Laravel Cloud: The repository is not connected to Laravel Cloud.');
        $this->postJson('/factory/projects/droids/cloud/applications', ['name' => 'Droids', 'region' => 'mars-1'])->assertJsonValidationErrors('region');
        $this->assertNull($this->project->refresh()->cloud_application_id);
    }

    public function test_email_goes_through_the_cloud_resend_integration(): void
    {
        $this->linkProject();
        [$prod, $stage] = $this->project->cloud_snapshot['envs'];
        $this->assertSame(['from' => 'hi@droids.dev', 'name' => 'Droids'], $prod['email']);
        $this->assertNull($stage['email']);
        Http::fake([self::API.'/environments/*/resend' => Http::response(['data' => []])]);

        $this->putJson('/factory/projects/droids/cloud/email', ['environment' => 'env-stage', 'from_address' => 'staging@droids.dev', 'from_name' => 'Droids staging'])->assertOk();
        $this->putJson('/factory/projects/droids/cloud/email', ['environment' => 'env-prod', 'from_address' => 'team@droids.dev', 'from_name' => 'Droids'])->assertOk();
        $this->putJson('/factory/projects/droids/cloud/email', ['environment' => 'env-prod', 'from_address' => 'nope', 'from_name' => 'Droids'])->assertJsonValidationErrors('from_address');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::API.'/environments/env-stage/resend' && $r['from_name'] === 'Droids staging');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === self::API.'/environments/env-prod/resend' && $r['from_address'] === 'team@droids.dev');
    }
}
