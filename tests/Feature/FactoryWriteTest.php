<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Droid;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\SubscriptionSchedule;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\FactoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FactoryWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FactoryDemoSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function task(string $project, int $number): Task
    {
        return Task::whereBelongsTo(Project::where('slug', $project)->firstOrFail())->where('number', $number)->firstOrFail();
    }

    public function test_guests_cannot_write(): void
    {
        auth()->logout();

        $this->postJson('/factory/projects', ['repo' => 'a/b', 'board' => 'X'])->assertUnauthorized();
    }

    public function test_state_returns_the_store(): void
    {
        $this->getJson('/factory/state')->assertOk()->assertJsonCount(4, 'store.projects');
    }

    public function test_moving_a_card_persists_its_column_and_docks_droids_on_done(): void
    {
        $this->patchJson('/factory/projects/store/tasks/412', ['column' => 'done'])
            ->assertOk()
            ->assertJsonPath('store.projects.0.done', 7);

        $this->assertSame('done', $this->task('store', 412)->column);
        $this->assertSame('idle', Droid::where('slug', 'cl07')->value('status'));

        $this->patchJson('/factory/projects/store/tasks/412', ['column' => 'shipped'])->assertUnprocessable();
    }

    public function test_assigning_a_builder_starts_the_task_and_releases_its_previous_one(): void
    {
        $this->postJson('/factory/projects/store/tasks/416/assign', ['droid' => 'cl07', 'as' => 'builder'])->assertOk();

        $this->assertSame('progress', $this->task('store', 416)->column);
        $this->assertSame('ready', $this->task('store', 412)->column);
        $this->assertNull($this->task('store', 412)->builder_id);
        $droid = Droid::where('slug', 'cl07')->firstOrFail();
        $this->assertSame($this->task('store', 416)->id, $droid->task_id);
        $this->assertSame('working', $droid->status);
        $this->assertTrue(Activity::where('droid_id', $droid->id)->where('text', 'like', 'Started #416%')->exists());
    }

    public function test_task_numbers_are_scoped_to_their_project(): void
    {
        // #88 exists only in billing; assigning it in the storefront is not found.
        $this->postJson('/factory/projects/store/tasks/88/assign', ['droid' => 'cx21', 'as' => 'reviewer'])->assertNotFound();
    }

    public function test_a_droid_cannot_review_its_own_work(): void
    {
        $this->postJson('/factory/projects/store/tasks/412/assign', ['droid' => 'cl07', 'as' => 'reviewer'])->assertUnprocessable();
    }

    public function test_creating_tasks_numbers_them_after_the_highest_in_the_project(): void
    {
        $feature = Milestone::where('name', 'v3.2 checkout')->firstOrFail()->features()->firstOrFail();

        $this->postJson('/factory/projects/store/tasks', ['tasks' => [
            ['title' => 'Saved payment methods', 'labels' => ['feature'], 'engine' => 'codex'],
            ['title' => 'Stacking rules on the server', 'feature' => $feature->id],
        ]])->assertCreated()->assertJsonPath('numbers', [421, 422]);

        $this->assertSame('backlog', $this->task('store', 421)->column);
        $this->assertSame($feature->id, $this->task('store', 422)->feature_id);
    }

    public function test_tasks_cannot_join_a_feature_from_another_project(): void
    {
        $other = Milestone::where('name', 'Tax compliance')->firstOrFail()->features()->firstOrFail();

        $this->postJson('/factory/projects/store/tasks', ['tasks' => [['title' => 'X', 'feature' => $other->id]]])->assertNotFound();
    }

    public function test_assembling_a_droid_gives_it_the_next_unit_number(): void
    {
        $this->postJson('/factory/droids', [
            'name' => 'Bolt', 'provider' => 'codex', 'model' => 'GPT-5.1-Codex', 'role' => 'Builder',
            'project' => 'ink', 'autonomy' => 'pr', 'instructions' => 'Write tests first.', 'seed' => 12,
        ])->assertCreated()->assertJsonPath('droid', 'cx22');

        $droid = Droid::where('slug', 'cx22')->firstOrFail();
        $this->assertSame('CX-22', $droid->unit);
        $this->assertSame('idle', $droid->status);
        $this->assertSame('Write tests first.', $droid->instructions);
    }

    public function test_reflect_appends_rules_and_model_can_change(): void
    {
        $this->patchJson('/factory/droids/cl02', ['model' => 'Opus 5.5', 'add_rules' => ['Run typecheck before pushing']])->assertOk();

        $droid = Droid::where('slug', 'cl02')->firstOrFail();
        $this->assertSame('Opus 5.5', $droid->model);
        $this->assertStringContainsString('- Run typecheck before pushing', (string) $droid->instructions);
    }

    public function test_connecting_a_repo_creates_a_project(): void
    {
        $this->postJson('/factory/projects', ['repo' => 'vtalbot/droids', 'board' => 'Roadmap'])
            ->assertCreated()
            ->assertJsonPath('project', 'droids');

        $this->postJson('/factory/projects', ['repo' => 'vtalbot/droids', 'board' => 'Roadmap'])->assertUnprocessable();
        $this->postJson('/factory/projects', ['repo' => 'not a repo', 'board' => 'Roadmap'])->assertUnprocessable();
    }

    public function test_milestones_and_features_can_be_added(): void
    {
        $this->postJson('/factory/projects/ink/milestones', ['name' => '1.1 profiles', 'due_on' => '2027-02-15', 'goal' => 'Work and personal setups'])
            ->assertCreated();
        $milestone = Milestone::where('name', '1.1 profiles')->firstOrFail();
        $this->assertSame('2027-02-15', $milestone->due_on?->toDateString());
        $this->assertSame('planned', $milestone->state);

        $this->postJson("/factory/milestones/{$milestone->id}/features", ['name' => 'Profile switching'])->assertCreated();
        $this->assertSame(['Profile switching'], $milestone->features()->pluck('name')->all());
    }

    public function test_api_key_subscriptions_store_the_key_encrypted_and_never_return_it(): void
    {
        $response = $this->postJson('/factory/subscriptions', [
            'provider' => 'claude', 'plan' => 'Anthropic API key', 'account' => 'Side projects', 'api_key' => 'sk-ant-secret-1234',
        ])->assertCreated();

        $this->assertStringNotContainsString('sk-ant-secret', (string) $response->getContent());
        $sub = Subscription::where('slug', $response->json('subscription'))->firstOrFail();
        $this->assertSame('sk-ant-secret-1234', $sub->api_key);
        $this->assertSame('API key ••••1234', $sub->auth);
        $this->assertNotSame('sk-ant-secret-1234', DB::table('subscriptions')->where('id', $sub->id)->value('api_key'));

        $this->postJson('/factory/subscriptions', ['provider' => 'claude', 'plan' => 'Anthropic API key'])->assertUnprocessable();
        $this->postJson('/factory/subscriptions', ['provider' => 'claude', 'plan' => 'ChatGPT Pro'])->assertUnprocessable();
    }

    public function test_subscriptions_can_be_paused(): void
    {
        $this->patchJson('/factory/subscriptions/max', ['enabled' => false])->assertOk()->assertJsonPath('store.paused.max', true);
    }

    public function test_the_schedule_grid_is_validated_and_saved(): void
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 'max'));
        $grid[6][3] = '';

        $this->putJson('/factory/schedules/claude', ['grid' => $grid])->assertOk();
        $saved = SubscriptionSchedule::where('provider', 'claude')->firstOrFail()->grid;
        $this->assertSame('max', $saved[0][0]);
        $this->assertSame('', $saved[6][3]);

        $grid[0][0] = 'pro'; // a Codex subscription
        $this->putJson('/factory/schedules/claude', ['grid' => $grid])->assertUnprocessable();
        $this->putJson('/factory/schedules/claude', ['grid' => [['max']]])->assertUnprocessable();
        $this->putJson('/factory/schedules/gemini', ['grid' => $grid])->assertNotFound();
    }
}
