<?php

namespace App\Worker;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the models the CLI logins on this machine can use, so new models show
 * up in Droids without a code change. Every source is best effort.
 *
 * Claude Code: the Models API, with the login's OAuth token or ANTHROPIC_API_KEY.
 * Codex: the model list Codex caches, the model in its config, the models its
 * recent sessions ran on, and the Models API when OPENAI_API_KEY is set.
 */
class Models
{
    public const CLAUDE_URL = 'https://api.anthropic.com/v1/models?limit=100';

    public const OPENAI_URL = 'https://api.openai.com/v1/models';

    /** Recent Codex sessions to read for the models they ran on. */
    private const SESSIONS = 30;

    public function __construct(private Usage $usage) {}

    /**
     * The models per engine, with the id the CLI takes and a label to show.
     *
     * @return array{claude: list<array{id: string, label: string}>, codex: list<array{id: string, label: string}>}
     */
    public function read(): array
    {
        $claude = [];
        $codex = [];
        $root = rtrim((string) config('droids.worker.logins'), '/');
        foreach (File::isDirectory($root) ? File::directories($root) : [] as $dir) {
            if ($this->usage->isCodex($dir)) {
                $codex = [...$codex, ...$this->codex($dir)];
            } else {
                $claude = [...$claude, ...$this->claude($dir, false)];
            }
        }
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        if ($home !== '') {
            $claude = [...$claude, ...$this->claude($home.'/.claude', true)];
            $codex = [...$codex, ...$this->codex($home.'/.codex')];
        }
        $codex = [...$codex, ...$this->openai()];

        return ['claude' => $this->unique($claude), 'codex' => $this->unique($codex)];
    }

    /** @return list<array{id: string, label: string}> */
    public function claude(string $dir, bool $default): array
    {
        $token = $this->usage->claudeToken($dir, $default);
        $key = (string) getenv('ANTHROPIC_API_KEY');
        if (! $token && $key === '') {
            return [];
        }
        try {
            $request = Http::acceptJson()->timeout(15)->withHeaders(['anthropic-version' => '2023-06-01']);
            $request = $token
                ? $request->withToken($token)->withHeaders(['anthropic-beta' => 'oauth-2025-04-20'])
                : $request->withHeaders(['x-api-key' => $key]);
            $data = $request->get(self::CLAUDE_URL)->throw()->json('data');
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($data) ? $data : [] as $m) {
            $id = is_array($m) ? ($m['id'] ?? null) : null;
            if (is_string($id) && str_starts_with($id, 'claude-')) {
                $out[] = ['id' => $id, 'label' => self::claudeLabel($id, is_string($m['display_name'] ?? null) ? $m['display_name'] : null)];
            }
        }

        return $out;
    }

    /** "Claude Opus 5.5" shows as "Opus 5.5", like the models Droids started with. */
    public static function claudeLabel(string $id, ?string $display): string
    {
        $label = $display ?: Str::of($id)->after('claude-')->replace('-', ' ')->title()->toString();

        return trim((string) preg_replace('/^Claude\s+/i', '', $label));
    }

    /** @return list<array{id: string, label: string}> */
    public function codex(string $dir): array
    {
        $ids = [];
        // Newer Codex versions cache the models the login can pick from.
        foreach (['models_cache.json', 'models.json'] as $name) {
            if (File::exists("{$dir}/{$name}")) {
                $ids = [...$ids, ...$this->slugs(json_decode((string) File::get("{$dir}/{$name}"), true))];
            }
        }
        if (File::exists("{$dir}/config.toml") && preg_match('/^\s*model\s*=\s*"([^"]+)"/m', (string) File::get("{$dir}/config.toml"), $m)) {
            $ids[] = $m[1];
        }
        foreach ($this->recentSessions("{$dir}/sessions") as $file) {
            // The model is set at the start of a session: its head is enough.
            $head = (string) file_get_contents($file, false, null, 0, 65536);
            if (preg_match_all('/"model"\s*:\s*"([^"]+)"/', $head, $m)) {
                $ids = [...$ids, ...$m[1]];
            }
        }

        return array_map(fn (string $id) => ['id' => $id, 'label' => self::codexLabel($id)], array_values(array_unique(array_filter($ids, fn (string $id) => self::looksLikeCodexModel($id)))));
    }

    /** @return list<array{id: string, label: string}> */
    private function openai(): array
    {
        $key = (string) getenv('OPENAI_API_KEY');
        if ($key === '') {
            return [];
        }
        try {
            $data = Http::withToken($key)->acceptJson()->timeout(15)->get(self::OPENAI_URL)->throw()->json('data');
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach (is_array($data) ? $data : [] as $m) {
            $id = is_array($m) ? ($m['id'] ?? null) : null;
            // Only the models Codex runs: codex builds and named GPT models like gpt-6.2-sol.
            if (is_string($id) && (str_contains($id, 'codex') || preg_match('/^gpt-\d+(\.\d+)?-[a-z]+$/', $id) === 1)) {
                $out[] = ['id' => $id, 'label' => self::codexLabel($id)];
            }
        }

        return $out;
    }

    /** "gpt-5.1-codex-mini" shows as "GPT-5.1-Codex-Mini". */
    public static function codexLabel(string $id): string
    {
        return implode('-', array_map(fn (string $p) => strtolower($p) === 'gpt' ? 'GPT' : ucfirst($p), explode('-', $id)));
    }

    private static function looksLikeCodexModel(string $id): bool
    {
        return preg_match('/^(gpt|o\d|codex)[a-z0-9.\-]*$/i', $id) === 1 && strlen($id) <= 60;
    }

    /**
     * Model ids anywhere in a cached model list: "slug" or "id" fields.
     *
     * @return list<string>
     */
    private function slugs(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $out = [];
        foreach (['slug', 'id', 'model'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $out[] = $data[$key];

                break;
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $out = [...$out, ...$this->slugs($v)];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function recentSessions(string $dir): array
    {
        if (! File::isDirectory($dir)) {
            return [];
        }
        $files = [];
        foreach (File::allFiles($dir) as $f) {
            if (str_ends_with($f->getFilename(), '.jsonl')) {
                $files[$f->getPathname()] = $f->getMTime();
            }
        }
        arsort($files);

        return array_slice(array_keys($files), 0, self::SESSIONS);
    }

    /**
     * @param  list<array{id: string, label: string}>  $models
     * @return list<array{id: string, label: string}>
     */
    private function unique(array $models): array
    {
        $out = [];
        foreach ($models as $m) {
            $out[$m['id']] ??= $m;
        }

        return array_values($out);
    }
}
