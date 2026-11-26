<?php

namespace App\Runs;

use App\Http\Controllers\Factory\DroidController;
use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Runs that answer in a chat instead of building a task: a planning session
 * that drafts issues, "what's next" proposals for the roadmap, a droid's
 * retrospective on its own work, and plain chat with a droid. The worker runs
 * them read-only on the person's subscriptions, and the droid answers with a
 * JSON object that becomes chat items.
 */
class Conversation
{
    /** How much of the conversation goes back into the prompt. */
    private const HISTORY = 12;

    /** Builder runs a retrospective looks back over. */
    private const RETRO_RUNS = 10;

    public function __construct(private Design $design, private Memory $memory) {}

    public static function topic(string $kind, Droid $droid, ?Project $project): string
    {
        return match (true) {
            in_array($kind, ['plan', 'propose'], true) && $project !== null => "plan:{$project->slug}",
            $kind === 'design' && $project !== null => "design:{$project->slug}",
            $kind === 'recruit' => 'recruit',
            default => $droid->slug,
        };
    }

    /** The topic as stored on chat messages: null for a droid's own chat. */
    public static function storedTopic(string $topic): ?string
    {
        return preg_match('/^(plan|design):|^recruit$/', $topic) === 1 ? $topic : null;
    }

    public function prompt(Run $run): string
    {
        $run->loadMissing(['droid', 'project']);
        $droid = $run->droid;
        $project = $run->project;

        $lines = ["You are {$droid->unit} {$droid->name}, a {$droid->role} droid in Droids, a software factory where AI droids build GitHub issues."];
        $design = $run->kind === 'design';
        $lines[] = match (true) {
            $design && $project?->isLinked() => "Your working folder is a fresh clone of {$project->repo}. Read the code to learn the product, but only write inside the folder ".Design::DIR.'/.',
            $design => 'You have no copy of the code, so work from the context below. Only write inside the folder '.Design::DIR.'/.',
            $project?->isLinked() => "Your working folder is a fresh clone of {$project->repo}. Read the code as much as you need, but do not change, commit or push anything.",
            default => 'You have no copy of the code for this conversation, so work from the context below.',
        };
        if ($droid->instructions) {
            $lines = [...$lines, '', 'Standing instructions from your operator:', $droid->instructions];
        }

        $lines = [...$lines, '', ...match ($run->kind) {
            'plan' => $this->planContext($project),
            'propose' => $this->proposeContext($project),
            'reflect' => $this->reflectContext($droid),
            'design' => $this->designContext($project),
            'recruit' => $this->recruitContext($run),
            default => $this->chatContext($droid),
        }];
        if (! $design) {
            $lines = [...$lines, ...$this->design->promptLines($project, 'conversation')];
        }
        $lines = [...$lines, ...$this->memory->promptLines($project, $design)];

        $history = $this->history($run);
        if ($history !== []) {
            $lines = [...$lines, '', 'The conversation so far:', ...$history];
        }

        return implode("\n", [...$lines,
            '',
            'The person now says:',
            (string) $run->input,
            '',
            'Answer with one JSON object and nothing else: no code fence, no text around it. Its shape:',
            $this->shape($run->kind),
            'Write "message" in Markdown, in plain conversational sentences. Put what you read or checked in "reasoning", in one or two sentences.',
        ]);
    }

    /** @return list<string> */
    private function planContext(?Project $project): array
    {
        if (! $project) {
            return ['You are planning, but no project is selected.'];
        }
        $milestone = $this->activeMilestone($project);

        return [
            "You are planning work for the project {$project->name} ({$project->repo}). Drafts become GitHub issues on its board.",
            ...$this->milestoneLines($milestone),
            '',
            'Open issues already on the board:',
            ...$this->taskLines($project->tasks()->where('column', '!=', 'done')->orderBy('number')->limit(60)->get()),
            '',
            'Draft issues a single droid can finish in under a day, without repeating open issues. Pick the engine for each: "codex" for scoped implementation, "claude" where the work needs design or careful review. Size is S, M or L. Give each a short body with the acceptance criteria.',
        ];
    }

