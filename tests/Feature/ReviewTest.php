<?php

namespace Tests\Feature;

use App\GitHub\RepositorySync;
use App\Models\Activity;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Reviewer droids: review the pull request on a worker, post the verdict, and loop fixes back to the builder. */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    private Project $project;

    private Task $task;

    private Droid $builder;

    private Droid $reviewer;

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
        $this->reviewer = Droid::create(['slug' => 'rook', 'unit' => 'CL-02', 'name' => 'Rook', 'provider' => 'claude', 'model' => 'Opus 5.5', 'role' => 'Reviewer']);
        $this->task->update(['builder_id' => $this->builder->id]);

        Subscription::create(['slug' => 'claude-max', 'provider' => 'claude', 'name' => 'Claude Max', 'color' => 'red', 'enabled' => true]);
        SubscriptionSchedule::create(['provider' => 'claude', 'grid' => array_fill(0, 7, array_fill(0, 24, 'claude-max'))]);
        [, $this->token] = Worker::issue('MacBook');
        $this->actingAs(User::factory()->create());
    }

    private function openPr(): PullRequest
    {
        $this->task->update(['column' => 'review']);

        return PullRequest::create(['task_id' => $this->task->id, 'number' => 12, 'title' => 'Add dark mode toggle', 'branch' => 'droids/7-add-dark-mode-toggle', 'state' => 'open',
            'checks' => [['name' => 'tests', 'state' => 'failure']]]);
    }

    private function assignReviewer(): void
    {
        $this->postJson('/factory/projects/droids/tasks/7/assign', ['droid' => 'rook', 'as' => 'reviewer'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function claim(array $extra = []): array
    {
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201),
            'api.github.com/repos/vtalbot/droids/issues/7' => Http::response(['body' => 'The toggle goes in the header.']),
            ...$extra,
        ]);

        return (array) $this->withToken($this->token)->postJson('/api/worker/claim', ['subscriptions' => ['claude-max']])->assertOk()->json('run');
    }

    private function finish(int $id, array $result): void
    {
        $this->withToken($this->token)->postJson("/api/worker/runs/{$id}/finish", $result)->assertOk();
    }

    private function review(string $verdict, array $comments = []): string
    {
        return json_encode(['verdict' => $verdict, 'summary' => 'The toggle works.', 'comments' => $comments]);
    }

    public function test_a_reviewer_waits_for_the_pull_request(): void
    {
        $this->assignReviewer();

        $this->assertSame(0, Run::count());
        $this->assertSame("Waiting for #7's pull request", $this->reviewer->fresh()->step);
    }

    public function test_assigning_a_reviewer_to_an_open_pull_request_queues_a_review(): void
    {
        $this->openPr();
        $this->assignReviewer();

        $run = Run::sole();
        $this->assertSame(['review', 'droids/7-add-dark-mode-toggle', 12], [$run->kind, $run->branch, $run->pr_number]);
        $this->assertSame('Queued: review PR #12', $this->reviewer->fresh()->step);

        $job = $this->claim();
        $this->assertSame('review', $job['kind']);
        $this->assertSame('droids/7-add-dark-mode-toggle', $job['branch']);
        $this->assertNull($job['commit_message']);
        $this->assertStringContainsString('git diff origin/HEAD...HEAD', $job['prompt']);
        $this->assertStringContainsString('failing on the pull request: tests', $job['prompt']);
    }

    public function test_a_builder_opening_a_pull_request_hands_it_to_the_reviewer(): void
    {
        $this->task->update(['reviewer_id' => $this->reviewer->id]);
        $build = Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'build', 'status' => 'queued', 'branch' => 'droids/7-add-dark-mode-toggle']);
        $pull = [
            'id' => 9012, 'number' => 12, 'title' => 'Add dark mode toggle', 'state' => 'open', 'draft' => false, 'merged_at' => null,
            'body' => 'Closes #7', 'head' => ['ref' => 'droids/7-add-dark-mode-toggle', 'sha' => 'abc'], 'html_url' => 'https://github.com/vtalbot/droids/pull/12',
            'additions' => 20, 'deletions' => 1, 'commits' => 1, 'changed_files' => 2,
        ];
        $this->claim([
            'api.github.com/repos/vtalbot/droids/pulls/12/reviews*' => Http::response([]),
            'api.github.com/repos/vtalbot/droids/pulls/12' => Http::response($pull),
            'api.github.com/repos/vtalbot/droids/pulls' => Http::response($pull, 201),
            'api.github.com/repos/vtalbot/droids/commits/*' => Http::response(['check_runs' => []]),
            'api.github.com/repos/vtalbot/droids' => Http::response(['default_branch' => 'main']),
        ]);

        $this->finish($build->id, ['status' => 'succeeded', 'pushed' => true, 'summary' => 'Done.']);

        $review = Run::where('kind', 'review')->sole();
        $this->assertSame([$this->reviewer->id, 12, 'queued'], [$review->droid_id, $review->pr_number, $review->status]);
        $this->assertSame('idle', $this->builder->fresh()->status);
        $this->assertSame('working', $this->reviewer->fresh()->status);
    }

    public function test_an_approval_is_posted_as_a_comment_and_kept_as_the_verdict(): void
    {
        $this->openPr();
        $this->assignReviewer();
        $job = $this->claim(['api.github.com/repos/vtalbot/droids/pulls/12/reviews' => Http::response(['id' => 1])]);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => $this->review('approve', [['path' => './src/theme.ts', 'line' => 4, 'body' => 'Nice.']])]);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls/12/reviews')
            && $r['event'] === 'COMMENT' && str_contains($r['body'], '**CL-02 Rook** (reviewer droid): Looks good to merge.')
            && $r['comments'] === [['path' => 'src/theme.ts', 'line' => 4, 'body' => 'Nice.', 'side' => 'RIGHT']]);
        $pr = PullRequest::sole();
        $this->assertSame('approved', $pr->state);
        $this->assertSame([['by' => 'CL-02', 'state' => 'approved', 'droid' => true]], $pr->reviews);
        $this->assertSame('idle', $this->reviewer->fresh()->status);
        $this->assertSame(0, Run::where('kind', 'fix')->count());
    }

    public function test_requested_changes_go_back_to_the_builder_and_come_back_for_review(): void
    {
        $this->openPr();
        $this->assignReviewer();
        $job = $this->claim(['api.github.com/repos/vtalbot/droids/pulls/12/reviews' => Http::response(['id' => 1])]);
        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => $this->review('changes', [['path' => 'src/theme.ts', 'line' => 9, 'body' => 'Persist the choice.']])]);

        $this->assertSame('changes', PullRequest::sole()->state);
        $fix = Run::where('kind', 'fix')->sole();
        $this->assertSame([$this->builder->id, 'droids/7-add-dark-mode-toggle', 12], [$fix->droid_id, $fix->branch, $fix->pr_number]);
        $this->assertStringContainsString('- src/theme.ts:9: Persist the choice.', (string) $fix->input);
        $this->assertSame('working', $this->builder->fresh()->status);
        $this->assertTrue(Activity::where('text', 'Asked for changes on PR #12, sent back to CL-01')->exists());

        $job = $this->claim();
        $this->assertSame('fix', $job['kind']);
        $this->assertSame('fix: address review on #7', $job['commit_message']);
        $this->assertStringContainsString('Persist the choice.', $job['prompt']);
        $this->assertStringContainsString('already holds the earlier work', $job['prompt']);

        $this->finish($job['id'], ['status' => 'succeeded', 'pushed' => true, 'summary' => 'Persisted it.']);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls'));
        $this->assertSame('queued', Run::where('kind', 'review')->latest('id')->value('status'), 'the fix goes back to the reviewer');
        $this->assertSame(2, Run::where('kind', 'review')->count());
    }

    public function test_after_two_rounds_of_fixes_a_person_steps_in(): void
    {
        $this->openPr();
        Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'fix', 'status' => 'succeeded']);
        Run::create(['droid_id' => $this->builder->id, 'task_id' => $this->task->id, 'kind' => 'fix', 'status' => 'succeeded']);
        $this->assignReviewer();
        $job = $this->claim(['api.github.com/repos/vtalbot/droids/pulls/12/reviews' => Http::response(['id' => 1])]);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => $this->review('changes')]);

        $this->assertSame(2, Run::where('kind', 'fix')->count());
        $this->assertTrue(Activity::where('text', 'Asked for changes on PR #12 for #7: it needs you')->exists());
    }

    public function test_comments_outside_the_diff_are_folded_into_the_review_body(): void
    {
        $this->openPr();
        $this->assignReviewer();
        $job = $this->claim(['api.github.com/repos/vtalbot/droids/pulls/12/reviews' => Http::sequence()
            ->push(['message' => 'Unprocessable Entity'], 422)
            ->push(['id' => 2])]);

        $this->finish($job['id'], ['status' => 'succeeded', 'summary' => $this->review('approve', [['path' => 'README.md', 'line' => 400, 'body' => 'Typo.']])]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/12/reviews') && ! isset($r['comments'])
            && str_contains($r['body'], '- `README.md:400` Typo.'));
        $this->assertNull(Run::where('kind', 'review')->value('error'));
    }

    public function test_the_sync_keeps_droid_verdicts(): void
    {
        $pr = $this->openPr();
        $pr->update(['reviews' => [['by' => 'CL-02', 'state' => 'changes', 'droid' => true]]]);
        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_fresh'], 201),
            'api.github.com/repos/vtalbot/droids/pulls/12/reviews*' => Http::response([['state' => 'COMMENTED', 'user' => ['login' => 'droids[bot]']]]),
            'api.github.com/repos/vtalbot/droids/commits/*' => Http::response(['check_runs' => []]),
        ]);

        app(RepositorySync::class)->upsertPull($this->project, [
            'id' => 9012, 'number' => 12, 'title' => 'Add dark mode toggle', 'state' => 'open', 'draft' => false, 'merged_at' => null,
            'body' => 'Closes #7', 'head' => ['ref' => 'droids/7-add-dark-mode-toggle', 'sha' => 'abc'], 'additions' => 1,
        ], true);

        $pr->refresh();
        $this->assertSame('changes', $pr->state);
        $this->assertContains(['by' => 'CL-02', 'state' => 'changes', 'droid' => true], $pr->reviews);
    }
}
