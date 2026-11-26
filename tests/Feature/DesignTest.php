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
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Designer droids write a project's design, and every other droid on the project works from it. */
class DesignTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Droid $designer;

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
        $this->designer = Droid::create([
            'slug' => 'iris', 'unit' => 'CL-09', 'name' => 'Iris', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Designer',
            'project_id' => $this->project->id,
        ]);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    private function ask(string $text, ?string $project = 'droids'): TestResponse
    {
        return $this->postJson('/factory/droids/iris/ask', ['kind' => 'design', 'project' => $project, 'text' => $text]);
    }

    /** @return array<string, mixed> */
    private function claim(): array
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201),
            'api.github.com/repos/vtalbot/droids/issues/*' => Http::response(['body' => 'Make it dark.']),
        ]);

        return (array) $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
    }

    private function finish(int $id, array $result): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", $result);
    }

    private function seedDesign(): void
    {
        $this->project->designFiles()->create(['path' => 'system.md', 'content' => '# Droids design', 'droid_id' => $this->designer->id]);
        $this->project->designFiles()->create(['path' => 'tokens.css', 'content' => ':root { --brand: #e11d48; }', 'droid_id' => $this->designer->id]);
    }

    public function test_a_design_session_is_queued_for_the_designer(): void
    {
        $this->ask('Create a design system')->assertCreated()
            ->assertJsonPath('store.asks.design:droids.kind', 'design')
            ->assertJsonPath('store.chats.design:droids.0.text', 'Create a design system');

        $job = $this->claim();

        $this->assertSame('design', $job['kind']);
        $this->assertSame('vtalbot/droids', $job['repo']);
        $this->assertSame([], $job['design']);
        $this->assertStringContainsString('only write inside the folder .droids/design/', $job['prompt']);
        $this->assertStringContainsString('is empty: this is the project\'s first design', $job['prompt']);
        $this->assertStringContainsString('screens/<name>.html', $job['prompt']);
    }

    public function test_designing_needs_a_project(): void
    {
        $this->ask('Create a design system', null)->assertUnprocessable();
    }

    public function test_the_designers_files_are_saved_on_the_project(): void
    {
        $this->ask('Create a design system');
        $id = $this->claim()['id'];

        $this->finish($id, ['status' => 'succeeded', 'summary' => '{"message": "Here is the system.", "reasoning": "Read the CSS."}', 'design' => [
            ['path' => 'system.md', 'content' => '# System'],
            ['path' => 'tokens.css', 'content' => ':root { --brand: #e11d48; }'],
            ['path' => 'screens/board.html', 'content' => '<h1>Board</h1>'],
            ['path' => '../escape.md', 'content' => 'nope'],
        ]])->assertOk();

        $this->assertSame(['screens/board.html', 'system.md', 'tokens.css'], $this->project->designFiles()->pluck('path')->all());
        $state = $this->getJson('/factory/state')->assertOk();
        $chat = $state->json('store.chats.design:droids');
        $this->assertSame(['user', 'agent', 'design'], array_column($chat, 'kind'));
        $this->assertSame(['system.md', 'tokens.css', 'screens/board.html'], $chat[2]['changed']);
        $this->assertSame('droids', $chat[2]['project']);
        $state->assertJsonPath('store.design.droids.1.path', 'system.md')
            ->assertJsonPath('store.design.droids.1.size', 8)
            ->assertJsonPath('store.design.droids.1.by', 'iris');
        $this->assertStringContainsString('Updated the design for Droids', (string) Activity::latest('id')->value('text'));
    }

    public function test_a_later_session_starts_from_the_design_and_can_change_it(): void
    {
        $this->seedDesign();
        $this->ask('Drop the tokens file, put them in the system');
        $job = $this->claim();

        $this->assertSame([['path' => 'system.md', 'content' => '# Droids design'], ['path' => 'tokens.css', 'content' => ':root { --brand: #e11d48; }']], $job['design']);
        $this->assertStringContainsString('already holds: system.md, tokens.css', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Merged."}', 'design' => [
            ['path' => 'system.md', 'content' => "# Droids design\n\n--brand: #e11d48"],
        ]])->assertOk();

        $this->assertSame(['system.md'], $this->project->designFiles()->pluck('path')->all());
        $item = collect($this->getJson('/factory/state')->json('store.chats.design:droids'))->firstWhere('kind', 'design');
        $this->assertSame(['changed' => ['system.md'], 'removed' => ['tokens.css']], ['changed' => $item['changed'], 'removed' => $item['removed']]);
    }

    public function test_an_empty_folder_keeps_the_design(): void
    {
        $this->seedDesign();
        $this->ask('Tweak the colors');
        $id = $this->claim()['id'];

        $this->finish($id, ['status' => 'succeeded', 'summary' => '{"message": "No change."}', 'design' => []])->assertOk();

        $this->assertSame(2, $this->project->designFiles()->count());
        $this->assertNotContains('design', array_column($this->getJson('/factory/state')->json('store.chats.design:droids'), 'kind'));
    }

    public function test_builders_get_the_design_and_are_told_to_follow_it(): void
    {
        $this->seedDesign();
        $builder = Droid::create(['slug' => 'cog', 'unit' => 'CL-01', 'name' => 'Cog', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Builder']);
        $task = $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode toggle', 'column' => 'ready', 'position' => 1]);
        Run::create(['droid_id' => $builder->id, 'task_id' => $task->id, 'kind' => 'build', 'status' => 'queued', 'branch' => 'droids/7-dark']);

        $job = $this->claim();

        $this->assertSame('build', $job['kind']);
        $this->assertCount(2, $job['design']);
        $this->assertStringContainsString('design from its designer droid in the folder .droids/design/: system.md, tokens.css. Start with system.md.', $job['prompt']);
        $this->assertStringContainsString('never commit it', $job['prompt']);
    }

    public function test_reviewers_and_planners_get_the_design_too(): void
    {
        $this->seedDesign();
        $reviewer = Droid::create(['slug' => 'vex', 'unit' => 'CX-02', 'name' => 'Vex', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Reviewer']);
        $task = $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode toggle', 'column' => 'review', 'position' => 1]);
        PullRequest::create(['task_id' => $task->id, 'number' => 12, 'title' => 'Dark mode', 'branch' => 'droids/7-dark', 'state' => 'open']);
        Run::create(['droid_id' => $reviewer->id, 'task_id' => $task->id, 'project_id' => $this->project->id, 'kind' => 'review', 'status' => 'queued', 'branch' => 'droids/7-dark', 'pr_number' => 12]);

        $review = $this->claim();
        $this->assertCount(2, $review['design']);
        $this->assertStringContainsString('Check that user interface changes follow it', $review['prompt']);

        $this->postJson('/factory/droids/iris/ask', ['kind' => 'plan', 'project' => 'droids', 'text' => 'Plan the theme work']);
        $plan = $this->claim();
        $this->assertSame('plan', $plan['kind']);
        $this->assertStringContainsString('.droids/design/', $plan['prompt']);
    }

    public function test_the_design_tab_reads_and_removes_files(): void
    {
        $this->seedDesign();

        $this->getJson('/factory/projects/droids/design')->assertOk()
            ->assertJsonPath('files.0.path', 'system.md')
            ->assertJsonPath('files.0.content', '# Droids design')
            ->assertJsonPath('files.0.by', 'iris');

        $this->deleteJson('/factory/projects/droids/design', ['path' => 'tokens.css'])->assertOk()
            ->assertJsonCount(1, 'store.design.droids');
    }
}
