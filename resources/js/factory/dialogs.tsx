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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CheckIcon } from 'lucide-react';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSubmit,
    PromptInputTextarea,
    PromptInputTools,
} from '@/components/ai-elements/prompt-input';
import { Shimmer } from '@/components/ai-elements/shimmer';
import { PROVIDERS, type Candidate, type Provider } from '@/factory/data';
import { request, useSave } from '@/factory/api';
import type { Store } from '@/factory/app';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

export type NewDroid = {
    name: string;
    provider: Provider;
    model: string;
    role: string;
    autonomy: string;
    instructions: string;
    seed: number;
};

export function AssembleDialog({
    open,
    onOpenChange,
    onCreate,
    nextSeed,
    nextUnit,
    defaultRole,
    store,
}: {
    store: Store;
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onCreate: (d: NewDroid) => Promise<boolean>;
    nextSeed: number;
    nextUnit: (p: Provider) => string;
    /** Ask for a droid of this role straight away, e.g. a designer. */
    defaultRole?: string;
}) {
    const save = useSave();
    const [picked, setPicked] = useState<Candidate | null>(null);
    const [seed, setSeed] = useState(nextSeed);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [busy, setBusy] = useState(false);

    const ask = store.asks?.recruit;
    const chat = store.chats.recruit ?? [];
    const thinking = sending || !!ask;
    const hr = store.droids.find((d) => d.role === 'HR');
    // Every batch the HR droid proposed, newest first, then the starters.
    const batches = chat
        .filter((i) => i.kind === 'candidates')
        .reverse()
        .flatMap((i) => (i.kind === 'candidates' ? i.items : []));
    const candidates = [
        ...batches,
        ...STARTERS.filter(
            (s) =>
                !batches.some((b) => b.name === s.name) &&
                (s.role !== 'HR' || !store.droids.some((d) => d.role === 'HR')),
        ),
    ];
    const lastAsk = chat.map((i) => i.kind).lastIndexOf('user');
    const answer = lastAsk >= 0 ? chat.slice(lastAsk + 1) : [];
    const note = answer.find((i) => i.kind === 'agent');
    const reply = !ask && note?.kind === 'agent' ? note.text : null;

    const request = async (msg: string) => {
        const t = msg.trim();
        if (!t || thinking) return;
        setText('');
        setSending(true);
        // No HR droid yet: hire the HR starter first, then ask her.
        let id = hr?.id;
        if (!id) {
            const mira = STARTERS[0];
            const made = await save('POST', '/factory/droids', {
                name: mira.name,
                provider: mira.provider,
                model: mira.model,
                role: mira.role,
                autonomy: 'pr',
                instructions: mira.instructions,
                seed: nextSeed,
            });
            id = made ? (made.droid as string) : undefined;
        }
        const res =
            id &&
            (await save(
                'POST',
                `/factory/droids/${encodeURIComponent(id)}/ask`,
                { kind: 'recruit', text: t },
            ));
        if (!res) setText(t);
        setSending(false);
    };

    useEffect(() => {
        if (!open) return;
        setPicked(null);
        setSeed(nextSeed);
        setText(
            defaultRole && defaultRole !== 'Builder'
                ? `I need a ${defaultRole.toLowerCase()} droid.`
                : '',
        );
    }, [open]); // eslint-disable-line

    const assemble = async () => {
        if (!picked) return;
        setBusy(true);
        const ok = await onCreate({
            name: picked.name,
            provider: picked.provider,
            model: picked.model,
            role: picked.role,
            autonomy: 'pr',
            instructions: picked.instructions,
            seed,
        });
        setBusy(false);
        if (ok) onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[calc(100%-2rem)] flex-col gap-4 sm:max-w-[720px]">
                <DialogHeader>
                    <DialogTitle>Assemble a droid</DialogTitle>
                    <DialogDescription>
                        {hr
                            ? `Tell ${hr.unit} ${hr.name} what you need and pick from the candidates. Droids join a project when you give them a task.`
                            : 'Describe the droid you need and an HR droid proposes candidates, or pick a starter. Droids join a project when you give them a task.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="-mx-1 grid min-h-0 flex-1 gap-2 overflow-y-auto px-1 sm:grid-cols-2">
                    {candidates.map((c, n) => {
                        const on =
                            picked?.name === c.name && picked.role === c.role;
                        const look = on ? seed : nextSeed + n;
                        return (
                            <button
                                type="button"
                                key={`${c.name}-${c.role}-${n}`}
                                onClick={() => {
                                    setPicked(c);
                                    setSeed(nextSeed + n);
                                }}
                                aria-pressed={on}
                                className={cn(
                                    'grid content-start gap-1.5 rounded-lg border p-3 text-left text-sm hover:bg-accent',
                                    on && 'border-ring bg-accent',
                                )}
                            >
                                <span className="flex items-center gap-2.5">
                                    <DroidAvatar
                                        provider={c.provider}
                                        status="idle"
                                        seed={look}
                                        className="size-9"
                                    />
                                    <span className="min-w-0 leading-tight">
                                        <span className="block font-medium">
                                            {c.name}
                                            {on && (
                                                <span className="ml-1.5 font-mono text-xs font-normal text-muted-foreground">
                                                    {nextUnit(c.provider)}
                                                </span>
                                            )}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {c.role} ·{' '}
                                            {PROVIDERS[c.provider].short}{' '}
                                            {c.model}
                                        </span>
                                    </span>
                                    {on && (
                                        <CheckIcon className="ml-auto size-4 shrink-0" />
                                    )}
                                </span>
                                <span>{c.pitch}</span>
                                {c.why && (
                                    <span className="text-xs text-muted-foreground">
                                        {c.why}
                                    </span>
                                )}
                                {on && c.instructions && (
                                    <span className="rounded-md bg-background p-2 text-xs">
                                        <span className="font-medium">
                                            Instructions:{' '}
                                        </span>
                                        {c.instructions}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {thinking ? (
                    <Shimmer className="text-sm">
                        {ask?.status === 'running'
                            ? `${hr?.unit} ${hr?.name} is looking for candidates…`
                            : store.workers?.online
                              ? 'Waiting for your worker to pick this up…'
                              : 'No worker is online. Start php artisan droids:work to get candidates…'}
                    </Shimmer>
                ) : (
                    reply && (
                        <p className="text-sm text-muted-foreground">{reply}</p>
                    )
                )}

                <PromptInput onSubmit={(m) => void request(m.text)}>
                    <PromptInputBody>
                        <PromptInputTextarea
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            placeholder="Describe the droid you need, e.g. a reviewer who knows Laravel security…"
                            className="min-h-12"
                        />
                    </PromptInputBody>
                    <PromptInputFooter>
                        <PromptInputTools>
                            <span className="px-2 text-xs text-muted-foreground">
                                {hr
                                    ? `${hr.unit} ${hr.name} (HR) proposes new candidates`
                                    : 'Mira joins as your HR droid and proposes candidates'}
                            </span>
                        </PromptInputTools>
                        <PromptInputSubmit
                            status={thinking ? 'submitted' : 'ready'}
                            disabled={thinking || !text.trim()}
                        />
                    </PromptInputFooter>
                </PromptInput>

                <DialogFooter className="items-center">
                    {picked && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="mr-auto"
                            onClick={() => setSeed((x) => x + 1)}
                        >
                            New look
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={!picked || busy}
                        onClick={() => void assemble()}
                    >
                        {picked
                            ? `Assemble ${picked.name}`
                            : 'Pick a candidate'}
                    </Button>
                </DialogFooter>
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

/** Ready-made droids, always offered next to the HR droid's candidates. */
const STARTERS: Candidate[] = [
    {
        name: 'Mira',
        role: 'HR',
        provider: 'claude',
        model: 'Sonnet 5.5',
        pitch: "I'm Mira. Tell me what you need and I'll find you the right droids, with a pitch for each.",
        why: 'An HR droid proposes candidates from your own words, so hire one first.',
        instructions:
            'Fill real gaps in the crew. Give each candidate a distinct name and a clear job. Prefer fewer, sharper droids.',
    },
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
        why: 'A planner keeps the boards stocked.',
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
