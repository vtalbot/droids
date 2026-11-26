<?php

namespace Tests\Feature;

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
}
