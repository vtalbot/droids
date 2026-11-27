import { useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BrainIcon,
    CheckCircle2Icon,
    CircleDotIcon,
    CloudIcon,
    FlagIcon,
    GlobeIcon,
    LightbulbIcon,
    MessagesSquareIcon,
    NotebookPenIcon,
    PaletteIcon,
    PencilIcon,
    PlusIcon,
    TrendingDownIcon,
    TrendingUpIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Progress } from '@/components/ui/progress';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { COLS, type Milestone, type Stats } from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { PrChip } from '@/factory/task-sheet';
import { CloudPanel, cloudHealth } from '@/factory/cloud';
import { DesignPanel } from '@/factory/design';
import { MemoryPanel } from '@/factory/memory';
import { DnsPanel } from '@/factory/dns';
import { cn } from '@/lib/utils';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';

type P = Store['projects'][number];
type Props = {
    store: Store;
    bump: () => void;
    project: string;
    proj: (id: string) => P;
    setProject: (id: string) => void;
    openTask: (k: string) => void;
    planWith: (pid: string) => void;
    propose: (pid: string) => void;
    designWith: (pid: string) => void;
    discuss: (pid: string) => void;
};

const engineCfg = {
    claude: { label: 'Claude Code', color: 'var(--claude)' },
    codex: { label: 'Codex', color: 'var(--codex)' },
} satisfies ChartConfig;
const burnCfg = {
    scope: { label: 'Scope', color: 'var(--muted-foreground)' },
    done: { label: 'Closed', color: 'var(--working)' },
} satisfies ChartConfig;
const cycleCfg = {
    hours: { label: 'Median hours', color: 'var(--working)' },
} satisfies ChartConfig;

const msProgress = (store: Store, pid: string, m: Milestone) => {
    const tasks = m.features.flatMap((f) => f.tasks);
    const d = tasks.filter((t) => taskCol(store, pid, t) === 'done').length;
    return {
        done: d,
        total: tasks.length,
        pct: tasks.length ? Math.round((d / tasks.length) * 100) : 0,
    };
};
const taskCol = (store: Store, pid: string, t: { n: number; done?: boolean }) =>
    store.issues.find((i) => i.project === pid && i.n === t.n)?.col ??
    (t.done ? 'done' : 'backlog');

/** Rename a project. The repository and its slug stay the same. */
function RenameProject({ p }: { p: P }) {
    const save = useSave();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(p.name);
    const [busy, setBusy] = useState(false);
    const submit = async () => {
        const n = name.trim();
        if (!n || busy) return;
        setBusy(true);
        const res = await save(
            'PATCH',
            `/factory/projects/${encodeURIComponent(p.id)}`,
            { name: n },
        );
        setBusy(false);
        if (res) {
            setOpen(false);
            toast.success(`Renamed to ${n}`);
        }
    };
    return (
        <>
            <Button
                variant="outline"
                onClick={() => {
                    setName(p.name);
                    setOpen(true);
                }}
            >
                <PencilIcon />
                Rename
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename project</DialogTitle>
                        <DialogDescription>
                            The name shows in the sidebar, on the board and to
                            the droids. The repository stays{' '}
                            <span className="font-mono">{p.repo}</span>.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void submit();
                        }}
                    >
                        <Label htmlFor="project-name">Name</Label>
                        <Input
                            id="project-name"
                            value={name}
                            maxLength={80}
                            autoFocus
                            onChange={(e) => setName(e.target.value)}
                        />
                        <DialogFooter className="mt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    busy ||
                                    !name.trim() ||
                                    name.trim() === p.name
                                }
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ProjectView(props: Props) {
    const { store, project } = props;
    if (project === 'all') return <Portfolio {...props} />;
    const p = props.proj(project);
    return (
        <div>
            <div className="blueprint border-b px-4 pt-7 pb-4 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <div className="mb-1 flex items-center gap-2 text-sm text-muted-foreground">
                            <span
                                className="size-2.5 rounded-[3px]"
                                style={{ background: p.color }}
                            />
                            <span className="font-mono">{p.repo}</span>
                        </div>
                        <h1 className="text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                            {headline(store, p)}
                        </h1>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => props.discuss(p.id)}>
                            <MessagesSquareIcon />
                            Discuss
                        </Button>
                        <RenameProject p={p} />
                        <Button
                            variant="outline"
                            onClick={() => props.propose(p.id)}
                        >
                            <LightbulbIcon />
                            Propose what's next
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => props.planWith(p.id)}
                        >
                            <NotebookPenIcon />
                            Plan tasks
                        </Button>
                    </div>
                </div>
            </div>
            <Tabs defaultValue="overview" className="p-4 md:p-6">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="roadmap">
                        <FlagIcon />
                        Roadmap
                    </TabsTrigger>
                    <TabsTrigger value="design">
                        <PaletteIcon />
                        Design
                    </TabsTrigger>
                    <TabsTrigger value="memory">
                        <BrainIcon />
                        Memory
                    </TabsTrigger>
                    <TabsTrigger value="dns">
                        <GlobeIcon />
                        DNS
                    </TabsTrigger>
                    <TabsTrigger value="cloud">
                        <CloudIcon />
                        Laravel Cloud
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="overview" className="mt-4">
                    <Overview {...props} p={p} />
                </TabsContent>
                <TabsContent value="roadmap" className="mt-4">
                    <Roadmap {...props} p={p} />
                </TabsContent>
                <TabsContent value="design" className="mt-4">
                    <DesignPanel
                        store={store}
                        bump={props.bump}
                        pid={p.id}
                        designWith={props.designWith}
                    />
                </TabsContent>
                <TabsContent value="memory" className="mt-4">
                    <MemoryPanel store={store} pid={p.id} />
                </TabsContent>
                <TabsContent value="dns" className="mt-4">
                    <DnsPanel pid={p.id} />
                </TabsContent>
                <TabsContent value="cloud" className="mt-4">
                    <CloudPanel store={store} bump={props.bump} pid={p.id} />
                </TabsContent>
            </Tabs>
        </div>
    );
}

