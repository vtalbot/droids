<?php

namespace App\Runs;

use App\Models\Project;
use App\Models\Run;

/**
 * A project's design: the design system and screen mockups its designer droid
 * writes. The worker puts the files in every run's clone under DIR, outside
 * git, so builders, reviewers and planners work from the same designs, and a
 * designer run sends the folder back to be saved.
 */
class Design
{
    /** Where the files live in a run's working folder. */
    public const DIR = '.droids/design';

    public const MAX_FILES = 60;

    /** Largest file kept, in bytes. */
    public const MAX_BYTES = 300_000;

    /** A path inside the design folder: relative, no "..", plain characters. */
    public static function validPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 200
            && preg_match('#^[A-Za-z0-9_][A-Za-z0-9._/-]*$#', $path) === 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '//');
    }

    /** @return list<array{path: string, content: string}> */
    public function files(?Project $project): array
    {
        if (! $project) {
            return [];
        }

        $out = [];
        foreach ($project->designFiles()->get(['path', 'content']) as $f) {
            $out[] = ['path' => $f->path, 'content' => $f->content];
        }

        return $out;
    }

    /**
     * Lines for a droid's prompt that point it at the project's design.
     *
     * @return list<string>
     */
    public function promptLines(?Project $project, string $for): array
    {
        $paths = $project ? $project->designFiles()->pluck('path')->all() : [];
        if ($paths === []) {
            return [];
        }
        $list = implode(', ', array_slice($paths, 0, 30)).(count($paths) > 30 ? ', …' : '');
        $start = in_array('system.md', $paths, true) ? ' Start with system.md.' : '';

        return ['', 'This project has a design from its designer droid in the folder '.self::DIR."/: {$list}.{$start}", match ($for) {
            'build' => 'Read it before any user interface work and follow it: use its tokens, components and layouts rather than inventing new ones. The folder is not part of the repository: never commit it or copy it in wholesale.',
            'review' => 'Check that user interface changes follow it, and ask for changes where they drift from it without a reason.',
            default => 'Use it when the conversation touches the user interface.',
        }];
    }

    /**
     * Save the folder a designer run sent back, as the project's whole design.
     *
     * @param  list<array{path: string, content: string}>  $files
     * @return array{changed: list<string>, removed: list<string>}
     */
    public function save(Run $run, Project $project, array $files): array
    {
        $incoming = [];
        foreach ($files as $f) {
            if (self::validPath($f['path']) && strlen($f['content']) <= self::MAX_BYTES && count($incoming) < self::MAX_FILES) {
                $incoming[$f['path']] = $f['content'];
            }
        }
        // An empty folder is more likely a failed copy than a designer deleting everything.
        if ($incoming === []) {
            return ['changed' => [], 'removed' => []];
        }

        $existing = $project->designFiles()->get()->keyBy('path');
        $changed = [];
        foreach ($incoming as $path => $content) {
            $file = $existing->get($path);
            if ($file && $file->content === $content) {
                continue;
            }
            $project->designFiles()->updateOrCreate(['path' => $path], ['content' => $content, 'droid_id' => $run->droid_id]);
            $changed[] = (string) $path;
        }
        $removed = [];
        foreach ($existing->keys() as $path) {
            if (! array_key_exists($path, $incoming)) {
                $removed[] = (string) $path;
            }
        }
        if ($removed !== []) {
            $project->designFiles()->whereIn('path', $removed)->delete();
        }

        return ['changed' => $changed, 'removed' => $removed];
    }
}
