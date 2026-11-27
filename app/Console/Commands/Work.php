<?php

namespace App\Console\Commands;

use App\Worker\Client;
use App\Worker\Runner;
use App\Worker\Usage;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class Work extends Command
{
    protected $signature = 'droids:work
        {--once : Run at most one job, then exit}
        {--sleep=5 : Seconds to wait when there is nothing to do}';

    protected $description = 'Run droids on this machine: pick up queued tasks from the Droids server and work on them with Claude Code or Codex';

    /** Seconds between usage reports. */
    private const USAGE_EVERY = 300;

    public function handle(Client $client, Runner $runner, Usage $usage): int
    {
        $reported = 0;
        $this->info('Droids worker started. Signed in to: '.(implode(', ', Runner::signedIn()) ?: 'no subscriptions yet'));

        do {
            if (time() - $reported >= self::USAGE_EVERY) {
                $reported = time();
                $this->reportUsage($client, $usage);
            }
            try {
                $job = $client->claim(Runner::signedIn());
            } catch (ConnectionException|RequestException $e) {
                $this->warn('Could not reach the Droids server: '.$e->getMessage());
                $job = null;
            }

            if (! $job) {
                if ($this->option('once')) {
                    $this->line('Nothing to do.');

                    return self::SUCCESS;
                }
                sleep(max(1, (int) $this->option('sleep')));

                continue;
            }

            $this->newLine();
            $kind = $job['kind'] ?? 'build';
            $where = in_array($kind, ['build', 'fix', 'review'], true) ? "{$kind} on {$job['repo']} ({$job['branch']})" : "{$kind}".(! empty($job['repo']) ? " on {$job['repo']}" : '');
            $this->info("Run #{$job['id']}: {$job['droid']['unit']} {$job['droid']['name']} {$where}");
            $result = $runner->run($job, fn (string $line) => $this->line('  '.str_replace("\n", "\n  ", $line)));
            $this->info("Run #{$job['id']} {$result['status']}".(! empty($result['error']) ? ": {$result['error']}" : ''));
            $reported = 0; // the run used some of the limits: report again
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** Usage is extra: a failure here never stops the worker. */
    private function reportUsage(Client $client, Usage $usage): void
    {
        try {
            $client->usage($usage->read());
        } catch (Throwable $e) {
            $this->warn('Could not report subscription usage: '.$e->getMessage());
        }
    }
}