    /** @return list<string> */
    private function proposeContext(?Project $project): array
    {
        if (! $project) {
            return ['You are proposing what comes next, but no project is selected.'];
        }
        $roadmap = [];
        foreach ($project->milestones()->with('features')->orderBy('position')->get() as $m) {
            $features = $m->features->pluck('name')->implode('; ');
            $roadmap[] = "- {$m->name} ({$m->state})".($m->goal ? ": {$m->goal}" : '').($features ? " Features: {$features}" : '');
        }

        return [
            "You are proposing what the project {$project->name} ({$project->repo}) should do next.",
            '',
            'Roadmap:',
            ...($roadmap ?: ['- No milestones yet.']),
            '',
            'Recently finished issues:',
            ...$this->taskLines($project->tasks()->where('column', 'done')->orderByDesc('number')->limit(30)->get()),
            '',
            'Open issues:',
            ...$this->taskLines($project->tasks()->where('column', '!=', 'done')->orderBy('number')->limit(40)->get()),
            '',
            'Propose up to 6 features or milestones, ranked by impact, that are not already on the roadmap. Ground each "why" in something you saw in the code, the issues or the roadmap. "effort" reads like "M · 4 tasks".',
        ];
    }

    /** @return list<string> */
    private function reflectContext(Droid $droid): array
    {
        $runs = $this->retroRuns($droid);
        $lines = [];
        foreach ($runs as $r) {
            $pr = $r->task?->pullRequest;
            $reviews = collect($pr->reviews ?? [])->map(fn (array $v) => "{$v['by']} {$v['state']}")->implode(', ');
            $failedChecks = collect($pr->checks ?? [])->where('state', 'failure')->pluck('name')->implode(', ');
            $lines[] = '- #'.($r->task->number ?? '?').' '.($r->task->title ?? '').": {$r->status}"
                .($pr ? ", PR #{$pr->number} {$pr->state}" : '')
                .($reviews ? ", reviews: {$reviews}" : '')
                .($failedChecks ? ", failing checks: {$failedChecks}" : '')
                .($r->error ? '. Error: '.Str::limit($r->error, 200) : '')
                .($r->summary ? '. Summary: '.Str::limit($r->summary, 300) : '');
        }

        return [
            'You are looking back over your own recent work to find what to do differently.',
            '',
            'Your last tasks, newest first:',
            ...($lines ?: ['- None yet.']),
            '',
            'Be specific and honest. Only propose a rule if it would have prevented a problem above, and write rules as short instructions to yourself.',
        ];
    }

    /** @return list<string> */
    private function recruitContext(Run $run): array
    {
        $crew = [];
        foreach (Droid::with('project')->orderBy('unit')->get() as $d) {
            $crew[] = "- {$d->unit} {$d->name}: {$d->role} on {$d->provider} {$d->model}".($d->project ? ", on {$d->project->name}" : '');
        }
        $projects = [];
        foreach (Project::orderBy('name')->get() as $p) {
            $open = $p->tasks()->where('column', '!=', 'done')->get();
            $labels = $open->flatMap(fn (Task $t) => $t->labels ?? [])->countBy()->sortDesc()->take(6)
                ->map(fn (int $n, string $l) => "{$l} ({$n})")->implode(', ');
            $projects[] = "- {$p->name} ({$p->repo}): {$open->count()} open issues".($labels ? ", labels: {$labels}" : '').($p->designFiles()->exists() ? ', has a design' : '');
        }
        $proposed = ChatMessage::where('topic', 'recruit')->where('kind', 'candidates')->get()
            ->flatMap(fn (ChatMessage $m) => array_column($m->payload['items'] ?? [], 'name'))->unique()->implode(', ');
        $models = collect(DroidController::MODELS)->map(fn (array $m, string $p) => "{$p}: ".implode(', ', $m))->implode('; ');

        return array_values(array_filter([
            'You are the HR droid of this factory: you propose new droids for the person to hire. Droids are not tied to a project when hired; they are given tasks on any project later.',
            '',
            'The crew today:',
            ...($crew ?: ['- Nobody yet.']),
            '',
            'Projects:',
            ...($projects ?: ['- None yet.']),
            $proposed !== '' ? "\nYou already proposed: {$proposed}. Propose new names, not these again." : null,
            '',
            'Propose 4 candidate droids that answer the person\'s request. With no specific request, fill the gaps in the crew for the projects above.',
            '- "name": a short, friendly one-word droid name, different from the crew\'s.',
            '- "role": one of '.implode(', ', DroidController::ROLES).'.',
            "- \"provider\" and \"model\": pick from {$models}. Claude fits design, review and careful work; Codex fits scoped implementation.",
            '- "pitch": two sentences in the droid\'s own voice, introducing itself and what it is good at.',
            '- "why": one sentence on why the person needs it, grounded in their request, the crew or the projects.',
            '- "instructions": standing instructions for the droid, three or four short rules.',
        ], fn ($l) => $l !== null));
    }

