<?php

namespace Tests\Feature;

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
use App\Runs\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The shared memory droids on a project keep for each other. */
class MemoryTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Task $task;

    private Droid $builder;

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
        GithubApp::create([
            'app_id' => 1, 'slug' => 'droids', 'name' => 'Droids', 'html_url' => 'https://github.com/apps/droids',
            'client_id' => 'Iv1.x', 'private_key' => self::$pem, 'webhook_secret' => 'hook',
        ]);
        $installation = GithubInstallation::create(['installation_id' => 42, 'account_login' => 'vtalbot']);
        $this->project = Project::create([
            'slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red',
            'github_installation_id' => $installation->id,
        ]);
        $this->task = $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode toggle', 'column' => 'progress', 'position' => 1]);
        $this->builder = Droid::create(['slug' => 'cog', 'unit' => 'CL-01', 'name' => 'Cog', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Builder']);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, mixed> */
    private function claim(): array
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201),
            'api.github.com/repos/vtalbot/droids/issues/*' => Http::response(['body' => '']),
            'api.github.com/repos/vtalbot/droids/pulls*' => Http::response(['number' => 12, 'html_url' => 'https://github.com/vtalbot/droids/pull/12', 'head' => ['ref' => 'b'], 'title' => 't', 'state' => 'open']),
            'api.github.com/repos/vtalbot/droids/pulls/12/reviews' => Http::response(['id' => 1]),
        ]);

        return (array) $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
    }

    private function finish(int $id, array $result): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", $result)->assertOk();
    }

    private function queueBuild(): void
    {
        Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'build', 'status' => 'queued', 'branch' => 'droids/7-dark']);
    }

    public function test_a_build_gets_the_memory_and_may_write_to_it(): void
    {
        $this->queueBuild();
        $job = $this->claim();

        $this->assertSame(Memory::HEADER, $job['memory']);
        $this->assertStringContainsString('Shared memory: .droids/memory.md', $job['prompt']);
        $this->assertStringContainsString('It is empty so far.', $job['prompt']);
        $this->assertStringContainsString('add a short bullet to that file', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'pushed' => false, 'memory_base' => Memory::HEADER, 'memory' => Memory::HEADER."\n- Run tests with `composer test` (2026-11-26)\n"]);

        $this->project->refresh();
        $this->assertStringContainsString('composer test', (string) $this->project->memory);
        $this->assertSame($this->builder->id, $this->project->memory_droid_id);
        $this->getJson('/factory/state')->assertJsonPath('store.memory.droids.by', 'cog');
    }

    public function test_edits_made_in_the_meantime_are_kept(): void
    {
        $base = Memory::HEADER."\n- Uses Pest.\n";
        $this->project->update(['memory' => $base]);
        $this->queueBuild();
        $job = $this->claim();
        // Another run added a note while this one worked.
        $this->project->update(['memory' => $base."- Deploy with Forge.\n"]);

        $this->finish($job['id'], ['status' => 'succeeded', 'pushed' => false, 'memory_base' => $base, 'memory' => $base."- Seed with php artisan db:seed.\n"]);

        $this->assertSame($base."- Deploy with Forge.\n- Seed with php artisan db:seed.\n", $this->project->fresh()->memory);
    }

    public function test_conversations_add_facts_with_a_remember_list(): void
    {
        $planner = Droid::create(['slug' => 'atlas', 'unit' => 'CL-02', 'name' => 'Atlas', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Planner']);
        $this->postJson('/factory/droids/atlas/ask', ['kind' => 'plan', 'project' => 'droids', 'text' => 'Plan']);
        $job = $this->claim();
        $this->assertStringContainsString('what the person decided or told you here', $job['prompt']);
        $this->assertStringContainsString('"next": ["..."], "remember": ["..."]}', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Planned.", "remember": ["The API lives in app/Api.", "", 42]}']);

        $memory = (string) $this->project->fresh()->memory;
        $this->assertStringStartsWith(Memory::HEADER, $memory);
        $this->assertStringContainsString('- The API lives in app/Api. (CL-02 Atlas, '.now()->toDateString().')', $memory);
        $this->assertSame($planner->id, $this->project->fresh()->memory_droid_id);
        $reply = $this->getJson('/factory/state')->json('store.chats')['plan:droids'];
        $this->assertSame(['The API lives in app/Api.'], end($reply)['remembered']);
    }

    public function test_a_droids_own_chat_adds_to_the_memory_of_the_project_it_is_on(): void
    {
        $this->builder->update(['project_id' => $this->project->id]);
        $this->postJson('/factory/droids/cog/ask', ['kind' => 'chat', 'text' => 'We use Pest from now on.'])->assertCreated();
        $job = $this->claim();
        $this->assertStringContainsString('"remember": ["..."]', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Noted.", "remember": ["Tests are written with Pest."]}']);

        $this->assertStringContainsString('- Tests are written with Pest. (CL-01 Cog', (string) $this->project->fresh()->memory);
    }

    public function test_reviewers_remember_too(): void
    {
        $reviewer = Droid::create(['slug' => 'lumen', 'unit' => 'CL-03', 'name' => 'Lumen', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Reviewer']);
        $this->task->update(['reviewer_id' => $reviewer->id]);
        PullRequest::create(['task_id' => $this->task->id, 'number' => 12, 'title' => 'Dark mode', 'branch' => 'droids/7-dark', 'state' => 'open']);
        Run::create(['droid_id' => $reviewer->id, 'task_id' => $this->task->id, 'project_id' => $this->project->id, 'kind' => 'review', 'status' => 'queued', 'branch' => 'droids/7-dark', 'pr_number' => 12]);
        $job = $this->claim();
        $this->assertStringContainsString('"remember": ["..."]', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"verdict": "approve", "summary": "Fine.", "remember": ["Migrations must be reversible."]}']);

        $this->assertStringContainsString('Migrations must be reversible. (CL-03 Lumen', (string) $this->project->fresh()->memory);
    }

    public function test_the_person_can_edit_the_memory(): void
    {
        $this->putJson('/factory/projects/droids/memory', ['memory' => "# Notes\n\n- Ship on Fridays."])->assertOk()
            ->assertJsonPath('store.memory.droids.text', "# Notes\n\n- Ship on Fridays.")
            ->assertJsonPath('store.memory.droids.by', null);

        $this->putJson('/factory/projects/droids/memory', ['memory' => ''])->assertOk();
        $this->assertNull($this->project->fresh()->memory);
    }
}
