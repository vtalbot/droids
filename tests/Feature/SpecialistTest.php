<?php

namespace Tests\Feature;

use App\Factory\Autopilot;
use App\Models\Droid;
use App\Models\Project;
use App\Models\Run;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Specialist roles on small models, editing droids, and droids prefilling forms. */
class SpecialistTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->project = Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red', 'domain' => 'mail.droids.dev']);
        Droid::create(['slug' => 'atlas', 'unit' => 'CL-01', 'name' => 'Atlas', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Planner', 'project_id' => $this->project->id]);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    public function test_a_droid_can_be_edited(): void
    {
        $this->patchJson('/factory/droids/atlas', ['name' => 'Scout', 'model' => 'Haiku 5.5', 'role' => 'Triager', 'autonomy' => 'ask', 'instructions' => 'Be brief.'])->assertOk()
            ->assertJsonPath('store.droids.0.name', 'Scout')
            ->assertJsonPath('store.droids.0.model', 'Haiku 5.5')
            ->assertJsonPath('store.droids.0.role', 'Triager')
            ->assertJsonPath('store.droids.0.autonomy', 'ask')
            ->assertJsonPath('store.droids.0.instructions', 'Be brief.');
        $this->patchJson('/factory/droids/atlas', ['model' => 'GPT-5.1-Codex'])->assertJsonValidationErrors('model');
        $this->patchJson('/factory/droids/atlas', ['role' => 'Wizard'])->assertJsonValidationErrors('role');
    }

    public function test_specialists_take_the_cards_their_labels_call_for_and_the_triager_triages(): void
    {
        $this->project->update(['auto_build' => true]);
        $this->project->tasks()->create(['number' => 1, 'title' => 'Big feature', 'column' => 'ready', 'position' => 1]);
        $this->project->tasks()->create(['number' => 2, 'title' => 'Crash on save', 'column' => 'ready', 'position' => 2, 'labels' => ['Bug']]);
        $builder = Droid::create(['slug' => 'bolt', 'unit' => 'CL-02', 'name' => 'Bolt', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder', 'project_id' => $this->project->id]);
        $fixer = Droid::create(['slug' => 'fix', 'unit' => 'CL-03', 'name' => 'Patch', 'provider' => 'claude', 'model' => 'Haiku 5.5', 'role' => 'Fixer', 'project_id' => $this->project->id]);
        $triager = Droid::create(['slug' => 'sort', 'unit' => 'CL-04', 'name' => 'Sort', 'provider' => 'claude', 'model' => 'Haiku 5.5', 'role' => 'Triager', 'project_id' => $this->project->id]);

        $this->artisan('droids:autopilot')->assertSuccessful();

        $this->assertSame($builder->id, $this->project->tasks()->where('number', 1)->value('builder_id'));
        $this->assertSame($fixer->id, $this->project->tasks()->where('number', 2)->value('builder_id'));
        $this->assertTrue(Autopilot::triager($this->project)?->is($triager));
    }

    public function test_hr_knows_which_roles_fit_small_models(): void
    {
        $this->postJson('/factory/droids/atlas/ask', ['kind' => 'recruit', 'text' => 'Someone cheap for bugs'])->assertCreated();
        $prompt = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.prompt');
        $this->assertStringContainsString('- Fixer: takes small Ready cards labelled bug or chore (GPT-5.1-Codex-Mini or Haiku 5.5)', $prompt);
        $this->assertStringContainsString('Pick the smallest model that does the role well', $prompt);
    }

    public function test_a_copywriter_prefills_the_email_form(): void
    {
        $writer = Droid::create(['slug' => 'quill', 'unit' => 'CL-05', 'name' => 'Quill', 'provider' => 'claude', 'model' => 'Haiku 5.5', 'role' => 'Copywriter']);
        $this->postJson('/factory/projects/droids/suggest', ['form' => 'nope'])->assertJsonValidationErrors('form');
        $this->postJson('/factory/projects/droids/suggest', ['form' => 'email', 'values' => ['from_local' => 'hello']])->assertCreated()
            ->assertJsonPath('store.asks.suggest:droids:email.kind', 'suggest');
        $this->assertSame($writer->id, Run::sole()->droid_id);

        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run');
        $this->assertStringContainsString('prefilling the email set-up of the project for Droids (vtalbot/droids), whose domain is mail.droids.dev', $job['prompt']);
        $this->assertStringContainsString('What is there now: from_local: hello.', $job['prompt']);
        $this->assertStringContainsString('"fields": {"from_local": "...", "from_name": "..."}', $job['prompt']);

        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "Short and friendly.", "fields": {"from_local": "Team@droids.dev", "from_name": "Droids Team", "other": "x"}}'])->assertOk();

        $items = $this->getJson('/factory/state')->json('store.chats')['suggest:droids:email'];
        $this->assertSame(['form' => 'email', 'fields' => ['from_local' => 'team', 'from_name' => 'Droids Team'], 'text' => 'Short and friendly.'], array_diff_key(end($items), ['id' => 1, 'kind' => 1]));
        $this->assertSame('suggestion', end($items)['kind']);
    }
}
