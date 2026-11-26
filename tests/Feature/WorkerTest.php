<?php

namespace Tests\Feature;

use App\Worker\AgentOutput;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Runs `droids:work --once` against a fake server, a local git remote and a stub CLI. */
class WorkerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/droids-worker-test-'.uniqid();
        File::ensureDirectoryExists("{$this->root}/git/vtalbot");
        File::ensureDirectoryExists("{$this->root}/logins/claude-max");

        // A remote with one commit on main.
        $this->sh(['git', 'init', '--bare', '-b', 'main', "{$this->root}/git/vtalbot/droids.git"]);
        $this->sh(['git', 'clone', "{$this->root}/git/vtalbot/droids.git", "{$this->root}/seed"]);
        File::put("{$this->root}/seed/README.md", "# Droids\n");
        $this->sh(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qam', 'init', '--allow-empty'], "{$this->root}/seed");
        $this->sh(['git', 'add', '-A'], "{$this->root}/seed");
        $this->sh(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'readme'], "{$this->root}/seed");
        $this->sh(['git', 'push', '-q', 'origin', 'main'], "{$this->root}/seed");

        config([
            'droids.worker.server' => 'https://droids.test',
            'droids.worker.token' => 'dw_test',
            'droids.worker.workspace' => "{$this->root}/work",
            'droids.worker.logins' => "{$this->root}/logins",
            'droids.worker.git_host' => "{$this->root}/git",
            'droids.worker.bin.claude' => "{$this->root}/claude",
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @param list<string> $cmd */
    private function sh(array $cmd, ?string $cwd = null): string
    {
        $p = new Process($cmd, $cwd);
        $p->mustRun();

        return $p->getOutput();
    }

    private function stubCli(string $body): void
    {
        File::put("{$this->root}/claude", "#!/usr/bin/env bash\n{$body}\n");
        chmod("{$this->root}/claude", 0755);
    }

    private function job(): array
    {
        return [
            'id' => 5, 'repo' => 'vtalbot/droids', 'branch' => 'droids/7-dark-mode', 'clone_token' => 'ghs_x',
            'provider' => 'claude', 'model' => 'sonnet', 'prompt' => 'Add dark mode',
            'subscription' => ['slug' => 'claude-max', 'name' => 'Claude Max', 'api_key' => null],
            'commit_message' => 'feat: Add dark mode (#7)', 'droid' => ['name' => 'Cog', 'unit' => 'CL-01'],
        ];
    }

    private function fakeServer(): void
    {
        Http::fake([
            'droids.test/api/worker/claim' => Http::response(['run' => $this->job()]),
            'droids.test/api/worker/runs/5/progress' => Http::response(['cancelled' => false]),
            'droids.test/api/worker/runs/5/token' => Http::response(['token' => 'ghs_push']),
            'droids.test/api/worker/runs/5/finish' => Http::response(['status' => 'succeeded']),
        ]);
    }

    public function test_a_run_commits_the_agents_work_and_pushes_the_branch(): void
    {
        $this->fakeServer();
        $this->stubCli(<<<'SH'
echo "dark" > theme.txt
echo "$CLAUDE_CONFIG_DIR" > config-dir.txt
echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Write","input":{"file_path":"theme.txt"}}]}}'
echo '{"type":"result","subtype":"success","is_error":false,"result":"Added a theme file.","total_cost_usd":0.12,"usage":{"input_tokens":100,"output_tokens":20}}'
SH);

        $this->artisan('droids:work --once')->assertSuccessful();

        $branches = $this->sh(['git', 'branch', '--list'], "{$this->root}/git/vtalbot/droids.git");
        $this->assertStringContainsString('droids/7-dark-mode', $branches);
        $show = $this->sh(['git', 'show', 'droids/7-dark-mode:config-dir.txt'], "{$this->root}/git/vtalbot/droids.git");
        $this->assertSame("{$this->root}/logins/claude-max", trim($show), 'the CLI ran with the subscription login folder');
        $this->assertStringContainsString('feat: Add dark mode (#7)', $this->sh(['git', 'log', '-1', '--format=%s', 'droids/7-dark-mode'], "{$this->root}/git/vtalbot/droids.git"));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/claim') && in_array('claude-max', $r['subscriptions'], true));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finish') && $r['status'] === 'succeeded' && $r['pushed'] === true
            && $r['summary'] === 'Added a theme file.' && $r['cost'] == 0.12);
        $this->assertDirectoryDoesNotExist("{$this->root}/work/run-5");
    }

    public function test_a_run_without_changes_pushes_nothing(): void
    {
        $this->fakeServer();
        $this->stubCli(<<<'SH'
echo '{"type":"result","subtype":"success","is_error":false,"result":"Already done.","total_cost_usd":0.01,"usage":{}}'
SH);

        $this->artisan('droids:work --once')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finish') && $r['status'] === 'succeeded' && $r['pushed'] === false);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/token'));
    }

    public function test_a_usage_limit_is_reported_with_its_reset_time(): void
    {
        $this->fakeServer();
        $this->stubCli(<<<'SH'
echo '{"type":"result","subtype":"error","is_error":true,"result":"Claude AI usage limit reached|1795000000"}'
exit 1
SH);

        $this->artisan('droids:work --once')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finish') && $r['status'] === 'limited'
            && str_starts_with((string) $r['limited_until'], '2026-11-18'));
    }

    public function test_a_crashing_cli_fails_the_run_with_its_error(): void
    {
        $this->fakeServer();
        $this->stubCli('echo "not logged in, run /login" >&2; exit 2');

        $this->artisan('droids:work --once')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finish') && $r['status'] === 'failed'
            && str_contains((string) $r['error'], 'not logged in'));
    }

    public function test_a_conversation_runs_read_only_and_pushes_nothing(): void
    {
        $job = ['kind' => 'plan', 'branch' => null, 'commit_message' => null, 'prompt' => 'Plan dark mode'] + $this->job();
        Http::fake([
            'droids.test/api/worker/claim' => Http::response(['run' => $job]),
            'droids.test/api/worker/runs/5/progress' => Http::response(['cancelled' => false]),
            'droids.test/api/worker/runs/5/finish' => Http::response(['status' => 'succeeded']),
        ]);
        $this->stubCli(<<<SH
printf '%s\n' "\$@" > {$this->root}/args.txt
echo "changed" > README.md
echo '{"type":"result","subtype":"success","is_error":false,"result":"{\\"message\\": \\"Two issues.\\"}","total_cost_usd":0.03,"usage":{}}'
SH);

        $this->artisan('droids:work --once')->assertSuccessful();

        $args = File::get("{$this->root}/args.txt");
        $this->assertStringContainsString("--allowedTools\nRead,Grep,Glob,LS", $args);
        $this->assertStringNotContainsString('--dangerously-skip-permissions', $args);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/finish') && $r['status'] === 'succeeded' && $r['pushed'] === false
            && $r['summary'] === '{"message": "Two issues."}');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/token'));
        $this->assertStringNotContainsString('droids/', $this->sh(['git', 'branch', '--list'], "{$this->root}/git/vtalbot/droids.git"));
    }

    public function test_codex_events_become_steps_and_a_summary(): void
    {
        $out = new AgentOutput('codex');
        $out->line('{"type":"item.started","item":{"type":"command_execution","command":"npm test"}}');
        $this->assertSame('Running npm test', $out->step);
        $out->line('{"type":"item.completed","item":{"type":"agent_message","text":"Fixed the flaky test.\nDetails follow."}}');
        $out->line('{"type":"turn.completed","usage":{"input_tokens":50,"output_tokens":7}}');

        $this->assertSame("Fixed the flaky test.\nDetails follow.", $out->summary);
        $this->assertSame([50, 7], [$out->tokensIn, $out->tokensOut]);
        $this->assertFalse($out->limited);

        $out->line('{"type":"turn.failed","error":{"message":"You\'ve hit your usage limit."}}');
        $this->assertTrue($out->limited);
    }
}
