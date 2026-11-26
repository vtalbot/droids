import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CheckIcon, SparklesIcon, UsersIcon } from 'lucide-react';
import { Shimmer } from '@/components/ai-elements/shimmer';
import {
    PROVIDERS,
    ROLES,
    type Candidate,
    type Project,
    type Provider,
} from '@/factory/data';
import { request, useSave } from '@/factory/api';
import type { Store } from '@/factory/app';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

export type NewDroid = {
    name: string;
    provider: Provider;
    model: string;
    role: string;
    project: string;
    autonomy: string;
    instructions: string;
    seed: number;
};

const NAMES = [
    'Bolt',
    'Cog',
    'Dyna',
    'Echo',
    'Hex',
    'Ion',
    'Jolt',
    'Kilo',
    'Nova',
    'Orbit',
    'Piston',
    'Volt',
];

export function AssembleDialog({
    open,
    onOpenChange,
    projects,
    defaultProject,
    onCreate,
    nextSeed,
    nextUnit,
    defaultRole = 'Builder',
    store,
}: {
    store: Store;
    open: boolean;
    onOpenChange: (o: boolean) => void;
    projects: Project[];
    defaultProject: string;
    onCreate: (d: NewDroid) => Promise<boolean>;
    nextSeed: number;
    nextUnit: (p: Provider) => string;
    defaultRole?: string;
}) {
    const [provider, setProvider] = useState<Provider>('claude');
    const [model, setModel] = useState('Sonnet 5.5');
    const [name, setName] = useState('Bolt');
    const [role, setRole] = useState('Builder');
    const [project, setProject] = useState(defaultProject);
    const [auto, setAuto] = useState('pr');
    const [seed, setSeed] = useState(nextSeed);
    const [instructions, setInstructions] = useState(
        'Write tests first. Keep PRs under 400 lines. Ask before adding a dependency.',
    );
    const [busy, setBusy] = useState(false);
    const [picked, setPicked] = useState<string | null>(null);
    const pick = (c: Candidate, look: number) => {
        setPicked(`${c.name}:${c.role}`);
        setSeed(look);
        setName(c.name);
        setRole(c.role);
        setProvider(c.provider);
        setModel(c.model);
        if (c.instructions) setInstructions(c.instructions);
    };
    useEffect(() => {
        if (open) {
            setPicked(null);
            setProject(defaultProject);
            setSeed(nextSeed);
            setName(NAMES[nextSeed % NAMES.length]);
            setRole(defaultRole);
            if (defaultRole === 'Designer') {
                setProvider('claude');
                setModel('Opus 5.5');
                setInstructions(
                    'Start from what the product already looks like. Keep the system small: a few colors, one type scale, the components the screens need.',
                );
            }
        }
    }, [open]); // eslint-disable-line
    const unit = nextUnit(provider);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100%-2rem)] overflow-y-auto sm:max-w-[600px]">
                <form
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        const ok = await onCreate({
                            name: name.trim() || 'Droid',
                            provider,
                            model,
                            role,
                            project,
                            autonomy: auto,
                            instructions,
                            seed,
                        });
                        setBusy(false);
                        if (ok) onOpenChange(false);
                    }}
                    className="grid gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Assemble a droid</DialogTitle>
                        <DialogDescription>
                            Pick an engine, give it a job and a project. Droids
                            work on their own branch and open pull requests.
                        </DialogDescription>
                    </DialogHeader>

                    {project && (
                        <CrewSuggestions
                            store={store}
                            project={project}
                            picked={picked}
                            onPick={pick}
                        />
                    )}

                    <div className="blueprint flex items-center gap-4 rounded-xl border bg-muted/40 p-3">
                        <DroidAvatar
                            provider={provider}
                            status="idle"
                            seed={seed}
                            className="size-16"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="flex items-baseline gap-2">
                                <span className="font-mono text-sm">
                                    {unit}
                                </span>
                                <span className="truncate text-lg font-semibold">
                                    {name || 'Droid'}
                                </span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {PROVIDERS[provider].label} · {model} · {role}
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setSeed((s) => s + 1)}
                        >
                            New look
                        </Button>
                    </div>

                    <div className="grid gap-2">
                        <Label>Engine</Label>
                        <RadioGroup
                            value={provider}
                            onValueChange={(v) => {
                                const p = v as Provider;
                                setProvider(p);
                                setModel(
                                    PROVIDERS[p].models[p === 'claude' ? 1 : 0],
                                );
                            }}
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                        >
                            {(['claude', 'codex'] as Provider[]).map((p) => (
                                <Label
                                    key={p}
                                    htmlFor={`eng-${p}`}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 font-normal',
                                        provider === p &&
                                            'border-ring bg-accent',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={`eng-${p}`}
                                        value={p}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="flex items-center gap-2 font-medium">
                                            <span
                                                className={cn(
                                                    'size-2 rounded-full',
                                                    p === 'claude'
                                                        ? 'bg-claude'
                                                        : 'bg-codex',
                                                )}
                                            />
                                            {PROVIDERS[p].label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {p === 'claude'
                                                ? 'Strong on planning, reviews and long refactors'
                                                : 'Fast at scoped implementation and test fixes'}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </RadioGroup>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="d-name">Name</Label>
                            <Input
                                id="d-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                maxLength={20}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-model">Model</Label>
                            <Select value={model} onValueChange={setModel}>
                                <SelectTrigger id="d-model" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PROVIDERS[provider].models.map((m) => (
                                        <SelectItem key={m} value={m}>
                                            {m}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-role">Role</Label>
                            <Select value={role} onValueChange={setRole}>
                                <SelectTrigger id="d-role" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {ROLES.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {r}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-proj">Project</Label>
                            <Select value={project} onValueChange={setProject}>
                                <SelectTrigger id="d-proj" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {projects.map((p) => (
                                        <SelectItem key={p.id} value={p.id}>
                                            {p.name} · {p.repo}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Autonomy</Label>
                        <RadioGroup
                            value={auto}
                            onValueChange={setAuto}
                            className="grid gap-2 sm:grid-cols-3"
                        >
                            {[
                                [
                                    'ask',
                                    'Ask first',
                                    'Checks in before each PR',
                                ],
                                ['pr', 'Open PRs', 'You review and merge'],
                                [
                                    'merge',
                                    'Merge when green',
                                    'Only with passing checks and a review',
                                ],
                            ].map(([v, l, s]) => (
                                <Label
                                    key={v}
                                    htmlFor={`au-${v}`}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 font-normal',
                                        auto === v && 'border-ring bg-accent',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={`au-${v}`}
                                        value={v}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">{l}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {s}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </RadioGroup>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="d-ins">Instructions</Label>
                        <Textarea
                            id="d-ins"
                            value={instructions}
                            onChange={(e) => setInstructions(e.target.value)}
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy}>
                            Assemble {unit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type InstalledRepo = { repo: string; private: boolean; installation: number };

export function ConnectDialog({
    open,
    onOpenChange,
    onConnect,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onConnect: (repo: string, installation: number | null) => Promise<boolean>;
}) {
    const [repo, setRepo] = useState('');
    const [busy, setBusy] = useState(false);
    const [repos, setRepos] = useState<InstalledRepo[] | null>(null);
    const [configured, setConfigured] = useState(true);
    const [loadError, setLoadError] = useState('');

    useEffect(() => {
        if (!open) return;
        let live = true;
        setRepos(null);
        setLoadError('');
        request('GET', '/factory/github/repos')
            .then((res) => {
                if (!live) return;
                const data = res as unknown as {
                    configured: boolean;
                    repos: InstalledRepo[];
                };
                setConfigured(data.configured);
                setRepos(data.repos);
            })
            .catch((e: Error) => {
                if (!live) return;
                setLoadError(e.message);
                setRepos([]);
            });
        return () => {
            live = false;
        };
    }, [open]);

    const picked = repos?.find((r) => r.repo === repo);
    const manual = !configured || (repos !== null && repos.length === 0);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[480px]">
                <form
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        const ok = await onConnect(
                            repo.trim(),
                            picked?.installation ?? null,
                        );
                        setBusy(false);
                        if (ok) {
                            setRepo('');
                            onOpenChange(false);
                        }
                    }}
                    className="grid gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Connect a repository</DialogTitle>
                        <DialogDescription>
                            Its issues, milestones, pull requests and checks
                            become this project's board and roadmap, and stay in
                            sync.
                        </DialogDescription>
                    </DialogHeader>
                    {!configured && (
                        <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                            The GitHub App isn't set up yet.{' '}
                            <a
                                href="/settings/github"
                                className="font-medium underline"
                            >
                                Create it in Settings
                            </a>{' '}
                            to sync. You can still add the project now and link
                            it later.
                        </p>
                    )}
                    {loadError && (
                        <p className="text-xs text-destructive">{loadError}</p>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="c-repo">Repository</Label>
                        {repos === null ? (
                            <div className="h-9 animate-pulse rounded-md bg-muted" />
                        ) : manual ? (
                            <>
                                <Input
                                    id="c-repo"
                                    className="font-mono"
                                    value={repo}
                                    onChange={(e) => setRepo(e.target.value)}
                                    placeholder="owner/repo"
                                    required
                                />
                                {configured && (
                                    <p className="text-xs text-muted-foreground">
                                        No repositories left to connect.{' '}
                                        <a
                                            href="/settings/github"
                                            className="underline"
                                        >
                                            Give the App access to more
                                        </a>
                                        .
                                    </p>
                                )}
                            </>
                        ) : (
                            <Select value={repo} onValueChange={setRepo}>
                                <SelectTrigger
                                    id="c-repo"
                                    className="w-full font-mono"
                                >
                                    <SelectValue placeholder="Pick a repository" />
                                </SelectTrigger>
                                <SelectContent>
                                    {repos.map((r) => (
                                        <SelectItem
                                            key={r.repo}
                                            value={r.repo}
                                            className="font-mono"
                                        >
                                            {r.repo}
                                            {r.private && (
                                                <span className="ml-2 font-sans text-xs text-muted-foreground">
                                                    private
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !repo.trim()}>
                            {busy && picked
                                ? 'Connecting and syncing'
                                : 'Connect'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Ready-made droids for a project with no crew yet, when nobody can run a suggestion. */
const STARTERS: Candidate[] = [
    {
        name: 'Bolt',
        role: 'Builder',
        provider: 'codex',
        model: 'GPT-5.1-Codex',
        pitch: "I'm Bolt. Hand me a clear issue and I'll come back with a small, tested pull request.",
        why: 'Every project needs someone turning ready issues into code.',
        instructions:
            'Write tests first. Keep PRs under 400 lines. Ask before adding a dependency.',
    },
    {
        name: 'Lumen',
        role: 'Reviewer',
        provider: 'claude',
        model: 'Sonnet 5.5',
        pitch: "I'm Lumen. I read every pull request like I'll maintain it, and I only block on real problems.",
        why: 'A reviewer catches what the builder missed before it reaches you.',
        instructions:
            'Check the change solves the issue and has tests. Flag risky migrations and missing error handling.',
    },
    {
        name: 'Atlas',
        role: 'Planner',
        provider: 'claude',
        model: 'Opus 5.5',
        pitch: "I'm Atlas. I turn a goal into issues one droid can finish in a day, and I keep the roadmap honest.",
        why: 'A planner keeps the board stocked and can suggest the rest of the crew later.',
        instructions:
            'Draft small issues with acceptance criteria. Pick the engine for each. Never duplicate an open issue.',
    },
    {
        name: 'Iris',
        role: 'Designer',
        provider: 'claude',
        model: 'Opus 5.5',
        pitch: "I'm Iris. I write the design system and screens that the builders follow.",
        why: 'A shared design keeps every droid’s UI work consistent.',
        instructions:
            'Start from what the product already looks like. Keep the system small: a few colors, one type scale, the components the screens need.',
    },
];

/** Candidates the planner proposes for a project, or starters when there is no droid to ask. */
function CrewSuggestions({
    store,
    project,
    picked,
    onPick,
}: {
    store: Store;
    project: string;
    picked: string | null;
    onPick: (c: Candidate, seed: number) => void;
}) {
    const save = useSave();
    const [sending, setSending] = useState(false);
    const key = `recruit:${project}`;
    const ask = store.asks?.[key];
    const chat = store.chats[key] ?? [];
    const lastCards = [...chat].reverse().find((i) => i.kind === 'candidates');
    // The answer to the latest request: cards, or a message saying why there are none.
    const lastAsk = chat.map((i) => i.kind).lastIndexOf('user');
    const answer = lastAsk >= 0 ? chat.slice(lastAsk + 1) : [];
    const note = answer.find((i) => i.kind === 'agent');
    const failed =
        !ask &&
        note?.kind === 'agent' &&
        !answer.some((i) => i.kind === 'candidates')
            ? note.text
            : null;
    const recruiter =
        store.droids.find(
            (d) => d.project === project && d.role === 'Planner',
        ) ??
        store.droids.find((d) => d.role === 'Planner') ??
        store.droids.find((d) => d.project === project) ??
        store.droids[0];
    const thinking = sending || !!ask;
    const items =
        lastCards?.kind === 'candidates'
            ? lastCards.items
            : recruiter
              ? []
              : STARTERS;

    const suggest = async () => {
        if (!recruiter || thinking) return;
        setSending(true);
        await save(
            'POST',
            `/factory/droids/${encodeURIComponent(recruiter.id)}/ask`,
            {
                kind: 'recruit',
                project,
                text: 'Suggest droids for this project.',
            },
        );
        setSending(false);
    };

    return (
        <div className="grid gap-3 rounded-xl border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5 text-sm font-medium">
                        <UsersIcon className="size-4" />
                        {recruiter ? 'Suggested crew' : 'Starter crew'}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {recruiter
                            ? `${recruiter.unit} ${recruiter.name} reads the repo, the issues and the crew, then proposes droids. Pick one to fill in the form.`
                            : 'Pick one to fill in the form. Once a droid exists, it can suggest a crew from the repo.'}
                    </p>
                </div>
                {recruiter && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={thinking}
                        onClick={() => void suggest()}
                    >
                        <SparklesIcon />
                        {items.length ? 'Suggest again' : 'Suggest a crew'}
                    </Button>
                )}
            </div>
            {thinking && (
                <Shimmer className="text-sm">
                    {ask?.status === 'running'
                        ? `${recruiter?.unit} is reading the project…`
                        : store.workers?.online
                          ? 'Waiting for your worker to pick this up…'
                          : 'No worker is online. Start php artisan droids:work to get suggestions…'}
                </Shimmer>
            )}
            {!thinking && failed && (
                <p className="text-sm text-muted-foreground">{failed}</p>
            )}
            {items.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-2">
                    {items.map((c, n) => {
                        const on = picked === `${c.name}:${c.role}`;
                        return (
                            <button
                                type="button"
                                key={`${c.name}-${n}`}
                                onClick={() => onPick(c, n + 3)}
                                aria-pressed={on}
                                className={cn(
                                    'grid gap-1.5 rounded-lg border p-3 text-left text-sm hover:bg-accent',
                                    on && 'border-ring bg-accent',
                                )}
                            >
                                <span className="flex items-center gap-2">
                                    <DroidAvatar
                                        provider={c.provider}
                                        status="idle"
                                        seed={n + 3}
                                        className="size-8"
                                    />
                                    <span className="min-w-0 leading-tight">
                                        <span className="block font-medium">
                                            {c.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {c.role} ·{' '}
                                            {PROVIDERS[c.provider].short}{' '}
                                            {c.model}
                                        </span>
                                    </span>
                                    {on && (
                                        <CheckIcon className="ml-auto size-4" />
                                    )}
                                </span>
                                <span>{c.pitch}</span>
                                {c.why && (
                                    <span className="text-xs text-muted-foreground">
                                        {c.why}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
