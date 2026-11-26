<?php

namespace App\Runs;

use App\GitHub\RepositoryWriter;
use App\Models\PullRequest;
use App\Models\Run;
use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

/**
 * A reviewer droid's pass over a pull request: what it is asked to check, and
 * how its verdict lands on GitHub and on the board.
 *
 * The GitHub App opened the pull request, and GitHub doesn't let an author
 * approve or block its own pull request, so the review is posted as a comment
 * that states the verdict. The verdict itself is kept on the pull request in
 * Droids, where it drives the board and the fix loop.
 */
class Review
{
    /** Inline comments posted per review; the rest go in the body. */
    private const MAX_COMMENTS = 20;

    public function __construct(private RepositoryWriter $writer, private Design $design, private Memory $memory) {}

    public function prompt(Run $run): string
    {
        $run->loadMissing(['droid', 'task.project', 'task.pullRequest']);
        $droid = $run->droid;
        $task = $run->task;
        $pr = $task?->pullRequest;

        $lines = [
            "You are {$droid->unit} {$droid->name}, a reviewer droid in Droids, a software factory where AI droids build GitHub issues.",
            "Your working folder is a clone of {$task?->project->repo} on the branch {$run->branch}, the head of pull request #{$run->pr_number}.",
            "The pull request solves issue #{$task?->number}: {$task?->title}",
        ];
        if ($droid->instructions) {
            $lines = [...$lines, '', 'Standing instructions from your operator:', $droid->instructions];
        }
        $lines = [...$lines, ...$this->design->promptLines($task?->project, 'review'), ...$this->memory->promptLines($task?->project, false)];
        $failing = collect($pr->checks ?? [])->where('state', 'failure')->pluck('name')->implode(', ');
        if ($failing !== '') {
            $lines = [...$lines, '', "These CI checks are failing on the pull request: {$failing}."];
        }

        return implode("\n", [...$lines,
            '',
            'How to review:',
            '- See the change with `git diff origin/HEAD...HEAD` and `git log origin/HEAD..HEAD`, and read the surrounding code.',
            '- Check that it solves the issue, that it is correct, that it has tests where the project has them, and that it follows the project\'s conventions.',
            '- Do not change, commit or push anything.',
            '- Ask for changes only for real problems: bugs, missing pieces of the issue, missing tests, broken conventions. Mention small things without blocking on them.',
            '',
            'Answer with one JSON object and nothing else: no code fence, no text around it. Its shape:',
            '{"verdict": "approve" or "changes", "summary": "...", "comments": [{"path": "src/file.ts", "line": 42, "body": "..."}], "remember": ["..."]}',
            '"summary" is Markdown for the pull request. "line" is a line number in the new version of the file, on a line the diff changed.',
        ]);
    }

    /**
     * Post the review and record the verdict.
     *
     * @return array{state: string, feedback: string}
     */
    public function post(Run $run, Task $task): array
    {
        $data = $this->decode((string) $run->summary);
        $verdict = match ($data['verdict'] ?? null) {
            'approve' => 'approved',
            'changes' => 'changes',
            default => 'commented',
        };
        $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : trim((string) $run->summary);
        $comments = $this->comments($data['comments'] ?? null);

        $droid = $run->droid;
        $heading = match ($verdict) {
            'approved' => 'Looks good to merge.',
            'changes' => 'Changes requested.',
            default => 'Comments, no verdict.',
        };
        $body = "**{$droid->unit} {$droid->name}** (reviewer droid): {$heading}\n\n".Str::limit($summary, 30000);

        $posted = $this->send($task, (int) $run->pr_number, $body, $comments);
        if (! $posted) {
            $run->error = 'The review could not be posted on GitHub; its verdict is kept in Droids.';
        }
        $this->record($task->pullRequest, $droid->unit, $verdict);
        $this->memory->remember($task->project, $droid, Memory::facts($data['remember'] ?? null));

        $notes = array_map(fn (array $c) => "- {$c['path']}:{$c['line']}: {$c['body']}", $comments);

        return ['state' => $verdict, 'feedback' => trim($summary."\n\n".implode("\n", $notes))];
    }

    /** @param list<array{path: string, line: int, body: string}> $comments */
    private function send(Task $task, int $number, string $body, array $comments): bool
    {
        try {
            $this->writer->createReview($task->project, $number, $body, array_slice($comments, 0, self::MAX_COMMENTS));

            return true;
        } catch (RequestException $e) {
            if ($e->response->status() !== 422 || $comments === []) {
                return false;
            }
        } catch (ConnectionException) {
            return false;
        }

        // A comment pointed outside the diff: fold them all into the body instead.
        $list = implode("\n", array_map(fn (array $c) => "- `{$c['path']}:{$c['line']}` {$c['body']}", $comments));
        try {
            $this->writer->createReview($task->project, $number, "{$body}\n\n{$list}", []);

            return true;
        } catch (RequestException|ConnectionException) {
            return false;
        }
    }

    /** Keep the droid's verdict next to the reviews synced from GitHub. */
    private function record(?PullRequest $pr, string $unit, string $verdict): void
    {
        if (! $pr) {
            return;
        }
        $reviews = array_values(array_filter($pr->reviews ?? [], fn (array $r) => $r['by'] !== $unit));
        $reviews[] = ['by' => $unit, 'state' => $verdict, 'droid' => true];
        $states = array_column($reviews, 'state');

        $pr->reviews = $reviews;
        if (in_array($pr->state, ['open', 'approved', 'changes'], true)) {
            $pr->state = match (true) {
                in_array('changes', $states, true) => 'changes',
                in_array('approved', $states, true) => 'approved',
                default => 'open',
            };
        }
        $pr->save();
    }

    /** @return array<string, mixed> */
    private function decode(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : [];
    }

    /** @return list<array{path: string, line: int, body: string}> */
    private function comments(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $c) {
            if (! is_array($c) || ! is_string($c['path'] ?? null) || ! is_numeric($c['line'] ?? null) || ! is_string($c['body'] ?? null)) {
                continue;
            }
            $out[] = ['path' => ltrim($c['path'], './'), 'line' => max(1, (int) $c['line']), 'body' => Str::limit(trim($c['body']), 2000)];
        }

        return array_slice($out, 0, 50);
    }
}
