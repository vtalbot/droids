<?php

namespace Tests\Feature;

use App\GitHub\GitHub;
use App\Models\GithubApp;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    private function pem(): string
    {
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $out);
            self::$pem = $out;
        }

        return self::$pem;
    }

    private function app(): GithubApp
    {
        return GithubApp::create([
            'app_id' => 123,
            'slug' => 'droids-test',
            'name' => 'Droids test',
            'owner_login' => 'vtalbot',
            'html_url' => 'https://github.com/apps/droids-test',
            'client_id' => 'Iv1.abc',
            'client_secret' => 'secret',
            'private_key' => $this->pem(),
            'webhook_secret' => 'hook-secret',
        ]);
    }

    private function linkedProject(): Project
    {
        $this->app();
        $installation = GithubInstallation::create([
            'installation_id' => 42,
            'account_login' => 'vtalbot',
            'account_type' => 'User',
            'repository_selection' => 'selected',
        ]);

        return Project::create([
            'slug' => 'droids',
            'name' => 'Droids',
            'repo' => 'vtalbot/droids',
            'board' => 'Roadmap',
            'color' => 'red',
            'github_installation_id' => $installation->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function issue(int $number, string $state = 'open', ?array $milestone = null): array
    {
        return [
            'id' => 1000 + $number,
            'number' => $number,
            'title' => "Issue {$number}",
            'state' => $state,
            'labels' => [['name' => 'bug']],
            'html_url' => "https://github.com/vtalbot/droids/issues/{$number}",
            'milestone' => $milestone,
        ];
    }

    /** @return array<string, mixed> */
    private function pull(int $number, array $overrides = []): array
    {
        return array_merge([
            'id' => 5000 + $number,
            'number' => $number,
            'title' => "PR {$number}",
            'state' => 'open',
            'draft' => false,
            'merged_at' => null,
            'body' => 'Closes #7',
            'head' => ['ref' => 'feat/thing', 'sha' => 'abc123'],
            'html_url' => "https://github.com/vtalbot/droids/pull/{$number}",
            'additions' => 10,
            'deletions' => 2,
            'commits' => 1,
            'changed_files' => 3,
        ], $overrides);
    }

    private function fakeToken(): array
    {
        return ['api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_test'], 201)];
    }

    public function test_jwt_is_signed_with_the_app_key(): void
    {
        $this->app();
        [$header, $payload, $signature] = explode('.', app(GitHub::class)->jwt());

        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame('RS256', json_decode($decode($header), true)['alg']);
        $this->assertSame('Iv1.abc', json_decode($decode($payload), true)['iss']);

        $public = openssl_pkey_get_details(openssl_pkey_get_private($this->pem()))['key'];
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function test_settings_page_shows_the_app_and_installations(): void
    {
        $this->linkedProject();

        $this->get('/settings/github')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/github')
            ->where('app.name', 'Droids test')
            ->where('installations.0.account', 'vtalbot')
            ->where('projects.0.repo', 'vtalbot/droids'));
    }

    public function test_manifest_flow_creates_the_app_and_sends_people_to_install_it(): void
    {
        $response = $this->get('/settings/github/create')->assertOk();
        $state = session('github_manifest_state');
        $response->assertSee('https://github.com/settings/apps/new?state='.$state, false);
        $response->assertSee('&quot;issues&quot;:&quot;write&quot;', false);

        Http::fake(['api.github.com/app-manifests/code-1/conversions' => Http::response([
            'id' => 777, 'slug' => 'droids-x', 'name' => 'Droids x', 'owner' => ['login' => 'vtalbot'],
            'html_url' => 'https://github.com/apps/droids-x', 'client_id' => 'Iv1.x', 'client_secret' => 's',
            'pem' => $this->pem(), 'webhook_secret' => 'w',
        ], 201)]);

        $this->get("/settings/github/created?code=code-1&state={$state}")
            ->assertRedirect('https://github.com/apps/droids-x/installations/new');

        $app = GithubApp::current();
        $this->assertSame(777, $app->app_id);
        $this->assertSame('w', $app->webhook_secret);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $app->getRawOriginal('private_key'));
        // GitHub rejects a JSON [] body on these endpoints, so none may be sent.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/conversions') && $r->body() === '');
    }

    public function test_manifest_callback_rejects_a_wrong_state(): void
    {
        $this->get('/settings/github/created?code=x&state=nope')->assertForbidden();
    }

    public function test_manifest_callback_explains_a_rejected_code(): void
    {
        $this->get('/settings/github/create');
        $state = session('github_manifest_state');
        Http::fake(['api.github.com/app-manifests/used/conversions' => Http::response(['message' => 'Not Found'], 404)]);

        $this->get("/settings/github/created?code=used&state={$state}")->assertRedirect('/settings/github');

        $this->assertNull(GithubApp::current());
    }

    public function test_install_callback_without_an_app_redirects_instead_of_failing(): void
    {
        $this->get('/settings/github/installed?installation_id=42')->assertRedirect('/settings/github');

        $this->assertSame(0, GithubInstallation::count());
    }

    public function test_install_callback_records_the_installation(): void
    {
        $this->app();
        Http::fake(['api.github.com/app/installations/42' => Http::response([
            'id' => 42, 'account' => ['login' => 'vtalbot', 'type' => 'User'], 'repository_selection' => 'all',
        ])]);

        $this->get('/settings/github/installed?installation_id=42')->assertRedirect('/settings/github');

        $this->assertDatabaseHas('github_installations', ['installation_id' => 42, 'repository_selection' => 'all']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->header('Authorization')[0], 'Bearer ey'));
    }

    public function test_repo_picker_lists_installed_repos_not_yet_linked(): void
    {
        $this->linkedProject();
        Http::fake($this->fakeToken() + ['api.github.com/installation/repositories*' => Http::response(['repositories' => [
            ['full_name' => 'vtalbot/droids', 'private' => false],
            ['full_name' => 'vtalbot/site', 'private' => true],
        ]])]);

        $this->getJson('/factory/github/repos')->assertOk()->assertExactJson([
            'configured' => true,
            'repos' => [['repo' => 'vtalbot/site', 'private' => true, 'installation' => 42]],
        ]);
    }

    public function test_repo_picker_says_when_the_app_is_missing(): void
    {
        $this->getJson('/factory/github/repos')->assertOk()->assertJson(['configured' => false]);
    }

    public function test_sync_pulls_milestones_issues_and_pull_requests(): void
    {
        $project = $this->linkedProject();
        $milestone = ['number' => 1, 'title' => 'v1', 'description' => 'Ship it', 'state' => 'open', 'due_on' => '2026-12-20T08:00:00Z'];

        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/milestones*' => Http::response([$milestone]),
            'api.github.com/repos/vtalbot/droids/issues*' => Http::response([
                $this->issue(7, 'open', $milestone),
                $this->issue(8, 'closed'),
                $this->issue(9) + ['pull_request' => []],
            ]),
            'api.github.com/repos/vtalbot/droids/pulls/9/reviews*' => Http::response([
                ['state' => 'APPROVED', 'user' => ['login' => 'vtalbot']],
            ]),
            'api.github.com/repos/vtalbot/droids/pulls/9' => Http::response($this->pull(9)),
            'api.github.com/repos/vtalbot/droids/pulls*' => Http::response([$this->pull(9)]),
            'api.github.com/repos/vtalbot/droids/commits/abc123/check-runs*' => Http::response(['check_runs' => [
                ['name' => 'tests', 'status' => 'completed', 'conclusion' => 'failure', 'started_at' => '2026-11-26T00:00:00Z', 'completed_at' => '2026-11-26T00:01:30Z', 'html_url' => 'https://x', 'details_url' => 'https://github.com/vtalbot/droids/actions/runs/1/job/555'],
                ['name' => 'lint', 'status' => 'in_progress', 'conclusion' => null],
            ]]),
        ]);

        $this->postJson('/factory/projects/droids/sync')->assertOk()
            ->assertJsonPath('store.projects.0.linked', true)
            ->assertJsonPath('store.prs.droids:7.state', 'approved')
            ->assertJsonPath('store.prs.droids:7.checks.0', ['name' => 'tests', 'state' => 'fail', 'dur' => '1m 30s', 'url' => 'https://x', 'job' => 555])
            ->assertJsonPath('store.prs.droids:7.checks.1.state', 'running');

        $this->assertSame(['review', 'done'], $project->tasks()->orderBy('number')->pluck('column')->all());
        $this->assertNull($project->tasks()->where('number', 9)->first());
        $this->assertSame('active', $project->milestones()->where('github_number', 1)->value('state'));
        $this->assertSame('Unsorted', Task::where('number', 7)->first()->feature->name);
        $this->assertNotNull($project->fresh()->synced_at);
    }

    public function test_connecting_a_repo_through_the_app_links_and_syncs_it(): void
    {
        $this->linkedProject();
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/site/milestones*' => Http::response([]),
            'api.github.com/repos/vtalbot/site/issues*' => Http::response([$this->issue(1)]),
            'api.github.com/repos/vtalbot/site/pulls*' => Http::response([]),
        ]);

        $this->postJson('/factory/projects', ['repo' => 'vtalbot/site', 'installation' => 42])
            ->assertCreated()
            ->assertJsonPath('project', 'site')
            ->assertJsonPath('warning', null)
            ->assertJsonPath('store.issues.0.title', 'Issue 1');
    }

    public function test_new_tasks_on_a_linked_project_become_github_issues(): void
    {
        $project = $this->linkedProject();
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/issues' => Http::response($this->issue(31), 201),
        ]);

        $this->postJson('/factory/projects/droids/tasks', ['tasks' => [['title' => 'Issue 31', 'labels' => ['bug'], 'engine' => 'codex']]])
            ->assertCreated()->assertJsonPath('numbers', [31]);

        $this->assertSame('codex', $project->tasks()->where('number', 31)->value('engine'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/issues') && $r['title'] === 'Issue 31' && $r['labels'] === ['bug']);
    }

    public function test_github_errors_come_back_as_a_readable_message(): void
    {
        $this->linkedProject();
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/issues' => Http::response(['message' => 'Issues are disabled for this repo'], 410),
        ]);

        $this->postJson('/factory/projects/droids/tasks', ['tasks' => [['title' => 'x']]])
            ->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'GitHub'));
    }

    private function prFor(Project $project, string $state, array $checks = []): PullRequest
    {
        $task = $project->tasks()->create(['number' => 7, 'title' => 'Seven', 'column' => 'review', 'position' => 1]);

        return PullRequest::create([
            'task_id' => $task->id, 'number' => 9, 'title' => 'PR 9', 'branch' => 'feat/thing', 'head_sha' => 'abc123',
            'state' => $state, 'checks' => $checks, 'reviews' => [],
        ]);
    }

    public function test_merge_squashes_an_approved_pull_request(): void
    {
        $project = $this->linkedProject();
        $this->prFor($project, 'approved');
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/pulls/9/merge' => Http::response(['merged' => true]),
            'api.github.com/repos/vtalbot/droids/pulls/9/reviews*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/pulls/9' => Http::response($this->pull(9, ['state' => 'closed', 'merged_at' => '2026-11-26T01:00:00Z'])),
            'api.github.com/repos/vtalbot/droids/commits/abc123/check-runs*' => Http::response(['check_runs' => []]),
        ]);

        $this->postJson('/factory/projects/droids/pulls/9/merge')->assertOk()
            ->assertJsonPath('store.prs.droids:7.state', 'merged');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/merge') && $r['merge_method'] === 'squash' && $r['sha'] === 'abc123');
        $this->assertSame('done', $project->tasks()->where('number', 7)->value('column'));
    }

    public function test_merge_refuses_an_unapproved_pull_request(): void
    {
        $project = $this->linkedProject();
        $this->prFor($project, 'open');

        $this->postJson('/factory/projects/droids/pulls/9/merge')->assertUnprocessable();
    }

    public function test_rerun_restarts_failed_actions_jobs(): void
    {
        $project = $this->linkedProject();
        $this->prFor($project, 'open', [
            ['name' => 'tests', 'state' => 'fail', 'job' => 555],
            ['name' => 'lint', 'state' => 'pass', 'job' => 556],
        ]);
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/actions/jobs/555/rerun' => Http::response([], 201),
            'api.github.com/repos/vtalbot/droids/pulls/9/reviews*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/pulls/9' => Http::response($this->pull(9)),
            'api.github.com/repos/vtalbot/droids/commits/abc123/check-runs*' => Http::response(['check_runs' => []]),
        ]);

        $this->postJson('/factory/projects/droids/pulls/9/rerun')->assertOk()->assertJsonPath('rerun', 1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/access_tokens') && $r->body() === '');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'jobs/555/rerun') && $r->body() === '');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'jobs/556'));
    }

    private function webhook(string $event, array $payload, string $secret = 'hook-secret')
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/github/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    public function test_webhooks_need_a_valid_signature(): void
    {
        $this->app();
        auth()->logout();

        $this->webhook('ping', ['zen' => 'hi'], 'wrong')->assertUnauthorized();
        $this->webhook('ping', ['zen' => 'hi'])->assertStatus(202);
    }

    public function test_issue_webhooks_update_the_board(): void
    {
        $project = $this->linkedProject();
        auth()->logout();
        $repo = ['full_name' => 'vtalbot/droids'];

        $this->webhook('issues', ['action' => 'opened', 'issue' => $this->issue(12), 'repository' => $repo])->assertStatus(202);
        $this->assertSame('backlog', $project->tasks()->where('number', 12)->value('column'));

        $this->webhook('issues', ['action' => 'closed', 'issue' => $this->issue(12, 'closed'), 'repository' => $repo]);
        $this->assertSame('done', $project->tasks()->where('number', 12)->value('column'));

        $this->webhook('issues', ['action' => 'deleted', 'issue' => $this->issue(12), 'repository' => $repo]);
        $this->assertFalse($project->tasks()->where('number', 12)->exists());
    }

    public function test_webhooks_for_unlinked_repos_are_ignored(): void
    {
        $this->linkedProject();

        $this->webhook('issues', ['action' => 'opened', 'issue' => $this->issue(3), 'repository' => ['full_name' => 'someone/else']])->assertStatus(202);
        $this->assertSame(0, Task::count());
    }

    public function test_installation_webhooks_track_suspension_and_removal(): void
    {
        $this->linkedProject();
        $installation = ['id' => 42, 'account' => ['login' => 'vtalbot', 'type' => 'User']];

        $this->webhook('installation', ['action' => 'suspend', 'installation' => $installation]);
        $this->assertNotNull(GithubInstallation::first()->suspended_at);

        $this->webhook('installation', ['action' => 'deleted', 'installation' => $installation]);
        $this->assertSame(0, GithubInstallation::count());
        $this->assertNull(Project::first()->github_installation_id);
    }

    public function test_scheduled_sync_command_runs_for_linked_projects(): void
    {
        $this->linkedProject();
        Http::fake($this->fakeToken() + [
            'api.github.com/repos/vtalbot/droids/milestones*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/issues*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/pulls*' => Http::response([]),
        ]);

        $this->artisan('github:sync')->expectsOutput('Synced vtalbot/droids')->assertSuccessful();
    }
}
