<?php

namespace App\Worker;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs one job on this machine: clone the repo, put Claude Code or Codex to
 * work on a new branch, then commit and push what it did.
 */
class Runner
{
    /** Send progress to the server at most this often, in seconds. */
    public const REPORT_EVERY = 5;

    public function __construct(private Client $client) {}

    /**
     * Subscription slugs this machine can run: one login folder per slug,
     * plus the slugs marked as your everyday CLI login.
     *
     * @return list<string>
     */
    public static function signedIn(): array
    {
        $dirs = File::isDirectory($root = (string) config('droids.worker.logins'))
            ? array_map('basename', File::directories($root))
            : [];

        return array_values(array_unique(array_filter([...$dirs, ...array_values((array) config('droids.worker.default_login'))])));
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  Closure(string): void  $say  prints a line for the person watching the worker
     * @return array<string, mixed> what was sent to the server as the result
     */
    public function run(array $job, Closure $say): array
    {
        $id = (int) $job['id'];
        $dir = rtrim((string) config('droids.worker.workspace'), '/')."/run-{$id}";
        $output = new AgentOutput((string) $job['provider']);

        try {
            File::deleteDirectory($dir);
            File::ensureDirectoryExists(dirname($dir));

            $say("Cloning {$job['repo']}");
            $this->git(['clone', '--filter=blob:none', $this->remote((string) $job['repo'], (string) $job['clone_token']), $dir], dirname($dir));
            $this->git(['checkout', '-b', (string) $job['branch']], $dir);
            $this->git(['config', 'user.name', trim("{$job['droid']['unit']} {$job['droid']['name']}")], $dir);
            $this->git(['config', 'user.email', 'droids@users.noreply.github.com'], $dir);

            $say("Running {$job['provider']} ({$job['model']}) on {$job['subscription']['name']}");
            $exit = $this->agent($job, $dir, $output, $say);

            if ($exit === null) {
                return $this->finish($id, ['status' => 'failed', 'error' => 'Cancelled from Droids.'], $output);
            }
            if ($output->limited) {
                return $this->finish($id, ['status' => 'limited', 'limited_until' => $output->limitedUntil, 'error' => $output->error ?? 'Usage limit reached.'], $output);
            }
            if ($exit !== 0) {
                return $this->finish($id, ['status' => 'failed', 'error' => $output->error ?: "The {$job['provider']} CLI exited with code {$exit}."], $output);
            }

            $pushed = $this->commitAndPush($job, $dir, $say);

            return $this->finish($id, ['status' => 'succeeded', 'pushed' => $pushed], $output);
        } catch (\Throwable $e) {
            return $this->finish($id, ['status' => 'failed', 'error' => $this->redact($e->getMessage(), $job)], $output);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Run the CLI, streaming its output into progress reports.
     *
     * @param  array<string, mixed>  $job
     * @param  Closure(string): void  $say
     * @return int|null exit code, or null when the server cancelled the run
     */
    private function agent(array $job, string $dir, AgentOutput $output, Closure $say): ?int
    {
        $process = new Process($this->command($job), $dir, $this->env($job), null, (int) config('droids.worker.timeout') * 60);
        $process->start();

        $buffer = '';
        $log = '';
        $lastReport = 0.0;
        $cancelled = false;

        $flush = function (bool $force = false) use (&$log, &$lastReport, &$cancelled, $job, $output) {
            if (! $force && microtime(true) - $lastReport < self::REPORT_EVERY) {
                return;
            }
            $lastReport = microtime(true);
            if (! $this->client->progress((int) $job['id'], $output->step, $output->progress(), $log)) {
                $cancelled = true;
            }
            $log = '';
        };

        while ($process->isRunning() && ! $cancelled) {
            $process->checkTimeout();
            $buffer .= $process->getIncrementalOutput();
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if (($text = $output->line($line)) !== null) {
                    $log .= $text."\n";
                    $say($text);
                }
            }
            $flush();
            usleep(200_000);
        }

        if ($cancelled) {
            $process->stop(5);

            return null;
        }

        foreach (explode("\n", $buffer.$process->getIncrementalOutput()) as $line) {
            if (($text = $output->line($line)) !== null) {
                $log .= $text."\n";
            }
        }
        $stderr = trim($process->getErrorOutput());
        if ($stderr !== '') {
            $output->line($stderr); // catches usage-limit messages printed to stderr
            $log .= $stderr."\n";
            if ($process->getExitCode() !== 0 && $output->error === null) {
                $output->error = mb_substr($stderr, -2000);
            }
        }
        $flush(true);

        return $process->getExitCode() ?? 1;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    private function command(array $job): array
    {
        $bin = (string) config("droids.worker.bin.{$job['provider']}");
        $prompt = (string) $job['prompt'];
        $model = (string) $job['model'];

        return $job['provider'] === 'codex'
            ? [$bin, 'exec', '--json', '--model', $model, '--dangerously-bypass-approvals-and-sandbox', '--skip-git-repo-check', $prompt]
            : [$bin, '-p', $prompt, '--model', $model, '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions'];
    }

    /**
     * Point the CLI at the subscription's login folder, or its API key.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, string>
     */
    private function env(array $job): array
    {
        $sub = (array) $job['subscription'];
        $env = ['DROIDS_RUN' => (string) $job['id']];
        $folder = rtrim((string) config('droids.worker.logins'), '/').'/'.$sub['slug'];

        if (! empty($sub['api_key'])) {
            return $env + ($job['provider'] === 'codex'
                ? ['OPENAI_API_KEY' => (string) $sub['api_key'], 'CODEX_API_KEY' => (string) $sub['api_key']]
                : ['ANTHROPIC_API_KEY' => (string) $sub['api_key']]);
        }
        if (File::isDirectory($folder)) {
            return $env + [$job['provider'] === 'codex' ? 'CODEX_HOME' : 'CLAUDE_CONFIG_DIR' => $folder];
        }

        return $env; // the everyday login
    }

    /** @param array<string, mixed> $job */
    private function commitAndPush(array $job, string $dir, Closure $say): bool
    {
        if (trim($this->git(['status', '--porcelain'], $dir)) !== '') {
            $this->git(['add', '-A'], $dir);
            $this->git(['commit', '-m', (string) $job['commit_message']], $dir);
        }

        $base = trim($this->git(['rev-parse', '--abbrev-ref', 'origin/HEAD'], $dir));
        $ahead = (int) trim($this->git(['rev-list', '--count', "{$base}..HEAD"], $dir));
        if ($ahead === 0) {
            $say('No changes to push');

            return false;
        }

        $say("Pushing {$ahead} commit(s) to {$job['branch']}");
        $this->git(['remote', 'set-url', 'origin', $this->remote((string) $job['repo'], $this->client->token((int) $job['id']))], $dir);
        $this->git(['push', '-u', 'origin', (string) $job['branch']], $dir);

        return true;
    }

    private function remote(string $repo, string $token): string
    {
        $host = rtrim((string) config('droids.worker.git_host'), '/');

        return str_starts_with($host, 'https://')
            ? 'https://x-access-token:'.$token.'@'.substr($host, 8)."/{$repo}.git"
            : "{$host}/{$repo}.git";
    }

    /** @param list<string> $args */
    private function git(array $args, string $cwd): string
    {
        $process = new Process(['git', ...$args], $cwd, ['GIT_TERMINAL_PROMPT' => '0'], null, 600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git '.$args[0].' failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(int $id, array $result, AgentOutput $output): array
    {
        $result += [
            'summary' => $output->summary,
            'cost' => $output->cost,
            'tokens_in' => $output->tokensIn,
            'tokens_out' => $output->tokensOut,
        ];
        $this->client->finish($id, $result);

        return $result;
    }

    /** @param array<string, mixed> $job */
    private function redact(string $text, array $job): string
    {
        return str_replace((string) ($job['clone_token'] ?? '~'), '***', $text);
    }
}
