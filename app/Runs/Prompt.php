<?php

namespace App\Runs;

use App\GitHub\GitHub;
use App\Models\Run;
use Illuminate\Http\Client\RequestException;

/** Writes the instructions a builder droid gets for its task. */
class Prompt
{
    public function __construct(private GitHub $github, private Design $design) {}

    public function build(Run $run): string
    {
        $droid = $run->droid;
        $task = $run->task;
        $project = $task->project;
        $body = $this->issueBody($run);

        $lines = [
            "You are {$droid->unit} {$droid->name}, a {$droid->role} droid working in the {$project->repo} repository.",
            '',
            "Your task is GitHub issue #{$task->number}: {$task->title}",
        ];
        if ($task->labels) {
            $lines[] = 'Labels: '.implode(', ', $task->labels);
        }
        if ($body !== '') {
            $lines = [...$lines, '', 'Issue description:', $body];
        }
        if ($droid->instructions) {
            $lines = [...$lines, '', 'Standing instructions from your operator:', $droid->instructions];
        }
        $lines = [...$lines, ...$this->design->promptLines($project, 'build')];
        $fix = $run->kind === 'fix';
        if ($fix) {
            $lines = [...$lines, '', "A reviewer looked at pull request #{$run->pr_number} and asked for these changes:", (string) $run->input];
        }

        return implode("\n", [...$lines,
            '',
            'How to work:',
            $fix
                ? "- You are on the branch {$run->branch}, which already holds the earlier work. Address the review on top of it, and stay on the branch."
                : "- You are on the branch {$run->branch}. Stay on it.",
            '- Read the code and any README, CLAUDE.md or AGENTS.md before changing things, and follow the project\'s conventions.',
            '- Make the smallest change that fully solves the issue, with tests where the project has them.',
            '- Run the project\'s tests and linters if you can, and fix what you broke.',
            '- Commit your work with Conventional Commits messages. Do not push and do not open a pull request: the factory does that.',
            '- If the issue is unclear or blocked, stop and explain why instead of guessing.',
            '- Finish with a short summary of what you changed and how you checked it. It becomes the pull request description.',
        ]);
    }

    private function issueBody(Run $run): string
    {
        $project = $run->task->project;
        if (! $project->isLinked()) {
            return '';
        }

        try {
            [$owner, $repo] = $project->ownerAndName();

            return trim((string) $this->github->forProject($project)->get("/repos/{$owner}/{$repo}/issues/{$run->task->number}")->json('body'));
        } catch (RequestException) {
            return '';
        }
    }
}