    /** @return list<string> */
    private function designContext(?Project $project): array
    {
        if (! $project) {
            return ['You are designing, but no project is selected.'];
        }
        $paths = $project->designFiles()->pluck('path')->all();
        $dir = Design::DIR;

        return [
            "You are the designer for the project {$project->name} ({$project->repo}). The other droids on the project build, review and plan from what you write.",
            $paths === []
                ? "The design folder {$dir}/ is empty: this is the project's first design."
                : "The design folder {$dir}/ already holds: ".implode(', ', $paths).'. Read it first and change it rather than starting over, unless the person asks for a new direction.',
            '',
            'How to design:',
            '- Ground the design in the product: read the existing views, components, styles and any brand assets in the code before deciding anything.',
            "- {$dir}/system.md is the design system: principles, color, typography, spacing, radius, elevation, motion, and each component with its variants and states. Keep it current: builders read it first.",
            "- {$dir}/tokens.css holds the tokens as CSS custom properties on :root, with a dark theme under a .dark class or prefers-color-scheme if the product has one. Use the names the code already uses where it has them.",
            "- Screens go in {$dir}/screens/<name>.html: one self-contained HTML file per screen, with its CSS inline and no scripts or outside requests, using the tokens and components from the system. Show realistic content and the empty, loading and error states that matter.",
            '- Only write inside the design folder. Delete a file there only when the person asks or it is replaced.',
            '- Accessibility is part of the design: color contrast at WCAG AA, visible focus, and touch targets of at least 44 px.',
            '',
            'In "message", tell the person what you designed or changed and why, in a few sentences, and name the files.',
        ];
    }

    /** @return list<string> */
    private function chatContext(Droid $droid): array
    {
        $droid->loadMissing(['task', 'project']);
        $lines = ["Your status: {$droid->status}".($droid->step ? ", current step: {$droid->step}" : '').'.'];
        if ($droid->project) {
            $lines[] = "You are docked on the project {$droid->project->name} ({$droid->project->repo}).";
        }
        if ($droid->task) {
            $lines[] = "You are assigned to issue #{$droid->task->number}: {$droid->task->title}.";
        }
        $last = Run::where('droid_id', $droid->id)->where('kind', 'build')->latest('id')->first();
        if ($last) {
            $lines[] = "Your last build run {$last->status}".($last->error ? ': '.Str::limit($last->error, 300) : '').($last->summary ? '. Summary: '.Str::limit($last->summary, 400) : '').'.';
        }

        return [...$lines, 'Answer the person directly. You cannot change the board from here: if they want something done, tell them what to click or assign.'];
    }

    private function shape(string $kind): string
    {
        return match ($kind) {
            'plan' => '{"message": "...", "reasoning": "...", "drafts": [{"title": "...", "body": "...", "labels": ["..."], "engine": "claude" or "codex", "size": "S" or "M" or "L"}]}',
            'propose' => '{"message": "...", "reasoning": "...", "proposals": [{"kind": "feature" or "milestone", "title": "...", "why": "...", "effort": "...", "engine": "claude" or "codex"}]}',
            'reflect' => '{"message": "...", "reasoning": "...", "good": ["..."], "improve": ["..."], "rules": ["..."]}',
            'recruit' => '{"message": "...", "reasoning": "...", "candidates": [{"name": "...", "role": "...", "provider": "claude" or "codex", "model": "...", "pitch": "...", "why": "...", "instructions": "..."}]}',
            default => '{"message": "...", "reasoning": "..."}',
        };
    }

