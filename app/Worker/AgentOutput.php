<?php

namespace App\Worker;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads the JSON lines Claude Code (`--output-format stream-json`) and Codex
 * (`exec --json`) print, and keeps what the factory shows: the current step,
 * the final summary, cost, tokens, and whether a usage limit was hit.
 */
class AgentOutput
{
    public ?string $step = null;

    public ?string $summary = null;

    public ?string $error = null;

    public float $cost = 0;

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public bool $limited = false;

    public ?string $limitedUntil = null;

    public int $events = 0;

    public function __construct(private string $provider) {}

    /** Feed one line of output. Returns a readable log line, or null to skip it. */
    public function line(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $event = json_decode($line, true);
        if (! is_array($event)) {
            $this->checkLimit($line);

            return $line;
        }
        $this->events++;

        return $this->provider === 'codex' ? $this->codex($event) : $this->claude($event);
    }

    /** Rough progress: climbs toward 90% as the agent works, since a run has no known length. */
    public function progress(): float
    {
        return round(0.1 + 0.8 * (1 - exp(-$this->events / 60)), 3);
    }

    /** @param array<string, mixed> $e */
    private function claude(array $e): ?string
    {
        $type = $e['type'] ?? '';

        if ($type === 'assistant') {
            $out = [];
            foreach ($e['message']['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'text' && trim((string) $part['text']) !== '') {
                    $this->step = $this->firstLine((string) $part['text']);
                    $out[] = trim((string) $part['text']);
                } elseif (($part['type'] ?? '') === 'tool_use') {
                    $this->step = $this->toolStep((string) ($part['name'] ?? 'tool'), (array) ($part['input'] ?? []));
                    $out[] = '› '.$this->step;
                }
            }

            return $out ? implode("\n", $out) : null;
        }

        if ($type === 'result') {
            $this->summary = isset($e['result']) ? trim((string) $e['result']) : $this->summary;
            $this->cost = (float) ($e['total_cost_usd'] ?? 0);
            $this->tokensIn = (int) ($e['usage']['input_tokens'] ?? 0) + (int) ($e['usage']['cache_read_input_tokens'] ?? 0) + (int) ($e['usage']['cache_creation_input_tokens'] ?? 0);
            $this->tokensOut = (int) ($e['usage']['output_tokens'] ?? 0);
            if (! empty($e['is_error'])) {
                $this->error = $this->summary ?: (string) ($e['subtype'] ?? 'error');
                $this->checkLimit($this->error);
            }

            return null;
        }

        return null;
    }

    /** @param array<string, mixed> $e */
    private function codex(array $e): ?string
    {
        $type = $e['type'] ?? '';
        $item = $e['item'] ?? [];

        if ($type === 'item.started' && ($item['type'] ?? '') === 'command_execution') {
            $this->step = 'Running '.Str::limit((string) ($item['command'] ?? ''), 120);

            return '› '.$this->step;
        }
        if ($type === 'item.completed') {
            return match ($item['type'] ?? '') {
                'agent_message' => $this->codexMessage((string) ($item['text'] ?? '')),
                'file_change' => '› '.($this->step = 'Edited '.implode(', ', array_map(fn ($c) => (string) ($c['path'] ?? ''), (array) ($item['changes'] ?? [])))),
                default => null,
            };
        }
        if ($type === 'turn.completed') {
            $this->tokensIn += (int) ($e['usage']['input_tokens'] ?? 0);
            $this->tokensOut += (int) ($e['usage']['output_tokens'] ?? 0);

            return null;
        }
        if ($type === 'turn.failed' || $type === 'error') {
            $this->error = (string) ($e['error']['message'] ?? $e['message'] ?? 'Codex failed');
            $this->checkLimit($this->error);

            return '! '.$this->error;
        }

        return null;
    }

    private function codexMessage(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        $this->summary = trim($text);
        $this->step = $this->firstLine($text);

        return trim($text);
    }

    /** @param array<string, mixed> $input */
    private function toolStep(string $tool, array $input): string
    {
        return match ($tool) {
            'Bash' => 'Running '.Str::limit((string) ($input['command'] ?? ''), 120),
            'Edit', 'MultiEdit', 'Write' => 'Editing '.($input['file_path'] ?? 'a file'),
            'Read' => 'Reading '.($input['file_path'] ?? 'a file'),
            'Grep', 'Glob' => 'Searching for '.Str::limit((string) ($input['pattern'] ?? ''), 80),
            'TodoWrite' => 'Planning the work',
            default => "Using {$tool}",
        };
    }

    private function firstLine(string $text): string
    {
        return Str::limit(trim(strtok(trim($text), "\n") ?: ''), 160);
    }

    /** Spot a plan's usage limit, and when it resets if the message says. */
    private function checkLimit(string $text): void
    {
        if (! preg_match('/usage limit|rate limit|limit reached|hit your limit|quota exceeded|too many requests/i', $text)) {
            return;
        }
        $this->limited = true;
        // Claude Code has used "Claude AI usage limit reached|<unix time>".
        if (preg_match('/\|(\d{10})\b/', $text, $m)) {
            $this->limitedUntil = Carbon::createFromTimestamp((int) $m[1])->toIso8601String();
        }
    }
}
