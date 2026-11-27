<?php

namespace App\Worker;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads how much of each subscription's plan limits is used, from the CLI
 * logins on this machine, so the Subscriptions page can show it.
 *
 * Claude Code: the plan's usage endpoint, with the login's OAuth token.
 * Codex: the rate limits Codex writes into its latest session log.
 */
class Usage
{
    public const CLAUDE_URL = 'https://api.anthropic.com/api/oauth/usage';

    /**
     * Usage per subscription slug: a list of windows with the percent used and when they reset.
     *
     * @return array<string, list<array{label: string, used: float, resets_at: string|null}>>
     */
    public function read(): array
    {
        $out = [];
        $root = rtrim((string) config('droids.worker.logins'), '/');
        foreach (File::isDirectory($root) ? File::directories($root) : [] as $dir) {
            $windows = $this->isCodex($dir) ? $this->codex($dir) : $this->claude($dir, false);
            if ($windows) {
                $out[basename($dir)] = $windows;
            }
        }
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        foreach ((array) config('droids.worker.default_login') as $provider => $slug) {
            if (! is_string($slug) || $slug === '' || isset($out[$slug]) || $home === '') {
                continue;
            }
            $windows = $provider === 'codex' ? $this->codex($home.'/.codex') : $this->claude($home.'/.claude', true);
            if ($windows) {
                $out[$slug] = $windows;
            }
        }

        return $out;
    }

    public function isCodex(string $dir): bool
    {
        return File::exists($dir.'/auth.json') || File::exists($dir.'/config.toml') || File::isDirectory($dir.'/sessions');
    }

    /** @return list<array{label: string, used: float, resets_at: string|null}> */
    public function claude(string $dir, bool $default): array
    {
        $token = $this->claudeToken($dir, $default);
        if (! $token) {
            return [];
        }
        try {
            $data = Http::withToken($token)->withHeaders(['anthropic-beta' => 'oauth-2025-04-20'])->acceptJson()->timeout(15)->get(self::CLAUDE_URL)->throw()->json();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (['five_hour' => '5-hour window', 'seven_day' => 'Weekly limit', 'seven_day_opus' => 'Weekly Opus limit', 'seven_day_sonnet' => 'Weekly Sonnet limit'] as $key => $label) {
            $w = $data[$key] ?? null;
            if (is_array($w) && is_numeric($w['utilization'] ?? null)) {
                $out[] = ['label' => $label, 'used' => $this->ratio((float) $w['utilization']), 'resets_at' => $this->time($w['resets_at'] ?? null)];
            }
        }

        return $out;
    }

    /** The login's OAuth token: in its folder, or in the macOS keychain. */
    public function claudeToken(string $dir, bool $default): ?string
    {
        $json = File::exists($dir.'/.credentials.json') ? (string) File::get($dir.'/.credentials.json') : null;
        if ($json === null && PHP_OS_FAMILY === 'Darwin') {
            // Claude Code names the keychain item after the config folder, unless it is the everyday login.
            $service = $default ? 'Claude Code-credentials' : 'Claude Code-credentials-'.substr(hash('sha256', $dir), 0, 8);
            $result = Process::timeout(10)->run(['security', 'find-generic-password', '-s', $service, '-w']);
            $json = $result->successful() ? trim($result->output()) : null;
        }
        $token = json_decode((string) $json, true)['claudeAiOauth']['accessToken'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return list<array{label: string, used: float, resets_at: string|null}> */
    public function codex(string $dir): array
    {
        $file = $this->latestSession($dir.'/sessions');
        if (! $file) {
            return [];
        }
        $limits = null;
        $at = null;
        foreach (array_reverse(explode("\n", (string) File::get($file))) as $line) {
            if (! str_contains($line, 'rate_limits')) {
                continue;
            }
            $e = json_decode($line, true);
            $limits = $e['payload']['rate_limits'] ?? $e['rate_limits'] ?? $e['msg']['rate_limits'] ?? null;
            if (is_array($limits)) {
                $at = $this->time($e['timestamp'] ?? null);
                break;
            }
        }
        if (! is_array($limits)) {
            return [];
        }

        $out = [];
        foreach (['primary', 'secondary'] as $key) {
            $w = $limits[$key] ?? null;
            if (! is_array($w) || ! is_numeric($w['used_percent'] ?? null)) {
                continue;
            }
            $resets = match (true) {
                is_numeric($w['resets_at'] ?? null) => Carbon::createFromTimestamp((int) $w['resets_at'])->toIso8601String(),
                is_numeric($w['resets_in_seconds'] ?? null) => Carbon::parse($at ?? 'now')->addSeconds((int) $w['resets_in_seconds'])->toIso8601String(),
                default => null,
            };
            // A window that reset since Codex last wrote it starts again from zero.
            $stale = $resets !== null && Carbon::parse($resets)->isPast();
            $out[] = ['label' => $this->windowLabel($w['window_minutes'] ?? null, $key), 'used' => $stale ? 0.0 : $this->ratio((float) $w['used_percent']), 'resets_at' => $stale ? null : $resets];
        }

        return $out;
    }

    private function latestSession(string $dir): ?string
    {
        if (! File::isDirectory($dir)) {
            return null;
        }
        $latest = null;
        $time = 0;
        foreach (File::allFiles($dir) as $f) {
            if (str_ends_with($f->getFilename(), '.jsonl') && $f->getMTime() > $time) {
                [$latest, $time] = [$f->getPathname(), $f->getMTime()];
            }
        }

        return $latest;
    }

    private function windowLabel(mixed $minutes, string $key): string
    {
        return match (true) {
            ! is_numeric($minutes) => $key === 'primary' ? '5-hour window' : 'Weekly limit',
            (int) $minutes >= 10080 => 'Weekly limit',
            (int) $minutes % 60 === 0 => ((int) $minutes / 60).'-hour window',
            default => "{$minutes}-minute window",
        };
    }

    private function ratio(float $percent): float
    {
        return round(max(0.0, min(100.0, $percent)) / 100, 4);
    }

    private function time(mixed $value): ?string
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
