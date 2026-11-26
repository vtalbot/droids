<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
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

/** Planning, proposals, Reflect and chat: questions run on a worker and come back as chat items. */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Droid $droid;

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
        $this->project->tasks()->create(['number' => 7, 'title' => 'Add dark mode toggle', 'column' => 'ready', 'position' => 1]);
        $this->droid = Droid::create([
            'slug' => 'cog', 'unit' => 'CL-01', 'name' => 'Cog', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Planner',
            'project_id' => $this->project->id,
        ]);
        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    private function ask(string $kind, string $text, ?string $project = 'droids'): TestResponse
    {
        return $this->postJson('/factory/droids/cog/ask', ['kind' => $kind, 'project' => $project, 'text' => $text, 'intro' => "Let's plan."]);
    }

    /** @return array<string, mixed> */
    private function claim(): array
    {
        Http::fake(['api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201)]);

        return (array) $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
    }

    private function finish(int $id, array $result): void
    {
        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", $result)->assertOk();
    }

    public function test_a_planning_message_is_saved_and_queued_for_a_worker(): void
    {
        $this->ask('plan', 'Plan the dark mode milestone')->assertCreated()
            ->assertJsonPath('store.asks.plan:droids.status', 'queued')
            ->assertJsonPath('store.chats.plan:droids.0.text', "Let's plan.")
            ->assertJsonPath('store.chats.plan:droids.1.kind', 'user')
            ->assertJsonPath('store.chats.plan:droids.1.text', 'Plan the dark mode milestone');

        $run = Run::sole();
        $this->assertSame(['plan', 'plan:droids', null], [$run->kind, $run->topic, $run->task_id]);
    }

    public function test_conversations_are_claimed_before_builds_and_run_read_only(): void
    {
        Run::create(['droid_id' => $this->droid->id, 'task_id' => $this->project->tasks()->value('id'), 'kind' => 'build', 'status' => 'queued', 'branch' => 'droids/7-x']);
        $this->ask('plan', 'Plan the dark mode milestone');

        $job = $this->claim();

        $this->assertSame('plan', $job['kind']);
        $this->assertSame('vtalbot/droids', $job['repo']);
        $this->assertSame('ghs_fresh', $job['clone_token']);
        $this->assertNull($job['commit_message']);
        $this->assertSame('opus', $job['model']);
        $this->assertStringContainsString('#7 Add dark mode toggle', $job['prompt']);
        $this->assertStringContainsString('Plan the dark mode milestone', $job['prompt']);
        $this->assertStringContainsString('"drafts"', $job['prompt']);
        $this->assertStringContainsString('do not change, commit or push', $job['prompt']);
        $this->assertSame('idle', $this->droid->fresh()->status, 'a conversation does not take the droid off its work');
    }

    public function test_a_plan_answer_becomes_draft_issues(): void
    {
        $this->ask('plan', 'Plan the dark mode milestone');
        $id = $this->claim()['id'];

        $this->finish($id, ['status' => 'succeeded', 'summary' => 'Here you go: {"message": "Three issues.", "reasoning": "Read the theme code.", "drafts": [
            {"title": "Add a theme store", "body": "Persist the choice.", "labels": ["ui"], "engine": "codex", "size": "S"},
            {"title": "", "labels": []},
            {"title": "Dark palette", "engine": "gpt", "size": "XL"}
        ]}']);

        $chat = $this->getJson('/factory/state')->assertOk()->assertJsonPath('store.asks', [])->json('store.chats.plan:droids');
        $this->assertSame(['agent', 'user', 'agent', 'drafts'], array_column($chat, 'kind'));
        $this->assertSame('Three issues.', $chat[2]['text']);
        $this->assertSame('Read the theme code.', $chat[2]['reasoning']);
        $this->assertSame([
            ['title' => 'Add a theme store', 'body' => 'Persist the choice.', 'labels' => ['ui'], 'engine' => 'codex', 'size' => 'S'],
            ['title' => 'Dark palette', 'body' => '', 'labels' => [], 'engine' => 'claude', 'size' => 'M'],
        ], $chat[3]['items']);
        $this->assertSame('droids', $chat[3]['project']);
    }

    public function test_proposals_come_back_as_cards(): void
    {
        $this->ask('propose', 'What should come next?');
        $job = $this->claim();
        $this->assertStringContainsString('"proposals"', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Two ideas.", "proposals": [{"kind": "milestone", "title": "Public beta", "why": "Ready.", "effort": "L · 8 tasks", "engine": "claude"}]}']);

        $card = ChatMessage::where('kind', 'proposals')->sole();
        $this->assertSame('plan:droids', $card->topic);
        $this->assertSame('Public beta', $card->payload['items'][0]['title']);
        $this->assertSame('milestone', $card->payload['items'][0]['kind']);
    }

    public function test_reflect_looks_back_at_the_droids_runs(): void
    {
        $task = $this->project->tasks()->first();
        Run::create(['droid_id' => $this->droid->id, 'task_id' => $task->id, 'kind' => 'build', 'status' => 'failed', 'error' => 'Typecheck failed', 'cost' => 1.5]);
        PullRequest::create(['task_id' => $task->id, 'number' => 12, 'title' => 'x', 'branch' => 'b', 'state' => 'merged', 'reviews' => [['by' => 'vtalbot', 'state' => 'changes']]]);

        $this->ask('reflect', 'Reflect on your last tasks', null)->assertCreated()->assertJsonPath('store.asks.cog.kind', 'reflect');
        $job = $this->claim();
        $this->assertNull($job['repo'], 'the droid is docked on a linked project, but reflect only needs its history');
        $this->assertStringContainsString('Typecheck failed', $job['prompt']);
        $this->assertStringContainsString('reviews: vtalbot changes', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Here is my retro.", "good": ["Small PRs"], "improve": ["Typecheck failed"], "rules": ["Run typecheck before pushing"]}']);

        $retro = $this->getJson('/factory/state')->json('store.chats.cog.3');
        $this->assertSame('retro', $retro['kind']);
        $this->assertSame(['Run typecheck before pushing'], $retro['rules']);
        $this->assertSame([['Tasks', '1'], ['PRs merged', '1'], ['Failed runs', '1'], ['Cost per task', '$1.50']], $retro['stats']);
    }

    public function test_chat_answers_and_failures_are_shown_in_the_conversation(): void
    {
        $this->ask('chat', 'Where is the theme set?', null);
        $this->finish($this->claim()['id'], ['status' => 'succeeded', 'summary' => 'In resources/css/app.css, not JSON.']);
        $this->ask('chat', 'And the toggle?', null);
        $this->finish($this->claim()['id'], ['status' => 'failed', 'error' => 'not logged in']);

        $texts = array_column($this->getJson('/factory/state')->json('store.chats.cog'), 'text');
        $this->assertSame(["Let's plan.", 'Where is the theme set?', 'In resources/css/app.css, not JSON.', 'And the toggle?', "I couldn't answer: not logged in"], $texts);
    }

    public function test_the_conversation_history_goes_into_the_next_prompt(): void
    {
        $this->ask('chat', 'Where is the theme set?', null);
        $this->finish($this->claim()['id'], ['status' => 'succeeded', 'summary' => '{"message": "In app.css."}']);
        $this->ask('chat', 'And the toggle?', null);

        $prompt = $this->claim()['prompt'];
        $this->assertStringContainsString("Person: Where is the theme set?\nYou: In app.css.", $prompt);
        $this->assertStringNotContainsString('Person: And the toggle?', $prompt);
    }

    public function test_a_newer_question_replaces_one_still_waiting(): void
    {
        $this->ask('plan', 'First idea');
        $this->ask('plan', 'Second idea');

        $this->assertSame(['cancelled', 'queued'], Run::orderBy('id')->pluck('status')->all());
        $this->assertSame(1, ChatMessage::where('payload->text', "Let's plan.")->count(), 'the greeting is saved once');
    }

    public function test_what_the_person_did_with_a_card_is_saved(): void
    {
        $message = ChatMessage::create(['droid_id' => $this->droid->id, 'topic' => 'plan:droids', 'kind' => 'drafts', 'payload' => ['project' => 'droids', 'items' => []]]);

        $this->patchJson("/factory/chat/{$message->id}", ['created' => [8, 9]])->assertOk()
            ->assertJsonPath('store.chats.plan:droids.0.created', [8, 9]);
        $this->assertSame('droids', $message->fresh()->payload['project']);
    }

    public function test_planning_needs_a_project(): void
    {
        $this->postJson('/factory/droids/cog/ask', ['kind' => 'plan', 'text' => 'Plan'])->assertJsonValidationErrors('project');
        $this->assertSame(0, Run::count());
    }
}
