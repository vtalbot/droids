import { useEffect, useMemo, useReducer, useRef, useState } from 'react';
import {
    ActivityIcon,
    BotIcon,
    Columns3Icon,
    LayoutGridIcon,
    MessageSquareIcon,
    PlusIcon,
    UserPlusIcon,
    WarehouseIcon,
    GitPullRequestIcon,
    SparklesIcon,
    GaugeIcon,
    NotebookPenIcon,
    FolderKanbanIcon,
    RefreshCwIcon,
} from 'lucide-react';
import { Head } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Separator } from '@/components/ui/separator';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Shimmer } from '@/components/ai-elements/shimmer';
import { cn } from '@/lib/utils';
import { GithubMark } from '@/factory/logos';
export { GithubMark };
import {
    COLS,
    PROVIDERS,
    STATUS,
    type Ask,
    type ChatItem,
    type CloudApp,
    type DesignFile,
    type ColKey,
    type Droid,
    type FeedItem,
    type Issue,
    type Milestone,
    type PR,
    type Project,
    type Provider,
    type Stats,
    type Subscription,
} from '@/factory/data';
import { NavUser } from '@/components/nav-user';
import {
    applyServerStore,
    request,
    SaveContext,
    useSave,
    type Save,
} from '@/factory/api';
import { DroidAvatar, ProviderMark } from '@/factory/droid-avatar';
import { ChatSheet, type ChatTarget } from '@/factory/chat';
import { TaskSheet, PrChip } from '@/factory/task-sheet';
import {
    SubscriptionsView,
    AddSubscriptionDialog,
    activeSub,
} from '@/factory/subscriptions';
import { EngineLogo } from '@/factory/logos';
import { ProjectView } from '@/factory/project';
import { cloudHealth } from '@/factory/cloud';
import { AssembleDialog, ConnectDialog } from '@/factory/dialogs';

export type Store = {
    droids: Droid[];
    issues: Issue[];
    projects: Project[];
    feed: FeedItem[];
    chats: Record<string, ChatItem[]>;
    prs: Record<string, PR>;
    subs: Subscription[];
    schedule: Record<Provider, string[][]>;
    paused: Record<string, boolean>;
    roadmap: Record<string, Milestone[]>;
    cloud: Record<string, CloudApp | null>;
    stats: Record<string, Stats>;
    github?: { configured: boolean };
    runs?: Record<string, Run>;
    asks?: Record<string, Ask>;
    cloudConnected?: boolean;
    design?: Record<string, DesignFile[]>;
    memory?: Record<
        string,
        { text: string; by?: string | null; updated?: string | null }
    >;
    workers?: { total: number; online: number };
};
export type Run = {
    id: number;
    kind?: 'build' | 'fix' | 'review';
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    droid: string;
    subscription: string | null;
    branch: string | null;
    pr: number | null;
    error: string | null;
    cost: number;
    started: string | null;
    log: string;
};
export type View = 'hangar' | 'projects' | 'board' | 'droids' | 'subscriptions';
const TITLES: Record<View, string> = {
    hangar: 'Hangar',
    projects: 'Projects',
    board: 'Board',
    droids: 'Droids',
    subscriptions: 'Subscriptions',
};

const ago = (m: number) =>
    m < 1
        ? 'just now'
        : m < 60
          ? `${m} min ago`
          : `${Math.round(m / 60)} h ago`;
const VIEWS: View[] = [
    'hangar',
    'projects',
    'board',
    'droids',
    'subscriptions',
];
const readUrl = (): { view: View; project: string } => {
    const v = location.pathname.split('/').filter(Boolean)[0] as View;
    return {
        view: VIEWS.includes(v) ? v : 'hangar',
        project: new URLSearchParams(location.search).get('project') || 'all',
    };
};

