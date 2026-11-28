<?php

namespace Tests\Feature;

use App\Factory\Autopilot;
use App\GitHub\RepositorySync;
use App\Models\ChatMessage;
use App\Models\Droid;
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

/** The Planner triages Ideas, and idle builders take Ready cards. */
class AutopilotTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->project = Project::create(['slug' => 'droids', 'name' => 'Droids', 'repo' => 'vtalbot/droids', 'board' => 'Roadmap', 'color' => 'red', 'auto_triage' => true]);
        $this->project->tasks()->create(['number' => 1, 'title' => 'Add dark mode', 'column' => 'backlog', 'position' => 1]);
        $this->project->tasks()->create(['number' => 2, 'title' => 'Make it faster', 'column' => 'backlog', 'position' => 2]);
        Droid::create(['slug' => 'atlas', 'unit' => 'CL-01', 'name' => 'Atlas', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Planner', 'project_id' => $this->project->id]);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    public function test_the_planner_triages_ideas_and_asks_in_the_discussion(): void
    {
        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->artisan('droids:autopilot')->assertSuccessful();
        $run = Run::sole();
        $this->assertSame(['triage', 'triage:droids'], [$run->kind, $run->topic]);

        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
        $this->assertStringContainsString('#1 Add dark mode', $job['prompt']);
        $this->assertStringContainsString('"questions"', $job['prompt']);
        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "Moved #1. For #2: which page is slow?",
            "ready": [{"number": 1, "priority": "high", "criteria": "- A toggle in settings"}], "questions": [{"number": 2, "priority": "whenever", "question": "Which page is slow?"}]}'])->assertOk();

        $this->assertSame('ready', $this->project->tasks()->where('number', 1)->value('column'));
        $this->assertSame([2, null], [$this->project->tasks()->where('number', 1)->value('priority'), $this->project->tasks()->where('number', 2)->value('priority')], 'an unknown priority is ignored');
        $this->assertStringContainsString('"priority"', $job['prompt']);
        $this->assertSame(['backlog', 'Which page is slow?'], array_values($this->project->tasks()->where('number', 2)->first(['column', 'question'])->toArray()));
        $chat = $this->getJson('/factory/state')->json('store.chats')['discuss:droids'];
        $this->assertSame('Moved #1. For #2: which page is slow?', end($chat)['text']);

        app(Autopilot::class)->tick();
        $this->assertSame(1, Run::count(), 'nothing new to triage');

        $this->travel(1)->minutes();
        ChatMessage::create(['droid_id' => Droid::value('id'), 'topic' => 'discuss:droids', 'kind' => 'user', 'payload' => ['text' => 'The checkout page.']]);
        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->assertSame(2, Run::count(), 'the answer brings #2 back');
        $prompt = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.prompt');
        $this->assertStringContainsString('Person: The checkout page.', $prompt);
        $this->assertStringContainsString('You asked earlier: Which page is slow?', $prompt);
        $this->assertStringNotContainsString("#1 Add dark mode\n", $prompt);
    }

    public function test_idle_builders_take_ready_cards_preferring_the_cards_engine(): void
    {
        $this->project->update(['auto_triage' => false, 'auto_build' => true]);
        $this->project->tasks()->where('number', 1)->update(['column' => 'ready', 'engine' => 'codex']);
        $claude = Droid::create(['slug' => 'bolt', 'unit' => 'CL-02', 'name' => 'Bolt', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder', 'project_id' => $this->project->id]);
        $codex = Droid::create(['slug' => 'cog', 'unit' => 'CX-01', 'name' => 'Cog', 'provider' => 'codex', 'model' => 'GPT-5.1-Codex', 'role' => 'Builder', 'project_id' => $this->project->id]);

        $this->artisan('droids:autopilot')->assertSuccessful();

        $task = $this->project->tasks()->where('number', 1)->first();
        $this->assertSame(['progress', $codex->id], [$task->column, $task->builder_id]);
        $this->assertSame('idle', $claude->fresh()->status);
        $this->assertSame('backlog', $this->project->tasks()->where('number', 2)->value('column'), 'Ideas cards wait for triage');
    }

    public function test_builders_take_the_highest_priority_ready_card_first(): void
    {
        $this->project->update(['auto_triage' => false, 'auto_build' => true]);
        $this->project->tasks()->where('number', 1)->update(['column' => 'ready', 'priority' => 4]);
        $this->project->tasks()->where('number', 2)->update(['column' => 'ready']);
        $this->project->tasks()->create(['number' => 3, 'title' => 'Fix login', 'column' => 'ready', 'position' => 3]);
        $bolt = Droid::create(['slug' => 'bolt', 'unit' => 'CL-02', 'name' => 'Bolt', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder', 'project_id' => $this->project->id]);

        $this->patchJson('/factory/projects/droids/tasks/3', ['priority' => 'urgent'])->assertOk()
            ->assertJsonPath('store.issues.2.priority', 'urgent');
        $this->patchJson('/factory/projects/droids/tasks/3', ['priority' => 'someday'])->assertJsonValidationErrors('priority');
        $this->patchJson('/factory/projects/droids/tasks/2', [])->assertJsonValidationErrors('column');

        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->assertSame($bolt->id, $this->project->tasks()->where('number', 3)->value('builder_id'));

        $this->patchJson('/factory/projects/droids/tasks/3', ['priority' => null])->assertOk();
        $this->assertNull($this->project->tasks()->where('number', 3)->value('priority'));
    }

    public function test_builders_wait_for_the_cards_a_card_is_blocked_by(): void
    {
        $this->project->update(['auto_triage' => false, 'auto_build' => true]);
        $this->project->tasks()->where('number', 1)->update(['column' => 'review']);
        $this->project->tasks()->where('number', 2)->update(['column' => 'ready', 'priority' => 1]);
        $this->project->tasks()->create(['number' => 3, 'title' => 'Fix login', 'column' => 'ready', 'position' => 3, 'priority' => 4]);
        Droid::create(['slug' => 'bolt', 'unit' => 'CL-02', 'name' => 'Bolt', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder', 'project_id' => $this->project->id]);

        $this->patchJson('/factory/projects/droids/tasks/2', ['blocked_by' => [1, 2, 99]])->assertOk()
            ->assertJsonPath('store.issues.1.blockedBy', [1]);
        $this->postJson('/factory/projects/droids/tasks/2/assign', ['droid' => 'bolt', 'as' => 'builder'])
            ->assertStatus(422)->assertJsonPath('message', 'Blocked by #1, which is not merged yet.');

        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->assertNull($this->project->tasks()->where('number', 2)->value('builder_id'), 'the urgent card waits for #1');
        $this->assertSame('bolt', $this->project->tasks()->where('number', 3)->first()->builder?->slug, 'the next card goes instead');

        Droid::where('slug', 'bolt')->update(['status' => 'idle', 'task_id' => null]);
        $this->project->tasks()->where('number', 1)->update(['column' => 'done']);
        $this->artisan('droids:autopilot')->assertSuccessful();
        $this->assertSame('bolt', $this->project->tasks()->where('number', 2)->first()->builder?->slug, 'merged: #2 can start');

        $this->patchJson('/factory/projects/droids/tasks/2', ['blocked_by' => []])->assertOk();
        $this->assertNull($this->project->tasks()->where('number', 2)->value('blocked_by'));
    }

    public function test_blockers_come_from_issue_text_and_triage(): void
    {
        $this->assertSame([12, 14, 3], Task::blockersIn("Some text.\n\nBlocked by #12 and #14\nDepends on: #3, #12"));
        $this->assertSame([], Task::blockersIn('Fixes #4. See #5.'));

        $this->postJson('/factory/projects/droids/tasks', ['tasks' => [['title' => 'Dark mode colors', 'body' => 'Blocked by #1']]])->assertCreated();
        $this->assertSame([1], $this->project->tasks()->where('number', 3)->first()->blocked_by);

        app(RepositorySync::class)->upsertIssue($this->project, ['number' => 2, 'id' => 22, 'title' => 'Make it faster', 'state' => 'open', 'body' => 'Depends on #1 and #2', 'labels' => []]);
        $this->assertSame([1], $this->project->tasks()->where('number', 2)->first()->blocked_by);

        app(Autopilot::class)->tick();
        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run');
        $this->assertStringContainsString('Already blocked by: #1', $job['prompt']);
        $this->assertStringContainsString('"blocked_by"', $job['prompt']);
        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "Moved #3 after #2.",
            "ready": [{"number": 3, "priority": "medium", "criteria": "- Colors", "blocked_by": [2, "x", 3]}], "questions": []}'])->assertOk();
        $this->assertSame([2], $this->project->tasks()->where('number', 3)->first()->blocked_by);
    }

    public function test_triage_sends_a_question_to_the_crew_droid_that_can_answer_it(): void
    {
        Droid::create(['slug' => 'iris', 'unit' => 'CL-02', 'name' => 'Iris', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'Designer', 'project_id' => $this->project->id]);
        app(Autopilot::class)->tick();
        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run');
        $this->assertStringContainsString('Designer (Iris)', $job['prompt']);
        $this->assertStringContainsString('"to"', $job['prompt']);
        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "Asked the Designer about #1.",
            "ready": [], "questions": [{"number": 1, "priority": "medium", "to": "designer", "question": "Where does the toggle go?"}, {"number": 2, "to": "person", "question": "Which page is slow?"}]}'])->assertOk();

        $dark = $this->project->tasks()->where('number', 1)->first();
        $this->assertSame(['Designer', 'Where does the toggle go?'], [$dark->question_for, $dark->question]);
        $this->assertNull($this->project->tasks()->where('number', 2)->value('question_for'));
        $this->assertSame('Designer', collect($this->getJson('/factory/state')->json('store.issues'))->firstWhere('n', 1)['questionFor']);
        $ask = Run::where('kind', 'answer')->sole();
        $this->assertSame(['answer:droids:1', 'Where does the toggle go?', 'iris'], [$ask->topic, $ask->input, $ask->droid->slug]);

        $job = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run');
        $this->assertSame($ask->id, $job['id']);
        $this->assertStringContainsString('#1 Add dark mode', $job['prompt']);
        $this->assertStringContainsString('Only you can decide', $job['prompt']);
        $this->withToken($this->token)->postJson("/api/worker/runs/{$job['id']}/finish", ['status' => 'succeeded', 'summary' => '{"message": "On #1: in the settings menu, under Appearance."}'])->assertOk();

        $dark->refresh();
        $this->assertSame('On #1: in the settings menu, under Appearance.', $dark->answer);
        $this->assertNull($dark->triaged_at);
        $chat = $this->getJson('/factory/state')->json('store.chats')['discuss:droids'];
        $this->assertSame('On #1: in the settings menu, under Appearance.', end($chat)['text']);

        app(Autopilot::class)->tick();
        $prompt = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.prompt');
        $this->assertStringContainsString('You asked earlier the Designer: Where does the toggle go?', $prompt);
        $this->assertStringContainsString('The Designer answered: On #1: in the settings menu', $prompt);
        $this->assertStringNotContainsString("#2 Make it faster\n", $prompt, 'the person has not answered #2');

        // A second question on the same card goes to the person: no loops between droids.
        $id = Run::where('kind', 'triage')->latest('id')->value('id');
        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", ['status' => 'succeeded', 'summary' => '{"message": "One more on #1.",
            "ready": [], "questions": [{"number": 1, "to": "Designer", "question": "Which icon?"}]}'])->assertOk();
        $this->assertNull($this->project->tasks()->where('number', 1)->value('question_for'));
        $this->assertSame(1, Run::where('kind', 'answer')->count());
    }

    public function test_triage_again_brings_back_cards_waiting_on_the_person(): void
    {
        $this->project->tasks()->update(['triaged_at' => now(), 'question' => 'Which page is slow?']);
        $this->project->tasks()->where('number', 2)->update(['question_for' => 'Designer']);
        $this->assertSame(0, Run::count());

        $this->postJson('/factory/projects/droids/triage')->assertOk()->assertJson(['reset' => 1, 'started' => true, 'message' => 'CL-01 Atlas will triage 1 card as soon as a worker picks it up.'])
            ->assertJsonPath('store.asks.triage:droids.status', 'queued');
        $this->postJson('/factory/projects/droids/triage')->assertOk()->assertJson(['started' => false, 'message' => 'A triage is already queued or running. These cards are next.']);
        $prompt = $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.prompt');
        $this->assertStringContainsString('#1 Add dark mode', $prompt);
        $this->assertStringNotContainsString("#2 Make it faster\n", $prompt, 'a droid is already on #2');

        // Ready cards no droid took go back through triage on request.
        Run::query()->update(['status' => 'succeeded']);
        $this->project->tasks()->create(['number' => 3, 'title' => 'Fix login', 'column' => 'ready', 'position' => 3]);
        $this->postJson('/factory/projects/droids/triage', ['ready' => true])->assertOk()->assertJson(['started' => true]);
        $this->assertSame('backlog', $this->project->tasks()->where('number', 3)->value('column'));
        $this->assertStringContainsString("#3 Fix login\n", (string) Run::where('kind', 'triage')->latest('id')->first()->input.$this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run.prompt'));
    }

    public function test_autopilot_is_switched_per_project_and_only_the_person_asks_questions(): void
    {
        $this->patchJson('/factory/projects/droids', ['auto_build' => true])->assertOk()
            ->assertJsonPath('store.projects.0.autoBuild', true)
            ->assertJsonPath('store.projects.0.autoTriage', true);
        $this->postJson('/factory/droids/atlas/ask', ['kind' => 'triage', 'project' => 'droids', 'text' => 'x'])->assertJsonValidationErrors('kind');
    }
}