function headline(store: Store, p: P) {
    const st = store.stats[p.id];
    const ms = store.roadmap[p.id]?.find((m) => m.state === 'active');
    if (!st || !ms) return p.name;
    const pace =
        st.burn
            .slice(-3)
            .reduce((s, w, i, a) => (i ? s + (w.done - a[i - 1].done) : s), 0) /
        2;
    const left = p.total - p.done;
    const weeks = Math.ceil(left / Math.max(pace, 0.5));
    return `${ms.name} is ${Math.round((p.total ? p.done / p.total : 0) * 100)}% done, ${weeks <= 3 ? 'on track' : 'at risk'} for ${ms.due}`;
}

function Kpi({
    label,
    value,
    delta,
    good,
    note,
}: {
    label: string;
    value: string;
    delta?: string;
    good?: boolean;
    note?: string;
}) {
    return (
        <div className="bg-card px-4 py-3">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-0.5 flex items-baseline gap-2">
                <span className="text-2xl font-semibold tabular-nums">
                    {value}
                </span>
                {delta && (
                    <span
                        className={cn(
                            'flex items-center gap-0.5 text-xs font-medium',
                            good ? 'text-done' : 'text-blocked',
                        )}
                    >
                        {good ? (
                            <TrendingUpIcon className="size-3.5" />
                        ) : (
                            <TrendingDownIcon className="size-3.5" />
                        )}
                        {delta}
                    </span>
                )}
            </div>
            {note && (
                <div className="text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}

function ChartCard({
    title,
    desc,
    children,
}: {
    title: string;
    desc: string;
    children: React.ReactNode;
}) {
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">{title}</CardTitle>
                <CardDescription>{desc}</CardDescription>
            </CardHeader>
            <CardContent className="px-2">{children}</CardContent>
        </Card>
    );
}

/** Weeks of zeros for a project with no history yet, so the charts render empty instead of failing. */
function emptyStats(p: P): Stats {
    const weeks = Array.from({ length: 8 }, (_, i) => {
        const d = new Date();
        d.setDate(d.getDate() - ((d.getDay() + 6) % 7) - (7 - i) * 7);
        return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
    });
    return {
        weeks,
        burn: weeks.map(() => ({ scope: p.total, done: p.done })),
        thru: weeks.map(() => ({ claude: 0, codex: 0 })),
        cycle: weeks.map(() => 0),
        spend: weeks.map(() => ({ claude: 0, codex: 0 })),
        ci: 0,
    };
}

function Overview({ store, p }: Props & { p: P }) {
    const st = store.stats[p.id] ?? emptyStats(p);
    // Nothing closed or run yet: show dashes instead of zeros that look like data.
    const fresh =
        st.thru.every((w) => !w.claude && !w.codex) &&
        st.spend.every((w) => !w.claude && !w.codex);
    const burn = st.burn.map((w, i) => ({ week: st.weeks[i], ...w }));
    const thru = st.thru.map((w, i) => ({ week: st.weeks[i], ...w }));
    const cyc = st.cycle.map((h, i) => ({ week: st.weeks[i], hours: h }));
    const spend = st.spend.map((w, i) => ({ week: st.weeks[i], ...w }));
    const last = thru[thru.length - 1],
        prev = thru[thru.length - 2];
    const health = cloudHealth(store, p.id);
    const openPrs = Object.entries(store.prs).filter(
        ([k, v]) => k.startsWith(p.id + ':') && v.state !== 'merged',
    ).length;
    const ax = {
        tickLine: false,
        axisLine: false,
        tickMargin: 8,
        fontSize: 12,
    } as const;
    return (
        <div className="grid gap-4">
            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border md:grid-cols-3 xl:grid-cols-6">
                <Kpi
                    label="Milestone"
                    value={`${Math.round((p.total ? p.done / p.total : 0) * 100)}%`}
                    note={`${p.done} of ${p.total}${p.due ? ` · due ${p.due}` : ''}`}
                />
                <Kpi
                    label="Closed this week"
                    value={fresh ? '—' : String(last.claude + last.codex)}
                    delta={
                        fresh
                            ? undefined
                            : `${last.claude + last.codex - prev.claude - prev.codex >= 0 ? '+' : ''}${last.claude + last.codex - prev.claude - prev.codex}`
                    }
                    good={last.claude + last.codex >= prev.claude + prev.codex}
                    note="vs last week"
                />
                <Kpi
                    label="PR cycle time"
                    value={fresh ? '—' : `${st.cycle[7]} h`}
                    delta={fresh ? undefined : `${st.cycle[7] - st.cycle[6]} h`}
                    good={st.cycle[7] <= st.cycle[6]}
                    note="median, open to merge"
                />
                <Kpi
                    label="Green on first push"
                    value={fresh ? '—' : `${Math.round(st.ci * 100)}%`}
                    note="CI runs this month"
                />
                <Kpi
                    label="Open PRs"
                    value={String(openPrs)}
                    note="from droids"
                />
                <Kpi
                    label="Production"
                    value={health.label}
                    note={health.note}
                />
            </div>
            {fresh && (
                <p className="text-sm text-muted-foreground">
                    No history yet: these charts fill in as droids close issues
                    and open pull requests on this project.
                </p>
            )}
            <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard
                    title="Burnup"
                    desc="Issues closed against milestone scope, by week"
                >
                    <ChartContainer
                        config={burnCfg}
                        className="aspect-auto h-[230px] w-full"
                    >
                        <AreaChart
                            data={burn}
                            margin={{ left: 0, right: 12, top: 8 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="week" {...ax} />
                            <YAxis width={28} {...ax} />
                            <ChartTooltip content={<ChartTooltipContent />} />
                            <Area
                                dataKey="done"
                                type="monotone"
                                fill="var(--color-done)"
                                fillOpacity={0.18}
                                stroke="var(--color-done)"
                                strokeWidth={2}
                            />
                            <Area
                                dataKey="scope"
                                type="stepAfter"
                                fill="none"
                                stroke="var(--color-scope)"
                                strokeDasharray="4 4"
                                strokeWidth={1.5}
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                        </AreaChart>
                    </ChartContainer>
                </ChartCard>
                <ChartCard
                    title="Throughput by engine"
                    desc="Issues closed per week"
                >
                    <ChartContainer
                        config={engineCfg}
                        className="aspect-auto h-[230px] w-full"
                    >
                        <BarChart
                            data={thru}
                            margin={{ left: 0, right: 12, top: 8 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="week" {...ax} />
                            <YAxis width={28} allowDecimals={false} {...ax} />
                            <ChartTooltip content={<ChartTooltipContent />} />
                            <Bar
                                dataKey="claude"
                                stackId="a"
                                fill="var(--color-claude)"
                                radius={[0, 0, 3, 3]}
                            />
                            <Bar
                                dataKey="codex"
                                stackId="a"
                                fill="var(--color-codex)"
                                radius={[3, 3, 0, 0]}
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                        </BarChart>
                    </ChartContainer>
                </ChartCard>
                <ChartCard
                    title="PR cycle time"
                    desc="Median hours from PR opened to merged"
                >
                    <ChartContainer
                        config={cycleCfg}
                        className="aspect-auto h-[200px] w-full"
                    >
                        <LineChart
                            data={cyc}
                            margin={{ left: 0, right: 12, top: 8 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="week" {...ax} />
                            <YAxis width={28} {...ax} />
                            <ChartTooltip content={<ChartTooltipContent />} />
                            <Line
                                dataKey="hours"
                                type="monotone"
                                stroke="var(--color-hours)"
                                strokeWidth={2}
                                dot={{ r: 3 }}
                            />
                        </LineChart>
                    </ChartContainer>
                </ChartCard>
                <ChartCard
                    title="Droid spend"
                    desc="Dollars per week, by engine"
                >
                    <ChartContainer
                        config={engineCfg}
                        className="aspect-auto h-[200px] w-full"
                    >
                        <AreaChart
                            data={spend}
                            margin={{ left: 0, right: 12, top: 8 }}
                        >
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="week" {...ax} />
                            <YAxis
                                width={28}
                                {...ax}
                                tickFormatter={(v) => `$${v}`}
                            />
                            <ChartTooltip content={<ChartTooltipContent />} />
                            <Area
                                dataKey="codex"
                                stackId="s"
                                type="monotone"
                                fill="var(--color-codex)"
                                fillOpacity={0.35}
                                stroke="var(--color-codex)"
                            />
                            <Area
                                dataKey="claude"
                                stackId="s"
                                type="monotone"
                                fill="var(--color-claude)"
                                fillOpacity={0.35}
                                stroke="var(--color-claude)"
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                        </AreaChart>
                    </ChartContainer>
                </ChartCard>
            </div>
        </div>
    );
}

function Roadmap({ store, p, openTask, propose }: Props & { p: P }) {
    const ms = store.roadmap[p.id] ?? [];
    const [addOpen, setAddOpen] = useState(false);
    const active = ms.find((m) => m.state === 'active')?.id;
    const save = useSave();
    const addTask = async (fid: string, mid: string, title: string) => {
        const f = ms
            .find((m) => m.id === mid)!
            .features.find((x) => x.id === fid)!;
        const res = await save(
            'POST',
            `/factory/projects/${encodeURIComponent(p.id)}/tasks`,
            {
                tasks: [
                    {
                        title,
                        labels: [f.name.toLowerCase().split(' ')[0]],
                        feature: Number(fid.slice(1)),
                    },
                ],
            },
        );
        if (!res) return;
        const [n] = res.numbers as number[];
        toast.success(`#${n} added to the Backlog`, {
            description: `${p.name} · ${f.name}`,
        });
    };
    return (
        <div className="grid gap-5">
            {/* timeline strip */}
            <div className="overflow-x-auto rounded-xl border p-4">
                <div className="flex min-w-[560px] items-stretch gap-0">
                    {ms.map((m, i) => {
                        const pr = msProgress(store, p.id, m);
                        return (
                            <div key={m.id} className="relative flex-1 pr-3">
                                <div className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'grid size-6 shrink-0 place-items-center rounded-full border-2',
                                            m.state === 'done'
                                                ? 'border-done bg-done text-background'
                                                : m.state === 'active'
                                                  ? 'border-working bg-background'
                                                  : 'border-border bg-background',
                                        )}
                                    >
                                        {m.state === 'done' ? (
                                            <CheckCircle2Icon className="size-4" />
                                        ) : (
                                            <span
                                                className={cn(
                                                    'size-2 rounded-full',
                                                    m.state === 'active'
                                                        ? 'bg-working'
                                                        : 'bg-border',
                                                )}
                                            />
                                        )}
                                    </span>
                                    {i < ms.length - 1 && (
                                        <span
                                            className={cn(
                                                'h-0.5 flex-1',
                                                m.state === 'done'
                                                    ? 'bg-done'
                                                    : 'bg-border',
                                            )}
                                        />
                                    )}
                                </div>
                                <div className="mt-2 font-medium">{m.name}</div>
                                <div className="text-xs text-muted-foreground">
                                    {m.state === 'done' ? 'Shipped' : 'Due'}{' '}
                                    {m.due} · {pr.done}/{pr.total} tasks
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="mr-auto font-semibold">
                    Milestones and features
                </h2>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => propose(p.id)}
                >
                    <LightbulbIcon />
                    Propose what's next
                </Button>
                <Button size="sm" onClick={() => setAddOpen(true)}>
                    <PlusIcon />
                    Add milestone
                </Button>
            </div>
            <Accordion
                type="multiple"
                defaultValue={active ? [active] : []}
                className="rounded-xl border"
            >
                {ms.map((m) => {
                    const pr = msProgress(store, p.id, m);
                    return (
                        <AccordionItem key={m.id} value={m.id} className="px-4">
                            <AccordionTrigger className="items-center gap-4 hover:no-underline">
                                <div className="grid min-w-0 flex-1 gap-1.5 sm:grid-cols-[minmax(0,1fr)_220px] sm:items-center sm:gap-6">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-base font-semibold">
                                                {m.name}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'rounded-full font-normal',
                                                    m.state === 'active' &&
                                                        'border-working/40 text-working',
                                                    m.state === 'done' &&
                                                        'border-done/40 text-done',
                                                )}
                                            >
                                                {m.state === 'done'
                                                    ? 'Shipped'
                                                    : m.state === 'active'
                                                      ? 'In progress'
                                                      : 'Planned'}
                                            </Badge>
                                            <span className="text-sm font-normal text-muted-foreground">
                                                {m.due}
                                            </span>
                                        </div>
                                        <div className="truncate text-sm font-normal text-muted-foreground">
                                            {m.goal}
                                        </div>
                                    </div>
                                    <div className="grid gap-1">
                                        <div className="flex justify-between text-xs font-normal text-muted-foreground">
                                            <span>
                                                {m.features.length} features ·{' '}
                                                {pr.done}/{pr.total} tasks
                                            </span>
                                            <span className="tabular-nums">
                                                {pr.pct}%
                                            </span>
                                        </div>
                                        <Progress
                                            value={pr.pct}
                                            className="h-1.5"
                                        />
                                    </div>
                                </div>
                            </AccordionTrigger>
                            <AccordionContent className="grid gap-2 pb-4">
                                {m.features.map((f) => {
                                    const d = f.tasks.filter(
                                        (t) =>
                                            taskCol(store, p.id, t) === 'done',
                                    ).length;
                                    return (
                                        <Collapsible
                                            key={f.id}
                                            defaultOpen={m.state === 'active'}
                                            className="rounded-lg border"
                                        >
                                            <CollapsibleTrigger className="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-accent/50">
                                                <span className="font-medium">
                                                    {f.name}
                                                </span>
                                                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                                    {d}/{f.tasks.length}
                                                </span>
                                                <Progress
                                                    value={
                                                        f.tasks.length
                                                            ? (d /
                                                                  f.tasks
                                                                      .length) *
                                                              100
                                                            : 0
                                                    }
                                                    className="h-1 w-24"
                                                />
                                            </CollapsibleTrigger>
                                            <CollapsibleContent className="border-t">
                                                <ul>
                                                    {f.tasks.map((t) => {
                                                        const col = taskCol(
                                                            store,
                                                            p.id,
                                                            t,
                                                        );
                                                        const iss =
                                                            store.issues.find(
                                                                (i) =>
                                                                    i.project ===
                                                                        p.id &&
                                                                    i.n === t.n,
                                                            );
                                                        const b =
                                                            store.droids.find(
                                                                (x) =>
                                                                    x.id ===
                                                                    iss?.builder,
                                                            );
                                                        const pr =
                                                            store.prs[
                                                                `${p.id}:${t.n}`
                                                            ];
                                                        return (
                                                            <li
                                                                key={t.n}
                                                                className="grid grid-cols-[18px_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-1 border-b px-3 py-2 text-sm last:border-0"
                                                            >
                                                                {col ===
                                                                'done' ? (
                                                                    <CheckCircle2Icon className="size-4 text-done" />
                                                                ) : (
                                                                    <CircleDotIcon
                                                                        className={cn(
                                                                            'size-4',
                                                                            col ===
                                                                                'progress' ||
                                                                                col ===
                                                                                    'review'
                                                                                ? 'text-working'
                                                                                : 'text-muted-foreground',
                                                                        )}
                                                                    />
                                                                )}
                                                                <button
                                                                    className={cn(
                                                                        'truncate text-left hover:underline',
                                                                        col ===
                                                                            'done' &&
                                                                            'text-muted-foreground',
                                                                    )}
                                                                    onClick={() =>
                                                                        iss &&
                                                                        openTask(
                                                                            `${p.id}:${t.n}`,
                                                                        )
                                                                    }
                                                                    disabled={
                                                                        !iss
                                                                    }
                                                                >
                                                                    <span className="mr-1.5 font-mono text-xs text-muted-foreground">
                                                                        #{t.n}
                                                                    </span>
                                                                    {t.title}
                                                                </button>
                                                                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                                                    {b && (
                                                                        <DroidAvatar
                                                                            provider={
                                                                                b.provider
                                                                            }
                                                                            status={
                                                                                b.status
                                                                            }
                                                                            seed={
                                                                                b.seed
                                                                            }
                                                                            className="size-5"
                                                                        />
                                                                    )}
                                                                    {
                                                                        COLS.find(
                                                                            (
                                                                                c,
                                                                            ) =>
                                                                                c.key ===
                                                                                col,
                                                                        )!.label
                                                                    }
                                                                </span>
                                                                {pr &&
                                                                    col !==
                                                                        'done' && (
                                                                        <div className="col-start-2 col-end-4 max-w-xs">
                                                                            <PrChip
                                                                                pr={
                                                                                    pr
                                                                                }
                                                                                onClick={() =>
                                                                                    openTask(
                                                                                        `${p.id}:${t.n}`,
                                                                                    )
                                                                                }
                                                                            />
                                                                        </div>
                                                                    )}
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                                {m.state !== 'done' && (
                                                    <AddInline
                                                        placeholder="Add a task to this feature…"
                                                        onAdd={(v) =>
                                                            addTask(
                                                                f.id,
                                                                m.id,
                                                                v,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </CollapsibleContent>
                                        </Collapsible>
                                    );
                                })}
                                {m.state !== 'done' && (
                                    <AddInline
                                        placeholder="Add a feature to this milestone…"
                                        onAdd={async (v) => {
                                            const res = await save(
                                                'POST',
                                                `/factory/milestones/${m.id.slice(1)}/features`,
                                                { name: v },
                                            );
                                            if (res)
                                                toast.success(
                                                    `Feature added to ${m.name}`,
                                                );
                                        }}
                                        bordered
                                    />
                                )}
                            </AccordionContent>
                        </AccordionItem>
                    );
                })}
            </Accordion>
            <AddMilestone
                open={addOpen}
                onOpenChange={setAddOpen}
                onAdd={async (m) => {
                    const res = await save(
                        'POST',
                        `/factory/projects/${encodeURIComponent(p.id)}/milestones`,
                        m,
                    );
                    if (res) toast.success(`Milestone ${m.name} added`);
                    return !!res;
                }}
            />
        </div>
    );
}

function AddInline({
    placeholder,
    onAdd,
    bordered,
}: {
    placeholder: string;
    onAdd: (v: string) => void;
    bordered?: boolean;
}) {
    const [v, setV] = useState('');
    return (
        <form
            className={cn(
                'flex gap-2 p-2',
                bordered && 'rounded-lg border border-dashed',
            )}
            onSubmit={(e) => {
                e.preventDefault();
                if (v.trim()) {
                    onAdd(v.trim());
                    setV('');
                }
            }}
        >
            <Input
                value={v}
                onChange={(e) => setV(e.target.value)}
                placeholder={placeholder}
                className="h-9 border-0 shadow-none focus-visible:ring-1"
                aria-label={placeholder}
            />
            <Button
                size="sm"
                variant="ghost"
                type="submit"
                disabled={!v.trim()}
            >
                <PlusIcon />
                Add
            </Button>
        </form>
    );
}

function AddMilestone({
    open,
    onOpenChange,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onAdd: (m: {
        name: string;
        due_on: string | null;
        goal: string;
    }) => Promise<boolean>;
}) {
    const [name, setName] = useState('');
    const [due, setDue] = useState('');
    const [goal, setGoal] = useState('');
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[460px]">
                <form
                    className="grid gap-4"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        const ok = await onAdd({
                            name: name.trim() || 'New milestone',
                            due_on: due || null,
                            goal,
                        });
                        if (!ok) return;
                        setName('');
                        setDue('');
                        setGoal('');
                        onOpenChange(false);
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Add a milestone</DialogTitle>
                        <DialogDescription>
                            It's created as a GitHub milestone on the
                            repository.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="ms-name">Name</Label>
                        <Input
                            id="ms-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="v3.4 accounts"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ms-due">Due</Label>
                        <Input
                            id="ms-due"
                            type="date"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            placeholder="Feb 27"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ms-goal">Goal</Label>
                        <Textarea
                            id="ms-goal"
                            value={goal}
                            onChange={(e) => setGoal(e.target.value)}
                            placeholder="What's true for users when this ships?"
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
                        <Button type="submit">Add milestone</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Portfolio({ store, proj, setProject }: Props) {
    return (
        <div>
            <div className="blueprint border-b px-4 pt-7 pb-5 md:px-6">
                <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                    Projects
                </h1>
                <p className="mt-1.5 text-muted-foreground">
                    {store.projects.length} projects, each linked to a GitHub
                    repo and, where deployed, Laravel Cloud.
                </p>
            </div>
            <div className="grid [grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr))] gap-4 p-4 md:p-6">
                {store.projects.map((p) => {
                    const st = store.stats[p.id];
                    const h = cloudHealth(store, p.id);
                    const ms = store.roadmap[p.id]?.find(
                        (m) => m.state === 'active',
                    );
                    return (
                        <Card
                            key={p.id}
                            className="cursor-pointer gap-3 py-4 transition-colors hover:border-ring/60"
                            onClick={() => setProject(p.id)}
                        >
                            <CardHeader className="px-4">
                                <CardTitle className="flex items-center gap-2">
                                    <span
                                        className="size-2.5 rounded-[3px]"
                                        style={{ background: p.color }}
                                    />
                                    {p.name}
                                    <span
                                        className={cn(
                                            'ml-auto flex items-center gap-1.5 text-xs font-normal',
                                            h.cls,
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'size-2 rounded-full',
                                                h.dot,
                                            )}
                                        />
                                        {h.label}
                                    </span>
                                </CardTitle>
                                <CardDescription className="font-mono text-xs">
                                    {p.repo}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 px-4">
                                <div className="grid gap-1.5">
                                    <div className="flex justify-between text-sm">
                                        <span>{ms?.name ?? p.milestone}</span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {Math.round(
                                                (p.total
                                                    ? p.done / p.total
                                                    : 0) * 100,
                                            )}
                                            % · due {p.due}
                                        </span>
                                    </div>
                                    <Progress
                                        value={
                                            (p.total ? p.done / p.total : 0) *
                                            100
                                        }
                                        className="h-1.5"
                                    />
                                </div>
                                {st && (
                                    <ChartContainer
                                        config={burnCfg}
                                        className="aspect-auto h-[70px] w-full"
                                    >
                                        <AreaChart
                                            data={st.burn.map((w, i) => ({
                                                week: st.weeks[i],
                                                ...w,
                                            }))}
                                            margin={{
                                                left: 0,
                                                right: 0,
                                                top: 4,
                                                bottom: 0,
                                            }}
                                        >
                                            <Area
                                                dataKey="done"
                                                type="monotone"
                                                fill="var(--color-done)"
                                                fillOpacity={0.15}
                                                stroke="var(--color-done)"
                                                strokeWidth={1.5}
                                            />
                                            <Area
                                                dataKey="scope"
                                                type="stepAfter"
                                                fill="none"
                                                stroke="var(--color-scope)"
                                                strokeDasharray="3 3"
                                            />
                                        </AreaChart>
                                    </ChartContainer>
                                )}
                                <div className="flex justify-between text-xs text-muted-foreground">
                                    <span>
                                        {
                                            store.droids.filter(
                                                (d) =>
                                                    d.project === p.id &&
                                                    d.status !== 'idle',
                                            ).length
                                        }{' '}
                                        droids active
                                    </span>
                                    <span>{proj(p.id).board}</span>
                                </div>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </div>
    );
}