export default function FactoryApp({
    initialStore,
    initialView,
    initialProject,
}: {
    initialStore: Store;
    initialView: View;
    initialProject: string;
}) {
    const store = useRef<Store>(structuredClone(initialStore)).current;
    const [, bump] = useReducer((x: number) => x + 1, 0);
    const [view, setView] = useState<View>(initialView);
    const [project, setProjectState] = useState<string>(
        initialProject === 'all' ||
            store.projects.some((p) => p.id === initialProject)
            ? initialProject
            : 'all',
    );
    const [filter, setFilter] = useState<'all' | Provider>('all');
    const [chat, setChat] = useState<ChatTarget>(null);
    const [taskKey, setTaskKey] = useState<string | null>(null);
    const [addSubOpen, setAddSubOpen] = useState(false);
    const openChat = (id: string) => {
        setTaskKey(null);
        setChat({ droid: id });
    };
    const propose = (pid: string) => {
        const pl =
            store.droids.find((d) => d.role === 'Planner') ?? store.droids[0];
        setChat({ droid: pl.id, plan: pid, propose: true });
    };
    const reflect = (id: string) => setChat({ droid: id, reflect: true });
    const planWith = (pid: string) => {
        const pl =
            store.droids.find(
                (d) => d.project === pid && d.role === 'Planner',
            ) ??
            store.droids.find((d) => d.role === 'Planner') ??
            store.droids[0];
        setChat({ droid: pl.id, plan: pid });
    };
    const [assembleOpen, setAssembleOpen] = useState(false);
    const [assembleRole, setAssembleRole] = useState('Builder');
    const assemble = (role = 'Builder') => {
        setAssembleRole(role);
        setAssembleOpen(true);
    };
    const designWith = (pid: string) => {
        const ds =
            store.droids.find(
                (d) => d.project === pid && d.role === 'Designer',
            ) ?? store.droids.find((d) => d.role === 'Designer');
        if (!ds) {
            toast.info(
                'Assemble a designer droid first: it writes the design for every project it is asked about.',
            );
            assemble('Designer');
            return;
        }
        setTaskKey(null);
        setChat({ droid: ds.id, design: pid });
    };
    const discuss = (pid: string) => {
        const dr =
            store.droids.find(
                (d) => d.project === pid && d.role === 'Planner',
            ) ??
            store.droids.find((d) => d.project === pid) ??
            store.droids.find((d) => d.role === 'Planner') ??
            store.droids[0];
        if (!dr) {
            toast.info(
                'Assemble a droid first: it answers questions about the project.',
            );
            assemble();
            return;
        }
        setTaskKey(null);
        setChat({ droid: dr.id, discuss: pid });
    };
    const [connectOpen, setConnectOpen] = useState(false);
    const [syncing, setSyncing] = useState(false);

    // Views switch on the client so in-progress edits survive; the URL stays shareable.
    const push = (v: View, p: string) => {
        const url = `/${v}${p === 'all' ? '' : `?project=${encodeURIComponent(p)}`}`;
        if (url !== location.pathname + location.search)
            history.pushState(history.state, '', url);
    };
    const go = (v: View) => {
        setView(v);
        push(v, project);
    };
    const setProject = (p: string, v: View = view) => {
        setProjectState(p);
        push(v, p);
    };
    useEffect(() => {
        const f = () => {
            const u = readUrl();
            setView(u.view);
            setProjectState(u.project);
        };
        addEventListener('popstate', f);
        return () => removeEventListener('popstate', f);
    }, []);

    // While droids run on a worker, pull their progress from the server.
    useEffect(() => {
        const live = () =>
            Object.keys(store.asks ?? {}).length > 0 ||
            Object.values(store.runs ?? {}).some(
                (r) => r.status === 'queued' || r.status === 'running',
            );
        const t = setInterval(() => {
            if (document.hidden || !live()) return;
            request('GET', '/factory/state')
                .then((res) => {
                    applyServerStore(store, res.store);
                    bump();
                })
                .catch(() => {
                    /* offline: try again on the next tick */
                });
        }, 4000);
        return () => clearInterval(t);
    }, [store]);

    // Keep "x min ago" labels fresh.
    useEffect(() => {
        const m = setInterval(() => {
            store.feed.forEach((f) => f.min++);
            store.droids.forEach((d) => d.status !== 'idle' && d.minutes++);
            bump();
        }, 60000);
        return () => clearInterval(m);
    }, [store]);

    const droid = (id?: string | null) => store.droids.find((d) => d.id === id);
    const proj = (id: string) =>
        store.projects.find((p) => p.id === id) ?? store.projects[0];
    const inProj = (p: string | null) => project === 'all' || p === project;

    // Write to the server, then reconcile with the store it returns. On failure,
    // show why and reload the server's state to undo the optimistic change.
    const save: Save = async (method, url, body) => {
        try {
            const res = await request(method, url, body);
            applyServerStore(store, res.store);
            bump();
            return res;
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Could not save');
            try {
                applyServerStore(
                    store,
                    (await request('GET', '/factory/state')).store,
                );
                bump();
            } catch {
                /* offline: keep what we have */
            }
            return null;
        }
    };

    const assign = (
        pid: string,
        n: number,
        id: string,
        as: 'builder' | 'reviewer',
    ) => {
        const i = store.issues.find((x) => x.project === pid && x.n === n);
        const d = droid(id);
        if (!i || !d) return;
        if (as === 'builder') {
            if (d.task && (d.task !== n || d.project !== pid)) {
                const old = store.issues.find(
                    (x) => x.project === d.project && x.n === d.task,
                );
                if (old && old.col === 'progress' && old.builder === d.id) {
                    old.builder = undefined;
                    old.col = 'ready';
                }
            }
            i.builder = id;
            if (i.col === 'ready' || i.col === 'backlog') i.col = 'progress';
            Object.assign(d, {
                status: 'working',
                task: n,
                project: i.project,
                progress: 0,
                minutes: 0,
                step: `Queued: clone ${proj(i.project).repo} and read #${n}`,
            });
        } else {
            i.reviewer = id;
            if (d.status === 'idle')
                Object.assign(d, {
                    status: 'working',
                    task: n,
                    project: i.project,
                    progress: 0,
                    minutes: 0,
                    step: `Waiting to review #${n}`,
                });
        }
        bump();
        void save(
            'POST',
            `/factory/projects/${encodeURIComponent(pid)}/tasks/${n}/assign`,
            { droid: id, as },
        ).then(
            (res) =>
                res &&
                toast.success(`${d.unit} ${d.name} assigned to #${n} as ${as}`),
        );
    };

    const needs = store.droids.filter(
        (d) => d.status === 'needs' || d.status === 'blocked',
    ).length;
    const ctx = {
        store,
        bump,
        droid,
        proj,
        inProj,
        project,
        assign,
        openChat,
        openTask: setTaskKey,
        planWith,
        reflect,
    };

    return (
        <SaveContext.Provider value={save}>
            <SidebarProvider>
                <Head title={TITLES[view]} />
                <Sidebar collapsible="icon">
                    <SidebarHeader>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    size="lg"
                                    onClick={() => {
                                        setView('hangar');
                                        setProject('all', 'hangar');
                                    }}
                                >
                                    <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                                        <BotIcon className="size-4" />
                                    </div>
                                    <div className="grid flex-1 text-left leading-tight">
                                        <span className="truncate font-semibold">
                                            Droids
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            Software factory
                                        </span>
                                    </div>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarHeader>
                    <SidebarContent>
                        <SidebarGroup>
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    {(
                                        [
                                            ['hangar', 'Hangar', WarehouseIcon],
                                            [
                                                'projects',
                                                'Projects',
                                                FolderKanbanIcon,
                                            ],
                                            ['board', 'Board', Columns3Icon],
                                            ['droids', 'Droids', BotIcon],
                                            [
                                                'subscriptions',
                                                'Subscriptions',
                                                GaugeIcon,
                                            ],
                                        ] as const
                                    ).map(([k, l, Icon]) => (
                                        <SidebarMenuItem key={k}>
                                            <SidebarMenuButton
                                                isActive={view === k}
                                                tooltip={l}
                                                onClick={() => go(k)}
                                            >
                                                <Icon />
                                                {l}
                                            </SidebarMenuButton>
                                            {k === 'hangar' && needs > 0 && (
                                                <SidebarMenuBadge className="text-needs">
                                                    {needs}
                                                </SidebarMenuBadge>
                                            )}
                                        </SidebarMenuItem>
                                    ))}
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                        <SidebarGroup>
                            <SidebarGroupLabel>Projects</SidebarGroupLabel>
                            <SidebarGroupAction
                                title="Connect a repository"
                                onClick={() => setConnectOpen(true)}
                            >
                                <PlusIcon />
                                <span className="sr-only">
                                    Connect a repository
                                </span>
                            </SidebarGroupAction>
                            <SidebarGroupContent>
                                <SidebarMenu className="gap-1">
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            isActive={project === 'all'}
                                            tooltip="All projects"
                                            onClick={() => setProject('all')}
                                        >
                                            <LayoutGridIcon />
                                            All projects
                                        </SidebarMenuButton>
                                        <SidebarMenuBadge>
                                            {
                                                store.droids.filter(
                                                    (d) => d.status !== 'idle',
                                                ).length
                                            }
                                        </SidebarMenuBadge>
                                    </SidebarMenuItem>
                                    {store.projects.map((p) => {
                                        const pct = Math.round(
                                            (p.total ? p.done / p.total : 0) *
                                                100,
                                        );
                                        const active = store.droids.filter(
                                            (d) =>
                                                d.project === p.id &&
                                                d.status !== 'idle',
                                        );
                                        return (
                                            <SidebarMenuItem key={p.id}>
                                                <SidebarMenuButton
                                                    isActive={project === p.id}
                                                    tooltip={`${p.name} · ${pct}%`}
                                                    onClick={() => {
                                                        const v =
                                                            view === 'hangar' ||
                                                            view ===
                                                                'subscriptions'
                                                                ? 'projects'
                                                                : view;
                                                        setView(v);
                                                        setProject(p.id, v);
                                                    }}
                                                    className="h-auto flex-col items-stretch gap-1.5 py-2 group-data-[collapsible=icon]:!h-8 group-data-[collapsible=icon]:!p-2"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className="size-2.5 shrink-0 rounded-[3px]"
                                                            style={{
                                                                background:
                                                                    p.color,
                                                            }}
                                                        />
                                                        <span className="truncate font-medium group-data-[collapsible=icon]:hidden">
                                                            {p.name}
                                                        </span>
                                                        {(() => {
                                                            const h =
                                                                cloudHealth(
                                                                    store,
                                                                    p.id,
                                                                );
                                                            return (
                                                                <span
                                                                    title={`Laravel Cloud: ${h.label}`}
                                                                    className={cn(
                                                                        'size-1.5 rounded-full group-data-[collapsible=icon]:hidden',
                                                                        h.dot,
                                                                    )}
                                                                />
                                                            );
                                                        })()}
                                                        <span className="ml-auto text-xs text-muted-foreground tabular-nums group-data-[collapsible=icon]:hidden">
                                                            {pct}%
                                                        </span>
                                                    </div>
                                                    <div className="flex flex-col gap-1.5 pl-[18px] group-data-[collapsible=icon]:hidden">
                                                        <Progress
                                                            value={pct}
                                                            className="h-1"
                                                        />
                                                        <div className="flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                            <span className="truncate">
                                                                {p.milestone} ·{' '}
                                                                {p.done}/
                                                                {p.total}
                                                            </span>
                                                            <span className="ml-auto flex -space-x-1.5">
                                                                {active
                                                                    .slice(0, 3)
                                                                    .map(
                                                                        (d) => (
                                                                            <DroidAvatar
                                                                                key={
                                                                                    d.id
                                                                                }
                                                                                provider={
                                                                                    d.provider
                                                                                }
                                                                                status={
                                                                                    d.status
                                                                                }
                                                                                seed={
                                                                                    d.seed
                                                                                }
                                                                                className="size-4 rounded"
                                                                            />
                                                                        ),
                                                                    )}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </SidebarMenuButton>
                                            </SidebarMenuItem>
                                        );
                                    })}
                                </SidebarMenu>
                            </SidebarGroupContent>
                        </SidebarGroup>
                    </SidebarContent>
                    <SidebarFooter>
                        <button
                            onClick={() => go('subscriptions')}
                            className="grid gap-2.5 rounded-lg border bg-background p-3 text-left text-xs group-data-[collapsible=icon]:hidden hover:bg-accent"
                        >
                            {(['claude', 'codex'] as Provider[]).map((pv) => {
                                const sub = activeSub(store, pv);
                                const w = sub?.windows[0];
                                const used = w
                                    ? w.used
                                    : sub?.spend
                                      ? sub.spend.used / sub.spend.cap
                                      : 0;
                                return (
                                    <div key={pv} className="grid gap-1">
                                        <div className="flex items-center gap-1.5">
                                            <EngineLogo
                                                provider={pv}
                                                className="size-3.5"
                                            />
                                            <span className="truncate font-medium">
                                                {sub ? sub.name : 'Paused'}
                                            </span>
                                            <span className="ml-auto text-muted-foreground tabular-nums">
                                                {Math.round(used * 100)}%
                                            </span>
                                        </div>
                                        <div className="h-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full',
                                                    used >= 0.85
                                                        ? 'bg-blocked'
                                                        : used >= 0.6
                                                          ? 'bg-needs'
                                                          : 'bg-done',
                                                )}
                                                style={{
                                                    width: `${used * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {w
                                                ? `${w.label} · ${w.resets}`
                                                : sub?.spend
                                                  ? 'Monthly spend cap'
                                                  : 'No subscription scheduled now'}
                                        </div>
                                    </div>
                                );
                            })}
                        </button>
                        <NavUser />
                    </SidebarFooter>
                    <SidebarRail />
                </Sidebar>

                <SidebarInset className="min-w-0">
                    <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-2 border-b bg-background/90 px-4 backdrop-blur">
                        <SidebarTrigger className="-ml-1" />
                        <Separator
                            orientation="vertical"
                            className="mr-1 !h-4"
                        />
                        <div className="flex min-w-0 items-center gap-2 text-sm">
                            <span className="text-muted-foreground max-sm:hidden">
                                {TITLES[view]}
                            </span>
                            <span className="text-muted-foreground max-sm:hidden">
                                /
                            </span>
                            <span className="truncate font-medium">
                                {project === 'all'
                                    ? 'All projects'
                                    : proj(project).name}
                            </span>
                        </div>
                        <div className="ml-auto flex items-center gap-2">
                            {project !== 'all' && proj(project).linked && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={syncing}
                                    title={
                                        proj(project).synced
                                            ? `Synced ${proj(project).synced}`
                                            : 'Not synced yet'
                                    }
                                    onClick={async () => {
                                        setSyncing(true);
                                        const res = await save(
                                            'POST',
                                            `/factory/projects/${encodeURIComponent(project)}/sync`,
                                        );
                                        setSyncing(false);
                                        if (res)
                                            toast.success(
                                                `Synced ${proj(project).repo}`,
                                            );
                                    }}
                                >
                                    <RefreshCwIcon
                                        className={cn(
                                            syncing && 'animate-spin',
                                        )}
                                    />
                                    <span className="max-sm:hidden">Sync</span>
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setConnectOpen(true)}
                                className="max-sm:hidden"
                            >
                                <GithubMark />
                                Connect repo
                            </Button>
                            <Button size="sm" onClick={() => assemble()}>
                                <PlusIcon />
                                Assemble droid
                            </Button>
                        </div>
                    </header>

                    <main className="min-w-0 flex-1">
                        {view === 'hangar' && (
                            <Hangar
                                {...ctx}
                                filter={filter}
                                setFilter={setFilter}
                            />
                        )}
                        {view === 'projects' && (
                            <ProjectView
                                store={store}
                                bump={bump}
                                project={project}
                                proj={proj}
                                setProject={setProject}
                                openTask={setTaskKey}
                                planWith={planWith}
                                propose={propose}
                                designWith={designWith}
                                discuss={discuss}
                            />
                        )}
                        {view === 'board' && <Board {...ctx} />}
                        {view === 'droids' && (
                            <Roster
                                {...ctx}
                                filter={filter}
                                setFilter={setFilter}
                            />
                        )}
                        {view === 'subscriptions' && (
                            <SubscriptionsView
                                store={store}
                                bump={bump}
                                onAdd={() => setAddSubOpen(true)}
                            />
                        )}
                        <p className="px-4 pb-8 text-xs text-muted-foreground md:px-6">
                            Projects not linked to the GitHub App, droid runs
                            and Laravel Cloud still show sample data.
                        </p>
                    </main>
                </SidebarInset>

                <ChatSheet
                    store={store}
                    target={chat}
                    onClose={() => setChat(null)}
                    bump={bump}
                    proj={proj}
                    onSwitchDroid={(id) =>
                        setChat((c) => (c ? { ...c, droid: id } : c))
                    }
                />
                <TaskSheet
                    store={store}
                    issueKey={taskKey}
                    onClose={() => setTaskKey(null)}
                    openChat={openChat}
                    bump={bump}
                    proj={proj}
                />
                <AddSubscriptionDialog
                    open={addSubOpen}
                    onOpenChange={setAddSubOpen}
                    onAdd={async (s) => {
                        const res = await save(
                            'POST',
                            '/factory/subscriptions',
                            s,
                        );
                        if (res)
                            toast.success(`${s.plan} added`, {
                                description:
                                    'Paint it into the schedule to start using it',
                            });
                        return !!res;
                    }}
                />
                <AssembleDialog
                    store={store}
                    defaultRole={assembleRole}
                    open={assembleOpen}
                    onOpenChange={setAssembleOpen}
                    onCreate={async (d) => {
                        const res = await save('POST', '/factory/droids', d);
                        const made = res && droid(res.droid as string);
                        if (made)
                            toast.success(
                                `${made.unit} ${made.name} assembled${made.project ? ` on ${proj(made.project).name}` : ''}`,
                            );
                        return !!res;
                    }}
                    nextSeed={store.droids.length + 1}
                    nextUnit={(pv) => {
                        const prefix = pv === 'claude' ? 'CL' : 'CX';
                        const top = Math.max(
                            0,
                            ...store.droids
                                .filter((d) => d.unit.startsWith(`${prefix}-`))
                                .map((d) => Number(d.unit.slice(3)) || 0),
                        );
                        return `${prefix}-${String(top + 1).padStart(2, '0')}`;
                    }}
                />
                <ConnectDialog
                    open={connectOpen}
                    onOpenChange={setConnectOpen}
                    onConnect={async (repo, installation) => {
                        const res = await save('POST', '/factory/projects', {
                            repo,
                            installation,
                        });
                        if (!res) return false;
                        setView('board');
                        setProject(res.project as string, 'board');
                        if (res.warning) toast.warning(res.warning as string);
                        else
                            toast.success(`Connected ${repo}`, {
                                description: installation
                                    ? 'Issues, PRs and checks are synced'
                                    : 'Set up the GitHub App in Settings to sync',
                            });
                        return true;
                    }}
                />
            </SidebarProvider>
        </SaveContext.Provider>
    );
}

type Ctx = {
    store: Store;
    bump: () => void;
    droid: (id?: string | null) => Droid | undefined;
    proj: (id: string) => Project;
    inProj: (p: string | null) => boolean;
    project: string;
    assign: (
        pid: string,
        n: number,
        id: string,
        as: 'builder' | 'reviewer',
    ) => void;
    openChat: (id: string) => void;
    openTask: (key: string) => void;
    planWith: (pid: string) => void;
    reflect: (id: string) => void;
};

function StatusBadge({ s }: { s: Droid['status'] }) {
    return (
        <Badge
            variant="outline"
            className={cn('gap-1.5 rounded-full', STATUS[s].cls)}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    STATUS[s].dot,
                    s === 'working' && 'status-glow',
                )}
            />
            {STATUS[s].label}
        </Badge>
    );
}

function ProviderFilter({
    filter,
    setFilter,
}: {
    filter: 'all' | Provider;
    setFilter: (f: 'all' | Provider) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={filter}
            onValueChange={(v) => v && setFilter(v as 'all' | Provider)}
            aria-label="Filter by provider"
        >
            <ToggleGroupItem value="all" className="px-3">
                All
            </ToggleGroupItem>
            <ToggleGroupItem value="claude" className="px-3">
                <span className="size-2 rounded-full bg-claude" />
                Claude Code
            </ToggleGroupItem>
            <ToggleGroupItem value="codex" className="px-3">
                <span className="size-2 rounded-full bg-codex" />
                Codex
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

function Hangar({
    store,
    droid,
    proj,
    inProj,
    project,
    assign,
    openChat,
    filter,
    setFilter,
}: Ctx & {
    filter: 'all' | Provider;
    setFilter: (f: 'all' | Provider) => void;
}) {
    const all = store.droids.filter((d) => inProj(d.project));
    const list = all.filter((d) => filter === 'all' || d.provider === filter);
    const c = (s: Droid['status']) => all.filter((d) => d.status === s).length;
    const order = { needs: 0, blocked: 1, working: 2, idle: 3 } as const;
    const waiting = c('needs') + c('blocked');
    const byProv = (pv: Provider) =>
        all.filter((d) => d.provider === pv && d.status !== 'idle').length;
    const feed = store.feed
        .filter((f) => inProj(droid(f.droid)!.project))
        .slice(0, 12);
    const scope =
        project === 'all'
            ? `${store.projects.length} projects`
            : proj(project).repo;

    return (
        <div className="blueprint border-b">
            <div className="flex flex-wrap items-end justify-between gap-4 px-4 pt-7 pb-5 md:px-6">
                <div className="min-w-0">
                    <h1 className="text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                        {c('working')} droids working
                        {waiting ? `, ${waiting} waiting on you` : ''}
                    </h1>
                    <p className="mt-1.5 text-muted-foreground">
                        Claude Code and Codex side by side across {scope}. Open
                        any droid to talk to it.
                    </p>
                </div>
                <ProviderFilter filter={filter} setFilter={setFilter} />
            </div>
            <div className="grid grid-cols-2 border-t bg-background sm:grid-cols-3 lg:grid-cols-6">
                {(
                    [
                        ['Working', c('working'), 'bg-working'],
                        ['Needs you', c('needs'), 'bg-needs'],
                        ['Blocked', c('blocked'), 'bg-blocked'],
                        ['Docked', c('idle'), 'bg-idle'],
                    ] as const
                ).map(([l, v, dot]) => (
                    <div
                        key={l}
                        className="border-r border-b px-4 py-3 md:px-6 lg:border-b-0"
                    >
                        <div className="text-2xl font-semibold tabular-nums">
                            {v}
                        </div>
                        <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <span className={cn('size-2 rounded-full', dot)} />
                            {l}
                        </div>
                    </div>
                ))}
                <div className="col-span-2 border-b px-4 py-3 sm:col-span-2 md:px-6 lg:border-b-0">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="text-2xl font-semibold tabular-nums">
                            ${all.reduce((s, d) => s + d.cost, 0).toFixed(2)}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            spend today
                        </span>
                    </div>
                    <div className="mt-2 flex h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="bg-claude"
                            style={{
                                width: `${(byProv('claude') / Math.max(1, byProv('claude') + byProv('codex'))) * 100}%`,
                            }}
                        />
                        <div className="bg-codex" style={{ flex: 1 }} />
                    </div>
                    <div className="mt-1 flex justify-between text-xs text-muted-foreground">
                        <span>Claude Code {byProv('claude')} active</span>
                        <span>Codex {byProv('codex')} active</span>
                    </div>
                </div>
            </div>
            <div className="grid gap-0 bg-background xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="grid [grid-template-columns:repeat(auto-fill,minmax(min(100%,290px),1fr))] content-start gap-4 p-4 md:p-6">
                    {list
                        .slice()
                        .sort((a, b) => order[a.status] - order[b.status])
                        .map((d) => (
                            <DroidCard
                                key={d.id}
                                d={d}
                                store={store}
                                proj={proj}
                                assign={assign}
                                openChat={openChat}
                            />
                        ))}
                    {!list.length && (
                        <p className="text-muted-foreground">
                            No droids here yet. Assemble one to get started.
                        </p>
                    )}
                </div>
                <aside className="border-t p-4 md:p-6 xl:border-t-0 xl:border-l">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                        <ActivityIcon className="size-4" />
                        Activity
                    </h2>
                    <ol className="grid">
                        {feed.map((f, i) => {
                            const d = droid(f.droid)!;
                            return (
                                <li
                                    key={`${f.droid}-${f.text}-${i}`}
                                    className={cn(
                                        'grid grid-cols-[28px_minmax(0,1fr)] gap-3 border-b py-2.5 text-sm',
                                        i === 0 &&
                                            f.min === 0 &&
                                            'animate-in fade-in',
                                    )}
                                >
                                    <DroidAvatar
                                        provider={d.provider}
                                        status={d.status}
                                        seed={d.seed}
                                        className="size-7"
                                    />
                                    <div>
                                        <span className="font-medium">
                                            {d.unit}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {d.name}
                                        </span>{' '}
                                        {f.text}
                                        <div className="text-xs text-muted-foreground">
                                            {ago(f.min)}
                                            {d.project &&
                                                ` · ${proj(d.project).name}`}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </aside>
            </div>
        </div>
    );
}

function DroidCard({
    d,
    store,
    proj,
    assign,
    openChat,
}: { d: Droid } & Pick<Ctx, 'store' | 'proj' | 'assign' | 'openChat'>) {
    const i = store.issues.find(
        (x) => x.n === d.task && x.project === d.project,
    );
    const p = d.project ? proj(d.project) : null;
    return (
        <Card
            className={cn(
                'gap-3 py-4 transition-colors',
                d.status === 'needs' && 'border-needs/45',
                d.status === 'blocked' && 'border-blocked/45',
            )}
        >
            <CardHeader className="flex flex-row items-center gap-3 px-4">
                <DroidAvatar
                    provider={d.provider}
                    status={d.status}
                    seed={d.seed}
                    className="size-11"
                />
                <div className="min-w-0 flex-1 leading-tight">
                    <div className="flex items-baseline gap-1.5">
                        <span className="font-mono text-sm font-medium">
                            {d.unit}
                        </span>
                        <span className="truncate font-semibold">{d.name}</span>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <ProviderMark provider={d.provider} />
                        <span className="truncate text-xs text-muted-foreground">
                            {d.model} · {d.role}
                        </span>
                    </div>
                </div>
                <StatusBadge s={d.status} />
            </CardHeader>
            <CardContent className="grid gap-2.5 px-4">
                {d.status === 'idle' ? (
                    <p className="text-sm text-muted-foreground">
                        Docked and charged.{' '}
                        {p
                            ? `Ready for a task from ${p.name}.`
                            : 'Ready for a task on any project.'}
                    </p>
                ) : (
                    <>
                        <div className="text-sm leading-snug">
                            <div className="mb-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <span
                                    className="size-2 rounded-[2px]"
                                    style={{ background: p?.color }}
                                />
                                {p?.repo}
                            </div>
                            <span className="mr-1 font-mono text-muted-foreground">
                                #{d.task}
                            </span>
                            {i?.title}
                        </div>
                        <div
                            className="truncate rounded-md bg-muted px-2.5 py-1.5 font-mono text-xs"
                            title={d.step}
                        >
                            {d.status === 'working' ? (
                                <Shimmer as="span" duration={2.4}>
                                    {d.step}
                                </Shimmer>
                            ) : (
                                <span className="text-muted-foreground">
                                    {d.step}
                                </span>
                            )}
                        </div>
                        {d.status === 'working' && (
                            <Progress
                                value={d.progress * 100}
                                className="h-1 [&>div]:bg-working"
                                aria-label="Progress"
                            />
                        )}
                    </>
                )}
            </CardContent>
            <CardFooter className="flex items-center gap-2 px-4 text-xs text-muted-foreground">
                {d.status !== 'idle' ? (
                    <>
                        <span>{d.minutes} min</span>
                        <span>${d.cost.toFixed(2)}</span>
                    </>
                ) : (
                    <span>{p?.name ?? 'No project yet'}</span>
                )}
                <span className="flex-1" />
                {d.status === 'idle' && (
                    <IssuePicker d={d} store={store} assign={assign} />
                )}
                {(d.status === 'needs' || d.status === 'blocked') && (
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-8 border-needs/40 text-needs hover:text-needs"
                        onClick={() => openChat(d.id)}
                    >
                        {d.status === 'needs' ? 'Respond' : 'Unblock'}
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="ghost"
                    className="h-8"
                    onClick={() => openChat(d.id)}
                >
                    <MessageSquareIcon />
                    Chat
                </Button>
            </CardFooter>
        </Card>
    );
}

function IssuePicker({
    d,
    store,
    assign,
    label = 'Assign task',
}: { d: Droid; label?: string } & Pick<Ctx, 'store' | 'assign'>) {
    const [open, setOpen] = useState(false);
    const list = store.issues
        .filter((i) => !i.builder && (i.col === 'ready' || i.col === 'backlog'))
        .sort(
            (a, b) =>
                (a.project === d.project ? 0 : 1) -
                    (b.project === d.project ? 0 : 1) ||
                (a.col === 'ready' ? 0 : 1) - (b.col === 'ready' ? 0 : 1),
        );
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button size="sm" variant="outline" className="h-8">
                    <SparklesIcon />
                    {label}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="end">
                <Command>
                    <CommandInput placeholder={`Give ${d.unit} an issue…`} />
                    <CommandList>
                        <CommandEmpty>No open issues.</CommandEmpty>
                        {store.projects
                            .filter((p) => list.some((i) => i.project === p.id))
                            .map((p) => (
                                <CommandGroup
                                    key={p.id}
                                    heading={`${p.name} · ${p.repo}`}
                                >
                                    {list
                                        .filter((i) => i.project === p.id)
                                        .map((i) => (
                                            <CommandItem
                                                key={`${i.project}-${i.n}`}
                                                value={`${i.n} ${i.title} ${p.name}`}
                                                onSelect={() => {
                                                    assign(
                                                        i.project,
                                                        i.n,
                                                        d.id,
                                                        d.role === 'Reviewer'
                                                            ? 'reviewer'
                                                            : 'builder',
                                                    );
                                                    setOpen(false);
                                                }}
                                            >
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    #{i.n}
                                                </span>
                                                <span className="truncate">
                                                    {i.title}
                                                </span>
                                                <span className="ml-auto text-xs text-muted-foreground">
                                                    {
                                                        COLS.find(
                                                            (c) =>
                                                                c.key === i.col,
                                                        )?.label
                                                    }
                                                </span>
                                            </CommandItem>
                                        ))}
                                </CommandGroup>
                            ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

function DroidPicker({
    issue,
    as,
    store,
    assign,
    children,
}: {
    issue: Issue;
    as: 'builder' | 'reviewer';
    children: React.ReactNode;
} & Pick<Ctx, 'store' | 'assign'>) {
    const [open, setOpen] = useState(false);
    const sorted = (pv: Provider) =>
        store.droids
            .filter((d) => d.provider === pv)
            .sort(
                (a, b) =>
                    (a.status === 'idle' ? 0 : 1) -
                        (b.status === 'idle' ? 0 : 1) ||
                    (a.project === issue.project ? 0 : 1) -
                        (b.project === issue.project ? 0 : 1),
            );
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="end">
                <Command>
                    <CommandInput
                        placeholder={`${as === 'builder' ? 'Builder' : 'Reviewer'} for #${issue.n}…`}
                    />
                    <CommandList>
                        <CommandEmpty>No droid found.</CommandEmpty>
                        {(['claude', 'codex'] as Provider[]).map((pv) => (
                            <CommandGroup
                                key={pv}
                                heading={PROVIDERS[pv].label}
                            >
                                {sorted(pv)
                                    .filter((d) => d.id !== issue.builder)
                                    .map((d) => (
                                        <CommandItem
                                            key={d.id}
                                            value={`${d.unit} ${d.name} ${d.role} ${PROVIDERS[pv].label}`}
                                            onSelect={() => {
                                                assign(
                                                    issue.project,
                                                    issue.n,
                                                    d.id,
                                                    as,
                                                );
                                                setOpen(false);
                                            }}
                                        >
                                            <DroidAvatar
                                                provider={d.provider}
                                                status={d.status}
                                                seed={d.seed}
                                                className="size-7"
                                            />
                                            <div className="min-w-0 leading-tight">
                                                <div>
                                                    <span className="font-mono text-xs">
                                                        {d.unit}
                                                    </span>{' '}
                                                    {d.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {d.role} · {d.model} ·{' '}
                                                    {d.status === 'idle'
                                                        ? 'Docked'
                                                        : `on #${d.task}`}
                                                </div>
                                            </div>
                                        </CommandItem>
                                    ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

function Board({
    store,
    droid,
    proj,
    inProj,
    project,
    assign,
    openChat,
    bump,
    openTask,
    planWith,
}: Ctx) {
    const save = useSave();
    const [drag, setDrag] = useState<string | null>(null);
    const [over, setOver] = useState<ColKey | null>(null);
    const list = store.issues.filter((i) => inProj(i.project));
    const p = project !== 'all' ? proj(project) : null;
    const totals = useMemo(
        () =>
            p
                ? { pct: Math.round((p.total ? p.done / p.total : 0) * 100) }
                : null,
        [p, p?.done, p?.total],
    );
    const drop = (col: ColKey) => {
        if (!drag) return;
        const [pid, n] = drag.split(':');
        const i = store.issues.find((x) => x.project === pid && x.n === +n)!;
        if (i.col !== col) {
            const pr = proj(i.project);
            if (col === 'done' && i.col !== 'done') pr.done++;
            if (i.col === 'done' && col !== 'done') pr.done--;
            i.col = col;
            void save(
                'PATCH',
                `/factory/projects/${encodeURIComponent(i.project)}/tasks/${i.n}`,
                { column: col },
            ).then(
                (res) =>
                    res &&
                    toast(
                        `#${i.n} moved to ${COLS.find((c) => c.key === col)!.label}`,
                    ),
            );
        }
        setDrag(null);
        setOver(null);
        bump();
    };
    return (
        <div>
            <div className="blueprint border-b px-4 pt-7 pb-5 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                            {p ? `${p.name} board` : 'All boards'}
                        </h1>
                        <p className="mt-1.5 flex flex-wrap items-center gap-x-2 text-muted-foreground">
                            <GithubMark className="size-3.5" />
                            {p ? (
                                <>
                                    <span className="font-mono text-sm">
                                        {p.repo}
                                    </span>{' '}
                                    ·{' '}
                                    {p.linked
                                        ? p.synced
                                            ? `synced ${p.synced}`
                                            : 'not synced yet'
                                        : 'not linked to the GitHub App'}
                                </>
                            ) : (
                                <>
                                    {store.projects.length} repositories on one
                                    board. Drag cards, or pair a builder with a
                                    reviewer.
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <Button
                            variant="outline"
                            disabled={!p && !store.projects.length}
                            onClick={() =>
                                planWith(p ? p.id : store.projects[0].id)
                            }
                        >
                            <NotebookPenIcon />
                            Plan tasks with a droid
                        </Button>
                        {p && totals && (
                            <div className="w-full max-w-xs rounded-lg border bg-background p-3 sm:w-72">
                                <div className="flex items-baseline justify-between text-sm">
                                    <span className="font-medium">
                                        {p.milestone}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {totals.pct}%
                                    </span>
                                </div>
                                <Progress
                                    value={totals.pct}
                                    className="my-2 h-1.5"
                                />
                                <div className="flex justify-between text-xs text-muted-foreground">
                                    <span>
                                        {p.done} of {p.total} issues closed
                                    </span>
                                    <span>Due {p.due}</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <div className="grid [grid-template-columns:repeat(5,minmax(200px,1fr))] gap-3 overflow-x-auto p-4 md:p-6">
                {COLS.map((c) => {
                    const items = list.filter((i) => i.col === c.key);
                    return (
                        <section
                            key={c.key}
                            aria-label={c.label}
                            onDragOver={(e) => {
                                if (drag) {
                                    e.preventDefault();
                                    setOver(c.key);
                                }
                            }}
                            onDragLeave={() =>
                                setOver((o) => (o === c.key ? null : o))
                            }
                            onDrop={(e) => {
                                e.preventDefault();
                                drop(c.key);
                            }}
                            className={cn(
                                'flex min-h-48 flex-col gap-2 rounded-xl border bg-muted/50 p-2',
                                over === c.key && 'border-ring bg-accent',
                            )}
                        >
                            <div
                                className="flex items-center gap-2 px-1.5 py-1 text-sm font-medium"
                                title={c.hint}
                            >
                                {c.label}
                                <span className="text-muted-foreground">
                                    {items.length}
                                </span>
                            </div>
                            {items.map((i) => {
                                const b = droid(i.builder),
                                    r = droid(i.reviewer),
                                    pr = proj(i.project);
                                return (
                                    <div
                                        key={`${i.project}-${i.n}`}
                                        draggable
                                        onDragStart={(e) => {
                                            setDrag(`${i.project}:${i.n}`);
                                            e.dataTransfer.effectAllowed =
                                                'move';
                                            try {
                                                e.dataTransfer.setData(
                                                    'text/plain',
                                                    String(i.n),
                                                );
                                            } catch {
                                                /* */
                                            }
                                        }}
                                        onDragEnd={() => {
                                            setDrag(null);
                                            setOver(null);
                                        }}
                                        className="grid cursor-grab gap-2 rounded-lg border bg-card p-3 shadow-xs active:cursor-grabbing"
                                    >
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            {project === 'all' && (
                                                <>
                                                    <span
                                                        className="size-2 rounded-[2px]"
                                                        style={{
                                                            background:
                                                                pr.color,
                                                        }}
                                                    />
                                                    {pr.name}
                                                </>
                                            )}
                                            <span className="font-mono">
                                                #{i.n}
                                            </span>
                                            <span className="ml-auto flex gap-1">
                                                {i.labels.map((l) => (
                                                    <Badge
                                                        key={l}
                                                        variant="outline"
                                                        className={cn(
                                                            'rounded-full px-1.5 py-0 text-[11px] font-normal',
                                                            l === 'bug' &&
                                                                'border-blocked/40 text-blocked',
                                                        )}
                                                    >
                                                        {l}
                                                    </Badge>
                                                ))}
                                            </span>
                                        </div>
                                        <button
                                            className="text-left text-sm leading-snug font-medium hover:underline"
                                            onClick={() =>
                                                openTask(`${i.project}:${i.n}`)
                                            }
                                        >
                                            {i.title}
                                        </button>
                                        {store.prs[`${i.project}:${i.n}`] && (
                                            <PrChip
                                                pr={
                                                    store.prs[
                                                        `${i.project}:${i.n}`
                                                    ]
                                                }
                                                onClick={() =>
                                                    openTask(
                                                        `${i.project}:${i.n}`,
                                                    )
                                                }
                                            />
                                        )}
                                        {!i.builder && i.engine && (
                                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <EngineLogo
                                                    provider={i.engine}
                                                    className="size-3.5"
                                                />
                                                Suggested for{' '}
                                                {PROVIDERS[i.engine].label}
                                            </div>
                                        )}
                                        {i.col !== 'done' || b ? (
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                {b ? (
                                                    <Chip
                                                        d={b}
                                                        label="builds"
                                                        onClick={() =>
                                                            openChat(b.id)
                                                        }
                                                    />
                                                ) : (
                                                    <DroidPicker
                                                        issue={i}
                                                        as="builder"
                                                        store={store}
                                                        assign={assign}
                                                    >
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-7 border-dashed px-2 text-xs"
                                                        >
                                                            <UserPlusIcon />
                                                            Builder
                                                        </Button>
                                                    </DroidPicker>
                                                )}
                                                {r ? (
                                                    <Chip
                                                        d={r}
                                                        label="reviews"
                                                        onClick={() =>
                                                            openChat(r.id)
                                                        }
                                                    />
                                                ) : b && i.col !== 'done' ? (
                                                    <DroidPicker
                                                        issue={i}
                                                        as="reviewer"
                                                        store={store}
                                                        assign={assign}
                                                    >
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 px-2 text-xs text-muted-foreground"
                                                        >
                                                            <GitPullRequestIcon />
                                                            Reviewer
                                                        </Button>
                                                    </DroidPicker>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function Chip({
    d,
    label,
    onClick,
}: {
    d: Droid;
    label: string;
    onClick: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    onClick={onClick}
                    className="flex items-center gap-1.5 rounded-md border bg-background py-0.5 pr-2 pl-0.5 text-xs hover:bg-accent"
                >
                    <DroidAvatar
                        provider={d.provider}
                        status={d.status}
                        seed={d.seed}
                        className="size-5"
                    />
                    <span className="font-medium">{d.name}</span>
                    <span className="text-muted-foreground">{label}</span>
                </button>
            </TooltipTrigger>
            <TooltipContent>
                {d.unit} {d.name}, {d.role.toLowerCase()} on{' '}
                {PROVIDERS[d.provider].label} {d.model}. Click to chat.
            </TooltipContent>
        </Tooltip>
    );
}

function Roster({
    store,
    proj,
    inProj,
    project,
    assign,
    openChat,
    reflect,
    filter,
    setFilter,
}: Ctx & {
    filter: 'all' | Provider;
    setFilter: (f: 'all' | Provider) => void;
}) {
    const list = store.droids.filter(
        (d) => inProj(d.project) && (filter === 'all' || d.provider === filter),
    );
    return (
        <div>
            <div className="blueprint flex flex-wrap items-end justify-between gap-4 border-b px-4 pt-7 pb-5 md:px-6">
                <div>
                    <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                        Droids
                    </h1>
                    <p className="mt-1.5 text-muted-foreground">
                        {list.length} droids
                        {project === 'all'
                            ? ' across all projects'
                            : ` on ${proj(project).name}`}
                        . Each one runs in its own sandbox and branch.
                    </p>
                </div>
                <ProviderFilter filter={filter} setFilter={setFilter} />
            </div>
            <div className="p-4 md:p-6">
                <div className="overflow-x-auto rounded-xl border">
                    <Table className="min-w-[980px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Droid</TableHead>
                                <TableHead>Engine</TableHead>
                                <TableHead>Subscription now</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>Current task</TableHead>
                                <TableHead className="text-right">
                                    Today
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {list.map((d) => {
                                const i = store.issues.find(
                                    (x) =>
                                        x.n === d.task &&
                                        x.project === d.project,
                                );
                                return (
                                    <TableRow key={d.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <DroidAvatar
                                                    provider={d.provider}
                                                    status={d.status}
                                                    seed={d.seed}
                                                    className="size-9"
                                                />
                                                <div className="leading-tight">
                                                    <div>
                                                        <span className="font-mono text-sm">
                                                            {d.unit}
                                                        </span>{' '}
                                                        <span className="font-medium">
                                                            {d.name}
                                                        </span>
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {d.role}
                                                    </div>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <EngineLogo
                                                    provider={d.provider}
                                                    className="size-5"
                                                />
                                                <span className="sr-only">
                                                    {
                                                        PROVIDERS[d.provider]
                                                            .label
                                                    }
                                                </span>
                                                <Badge
                                                    variant="secondary"
                                                    className="rounded-full font-normal whitespace-nowrap"
                                                >
                                                    {d.model}
                                                </Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {(() => {
                                                const sb = activeSub(
                                                    store,
                                                    d.provider,
                                                );
                                                return sb ? (
                                                    <span className="flex items-center gap-1.5 text-sm whitespace-nowrap">
                                                        <span
                                                            className="size-2.5 rounded-[2px]"
                                                            style={{
                                                                background:
                                                                    sb.color,
                                                            }}
                                                        />
                                                        {sb.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Paused
                                                    </span>
                                                );
                                            })()}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge s={d.status} />
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {d.project
                                                ? proj(d.project).repo
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="max-w-[280px] whitespace-normal">
                                            {i ? (
                                                <>
                                                    <span className="font-mono text-muted-foreground">
                                                        #{i.n}
                                                    </span>{' '}
                                                    {i.title}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            ${d.cost.toFixed(2)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                {d.status === 'idle' && (
                                                    <IssuePicker
                                                        d={d}
                                                        store={store}
                                                        assign={assign}
                                                        label="Assign"
                                                    />
                                                )}
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-8"
                                                            onClick={() =>
                                                                reflect(d.id)
                                                            }
                                                        >
                                                            <SparklesIcon />
                                                            Reflect
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        Ask {d.unit} to review
                                                        its previous tasks and
                                                        propose how to improve
                                                    </TooltipContent>
                                                </Tooltip>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-8"
                                                    onClick={() =>
                                                        openChat(d.id)
                                                    }
                                                >
                                                    <MessageSquareIcon />
                                                    Chat
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </div>
    );
}