    /** @return list<string> */
    private function history(Run $run): array
    {
        $messages = ChatMessage::where('droid_id', $run->droid_id)
            ->where('topic', self::storedTopic((string) $run->topic))
            ->whereIn('kind', ['user', 'agent'])
            ->latest('id')->limit(self::HISTORY + 1)->get()->reverse()->values();

        // The newest user message is the request itself.
        if ($messages->last()?->kind === 'user') {
            $messages->pop();
        }

        $lines = [];
        foreach ($messages as $m) {
            $lines[] = ($m->kind === 'user' ? 'Person: ' : 'You: ').Str::limit((string) ($m->payload['text'] ?? ''), 1500);
        }

        return $lines;
    }

    /**
     * Turn the droid's answer into chat messages.
     *
     * @param  array{changed: list<string>, removed: list<string>}|null  $design  the design files a designer run changed
     */
    public function answer(Run $run, ?array $design = null): void
    {
        $run->loadMissing(['droid', 'project']);
        $data = $this->decode((string) $run->summary);
        $text = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : null;

        if (! is_array($data) || $text === null) {
            $raw = trim((string) $run->summary);
            $this->say($run, 'agent', ['text' => $raw !== '' ? Str::limit($raw, 6000) : 'I finished without an answer. Try asking again.']);
            $this->sayDesign($run, $design);

            return;
        }

        if ($run->project && $run->kind !== 'design') {
            $this->memory->remember($run->project, $run->droid, Memory::facts($data['remember'] ?? null));
        }
        $this->say($run, 'agent', array_filter([
            'text' => Str::limit($text, 6000),
            'reasoning' => is_string($data['reasoning'] ?? null) ? Str::limit($data['reasoning'], 1000) : null,
        ]));

        $slug = $run->project?->slug;
        match ($run->kind) {
            'plan' => $slug && ($items = $this->drafts($data['drafts'] ?? null)) ? $this->say($run, 'drafts', ['project' => $slug, 'items' => $items]) : null,
            'propose' => $slug && ($items = $this->proposals($data['proposals'] ?? null)) ? $this->say($run, 'proposals', ['project' => $slug, 'items' => $items]) : null,
            'recruit' => ($items = $this->candidates($data['candidates'] ?? null)) ? $this->say($run, 'candidates', ['items' => $items]) : null,
            'reflect' => $this->say($run, 'retro', [
                'provider' => $run->droid->provider,
                'stats' => $this->retroStats($run->droid),
                'good' => $this->strings($data['good'] ?? null, 6),
                'improve' => $this->strings($data['improve'] ?? null, 6),
                'rules' => $this->strings($data['rules'] ?? null, 5),
            ]),
            default => null,
        };
        $this->sayDesign($run, $design);
    }

    /** @param array{changed: list<string>, removed: list<string>}|null $design */
    private function sayDesign(Run $run, ?array $design): void
    {
        if ($run->kind === 'design' && $run->project && $design && ($design['changed'] !== [] || $design['removed'] !== [])) {
            $this->say($run, 'design', ['project' => $run->project->slug, 'changed' => $design['changed'], 'removed' => $design['removed']]);
        }
    }

    public function failed(Run $run, string $error): void
    {
        $this->say($run, 'agent', ['text' => "I couldn't answer: {$error}"]);
    }

    /** @param array<string, mixed> $payload */
    private function say(Run $run, string $kind, array $payload): void
    {
        ChatMessage::create([
            'droid_id' => $run->droid_id,
            'topic' => self::storedTopic((string) $run->topic),
            'kind' => $kind,
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /** @return list<array{title: string, body: string, labels: list<string>, engine: string, size: string}> */
    private function drafts(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 15) : [] as $d) {
            if (! is_array($d) || ! is_string($d['title'] ?? null) || trim($d['title']) === '') {
                continue;
            }
            $out[] = [
                'title' => Str::limit(trim($d['title']), 200, ''),
                'body' => is_string($d['body'] ?? null) ? Str::limit($d['body'], 4000) : '',
                'labels' => array_map(fn (string $l) => Str::limit($l, 40, ''), $this->strings($d['labels'] ?? null, 5)),
                'engine' => ($d['engine'] ?? '') === 'codex' ? 'codex' : 'claude',
                'size' => in_array($d['size'] ?? '', ['S', 'M', 'L'], true) ? $d['size'] : 'M',
            ];
        }

        return $out;
    }

    /** @return list<array{kind: string, title: string, why: string, effort: string, engine: string}> */
    private function proposals(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 8) : [] as $p) {
            if (! is_array($p) || ! is_string($p['title'] ?? null) || trim($p['title']) === '') {
                continue;
            }
            $out[] = [
                'kind' => ($p['kind'] ?? '') === 'milestone' ? 'milestone' : 'feature',
                'title' => Str::limit(trim($p['title']), 120, ''),
                'why' => is_string($p['why'] ?? null) ? Str::limit($p['why'], 500) : '',
                'effort' => is_string($p['effort'] ?? null) ? Str::limit($p['effort'], 40, '') : '',
                'engine' => ($p['engine'] ?? '') === 'codex' ? 'codex' : 'claude',
            ];
        }

