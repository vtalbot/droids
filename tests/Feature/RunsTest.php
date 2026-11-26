<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Droid;
use App\Models\GithubApp;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Task;
use App\Models\User;
use App\Models\Worker;
use App\Runs\SubscriptionPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunsTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Droid $droid;

    private Task $task;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['droids.timezone' => 'America/Toronto']);
        // Thursday 10:00 in Toronto.
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00', 'America/Toronto'));

        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$pem = $pem;
        }
        GithubApp::create([
            'app_id' => 1, 'slug' => 'droids', 'name' => 'Droids', 'html_url' => 'https://github.com/apps/droids',
            'client_id' => 'Iv1.x', 'private_key' => self::$pem, 'webhook_secret' => 'hook',
        ]);
        $installation = GithubInstallation::create(['installation_id' => 42, 'account_login' => 'vtalbot']);
        $this->project = Project::create([
            'slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red',
            'github_installation_id' => $installation->id,
        ]);
        $this->task = $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode toggle', 'column' => 'ready', 'position' => 1, 'labels' => ['ui']]);
        $this->droid = Droid::create([
            'slug' => 'cog', 'unit' => 'CL-01', 'name' => 'Cog', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Builder',
            'instructions' => 'Write tests first.',
        ]);

        $this->sub('claude-max', 'Claude Max');
        $this->sub('claude-pro', 'Claude Pro');
        $this->paint('claude', 'claude-max');

        [, $this->token] = Worker::issue('MacBook');
    }

    private function sub(string $slug, string $name, ?string $apiKey = null): Subscription
    {
        return Subscription::create(['slug' => $slug, 'provider' => 'claude', 'name' => $name, 'color' => 'red', 'enabled' => true, 'api_key' => $apiKey]);
    }

    private function paint(string $provider, string $slug): void
    {
        SubscriptionSchedule::updateOrCreate(['provider' => $provider], ['grid' => array_fill(0, 7, array_fill(0, 24, $slug))]);
    }

    private function github(): array
    {
        return [
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201),
            'api.github.com/repos/vtalbot/droids/issues/7' => Http::response(['body' => 'The toggle goes in the header.']),
        ];
    }

    private function assign(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/factory/projects/droids/tasks/7/assign', ['droid' => 'cog', 'as' => 'builder'])
            ->assertOk();
    }

    private function claim(array $subscriptions = ['claude-max', 'claude-pro'])
    {
        return $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => $subscriptions]);
    }

    public function test_assigning_a_builder_on_a_linked_repo_queues_a_run(): void
    {
        $this->assign();

        $run = Run::sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('droids/7-add-dark-mode-toggle', $run->branch);
        $this->assertStringContainsString('waiting for a worker', $this->droid->fresh()->step);
    }

    public function test_unlinked_projects_do_not_queue_runs(): void
    {
        $this->project->update(['github_installation_id' => null]);
        $this->assign();

        $this->assertSame(0, Run::count());
    }

    public function test_reassigning_cancels_the_previous_run(): void
    {
        $this->assign();
        $this->assign();

        $this->assertSame(['cancelled', 'queued'], Run::orderBy('id')->pluck('status')->all());
    }

    public function test_picker_follows_the_schedule_and_falls_back_when_limited(): void
    {
        $picker = app(SubscriptionPicker::class);
        $this->assertSame('claude-max', $picker->pick('claude')?->slug);

        Subscription::where('slug', 'claude-max')->update(['limited_until' => now()->addHour()]);
        $this->assertSame('claude-pro', $picker->pick('claude')?->slug);

        $this->assertNull($picker->pick('claude', ['claude-max']), 'the worker is not signed in to the fallback');
    }

    public function test_picker_starts_nothing_in_paused_hours(): void
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 'claude-max'));
        $grid[3][10] = ''; // Thursday 10:00
        SubscriptionSchedule::where('provider', 'claude')->update(['grid' => $grid]);

        $this->assertNull(app(SubscriptionPicker::class)->pick('claude'));
    }

    public function test_api_key_subscriptions_need_no_login_on_the_worker(): void
    {
        $this->sub('anthropic-api', 'Anthropic API key', 'sk-ant-test');
        $this->paint('claude', 'anthropic-api');

        $this->assertSame('anthropic-api', app(SubscriptionPicker::class)->pick('claude', [])?->slug);
    }

    public function test_workers_need_a_valid_token(): void
    {
        $this->withToken('dw_nope')->postJson('/api/worker/claim', ['subscriptions' => []])->assertUnauthorized();
    }

    public function test_claim_hands_out_the_job_with_prompt_token_and_model(): void
    {
        $this->assign();
        Http::fake($this->github());

        $run = $this->claim()->assertOk()->json('run');

        $this->assertSame('vtalbot/droids', $run['repo']);
        $this->assertSame('ghs_fresh', $run['clone_token']);
        $this->assertSame('sonnet', $run['model']);
        $this->assertSame('claude-max', $run['subscription']['slug']);
        $this->assertStringContainsString('#7: Add dark mode toggle', $run['prompt']);
        $this->assertStringContainsString('The toggle goes in the header.', $run['prompt']);
        $this->assertStringContainsString('Write tests first.', $run['prompt']);
        $this->assertSame('feat: Add dark mode toggle (#7)', $run['commit_message']);

        $this->assertSame('running', Run::sole()->status);
        $this->assertSame(['claude-max', 'claude-pro'], Worker::sole()->subscriptions);
        $this->assertNull($this->claim()->json('run'), 'a running job is not handed out twice');
    }

    public function test_claim_waits_when_no_subscription_is_usable(): void
    {
        $this->assign();

        $this->claim([])->assertOk()->assertJsonPath('run', null);
        $this->assertSame('queued', Run::sole()->status);
    }

    public function test_progress_updates_the_droid_and_the_log(): void
    {
        $this->assign();
        Http::fake($this->github());
        $id = $this->claim()->json('run.id');

        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/progress", [
            'step' => 'Running php artisan test', 'progress' => 0.4, 'log' => "› Running php artisan test\n",
        ])->assertOk()->assertJsonPath('cancelled', false);

        $this->assertSame('Running php artisan test', $this->droid->fresh()->step);
        $this->assertEqualsWithDelta(0.4, $this->droid->fresh()->progress, 0.001);
        $this->assertStringContainsString('php artisan test', (string) Run::sole()->log);
    }

    public function test_another_worker_cannot_report_on_the_run(): void
    {
        $this->assign();
        Http::fake($this->github());
        $id = $this->claim()->json('run.id');
        [, $other] = Worker::issue('Other');

        $this->withToken($other)->postJson("/api/worker/runs/{$id}/progress", ['step' => 'x'])->assertNotFound();
    }

    public function test_a_pushed_branch_becomes_a_pull_request(): void
    {
        $this->assign();
        Http::fake($this->github() + [
            'api.github.com/repos/vtalbot/droids/pulls/12/reviews*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/pulls/12' => Http::response($this->pull()),
            'api.github.com/repos/vtalbot/droids/pulls' => Http::response($this->pull(), 201),
            'api.github.com/repos/vtalbot/droids/commits/*' => Http::response(['check_runs' => []]),
            'api.github.com/repos/vtalbot/droids' => Http::response(['default_branch' => 'main']),
        ]);
        $id = $this->claim()->json('run.id');

        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", [
            'status' => 'succeeded', 'pushed' => true, 'summary' => 'Added the toggle and a test.', 'cost' => 0.42,
            'tokens_in' => 1000, 'tokens_out' => 200,
        ])->assertOk()->assertJsonPath('pr', 12);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls')
            && $r['head'] === 'droids/7-add-dark-mode-toggle' && $r['base'] === 'main' && str_starts_with($r['body'], 'Closes #7'));
        $this->assertSame('review', $this->task->fresh()->column);
        $this->assertSame('idle', $this->droid->fresh()->status);
        $this->assertSame(12, PullRequest::where('task_id', $this->task->id)->value('number'));
        $this->assertTrue(Activity::where('text', 'like', 'Opened PR #12%')->exists());
    }

    public function test_hitting_a_usage_limit_requeues_on_another_subscription(): void
    {
        $this->assign();
        Http::fake($this->github());
        $id = $this->claim()->json('run.id');

        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", [
            'status' => 'limited', 'limited_until' => now()->addHours(3)->toIso8601String(), 'error' => 'Claude AI usage limit reached',
        ])->assertOk()->assertJsonPath('status', 'queued');

        $this->assertTrue(Subscription::where('slug', 'claude-max')->sole()->isLimited());
        $this->assertSame('claude-pro', $this->claim()->json('run.subscription.slug'));
    }

    public function test_a_failed_run_blocks_the_droid(): void
    {
        $this->assign();
        Http::fake($this->github());
        $id = $this->claim()->json('run.id');

        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", ['status' => 'failed', 'error' => 'Tests would not pass.'])->assertOk();

        $this->assertSame('blocked', $this->droid->fresh()->status);
        $this->assertSame('Tests would not pass.', $this->droid->fresh()->step);
        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/progress", ['step' => 'x'])->assertStatus(409);
    }

    public function test_stalled_runs_are_swept(): void
    {
        $this->assign();
        Http::fake($this->github());
        $this->claim();

        Carbon::setTestNow(now()->addMinutes(20));
        $this->artisan('droids:sweep')->assertSuccessful();

        $this->assertSame('failed', Run::sole()->status);
        $this->assertSame('blocked', $this->droid->fresh()->status);
    }

    public function test_workers_settings_issue_a_token_once(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/settings/workers', ['name' => 'Studio'])->assertRedirect('/settings/workers');
        $token = session('worker_token')['token'];

        $this->assertNotNull(Worker::findByToken($token));
        $this->assertStringNotContainsString($token, (string) Worker::where('name', 'Studio')->value('token_hash'));
    }

    public function test_the_store_shows_the_latest_run_per_task(): void
    {
        $this->assign();

        $this->getJson('/factory/state')->assertOk()
            ->assertJsonPath('store.runs.droids:7.status', 'queued')
            ->assertJsonPath('store.workers.total', 1);
    }

    public function test_the_store_shows_which_workers_are_signed_in_to_each_subscription(): void
    {
        $this->sub('claude-api', 'Claude API', 'sk-ant-key');
        $this->claim(['claude-max']);

        $subs = collect($this->actingAs(User::factory()->create())->getJson('/factory/state')->assertOk()->json('store.subs'))->keyBy('id');

        $this->assertSame(['MacBook'], $subs['claude-max']['signedInOn']);
        $this->assertSame('Signed in on MacBook', $subs['claude-max']['auth']);
        $this->assertSame([], $subs['claude-pro']['signedInOn']);
        $this->assertFalse($subs['claude-pro']['api']);
        $this->assertTrue($subs['claude-api']['api']);
    }

    /** @return array<string, mixed> */
    private function pull(): array
    {
        return [
            'id' => 9012, 'number' => 12, 'title' => 'Add dark mode toggle', 'state' => 'open', 'draft' => false, 'merged_at' => null,
            'body' => 'Closes #7', 'head' => ['ref' => 'droids/7-add-dark-mode-toggle', 'sha' => 'abc'], 'html_url' => 'https://github.com/vtalbot/droids/pull/12',
            'additions' => 20, 'deletions' => 1, 'commits' => 1, 'changed_files' => 2,
        ];
    }
}
