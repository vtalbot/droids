<?php

namespace Tests\Feature;

use App\Models\Droid;
use App\Models\EngineModel;
use App\Models\Project;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\User;
use App\Models\Worker;
use App\Worker\Models;
use App\Worker\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The worker finds the models its logins can use, and droids can run on them. */
class ModelsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string|false $home;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->dir = sys_get_temp_dir().'/droids-models-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/logins');
        File::ensureDirectoryExists($this->dir.'/home');
        config(['droids.worker.logins' => $this->dir.'/logins']);
        $this->home = $_SERVER['HOME'] ?? false;
        $_SERVER['HOME'] = $this->dir.'/home';
        putenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        if ($this->home !== false) {
            $_SERVER['HOME'] = $this->home;
        }
        parent::tearDown();
    }

    public function test_the_worker_finds_claude_and_codex_models_from_its_logins(): void
    {
        File::ensureDirectoryExists($this->dir.'/logins/claude-max');
        File::put($this->dir.'/logins/claude-max/.credentials.json', json_encode(['claudeAiOauth' => ['accessToken' => 'sk-ant-oat-x']]));
        $codex = $this->dir.'/logins/chatgpt-pro';
        File::ensureDirectoryExists($codex.'/sessions/2026/11/27');
        File::put($codex.'/auth.json', '{}');
        File::put($codex.'/models_cache.json', json_encode(['models' => [['slug' => 'gpt-6.2-codex', 'display_name' => 'x'], ['slug' => 'gpt-6.2-codex-mini']]]));
        File::put($codex.'/config.toml', "model = \"gpt-6-codex\"\n");
        File::put($codex.'/sessions/2026/11/27/rollout-1.jsonl', json_encode(['type' => 'turn_context', 'payload' => ['model' => 'gpt-5.1-codex', 'cwd' => '/tmp']]));
        Http::fake([Models::CLAUDE_URL => Http::response(['data' => [
            ['id' => 'claude-opus-6', 'display_name' => 'Claude Opus 6'],
            ['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5'],
        ]])]);

        $found = (new Models(app(Usage::class)))->read();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-ant-oat-x') && $r->hasHeader('anthropic-version'));
        $this->assertSame([['id' => 'claude-opus-6', 'label' => 'Opus 6'], ['id' => 'claude-haiku-5-5', 'label' => 'Haiku 5.5']], $found['claude']);
        $this->assertSame(['gpt-6.2-codex', 'gpt-6.2-codex-mini', 'gpt-6-codex', 'gpt-5.1-codex'], array_column($found['codex'], 'id'));
        $this->assertSame('GPT-6.2-Codex-Mini', $found['codex'][1]['label']);
    }

    public function test_reported_models_are_offered_and_runs_use_their_cli_id(): void
    {
        [, $token] = Worker::issue('MacBook');
        $this->withToken($token)->postJson('/api/worker/models', ['models' => [
            'claude' => [['id' => 'claude-opus-6', 'label' => 'Opus 6']],
            'codex' => [['id' => 'gpt-6.2-codex', 'label' => 'GPT-6.2-Codex']],
            'other' => [['id' => 'x', 'label' => 'X']],
        ]])->assertOk();
        $this->withToken($token)->postJson('/api/worker/models', ['models' => ['codex' => [['id' => 'bad id; rm', 'label' => 'x']]]])->assertJsonValidationErrors('models.codex.0.id');

        $this->assertSame(2, EngineModel::count());
        $this->actingAs(User::factory()->create());
        $project = Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red']);
        Droid::create(['slug' => 'atlas', 'unit' => 'CL-01', 'name' => 'Atlas', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Planner', 'project_id' => $project->id]);
        $this->getJson('/factory/state')->assertJsonPath('store.models.claude', ['Opus 6', 'Opus 5.5', 'Sonnet 5.5', 'Haiku 5.5'])
            ->assertJsonPath('store.models.codex.0', 'GPT-6.2-Codex');

        $this->patchJson('/factory/droids/atlas', ['model' => 'Opus 6'])->assertOk();
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        $this->postJson('/factory/droids/atlas/ask', ['kind' => 'chat', 'project' => 'droids', 'text' => 'Hi'])->assertCreated();
        $this->assertSame('claude-opus-6', $this->withToken($token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.model'));

        $this->travel(EngineModel::FORGET_AFTER_DAYS + 1)->days();
        $this->getJson('/factory/state')->assertJsonPath('store.models.claude', ['Opus 5.5', 'Sonnet 5.5', 'Haiku 5.5']);
        $this->assertSame(1, Run::count());
    }
}
