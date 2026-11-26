<?php

namespace App\Worker;

use App\Runs\Design;
use App\Runs\Memory;
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

            $kind = (string) ($job['kind'] ?? 'build');
            $build = self::isBuild($job);
            if (! empty($job['repo'])) {
                $say("Cloning {$job['repo']}");
                // Builds and reviews need history to diff against; conversations only read the latest code.
                $depth = $build || $kind === 'review' ? [] : ['--depth=1'];
                $this->git(['clone', '--filter=blob:none', ...$depth, $this->remote((string) $job['repo'], (string) $job['clone_token']), $dir], dirname($dir));
            } else {
                File::ensureDirectoryExists($dir);
            }
            if ($kind === 'build') {
                $this->git(['checkout', '-b', (string) $job['branch']], $dir);
            } elseif ($kind === 'fix' || $kind === 'review') {
                $this->git(['checkout', (string) $job['branch']], $dir); // the pull request's branch, from origin
            }
            $this->placeDesign($job, $dir);
            $memory = $this->placeMemory($job, $dir);
            if ($build) {
                $this->git(['config', 'user.name', trim("{$job['droid']['unit']} {$job['droid']['name']}")], $dir);
                $this->git(['config', 'user.email', 'droids@users.noreply.github.com'], $dir);
            }

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

            $pushed = $build && $this->commitAndPush($job, $dir, $say);
            $result = ['status' => 'succeeded', 'pushed' => $pushed];
            if ($memory !== null && is_file($file = $dir.'/'.Memory::FILE) && ($edited = (string) file_get_contents($file)) !== $memory) {
                $result += ['memory' => $edited, 'memory_base' => $memory];
                $say('Sending the updated shared memory');
            }
            if ($kind === 'design') {
                $result['design'] = $this->collectDesign($dir);
                $say('Sending '.count($result['design']).' design file(s)');
            }

            return $this->finish($id, $result, $output);
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

        // Builds and fixes change the code; reviews and conversations may only read it.
        if (self::isBuild($job)) {
            return $job['provider'] === 'codex'
                ? [$bin, 'exec', '--json', '--model', $model, '--dangerously-bypass-approvals-and-sandbox', '--skip-git-repo-check', $prompt]
                : [$bin, '-p', $prompt, '--model', $model, '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions'];
        }

        // A designer may write, but only in the design folder (Codex's sandbox can't narrow it, and the clone is thrown away).
        $design = ($job['kind'] ?? '') === 'design';
        $folder = './'.Design::DIR.'/**';
        $tools = match ($job['kind'] ?? '') {
            'review' => 'Read,Grep,Glob,LS,Bash(git diff:*),Bash(git log:*),Bash(git show:*)',
            'design' => "Read,Grep,Glob,LS,Edit({$folder}),Write({$folder}),Edit(./".Memory::FILE.')',
            default => 'Read,Grep,Glob,LS',
        };

        return $job['provider'] === 'codex'
            ? [$bin, 'exec', '--json', '--model', $model, '--sandbox', $design ? 'workspace-write' : 'read-only', '--skip-git-repo-check', $prompt]
            : [$bin, '-p', $prompt, '--model', $model, '--output-format', 'stream-json', '--verbose', '--allowedTools', $tools];
    }

    /**
     * Put the project's design in the working folder, kept out of git so a build never commits it.
     *
     * @param  array<string, mixed>  $job
     */
    private function placeDesign(array $job, string $dir): void
    {
        $files = is_array($job['design'] ?? null) ? $job['design'] : [];
        if ($files === [] && ($job['kind'] ?? '') !== 'design') {
            return;
        }
        $root = $dir.'/'.Design::DIR;
        File::ensureDirectoryExists($root);
        foreach ($files as $f) {
            $path = (string) ($f['path'] ?? '');
            if (! Design::validPath($path)) {
                continue;
            }
            File::ensureDirectoryExists(dirname("{$root}/{$path}"));
            File::put("{$root}/{$path}", (string) ($f['content'] ?? ''));
        }
        $this->exclude($dir);
    }

    /**
     * Put the project's shared memory in the working folder, and return what was written.
     *
     * @param  array<string, mixed>  $job
     */
    private function placeMemory(array $job, string $dir): ?string
    {
        if (! is_string($job['memory'] ?? null)) {
            return null;
        }
        File::ensureDirectoryExists(dirname($dir.'/'.Memory::FILE));
        File::put($dir.'/'.Memory::FILE, $job['memory']);
        $this->exclude($dir);

        return $job['memory'];
    }

    /** Keep the .droids folder out of git, so a build never commits it. */
    private function exclude(string $dir): void
    {
        $file = "{$dir}/.git/info/exclude";
        if (! File::isDirectory("{$dir}/.git") || (File::exists($file) && str_contains(File::get($file), '/.droids/'))) {
            return;
        }
        File::ensureDirectoryExists(dirname($file));
        File::append($file, "\n/.droids/\n");
    }

    /**
     * The design folder after a designer run, to save on the server.
     *
     * @return list<array{path: string, content: string}>
     */
    private function collectDesign(string $dir): array
    {
        $root = $dir.'/'.Design::DIR;
        if (! File::isDirectory($root)) {
            return [];
        }
        $out = [];
        foreach (File::allFiles($root, true) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if (! Design::validPath($path) || $file->getSize() > Design::MAX_BYTES || $file->isLink()) {
                continue;
            }
            $content = (string) File::get($file->getPathname());
            if (! mb_check_encoding($content, 'UTF-8')) {
                continue; // text only: images and fonts stay out
            }
            $out[] = ['path' => $path, 'content' => $content];
            if (count($out) >= Design::MAX_FILES) {
                break;
            }
        }
        usort($out, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return $out;
    }

    /** @param array<string, mixed> $job */
    private static function isBuild(array $job): bool
    {
        return in_array($job['kind'] ?? 'build', ['build', 'fix'], true);
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

        // A fix adds to a branch that is already pushed; a new build is measured against the default branch.
        $base = ($job['kind'] ?? 'build') === 'fix'
            ? "origin/{$job['branch']}"
            : trim($this->git(['rev-parse', '--abbrev-ref', 'origin/HEAD'], $dir));
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
