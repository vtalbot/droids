<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\GithubApp;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Task;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Blocked droids: retried by hand, looked at by a Mechanic, and droids deleted. */
class MechanicTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Task $task;

    private Droid $builder;

    private Run $failed;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$pem = $pem;
        }
        GithubApp::create(['app_id' => 1, 'slug' => 'droids', 'name' => 'Droids', 'html_url' => 'https://github.com/apps/droids', 'client_id' => 'Iv1.x', 'private_key' => self::$pem, 'webhook_secret' => 'hook']);
        $installation = GithubInstallation::create(['installation_id' => 42, 'account_login' => 'vtalbot']);
        $this->project = Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red', 'github_installation_id' => $installation->id]);
        $this->task = $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode', 'column' => 'progress', 'position' => 1]);
        $this->builder = Droid::create(['slug' => 'cog', 'unit' => 'CX-01', 'name' => 'Cog', 'provider' => 'codex', 'model' => 'GPT-5.1-Codex', 'role' => 'Builder', 'project_id' => $this->project->id, 'status' => 'blocked', 'task_id' => $this->task->id]);
        $this->task->update(['builder_id' => $this->builder->id]);
        $this->failed = Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'build', 'status' => 'failed', 'branch' => 'droids/7-add-dark-mode', 'error' => "The 'gpt-5.1-codex' model is not supported.", 'log' => 'codex: error: unsupported model']);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    public function test_a_blocked_droid_retries_on_its_new_model(): void
    {
        $this->patchJson('/factory/droids/cog', ['model' => 'GPT-6.2-Sol'])->assertOk();
        $this->postJson('/factory/droids/cog/retry')->assertOk()->assertJsonPath('store.droids.0.status', 'working');

        $retry = Run::latest('id')->first();
        $this->assertSame(['build', 'queued', 'droids/7-add-dark-mode'], [$retry->kind, $retry->status, $retry->branch]);
        $this->postJson('/factory/droids/cog/retry')->assertStatus(422);
    }

    public function test_the_mechanic_switches_an_unsupported_model_and_retries(): void
    {
        Droid::create(['slug' => 'wrench', 'unit' => 'CL-02', 'name' => 'Wrench', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Mechanic', 'project_id' => $this->project->id]);

        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->artisan('droids:autopilot')->assertSuccessful();
        $look = Run::where('kind', 'unblock')->sole();
        $this->assertSame("unblock:droids:{$this->failed->id}", $look->topic);

        Http::fake(['api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201)]);
        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
        $this->assertSame($look->id, $job['id']);
        $this->assertStringContainsString("The 'gpt-5.1-codex' model is not supported.", $job['prompt']);
        $this->assertStringContainsString('GPT-6.2-Sol', $job['prompt']);

        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "Cog was on a retired model; I moved it to GPT-6.2-Sol and restarted #7.", "action": "model", "model": "GPT-6.2-Sol"}'])->assertOk();

        $this->assertSame(['GPT-6.2-Sol', 'working'], [$this->builder->fresh()->model, $this->builder->fresh()->status]);
        $this->assertSame('queued', Run::where('kind', 'build')->latest('id')->value('status'));
        $this->assertSame('Cog was on a retired model; I moved it to GPT-6.2-Sol and restarted #7.', ChatMessage::where('topic', 'discuss:droids')->latest('id')->first()->payload['text']);
    }

    public function test_the_mechanic_leaves_it_to_the_person_after_two_looks(): void
    {
        Droid::create(['slug' => 'wrench', 'unit' => 'CL-02', 'name' => 'Wrench', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Mechanic', 'project_id' => $this->project->id]);
        foreach ([1, 2] as $n) {
            $this->artisan('droids:autopilot')->assertSuccessful();
            Run::where('kind', 'unblock')->update(['status' => 'succeeded']);
            $this->failed = Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'build', 'status' => 'failed', 'error' => "Failed again {$n}"]);
        }
        $this->artisan('droids:autopilot')->assertSuccessful();

        $this->assertSame(2, Run::where('kind', 'unblock')->count());
    }

    public function test_deleting_a_droid_puts_its_task_back_and_keeps_the_discussion(): void
    {
        ChatMessage::create(['droid_id' => $this->builder->id, 'topic' => 'discuss:droids', 'kind' => 'agent', 'payload' => ['text' => 'Shared']]);
        ChatMessage::create(['droid_id' => $this->builder->id, 'topic' => null, 'kind' => 'agent', 'payload' => ['text' => 'Own']]);

        $this->deleteJson('/factory/droids/cog')->assertOk()->assertJsonCount(0, 'store.droids');

        $this->assertSame(['ready', null], [$this->task->fresh()->column, $this->task->fresh()->builder_id]);
        $this->assertSame(['Shared'], ChatMessage::pluck('payload')->pluck('text')->all());
        $this->assertSame(0, Run::count());
    }
}