        return $out;
    }

    /** @return list<array{name: string, role: string, provider: string, model: string, pitch: string, why: string, instructions: string}> */
    private function candidates(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 6) : [] as $c) {
            if (! is_array($c) || ! is_string($c['name'] ?? null) || trim($c['name']) === '') {
                continue;
            }
            $provider = ($c['provider'] ?? '') === 'codex' ? 'codex' : 'claude';
            $models = DroidController::MODELS[$provider];
            $out[] = [
                'name' => Str::limit(trim($c['name']), 20, ''),
                'role' => in_array($c['role'] ?? null, DroidController::ROLES, true) ? $c['role'] : 'Builder',
                'provider' => $provider,
                'model' => in_array($c['model'] ?? null, $models, true) ? $c['model'] : $models[0],
                'pitch' => is_string($c['pitch'] ?? null) ? Str::limit(trim($c['pitch']), 400) : '',
                'why' => is_string($c['why'] ?? null) ? Str::limit(trim($c['why']), 300) : '',
                'instructions' => is_string($c['instructions'] ?? null) ? Str::limit(trim($c['instructions']), 2000) : '',
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function strings(mixed $list, int $max): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = Str::limit(trim($s), 300);
            }
        }

        return array_slice($out, 0, $max);
    }

    /** @return Collection<int, Run> */
    private function retroRuns(Droid $droid)
    {
        return Run::with('task.pullRequest')->where('droid_id', $droid->id)->where('kind', 'build')
            ->whereIn('status', ['succeeded', 'failed'])->latest('id')->limit(self::RETRO_RUNS)->get();
    }

    /** @return list<array{0: string, 1: string}> */
    private function retroStats(Droid $droid): array
    {
        $runs = $this->retroRuns($droid);
        $tasks = $runs->pluck('task_id')->unique()->count();
        $merged = $runs->filter(fn (Run $r) => $r->task?->pullRequest?->state === 'merged')->pluck('task_id')->unique()->count();

        return [
            ['Tasks', (string) $tasks],
            ['PRs merged', (string) $merged],
            ['Failed runs', (string) $runs->where('status', 'failed')->count()],
            ['Cost per task', $tasks ? '$'.number_format($runs->sum('cost') / $tasks, 2) : '$0.00'],
        ];
    }

    private function activeMilestone(Project $project): ?Milestone
    {
        return $project->milestones()->with('features')->orderByRaw("state = 'active' desc")->orderBy('position')->first();
    }

    /** @return list<string> */
    private function milestoneLines(?Milestone $m): array
    {
        if (! $m) {
            return ['The project has no milestone yet.'];
        }

        return array_values(array_filter([
            "Current milestone: {$m->name}".($m->due_on ? ", due {$m->due_on->toDateString()}" : ''),
            $m->goal ? "Goal: {$m->goal}" : null,
            $m->features->isNotEmpty() ? 'Features: '.$m->features->pluck('name')->implode('; ') : null,
        ]));
    }

    /**
     * @param  iterable<Task>  $tasks
     * @return list<string>
     */
    private function taskLines(iterable $tasks): array
    {
        $out = [];
        foreach ($tasks as $t) {
            $out[] = "- #{$t->number} {$t->title}".($t->labels ? ' ['.implode(', ', $t->labels).']' : '')." ({$t->column})";
        }

        return $out ?: ['- None.'];
    }
}
