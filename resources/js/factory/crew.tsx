import { useState } from 'react';
import {
    CheckIcon,
    MinusIcon,
    PlusIcon,
    SearchCheckIcon,
    SparklesIcon,
    UsersIcon,
    XIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';
import { PROVIDERS, ROLES, STATUS, type Droid } from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

const NONE = '__none';

/** Move a droid onto a project, or off every project. */
export function useMoveDroid(store: Store) {
    const save = useSave();
    return async (d: Droid, project: string | null) => {
        const res = await save(
            'PATCH',
            `/factory/droids/${encodeURIComponent(d.id)}`,
            { project },
        );
        if (!res) return false;
        const name = project
            ? store.projects.find((p) => p.id === project)?.name
            : null;
        toast.success(
            name
                ? `${d.unit} ${d.name} joined ${name}`
                : `${d.unit} ${d.name} left its project`,
        );
        return true;
    };
}

/** The project picker on a droid's row in the Droids table. */
export function DroidProjectSelect({ store, d }: { store: Store; d: Droid }) {
    const move = useMoveDroid(store);
    const [busy, setBusy] = useState(false);
    return (
        <Select
            value={d.project ?? NONE}
            disabled={busy || !!d.task}
            onValueChange={async (v) => {
                setBusy(true);
                await move(d, v === NONE ? null : v);
                setBusy(false);
            }}
        >
            <SelectTrigger
                size="sm"
                className="h-8 w-44 text-xs"
                aria-label={`Project of ${d.unit} ${d.name}`}
                title={d.task ? 'Unassign its issue to move it' : undefined}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>
                    <span className="text-muted-foreground">No project</span>
                </SelectItem>
                {store.projects.map((p) => (
                    <SelectItem key={p.id} value={p.id}>
                        <span
                            className="size-2 rounded-[2px]"
                            style={{ background: p.color }}
                        />
                        {p.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Ask HR for a droid of a role, with a prompt the person can add to. */
export type AskHr = (role: string, prompt: string, project: string) => void;

type Need = {
    role: string;
    duty: string;
    droids: Droid[];
    /** Helps, on a small model, but the crew runs without it. */
    optional?: boolean;
};

/** The roles a project's crew needs, with the crew droids that fill each. */
export function crewNeeds(store: Store, pid: string): Need[] {
    const crew = store.droids.filter((d) => d.project === pid);
    const roles: [string, string][] = [
        [
            'Planner',
            "it plans tasks with me, triages the Ideas column and sets each card's priority",
        ],
        [
            'Builder',
            'it takes cards from Ready for droids, highest priority first, and opens a pull request for each',
        ],
        [
            'Reviewer',
            "it reviews the builders' pull requests and asks for fixes before they merge",
        ],
    ];
    if (store.design?.[pid]?.length)
        roles.push([
            'Designer',
            'it keeps the design files up to date as the project changes',
        ]);
    const light: [string, string][] = [
        [
            'Triager',
            'it triages the Ideas column, writes acceptance criteria and sets priorities, so the planner can focus on planning',
        ],
        [
            'Fixer',
            'it takes small Ready cards labelled bug or chore, so builders keep the bigger work',
        ],
        [
            'Mechanic',
            'it looks at droids that get blocked, retries them or switches a model that is not available, and tells me when only I can fix it',
        ],
        [
            'Copywriter',
            'it suggests names, sender addresses and wording when I ask it to prefill a form',
        ],
    ];
    return [
        ...roles.map(([role, duty]) => ({ role, duty, optional: false })),
        ...light.map(([role, duty]) => ({ role, duty, optional: true })),
    ].map((n) => ({ ...n, droids: crew.filter((d) => d.role === n.role) }));
}

/** The prompt for HR about a missing droid, for the person to add to. */
export function hrPrompt(store: Store, pid: string, need: Need): string {
    const p = store.projects.find((x) => x.id === pid);
    const where = p ? ` for ${p.name} (${p.repo})` : '';
    let text = `I need a ${need.role.toLowerCase()} droid${where}: ${need.duty}.`;
    const fits = ROLES.find((r) => r.role === need.role);
    if (fits?.light) text += ` It should run on a small model (${fits.model}).`;
    if (need.role === 'Reviewer') {
        const engines = new Set(
            store.droids
                .filter((d) => d.project === pid && d.role === 'Builder')
                .map((d) => d.provider),
        );
        if (engines.size === 1) {
            const [e] = [...engines];
            const other = e === 'claude' ? 'codex' : 'claude';
            text += ` The builders run on ${PROVIDERS[e].label}, so a ${PROVIDERS[other].label} reviewer would give a second opinion.`;
        }
    }
    return `${text} `;
}

/** The droids on a project, with a picker to bring in more. */
export function CrewCard({
    store,
    pid,
    openChat,
    askHr,
}: {
    store: Store;
    pid: string;
    openChat?: (id: string) => void;
    askHr?: AskHr;
}) {
    const move = useMoveDroid(store);
    const [busy, setBusy] = useState('');
    const [checking, setChecking] = useState(false);
    const crew = store.droids.filter((d) => d.project === pid);
    const others = store.droids.filter((d) => d.project !== pid);
    const run = async (d: Droid, project: string | null) => {
        setBusy(d.id);
        await move(d, project);
        setBusy('');
    };
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="flex flex-row flex-wrap items-center gap-3 px-4">
                <UsersIcon className="size-5 text-muted-foreground" />
                <div className="mr-auto">
                    <CardTitle className="text-base">Crew</CardTitle>
                    <CardDescription>
                        {crew.length
                            ? `${crew.length} droid${crew.length === 1 ? '' : 's'} on this project`
                            : 'No droid on this project yet'}
                    </CardDescription>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    aria-pressed={checking}
                    onClick={() => setChecking((c) => !c)}
                >
                    <SearchCheckIcon />
                    Find required droids
                </Button>
                {others.length > 0 && (
                    <Select
                        value=""
                        disabled={!!busy}
                        onValueChange={(id) => {
                            const d = others.find((x) => x.id === id);
                            if (d) void run(d, pid);
                        }}
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-auto"
                            aria-label="Add a droid to this project"
                        >
                            <PlusIcon />
                            <span>Add a droid</span>
                        </SelectTrigger>
                        <SelectContent align="end">
                            {others.map((d) => (
                                <SelectItem
                                    key={d.id}
                                    value={d.id}
                                    disabled={!!d.task}
                                >
                                    <span className="font-mono text-xs">
                                        {d.unit}
                                    </span>
                                    {d.name}
                                    <span className="text-xs text-muted-foreground">
                                        {d.role}
                                        {d.project
                                            ? ` · on ${store.projects.find((p) => p.id === d.project)?.name ?? d.project}`
                                            : ''}
                                        {d.task ? ' · busy' : ''}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </CardHeader>
            {crew.length > 0 && (
                <CardContent className="flex flex-wrap gap-2 px-4">
                    {crew.map((d) => (
                        <div
                            key={d.id}
                            className="flex items-center gap-2 rounded-lg border py-1.5 pr-1 pl-2"
                        >
                            <button
                                type="button"
                                className="flex items-center gap-2 text-left"
                                onClick={() => openChat?.(d.id)}
                            >
                                <DroidAvatar
                                    provider={d.provider}
                                    status={d.status}
                                    seed={d.seed}
                                    className="size-7"
                                />
                                <span className="leading-tight">
                                    <span className="block text-sm font-medium">
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {d.unit}
                                        </span>{' '}
                                        {d.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {d.role}
                                    </span>
                                </span>
                            </button>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'rounded-full',
                                    STATUS[d.status].cls,
                                )}
                            >
                                {STATUS[d.status].label}
                            </Badge>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                aria-label={`Take ${d.unit} ${d.name} off this project`}
                                title={
                                    d.task
                                        ? 'Unassign its issue first'
                                        : undefined
                                }
                                disabled={!!busy || !!d.task}
                                onClick={() => void run(d, null)}
                            >
                                <XIcon />
                            </Button>
                        </div>
                    ))}
                </CardContent>
            )}
            {checking && (
                <CardContent className="border-t px-4 pt-3">
                    <CrewCheck
                        store={store}
                        pid={pid}
                        busy={!!busy}
                        add={(d) => void run(d, pid)}
                        askHr={askHr}
                    />
                </CardContent>
            )}
            <CardContent className="grid gap-2 border-t px-4 pt-3">
                <Autopilot store={store} pid={pid} crew={crew} />
            </CardContent>
        </Card>
    );
}

/** Each role the crew needs: who fills it, or a way to get one. */
function CrewCheck({
    store,
    pid,
    busy,
    add,
    askHr,
}: {
    store: Store;
    pid: string;
    busy: boolean;
    add: (d: Droid) => void;
    askHr?: AskHr;
}) {
    const needs = crewNeeds(store, pid);
    const missing = needs.filter((n) => !n.optional && !n.droids.length).length;
    return (
        <div className="grid gap-2 text-sm">
            <p className="text-muted-foreground">
                {missing
                    ? `${missing} role${missing === 1 ? ' is' : 's are'} missing for droids to run this project on their own.`
                    : 'The crew has every role it needs.'}
            </p>
            {needs.map((n) => {
                // A free droid of this role can join without asking HR.
                const free = store.droids.find(
                    (d) => d.role === n.role && !d.project && !d.task,
                );
                return (
                    <div
                        key={n.role}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {n.droids.length ? (
                            <CheckIcon className="size-4 text-working" />
                        ) : n.optional ? (
                            <MinusIcon className="size-4 text-muted-foreground" />
                        ) : (
                            <XIcon className="size-4 text-needs" />
                        )}
                        <span className="w-24 font-medium">{n.role}</span>
                        {n.optional && !n.droids.length && (
                            <span className="text-xs text-muted-foreground">
                                Optional, small model
                            </span>
                        )}
                        {n.droids.length ? (
                            <span className="text-muted-foreground">
                                {n.droids
                                    .map((d) => `${d.unit} ${d.name}`)
                                    .join(', ')}
                            </span>
                        ) : (
                            <>
                                {askHr && (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            askHr(
                                                n.role,
                                                hrPrompt(store, pid, n),
                                                pid,
                                            )
                                        }
                                    >
                                        <SparklesIcon />
                                        Find a {n.role.toLowerCase()}
                                    </Button>
                                )}
                                {free && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={busy}
                                        onClick={() => add(free)}
                                    >
                                        <PlusIcon />
                                        Add {free.unit} {free.name}
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** Per project: the Planner triages Ideas, and idle builders take Ready cards. */
function Autopilot({
    store,
    pid,
    crew,
}: {
    store: Store;
    pid: string;
    crew: Droid[];
}) {
    const save = useSave();
    const p = store.projects.find((x) => x.id === pid);
    if (!p) return null;
    const planner = crew.find((d) => d.role === 'Planner') ?? crew[0];
    const builders = crew.filter((d) => d.role === 'Builder').length;
    const toggle = (key: 'auto_triage' | 'auto_build', v: boolean) =>
        void save('PATCH', `/factory/projects/${encodeURIComponent(pid)}`, {
            [key]: v,
        });
    return (
        <>
            <label className="flex items-start gap-3 text-sm">
                <Switch
                    className="mt-0.5"
                    checked={!!p.autoTriage}
                    onCheckedChange={(v) => toggle('auto_triage', v)}
                />
                <span>
                    Triage Ideas
                    <span className="block text-xs text-muted-foreground">
                        {planner
                            ? `${planner.unit} ${planner.name} reads each new card, adds acceptance criteria and moves it to Ready for droids, or asks you in the project discussion.`
                            : 'Add a Planner droid to the crew to triage new cards.'}
                    </span>
                </span>
            </label>
            <label className="flex items-start gap-3 text-sm">
                <Switch
                    className="mt-0.5"
                    checked={!!p.autoBuild}
                    onCheckedChange={(v) => toggle('auto_build', v)}
                />
                <span>
                    Builders take Ready cards
                    <span className="block text-xs text-muted-foreground">
                        {builders
                            ? `An idle builder on the crew starts the next card in Ready for droids, and an idle reviewer checks its pull request.`
                            : 'Add a Builder droid to the crew to start cards on their own.'}
                    </span>
                </span>
            </label>
        </>
    );
}
