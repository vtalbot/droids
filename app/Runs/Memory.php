<?php

namespace App\Runs;

use App\Models\Droid;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * A project's shared memory: notes the droids on it keep for each other
 * (conventions, commands, gotchas, decisions), as one Markdown document.
 *
 * The worker puts it in every run's clone at FILE. Runs that may write
 * (builds, fixes, designs) edit the file and the worker sends it back; runs
 * that answer in JSON add facts with a "remember" list instead.
 */
class Memory
{
    public const FILE = '.droids/memory.md';

    /** Largest memory kept, in bytes. */
    public const MAX_BYTES = 50_000;

    public const HEADER = "# Shared memory\n\nNotes the droids on this project keep for each other.\n";

    /** The document a run starts from, or null when the run has no project. */
    public function content(?Project $project): ?string
    {
        if (! $project) {
            return null;
        }

        return $project->memory ?: self::HEADER;
    }

    /**
     * Lines for a droid's prompt that point it at the shared memory.
     *
     * @return list<string>
     */
    public function promptLines(?Project $project, bool $writable): array
    {
        if (! $project) {
            return [];
        }
        $empty = trim((string) $project->memory) === '';

        return ['',
            'Shared memory: '.self::FILE.' holds notes the droids on this project keep for each other: conventions, commands that work, gotchas, and what the person decided.'
            .($empty ? ' It is empty so far.' : ' Read it before you start.'),
            $writable
                ? 'When you learn something the next droid should know, add a short bullet to that file, with today\'s date. Only lasting facts: do not log what you did, and keep it short. It is not part of the repository: never commit it.'
                : 'To add to it, put what the next droid on this project should know in a "remember" list in your JSON answer, as short sentences that make sense on their own: facts about the code, and what the person decided or told you here (priorities, scope, plans agreed, preferences, things to avoid). Leave out what is already in it, and leave the list empty when nothing new came up.',
        ];
    }

    /** Save the file a run sent back. Edits made by another run in the meantime are kept, and this run's new lines added to them. */
    public function save(Project $project, Droid $droid, string $base, string $edited): bool
    {
        $edited = $this->clip($edited);
        $current = $this->content($project);
        if ($edited === $base || $edited === $current) {
            return false;
        }

        if ($current === $base) {
            $next = $edited;
        } else {
            $old = array_flip(explode("\n", $base));
            $added = array_filter(explode("\n", $edited), fn (string $l) => trim($l) !== '' && ! isset($old[$l]) && ! str_contains((string) $current, $l));
            if ($added === []) {
                return false;
            }
            $next = rtrim((string) $current)."\n".implode("\n", $added)."\n";
        }

        $this->store($project, $droid, $next);

        return true;
    }

    /**
     * Append facts a droid listed in its JSON answer.
     *
     * @param  list<string>  $facts
     * @return list<string> the facts added
     */
    public function remember(Project $project, Droid $droid, array $facts): array
    {
        $lines = [];
        $added = [];
        $current = (string) $this->content($project);
        foreach (array_slice($facts, 0, 8) as $fact) {
            $fact = Str::limit(trim(preg_replace('/\s+/', ' ', $fact) ?? ''), 300);
            if ($fact !== '' && ! str_contains($current, $fact) && ! in_array($fact, $added, true)) {
                $lines[] = "- {$fact} ({$droid->unit} {$droid->name}, ".now()->toDateString().')';
                $added[] = $fact;
            }
        }
        if ($lines === []) {
            return [];
        }

        $this->store($project, $droid, rtrim($current)."\n".implode("\n", $lines)."\n");

        return $added;
    }

    /**
     * The facts in a decoded JSON answer.
     *
     * @return list<string>
     */
    public static function facts(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $f) {
            if (is_string($f) && trim($f) !== '') {
                $out[] = $f;
            }
        }

        return $out;
    }

    private function store(Project $project, Droid $droid, string $memory): void
    {
        $project->forceFill([
            'memory' => $this->clip($memory),
            'memory_droid_id' => $droid->id,
            'memory_updated_at' => now(),
        ])->save();
    }

    /** Keep the newest end of a memory that grew past the limit, under its header. */
    private function clip(string $memory): string
    {
        if (strlen($memory) <= self::MAX_BYTES) {
            return $memory;
        }

        return self::HEADER."\n".substr($memory, -(self::MAX_BYTES - strlen(self::HEADER) - 1));
    }
}
