<?php

namespace Tests\Feature;

use App\Models\Activity;
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

    public function test_an_hr_droid_proposes_candidates_from_the_request(): void
    {
        $this->project->tasks()->first()->update(['labels' => ['ui', 'bug']]);
        ChatMessage::create(['droid_id' => $this->droid->id, 'topic' => 'recruit', 'kind' => 'candidates', 'payload' => ['items' => [['name' => 'Probe']]]]);
        $this->postJson('/factory/droids/cog/ask', ['kind' => 'recruit', 'text' => 'I need a security reviewer.'])->assertCreated()
            ->assertJsonPath('store.asks.recruit.kind', 'recruit');
        $job = $this->claim();

        $this->assertSame('recruit', $job['kind']);
        $this->assertNull($job['repo'], 'recruiting looks at the whole factory, not one clone');
        $this->assertNull($job['memory']);
        $this->assertStringContainsString('You are the HR droid', $job['prompt']);
        $this->assertStringContainsString('- CL-01 Cog: Planner on claude Opus 5.5, on Droids', $job['prompt']);
        $this->assertStringContainsString('- Droids (vtalbot/droids): 1 open issues, labels: ui (1), bug (1)', $job['prompt']);
        $this->assertStringContainsString('Names already taken (crew, starters and earlier candidates): Cog, Mira, Bolt, Lumen, Atlas, Iris, Probe.', $job['prompt']);
        $this->assertStringContainsString('Builder, Reviewer, Designer, Tester, Planner, Docs, HR', $job['prompt']);
        $this->assertStringContainsString('I need a security reviewer.', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Two droids.", "candidates": [
            {"name": "Warden", "role": "Reviewer", "provider": "claude", "model": "Opus 5.5", "pitch": "I review.", "why": "You asked.", "instructions": "Check auth."},
            {"name": "Rivet", "role": "Wizard", "provider": "codex", "model": "GPT-9", "pitch": "I build."},
            {"name": "", "role": "Builder"},
            {"name": "bolt", "role": "Builder", "pitch": "A starter name."},
            {"name": "Cog", "role": "Planner", "pitch": "A crew name."},
            {"name": "Probe", "role": "Tester", "pitch": "Proposed before."},
            {"name": "warden", "role": "Reviewer", "pitch": "Twice in one batch."}
        ]}']);

        $chat = $this->getJson('/factory/state')->json('store.chats.recruit');
        $this->assertSame(['candidates', 'user', 'agent', 'candidates'], array_column($chat, 'kind'));
        $this->assertSame([
            ['name' => 'Warden', 'role' => 'Reviewer', 'provider' => 'claude', 'model' => 'Opus 5.5', 'pitch' => 'I review.', 'why' => 'You asked.', 'instructions' => 'Check auth.'],
            ['name' => 'Rivet', 'role' => 'Builder', 'provider' => 'codex', 'model' => 'GPT-5.1-Codex', 'pitch' => 'I build.', 'why' => '', 'instructions' => ''],
        ], $chat[3]['items']);
    }

    public function test_droids_can_be_assembled_without_a_project(): void
    {
        $this->postJson('/factory/droids', ['name' => 'Mira', 'provider' => 'claude', 'model' => 'Sonnet 5.5', 'role' => 'HR', 'autonomy' => 'pr', 'instructions' => 'Hire well.'])
            ->assertCreated();

        $mira = Droid::where('name', 'Mira')->sole();
        $this->assertNull($mira->project_id);
        $this->assertSame('HR', $mira->role);
    }

    public function test_a_droid_can_be_moved_to_a_project_and_off_it(): void
    {
        $mira = Droid::create(['slug' => 'cl09', 'unit' => 'CL-09', 'name' => 'Mira', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder']);

        $this->patchJson('/factory/droids/cl09', ['project' => 'droids'])->assertOk()->assertJsonPath('store.droids.1.project', 'droids');
        $this->assertSame($this->project->id, $mira->refresh()->project_id);
        $this->patchJson('/factory/droids/cl09', ['project' => null])->assertOk();
        $this->assertNull($mira->refresh()->project_id);
        $this->patchJson('/factory/droids/cl09', ['project' => 'nope'])->assertJsonValidationErrors('project');
        $this->assertSame(['joined Droids', 'left Droids'], Activity::where('droid_id', $mira->id)->orderBy('id')->pluck('text')->all());

        $this->droid->update(['task_id' => $this->project->tasks()->first()->id]);
        $this->patchJson('/factory/droids/cog', ['project' => null])->assertUnprocessable();
        $this->assertSame($this->project->id, $this->droid->refresh()->project_id);
    }

    public function test_questions_are_answered_even_in_paused_hours(): void
    {
        SubscriptionSchedule::where('provider', 'claude')->update(['grid' => array_fill(0, 7, array_fill(0, 24, ''))]);
        $this->project->tasks()->first()->update(['builder_id' => $this->droid->id]);
        Run::create(['droid_id' => $this->droid->id, 'task_id' => $this->project->tasks()->first()->id, 'kind' => 'build', 'status' => 'queued']);
        $this->ask('chat', 'Where is the theme?');

        $job = $this->claim();
        $this->assertSame('chat', $job['kind']);
        $this->assertNull($this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->json('run'), 'builds still wait for a painted hour');
    }

    public function test_a_question_no_worker_can_take_says_why(): void
    {
        $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-team']])->assertOk();
        $this->ask('chat', 'Where is the theme?');

        $asks = $this->getJson('/factory/state')->json('store.asks');
        $this->assertStringContainsString("isn't signed in to a free Claude subscription (claude-max)", reset($asks)['why']);

        Subscription::where('slug', 'claude-max')->update(['limited_until' => now()->addHour()]);
        $asks = $this->getJson('/factory/state')->json('store.asks');
        $this->assertSame('Every Claude subscription is at its usage limit.', reset($asks)['why']);
    }

    public function test_a_project_discussion_is_one_thread_whichever_droid_answers(): void
    {
        $this->postJson('/factory/droids/cog/ask', ['kind' => 'chat', 'project' => 'droids', 'text' => 'How is it going?', 'intro' => "Let's talk about Droids."])
            ->assertCreated()->assertJsonPath('store.asks.discuss:droids.kind', 'chat');
        $job = $this->claim();
        $this->assertSame('vtalbot/droids', $job['repo'], 'the droid reads the code');
        $this->assertStringContainsString('talk about the project Droids (vtalbot/droids)', $job['prompt']);
        $this->assertStringContainsString('#7 Add dark mode toggle', $job['prompt']);
        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Dark mode is next.", "remember": ["Theme lives in app.css."]}']);

        Droid::create(['slug' => 'rex', 'unit' => 'CL-02', 'name' => 'Rex', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Builder']);
        $this->postJson('/factory/droids/rex/ask', ['kind' => 'chat', 'project' => 'droids', 'text' => 'And after that?', 'intro' => "Let's talk about Droids."])->assertCreated();
        $prompt = $this->claim()['prompt'];
        $this->assertStringContainsString('You are CL-02 Rex', $prompt);
        $this->assertStringContainsString('You: Dark mode is next.', $prompt);

        $chat = $this->getJson('/factory/state')->json('store.chats')['discuss:droids'];
        $this->assertSame(['agent', 'user', 'agent', 'user'], array_column($chat, 'kind'), 'one intro, then both questions');
        $this->assertStringContainsString('Theme lives in app.css.', (string) $this->project->refresh()->memory);
    }

    public function test_a_project_discussion_offers_milestones_and_issues_to_create(): void
    {
        $this->ask('chat', 'Let us plan dark mode on a new milestone');
        $job = $this->claim();
        $this->assertStringContainsString('"milestones"', $job['prompt']);
        $this->assertStringContainsString('never say you created something', $job['prompt']);
        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Both are ready below.", "next": ["Draft the tests too", ""],
            "milestones": [{"title": "Dark mode", "goal": "Ship a dark theme.", "due": "2027-01-15"}, {"title": ""}],
            "drafts": [{"title": "Add a theme store", "engine": "codex", "size": "S", "milestone": "Dark mode", "feature": "Theme"}]}']);

        $chat = $this->getJson('/factory/state')->json('store.chats')['discuss:droids'];
        $this->assertSame(['agent', 'user', 'agent', 'proposals', 'drafts'], array_column($chat, 'kind'));
        $this->assertSame(['Draft the tests too'], $chat[2]['next']);
        $this->assertSame([['kind' => 'milestone', 'title' => 'Dark mode', 'why' => 'Ship a dark theme.', 'effort' => 'due 2027-01-15', 'engine' => 'claude', 'due' => '2027-01-15']], $chat[3]['items']);
        $this->assertSame(['Dark mode', 'Theme'], [$chat[4]['items'][0]['milestone'], $chat[4]['items'][0]['feature']]);
    }

    public function test_issues_can_be_filed_on_a_milestone_and_feature_by_name(): void
    {
        $milestone = $this->project->milestones()->create(['name' => 'Dark mode', 'state' => 'planned', 'position' => 1]);
        $this->project->update(['github_installation_id' => null]);

        $this->postJson('/factory/projects/droids/tasks', ['tasks' => [
            ['title' => 'Add a theme store', 'milestone' => $milestone->id, 'feature_name' => 'Theme'],
            ['title' => 'Dark palette', 'milestone' => $milestone->id, 'feature_name' => 'theme'],
            ['title' => 'Docs', 'milestone' => $milestone->id],
        ]])->assertCreated();

        $this->assertSame(['Theme', 'Unsorted'], $milestone->features()->orderBy('position')->pluck('name')->all());
        $this->assertSame(2, $milestone->features()->where('name', 'Theme')->first()->tasks()->count());
    }

    public function test_planning_picks_up_the_project_discussion(): void
    {
        ChatMessage::create(['droid_id' => $this->droid->id, 'topic' => 'discuss:droids', 'kind' => 'user', 'payload' => ['text' => 'We agreed dark mode ships first.']]);

        $this->ask('plan', 'Plan what we discussed');

        $prompt = $this->claim()['prompt'];
        $this->assertStringContainsString('which this planning follows on from', $prompt);
        $this->assertStringContainsString('Person: We agreed dark mode ships first.', $prompt);
    }

    public function test_a_discussion_on_a_cloud_project_can_propose_environment_variables(): void
    {
        $this->project->update(['cloud_application_id' => 'app-1', 'cloud_snapshot' => ['envs' => [['id' => 'env-prod', 'name' => 'production']]]]);
        $this->ask('chat', 'Turn on Sentry');
        $job = $this->claim();
        $this->assertStringContainsString('environments: production', $job['prompt']);
        $this->assertStringContainsString('"variables"', $job['prompt']);
        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => '{"message": "Ready below.", "variables": [{"key": "sentry_dsn", "value": "", "environment": "production"}, {"key": "1BAD", "value": "x"}]}']);

        $chat = $this->getJson('/factory/state')->json('store.chats')['discuss:droids'];
        $this->assertSame('variables', end($chat)['kind']);
        $this->assertSame([['key' => 'SENTRY_DSN', 'value' => '', 'environment' => 'production']], end($chat)['items']);
    }
}
