<?php

namespace Tests\Feature;

use App\Models\Droid;
use App\Models\Project;
use App\Models\PullRequest;
use App\Models\Run;
use App\Models\User;
use Database\Seeders\FactoryDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_hangar(): void
    {
        $this->get(route('home'))->assertRedirect('/hangar');
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('factory', ['view' => 'hangar']))->assertRedirect(route('login'));
    }

    public function test_unknown_views_are_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/nope')->assertNotFound();
    }

    /** @return list<array{string}> */
    public static function views(): array
    {
        return [['hangar'], ['projects'], ['board'], ['droids'], ['subscriptions']];
    }

    #[DataProvider('views')]
    public function test_every_view_renders_the_factory_store(string $view): void
    {
        $this->seed(FactoryDemoSeeder::class);

        $this->actingAs(User::factory()->create())
            ->get(route('factory', ['view' => $view, 'project' => 'store']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('factory')
                ->where('view', $view)
                ->where('project', 'store')
                ->has('store.projects', 4)
                ->has('store.droids', 11)
                ->has('store.subs', 4)
                ->has('store.schedule.claude', 7)
                ->has('store.schedule.claude.0', 24)
            );
    }

    public function test_the_store_derives_milestone_progress_and_pr_status(): void
    {
        $this->seed(FactoryDemoSeeder::class);

        $this->actingAs(User::factory()->create())
            ->get(route('factory', ['view' => 'board']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project', 'all')
                ->where('store.projects.0.id', 'store')
                ->where('store.projects.0.milestone', 'v3.2 checkout')
                ->where('store.projects.0.due', 'Dec 12')
                ->where('store.projects.0.done', 6)
                ->where('store.projects.0.total', 10)
                ->where('store.prs.store:405.state', 'approved')
                ->has('store.prs.store:405.checks', 6)
                ->where('store.droids.0.task', 412)
                ->has('store.roadmap.store', 3)
                ->has('store.chats.cl02', 2)
            );
    }

    public function test_the_demo_seeder_can_run_twice(): void
    {
        $this->seed(FactoryDemoSeeder::class);
        $this->seed(FactoryDemoSeeder::class);

        $this->assertDatabaseCount('projects', 4);
        $this->assertDatabaseCount('droids', 11);
        $this->assertDatabaseCount('activities', 8);
    }

    public function test_projects_without_stored_stats_get_them_from_their_history(): void
    {
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));
        $project = Project::create(['slug' => 'fresh', 'name' => 'Fresh', 'repo' => 'vtalbot/fresh', 'board' => 'Roadmap', 'color' => 'teal']);
        $codex = Droid::create(['slug' => 'cx01', 'unit' => 'CX-01', 'name' => 'Bolt', 'provider' => 'codex', 'model' => 'GPT-5.1-Codex', 'role' => 'Builder']);
        $done = $project->tasks()->create(['number' => 1, 'title' => 'Done', 'column' => 'done', 'position' => 1, 'builder_id' => $codex->id]);
        $project->tasks()->create(['number' => 2, 'title' => 'Open', 'column' => 'ready', 'position' => 2]);
        $pr = PullRequest::create(['task_id' => $done->id, 'number' => 5, 'title' => 'Done', 'branch' => 'b', 'state' => 'merged', 'checks' => [['name' => 'ci', 'state' => 'success']]]);
        $pr->forceFill(['created_at' => now()->subHours(6)])->save();
        Run::create(['droid_id' => $codex->id, 'task_id' => $done->id, 'kind' => 'build', 'status' => 'succeeded', 'cost' => 1.25]);

        $stats = $this->actingAs(User::factory()->create())->getJson('/factory/state')->assertOk()->json('store.stats.fresh');

        $this->assertCount(8, $stats['weeks']);
        $this->assertSame(['scope' => 2, 'done' => 1], end($stats['burn']));
        $this->assertSame(['claude' => 0, 'codex' => 1], end($stats['thru']));
        $this->assertEquals(6, end($stats['cycle']));
        $this->assertEquals(['claude' => 0, 'codex' => 1.25], end($stats['spend']));
        $this->assertEquals(1, $stats['ci']);
    }
}
