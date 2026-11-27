<?php

namespace App\Runs;

use App\GitHub\RepositoryWriter;
use App\Http\Controllers\Factory\CloudVariableController;
use App\Http\Controllers\Factory\DroidController;
use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Droid;
use App\Models\EngineModel;
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

    /** How actions work in a conversation: the droid proposes, the person clicks. */
    private const ACTION_RULES = [
        'You cannot create anything yourself: milestones and issues you put in the JSON appear under your message as cards, with buttons the person clicks to create them on the board and on GitHub. So never say you created something. Say it is ready below and that one click creates it.',
        'A draft can name the milestone it belongs to in "milestone", either an existing one or one you propose in the same answer, and a feature of that milestone in "feature".',
        'Put up to three short follow-ups the person is likely to want next in "next", written as what they would type, such as "Draft the issues for this milestone". Leave it empty when nothing obvious follows.',
    ];

    /** The starter droids Assemble droid always offers (resources/js/factory/dialogs.tsx). */
    private const STARTER_NAMES = ['Mira', 'Bolt', 'Lumen', 'Atlas', 'Iris'];

    /**
     * Forms a droid can fill in: each field it suggests, with what it means.
     *
     * @var array<string, array{title: string, fields: array<string, string>}>
     */
    public const SUGGEST_FORMS = [
        'email' => ['title' => 'the email set-up of the project', 'fields' => [
            'from_local' => 'the part of the sender address before the @: short, lowercase, letters, digits, dots or dashes (like hello, team or notifications)',
            'from_name' => 'the sender name people see in their inbox, usually the product name, maybe with a team word',
        ]],
        'project' => ['title' => 'the name of the project', 'fields' => [
            'name' => 'a short, memorable product name for the project, at most 40 characters',
        ]],
    ];

    /** Ideas cards one triage run looks at. */
    public const TRIAGE_BATCH = 10;

    public function __construct(private Design $design, private Memory $memory, private RepositoryWriter $github) {}

    public static function topic(string $kind, Droid $droid, ?Project $project): string
    {
        return match (true) {
            in_array($kind, ['plan', 'propose'], true) && $project !== null => "plan:{$project->slug}",
            $kind === 'design' && $project !== null => "design:{$project->slug}",
            $kind === 'recruit' => 'recruit',
            $kind === 'chat' && $project !== null => "discuss:{$project->slug}",
            $kind === 'triage' && $project !== null => "triage:{$project->slug}",
            default => $droid->slug,
        };
    }

    /** The topic as stored on chat messages: null for a droid's own chat. */
    public static function storedTopic(string $topic): ?string
    {
        // Triage and the Mechanic talk to the person in the project discussion.
        if (str_starts_with($topic, 'triage:')) {
            return 'discuss:'.substr($topic, 7);
        }
        if (preg_match('/^(?:unblock|answer):([^:]+):\d+$/', $topic, $m) === 1) {
            return "discuss:{$m[1]}";
        }

        return preg_match('/^(plan|design|discuss|suggest):|^recruit$/', $topic) === 1 ? $topic : null;
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
            'triage' => $this->triageContext($project, $droid),
            'answer' => $this->answerContext($run),
            'suggest' => $this->suggestContext($run),
            'unblock' => $this->unblockContext($run),
            default => $project ? $this->discussContext($project) : $this->chatContext($droid),
        }];
        if (! $design) {
            $lines = [...$lines, ...$this->design->promptLines($project, 'conversation')];
        }
        $lines = [...$lines, ...$this->memory->promptLines($this->memoryProject($run), $design)];

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
            $run->kind === 'suggest' ? $this->suggestShape($run) : $this->shape($run->kind, $project !== null, $this->memoryProject($run) !== null),
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
            ...$this->openMilestones($project),
            '',
            'Open issues already on the board:',
            ...$this->taskLines($project->tasks()->where('column', '!=', 'done')->orderBy('number')->limit(60)->get()),
            ...$this->discussionLines($project),
            '',
            'Draft issues a single droid can finish in under a day, without repeating open issues. Pick the engine for each: "codex" for scoped implementation, "claude" where the work needs design or careful review. Size is S, M or L. Give each a short body with the acceptance criteria.',
            ...self::ACTION_RULES,
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
            ...$this->discussionLines($project),
            '',
            'Propose up to 6 features or milestones, ranked by impact, that are not already on the roadmap. Ground each "why" in something you saw in the code, the issues or the roadmap. "effort" reads like "M · 4 tasks".',
            self::ACTION_RULES[2],
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
        $proposed = collect($this->takenNames())->unique(fn (string $n) => mb_strtolower($n))->implode(', ');
        $models = collect(EngineModel::labels())->map(fn (array $m, string $p) => "{$p}: ".implode(', ', $m))->implode('; ');
        $roles = [];
        foreach (DroidController::ROLE_GUIDE as $role => [$does, $fits]) {
            $roles[] = "  - {$role}: {$does} ({$fits})";
        }

        return [
            'You are the HR droid of this factory: you propose new droids for the person to hire. Droids are not tied to a project when hired; they are given tasks on any project later.',
            '',
            'The crew today:',
            ...($crew ?: ['- Nobody yet.']),
            '',
            'Projects:',
            ...($projects ?: ['- None yet.']),
            "\nNames already taken (crew, starters and earlier candidates): {$proposed}. Every candidate needs a new name that is not in this list, and no two candidates may share a name. A candidate with a taken name is dropped.",
            '',
            'Propose 4 candidate droids that answer the person\'s request. With no specific request, fill the gaps in the crew for the projects above.',
            '- "name": a short, friendly one-word droid name that is not taken.',
            '- "role": one of these, with what it does and the models that fit it:',
            ...$roles,
            "- \"provider\" and \"model\": pick from {$models}. Claude fits design, review and careful work; Codex fits scoped implementation. Pick the smallest model that does the role well: light roles (Triager, Fixer, Docs, Copywriter) run well on Haiku 5.5 or the lightest GPT model on Codex, which keeps the big models free for building and review.",
            '- "pitch": two sentences in the droid\'s own voice, introducing itself and what it is good at.',
            '- "why": one sentence on why the person needs it, grounded in their request, the crew or the projects.',
            '- "instructions": standing instructions for the droid, three or four short rules.',
        ];
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
    private function discussContext(Project $project): array
    {
        $milestone = $this->activeMilestone($project);
        $open = $project->tasks()->where('column', '!=', 'done')->orderBy('number')->limit(40)->get();
        $crew = Droid::where('project_id', $project->id)->orderBy('unit')->get()
            ->map(fn (Droid $d) => "{$d->unit} {$d->name} ({$d->role}, {$d->status})")->implode(', ');

        return [
            "The person wants to talk about the project {$project->name} ({$project->repo}): how it is going, the code, the plan, or what to do next.",
            ...$this->milestoneLines($milestone),
            ...$this->openMilestones($project),
            $crew !== '' ? "Droids docked on it: {$crew}." : 'No droid is docked on it yet.',
            '',
            'Open issues on its board:',
            ...$this->taskLines($open),
            '',
            'Answer the person directly and ground what you say in the code and the board.',
            'When the person wants a milestone, put it in "milestones". When they want work planned, or agree to a plan you discussed, draft the issues in "drafts". Leave both empty otherwise.',
            ...$this->cloudLines($project),
            ...self::ACTION_RULES,
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

    private const DRAFT = '{"title": "...", "body": "...", "labels": ["..."], "engine": "claude" or "codex", "size": "S" or "M" or "L", "milestone": "..." or null, "feature": "..." or null}';

    private function shape(string $kind, bool $project = false, bool $memory = false): string
    {
        $shape = match ($kind) {
            'plan' => '{"message": "...", "reasoning": "...", "drafts": ['.self::DRAFT.'], "next": ["..."]}',
            'chat' => $project
                ? '{"message": "...", "reasoning": "...", "milestones": [{"title": "...", "goal": "...", "due": "YYYY-MM-DD" or null}], "drafts": ['.self::DRAFT.'], "variables": [{"key": "...", "value": "...", "environment": "..." or null}], "next": ["..."]}'
                : '{"message": "...", "reasoning": "..."}',
            'propose' => '{"message": "...", "reasoning": "...", "proposals": [{"kind": "feature" or "milestone", "title": "...", "why": "...", "effort": "...", "engine": "claude" or "codex"}], "next": ["..."]}',
            'reflect' => '{"message": "...", "reasoning": "...", "good": ["..."], "improve": ["..."], "rules": ["..."]}',
            'unblock' => '{"message": "...", "reasoning": "...", "action": "retry" or "model" or "ask", "model": "..." or null}',
            'triage' => '{"message": "...", "reasoning": "...", "ready": [{"number": 12, "priority": "high", "criteria": "- ...\\n- ..."}], "questions": [{"number": 13, "priority": "medium", "to": "person" or a crew role, "question": "..."}]}',
            'recruit' => '{"message": "...", "reasoning": "...", "candidates": [{"name": "...", "role": "...", "provider": "claude" or "codex", "model": "...", "pitch": "...", "why": "...", "instructions": "..."}]}',
            default => '{"message": "...", "reasoning": "..."}',
        };

        // Every run with a project can add to its shared memory.
        return $memory && $kind !== 'design' ? substr($shape, 0, -1).', "remember": ["..."]}' : $shape;
    }

    /** @return list<string> */
    private function history(Run $run): array
    {
        $topic = self::storedTopic((string) $run->topic);
        // A project discussion is one thread, whichever droid answers.
        $messages = ChatMessage::when(! str_starts_with((string) $topic, 'discuss:'), fn ($q) => $q->where('droid_id', $run->droid_id))
            ->where('topic', $topic)
            ->whereIn('kind', ['user', 'agent'])
            ->latest('id')->limit(self::HISTORY + 1)->get()->reverse()->values();

        // The newest user message is the request itself; a triage run starts on its own.
        if (! in_array($run->kind, ['triage', 'unblock', 'answer'], true) && $messages->last()?->kind === 'user') {
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
        if ($run->kind === 'suggest') {
            $this->suggested($run, is_array($data) ? $data : []);

            return;
        }
        if ($run->kind === 'unblock') {
            $this->unblocked($run, is_array($data) ? $data : []);

            return;
        }
        if ($run->kind === 'answer') {
            $raw = trim((string) $run->summary);
            $said = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : ($raw !== '' && ! is_array($data) ? $raw : null);
            $this->answered($run, is_array($data) ? $data : [], $said);

            return;
        }
        $text = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : null;

        if (! is_array($data) || $text === null) {
            $raw = trim((string) $run->summary);
            $this->say($run, 'agent', ['text' => $raw !== '' ? Str::limit($raw, 6000) : 'I finished without an answer. Try asking again.']);
            $this->sayDesign($run, $design);

            return;
        }

        $remembered = [];
        if (($memory = $this->memoryProject($run)) && $run->kind !== 'design') {
            $remembered = $this->memory->remember($memory, $run->droid, Memory::facts($data['remember'] ?? null));
        }
        $next = in_array($run->kind, ['chat', 'plan', 'propose'], true) ? array_map(fn (string $n) => Str::limit($n, 120, ''), $this->strings($data['next'] ?? null, 3)) : [];
        $this->say($run, 'agent', array_filter([
            'text' => Str::limit($text, 6000),
            'reasoning' => is_string($data['reasoning'] ?? null) ? Str::limit($data['reasoning'], 1000) : null,
            'next' => $next ?: null,
            // Shown under the reply, so the person sees what the next droid will know.
            'remembered' => $remembered ?: null,
        ]));

        $slug = $run->project?->slug;
        match ($run->kind) {
            'plan' => $slug && ($items = $this->drafts($data['drafts'] ?? null)) ? $this->say($run, 'drafts', ['project' => $slug, 'items' => $items]) : null,
            'propose' => $slug && ($items = $this->proposals($data['proposals'] ?? null)) ? $this->say($run, 'proposals', ['project' => $slug, 'items' => $items]) : null,
            'chat' => $slug ? $this->sayActions($run, $slug, $data) : null,
            'triage' => $run->project ? $this->triaged($run->project, $data) : null,
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

    /**
     * A project discussion's milestones and drafts, as cards with create buttons.
     *
     * @param  array<string, mixed>  $data
     */
    private function sayActions(Run $run, string $slug, array $data): void
    {
        $milestones = [];
        foreach (is_array($data['milestones'] ?? null) ? array_slice($data['milestones'], 0, 4) : [] as $m) {
            if (! is_array($m) || ! is_string($m['title'] ?? null) || trim($m['title']) === '') {
                continue;
            }
            $due = is_string($m['due'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $m['due']) === 1 ? $m['due'] : null;
            $milestones[] = array_filter([
                'kind' => 'milestone',
                'title' => Str::limit(trim($m['title']), 120, ''),
                'why' => is_string($m['goal'] ?? null) ? Str::limit(trim($m['goal']), 300, '') : '',
                'effort' => $due ? 'due '.$due : '',
                'engine' => $run->droid->provider,
                'due' => $due,
            ], fn ($v) => $v !== null);
        }
        if ($milestones) {
            $this->say($run, 'proposals', ['project' => $slug, 'items' => $milestones]);
        }
        if ($items = $this->drafts($data['drafts'] ?? null)) {
            $this->say($run, 'drafts', ['project' => $slug, 'items' => $items]);
        }
        if ($run->project?->cloud_application_id && ($items = $this->variables($data['variables'] ?? null))) {
            $this->say($run, 'variables', ['project' => $slug, 'items' => $items]);
        }
    }

    /** @return list<array{key: string, value: string, environment?: string}> */
    private function variables(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 20) : [] as $v) {
            $key = is_array($v) && is_string($v['key'] ?? null) ? strtoupper(trim($v['key'])) : '';
            if (preg_match(CloudVariableController::KEY, $key) !== 1 || strlen($key) > 255) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'value' => is_scalar($v['value'] ?? null) ? Str::limit((string) $v['value'], 2000, '') : '',
                ...(is_string($v['environment'] ?? null) && $v['environment'] !== '' ? ['environment' => Str::limit($v['environment'], 60, '')] : []),
            ];
        }

        return $out;
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

    /** @return list<array{title: string, body: string, labels: list<string>, engine: string, size: string, milestone?: string, feature?: string}> */
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
                ...array_filter([
                    'milestone' => is_string($d['milestone'] ?? null) && trim($d['milestone']) !== '' ? Str::limit(trim($d['milestone']), 120, '') : null,
                    'feature' => is_string($d['feature'] ?? null) && trim($d['feature']) !== '' ? Str::limit(trim($d['feature']), 120, '') : null,
                ]),
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
        // A name already in the crew, already proposed, or twice in this batch is dropped.
        $taken = [];
        foreach ($this->takenNames() as $n) {
            $taken[mb_strtolower($n)] = true;
        }
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 8) : [] as $c) {
            if (! is_array($c) || ! is_string($c['name'] ?? null) || trim($c['name']) === '') {
                continue;
            }
            $key = mb_strtolower(Str::limit(trim($c['name']), 20, ''));
            if (isset($taken[$key])) {
                continue;
            }
            $taken[$key] = true;
            $provider = ($c['provider'] ?? '') === 'codex' ? 'codex' : 'claude';
            $models = EngineModel::labels()[$provider];
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

        return array_slice($out, 0, 6);
    }

    /**
     * Names the HR droid may not use again: the crew, the starters and every earlier proposal.
     *
     * @return list<string>
     */
    private function takenNames(): array
    {
        $names = [];
        foreach (Droid::pluck('name') as $n) {
            $names[] = (string) $n;
        }
        foreach (self::STARTER_NAMES as $n) {
            $names[] = $n;
        }
        foreach (ChatMessage::where('topic', 'recruit')->where('kind', 'candidates')->get() as $m) {
            foreach ($m->payload['items'] ?? [] as $item) {
                if (is_string($item['name'] ?? null)) {
                    $names[] = $item['name'];
                }
            }
        }

        return $names;
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

    /** The project whose shared memory a run reads and adds to: a droid's own chat uses the project it is on. */
    private function memoryProject(Run $run): ?Project
    {
        if ($run->project) {
            return $run->project;
        }

        return in_array($run->kind, ['chat', 'reflect'], true) ? $run->droid->project : null;
    }

    /**
     * The Ideas cards a triage run should look at: new ones, and ones whose
     * question the person may have answered in the discussion since.
     *
     * @return Collection<int, Task>
     */
    public static function triageCandidates(Project $project): Collection
    {
        $answered = ChatMessage::where('topic', "discuss:{$project->slug}")->where('kind', 'user')->max('created_at');

        return $project->tasks()->where('column', 'backlog')->whereNull('builder_id')
            ->where(fn ($q) => $q->whereNull('triaged_at')
                ->orWhere(fn ($q) => $q->whereNotNull('question')->where('triaged_at', '<', $answered ?? '1970-01-01')))
            ->orderByRaw(Task::BY_PRIORITY)->orderBy('position')->limit(self::TRIAGE_BATCH)->get();
    }

    /** @return list<string> */
    private function triageContext(?Project $project, Droid $triager): array
    {
        if (! $project) {
            return ['You are triaging, but no project is selected.'];
        }
        // Crew droids that can answer a question, by role: never the triager itself.
        $helpers = Droid::where('project_id', $project->id)->whereKeyNot($triager->id)
            ->whereNotIn('role', ['HR', 'Triager', 'Mechanic'])->orderBy('unit')->get()->unique('role');
        $roles = $helpers->map(fn (Droid $d) => "{$d->role} ({$d->name})")->implode(', ');
        $cards = [];
        foreach (self::triageCandidates($project) as $t) {
            $body = $project->isLinked() ? rescue(fn () => $this->github->issueBody($project, $t->number), '', false) : '';
            $cards = [...$cards, '', "#{$t->number} {$t->title}".($t->labels ? ' ['.implode(', ', $t->labels).']' : '').($t->priority ? " (priority set by the person: {$t->priorityName()})" : ''),
                $body !== '' ? Str::limit($body, 3000) : '(no description)'];
            if ($t->question) {
                $cards[] = 'You asked earlier'.($t->question_for ? " the {$t->question_for}" : '').": {$t->question}";
            }
            if ($t->answer) {
                $cards[] = "The {$t->question_for} answered: ".Str::limit($t->answer, 1500);
            }
        }

        return [
            "You triage the Ideas column of the project {$project->name} ({$project->repo}). A card you mark ready moves to \"Ready for droids\", where a builder droid picks it up and builds it alone, with no one to ask.",
            ...$this->milestoneLines($this->activeMilestone($project)),
            '',
            'The cards to triage:',
            ...$cards,
            '',
            'For each card, read the code it touches, then decide:',
            '- Ready: a builder could finish it in under a day without guessing. Put it in "ready" with acceptance criteria as a short Markdown list, which is added to the issue.',
            '- Not ready: something is missing. Put one short, specific question in "questions". Do not ask what you can find in the code yourself.',
            ...($roles !== '' ? [
                "  Send it to the droid who can answer from the code, the design or the plan: put its role in \"to\". On the crew: {$roles}. The Planner knows scope and the plan, the Designer the screens and flows, a Builder or Reviewer the technical approach, a Copywriter names and wording.",
                '  Put "person" in "to" only for what only the person can decide: business rules, pricing, priorities between goals, taste, or anything a droid answered with "only you can decide".',
            ] : ['  Put "person" in "to": there is no other droid on the crew to ask.']),
            'Give every card a "priority": urgent (broken in production or blocking other work), high (on the active milestone\'s critical path), medium (planned work), or low (nice to have). Builders take the highest priority first. Keep a priority the person set unless the discussion changes it.',
            'A card you asked about before is ready once the conversation or the droid you asked answers it.',
            'In "message", tell the person in a few sentences which cards you moved, which questions you sent to which droid, and what you need from them, by issue number. They answer in this discussion.',
        ];
    }

    /** @param array<string, mixed> $data */
    private function triaged(Project $project, array $data): void
    {
        $candidates = self::triageCandidates($project)->keyBy('number');
        foreach (is_array($data['ready'] ?? null) ? $data['ready'] : [] as $r) {
            $task = is_array($r) && is_numeric($r['number'] ?? null) ? $candidates->pull((int) $r['number']) : null;
            if (! $task) {
                continue;
            }
            $criteria = is_string($r['criteria'] ?? null) ? trim(Str::limit($r['criteria'], 3000, '')) : '';
            if ($criteria !== '' && $project->isLinked()) {
                rescue(fn () => $this->github->appendToIssue($project, $task->number, "## Acceptance criteria\n\n{$criteria}"), null, false);
            }
            $task->update(['column' => 'ready', 'triaged_at' => now(), 'question' => null, 'priority' => $this->priorityFor($task, $r), 'position' => (int) $project->tasks()->where('column', 'ready')->max('position') + 1]);
        }
        foreach (is_array($data['questions'] ?? null) ? $data['questions'] : [] as $q) {
            $task = is_array($q) && is_numeric($q['number'] ?? null) ? $candidates->pull((int) $q['number']) : null;
            if ($task && is_string($q['question'] ?? null) && trim($q['question']) !== '') {
                $question = Str::limit(trim($q['question']), 500);
                $helper = $this->helperFor($project, $task, is_string($q['to'] ?? null) ? $q['to'] : 'person');
                $task->update(['triaged_at' => now(), 'question' => $question, 'question_for' => $helper?->role, 'answer' => null, 'priority' => $this->priorityFor($task, $q)]);
                if ($helper) {
                    Run::create([
                        'droid_id' => $helper->id,
                        'project_id' => $project->id,
                        'kind' => 'answer',
                        'topic' => "answer:{$project->slug}:{$task->number}",
                        'input' => $question,
                        'status' => 'queued',
                    ]);
                }
            }
        }
        // Cards the droid passed over are looked at again only when they change.
        foreach ($candidates as $task) {
            $task->update(['triaged_at' => now()]);
        }
    }

    /**
     * The crew droid a triage question goes to, or null when it is for the person:
     * no droid has that role, or a droid was already asked about this card.
     */
    private function helperFor(Project $project, Task $task, string $role): ?Droid
    {
        if (strtolower($role) === 'person' || Run::where('topic', "answer:{$project->slug}:{$task->number}")->exists()) {
            return null;
        }

        return Droid::where('project_id', $project->id)->where('role', ucfirst(strtolower($role)))
            ->whereNotIn('role', ['HR', 'Triager', 'Mechanic'])->orderBy('unit')->first();
    }

    /** @return list<string> */
    private function answerContext(Run $run): array
    {
        $project = $run->project;
        $task = $project?->tasks()->where('number', (int) Str::afterLast((string) $run->topic, ':'))->first();
        if (! $project || ! $task) {
            return ['A triage droid asked you about a card that is gone. Say so in "message".'];
        }
        $body = $project->isLinked() ? rescue(fn () => $this->github->issueBody($project, $task->number), '', false) : '';

        return [
            "The droid triaging the Ideas column of {$project->name} ({$project->repo}) asks you about a card before a builder takes it.",
            '',
            "#{$task->number} {$task->title}".($task->labels ? ' ['.implode(', ', $task->labels).']' : ''),
            $body !== '' ? Str::limit($body, 3000) : '(no description)',
            ...$this->milestoneLines($this->activeMilestone($project)),
            '',
            'Answer the question as the '.$run->droid->role.', from the code, the design, the plan and the discussion. Be concrete: name the choice, so a builder can follow it.',
            'If only the person can decide (business rules, pricing, taste, priorities between goals), start "message" with "Only you can decide:" and say what they need to choose between.',
            "Start \"message\" with \"On #{$task->number}:\". It is posted in the project discussion.",
        ];
    }

    /**
     * Keep a droid's answer on the card, and send the card back to triage.
     *
     * @param  array<mixed>  $data
     */
    private function answered(Run $run, array $data, ?string $text): void
    {
        $text = $text ?? 'I could not answer this one.';
        $number = (int) Str::afterLast((string) $run->topic, ':');
        $task = $run->project?->tasks()->where('number', $number)->first();
        if (($memory = $this->memoryProject($run)) !== null) {
            $this->memory->remember($memory, $run->droid, Memory::facts($data['remember'] ?? null));
        }
        $this->say($run, 'agent', array_filter([
            'text' => Str::limit($text, 6000),
            'reasoning' => is_string($data['reasoning'] ?? null) ? Str::limit($data['reasoning'], 1000) : null,
        ]));
        if ($task && $task->column === 'backlog') {
            // Triaged again on the next autopilot tick, with this answer.
            $task->update(['answer' => Str::limit($text, 4000), 'triaged_at' => null]);
        }
    }

    /**
     * The priority the triage droid gave a card, else the one it had.
     *
     * @param  array<mixed>  $item
     */
    private function priorityFor(Task $task, array $item): ?int
    {
        return Task::priorityValue($item['priority'] ?? null) ?? $task->priority;
    }

    /** The failed task run a Mechanic's run looks at, from its topic "unblock:{project}:{run}". */
    private static function unblockTarget(Run $run): ?Run
    {
        $id = (int) Str::afterLast((string) $run->topic, ':');

        return Run::with(['droid', 'task.project'])->find($id);
    }

    /** @return list<string> */
    private function unblockContext(Run $run): array
    {
        $failed = self::unblockTarget($run);
        if (! $failed || ! $failed->task) {
            return ['You are the Mechanic, but the blocked run is gone. Say so in "message" and use "ask".'];
        }
        $d = $failed->droid;
        $models = EngineModel::labels()[$d->provider] ?? [];
        $log = Str::of((string) $failed->log)->trim()->substr(-4000)->toString();

        return [
            "You are the Mechanic of {$failed->task->project->name}: you get droids that are blocked going again.",
            "{$d->unit} {$d->name}, a {$d->role} on {$d->provider} {$d->model}, is blocked on #{$failed->task->number} {$failed->task->title} (a {$failed->kind} run).",
            '',
            'The error:',
            Str::limit((string) $failed->error, 2000),
            '',
            'The end of its log:',
            $log !== '' ? $log : '(empty)',
            '',
            'Models '.$d->provider.' droids can use: '.implode(', ', $models).'.',
            '',
            'Decide what gets it going again:',
            '- "retry": the failure looks temporary (network, timeout, a worker that stopped, a flaky service). The run starts again.',
            '- "model": its model is not supported or not available. Put the best replacement from the list above in "model"; the droid switches to it and starts again.',
            '- "ask": only the person can fix it (a missing secret or permission, an unclear task, the same code failure again). Say exactly what they need to do in "message".',
            'In "message", tell the person in two or three sentences what went wrong and what you did, naming the droid and the issue.',
        ];
    }

    /**
     * Apply the Mechanic's decision: run again, switch the model and run again, or leave it to the person.
     *
     * @param  array<mixed>  $data
     */
    private function unblocked(Run $run, array $data): void
    {
        $failed = self::unblockTarget($run);
        $action = is_string($data['action'] ?? null) ? $data['action'] : 'ask';
        $text = is_string($data['message'] ?? null) ? Str::limit($data['message'], 2000) : 'I looked at the blocked droid but could not tell what to do.';
        $d = $failed?->droid;
        // Only while the droid is still blocked on that same run.
        $still = $failed && $d && RunQueue::lastFailure($d)?->is($failed);
        if ($still && $action === 'model') {
            $model = is_string($data['model'] ?? null) ? $data['model'] : null;
            if ($model && in_array($model, EngineModel::labels()[$d->provider] ?? [], true) && $model !== $d->model) {
                $d->update(['model' => $model]);
                Activity::create(['droid_id' => $d->id, 'text' => "switched to {$model}, by {$run->droid->name}"]);
            } else {
                $action = 'ask';
            }
        }
        if ($still && in_array($action, ['retry', 'model'], true)) {
            $retried = app(RunQueue::class)->retry($failed);
            if ($retried) {
                Activity::create(['droid_id' => $run->droid_id, 'text' => "got {$d->unit} {$d->name} going again on #{$failed->task?->number}"]);
            }
        }
        $this->say($run, 'agent', array_filter([
            'text' => $text,
            'reasoning' => is_string($data['reasoning'] ?? null) ? Str::limit($data['reasoning'], 1000) : null,
        ]));
    }

    /** The form a suggest run fills, from its topic "suggest:{project}:{form}". */
    private static function suggestForm(Run $run): ?string
    {
        $form = Str::afterLast((string) $run->topic, ':');

        return isset(self::SUGGEST_FORMS[$form]) ? $form : null;
    }

    /** @return list<string> */
    private function suggestContext(Run $run): array
    {
        $form = self::suggestForm($run);
        $project = $run->project;
        if (! $form || ! $project) {
            return ['You are filling in a form, but it is not known which one.'];
        }
        $fields = [];
        foreach (self::SUGGEST_FORMS[$form]['fields'] as $key => $meaning) {
            $fields[] = "- \"{$key}\": {$meaning}";
        }

        $title = self::SUGGEST_FORMS[$form]['title'];

        return [
            "You are a creative droid prefilling {$title} for {$project->name} ({$project->repo})".($project->domain ? ", whose domain is {$project->domain}" : '').'. The person reviews and edits what you suggest before saving it.',
            'Learn what the product is from the code, its README and the context below, then suggest values that fit its voice. Prefer short, plain, friendly choices.',
            '',
            'The fields:',
            ...$fields,
        ];
    }

    private function suggestShape(Run $run): string
    {
        $form = self::suggestForm($run);
        $fields = implode(', ', array_map(fn (string $k) => "\"{$k}\": \"...\"", array_keys($form ? self::SUGGEST_FORMS[$form]['fields'] : [])));

        return '{"message": "...", "reasoning": "...", "fields": {'.$fields.'}}';
    }

    /**
     * Keep the fields the form has, as a suggestion item the form fills itself from.
     *
     * @param  array<mixed>  $data
     */
    private function suggested(Run $run, array $data): void
    {
        $form = self::suggestForm($run);
        $fields = [];
        foreach ($form ? array_keys(self::SUGGEST_FORMS[$form]['fields']) : [] as $key) {
            $v = $data['fields'][$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $fields[$key] = Str::limit(trim($v), 200, '');
            }
        }
        if (isset($fields['from_local'])) {
            // Only the part before the @, in the characters an address allows.
            $local = preg_replace('/[^a-z0-9._+-]/', '', strtolower(Str::before($fields['from_local'], '@')));
            $fields['from_local'] = $local ?: null;
            $fields = array_filter($fields);
        }
        $this->say($run, 'suggestion', array_filter([
            'form' => $form,
            'fields' => $fields,
            'text' => $fields ? (is_string($data['message'] ?? null) ? Str::limit($data['message'], 600) : null) : 'I could not come up with a suggestion. Try again.',
        ]));
    }

    /** @return list<string> */
    private function cloudLines(Project $project): array
    {
        if (! $project->cloud_application_id) {
            return [];
        }
        $envs = implode(', ', array_map(fn (array $e) => (string) $e['name'], $project->cloud_snapshot['envs'] ?? []));

        return [
            "The project runs on Laravel Cloud (environments: {$envs}). When the person wants environment variables set or changed there, put them in \"variables\", with the environment by name, or null for the first one. They appear as a card the person reviews and applies, and the app needs a deploy to pick them up.",
            'Never put a secret you were not given in "value": leave it empty for the person to fill in.',
        ];
    }

    /** @return list<string> */
    private function openMilestones(Project $project): array
    {
        $names = $project->milestones()->where('state', '!=', 'done')->orderBy('position')->pluck('name')->all();

        return $names ? ['Open milestones: '.implode('; ', $names).'.'] : [];
    }

    /**
     * The latest of the project's discussion, so planning picks up what was agreed there.
     *
     * @return list<string>
     */
    private function discussionLines(Project $project): array
    {
        $messages = ChatMessage::where('topic', "discuss:{$project->slug}")
            ->whereIn('kind', ['user', 'agent'])
            ->latest('id')->limit(self::HISTORY)->get()->reverse();

        $lines = [];
        foreach ($messages as $m) {
            $lines[] = ($m->kind === 'user' ? 'Person: ' : 'Droid: ').Str::limit((string) ($m->payload['text'] ?? ''), 1500);
        }

        return $lines ? ['', 'The latest of the project discussion, which this planning follows on from:', ...$lines] : [];
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
