import { Fragment, useEffect, useState } from 'react';
import { Line, LineChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import {
    ActivityIcon,
    CheckCircle2Icon,
    CircleDashedIcon,
    CloudIcon,
    DatabaseIcon,
    ExternalLinkIcon,
    GaugeIcon,
    GitCommitHorizontalIcon,
    LinkIcon,
    LoaderCircleIcon,
    MoonIcon,
    RocketIcon,
    RotateCwIcon,
    ServerIcon,
    TimerIcon,
    XCircleIcon,
    UnlinkIcon,
    ZapIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { BUILD_LOG } from '@/factory/demo';
import { applyServerStore, request, useSave } from '@/factory/api';
import {
    DEPLOY_STEPS,
    type CloudEnv,
    type DeployStatus,
    type Deployment,
} from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { Meter } from '@/factory/subscriptions';
import { cn } from '@/lib/utils';
import type { Store } from '@/factory/app';

const ENV_COLOR: Record<string, string> = {
    green: 'oklch(0.62 0.15 155)',
    yellow: 'oklch(0.8 0.15 90)',
    orange: 'oklch(0.7 0.16 55)',
    blue: 'oklch(0.6 0.17 255)',
    purple: 'oklch(0.58 0.18 300)',
    red: 'oklch(0.6 0.2 25)',
    cyan: 'oklch(0.72 0.12 210)',
    gray: 'oklch(0.6 0.01 260)',
};
const live = (s: DeployStatus) =>
    ![
        'deployment.succeeded',
        'deployment.failed',
        'build.failed',
        'failed',
        'cancelled',
    ].includes(s);
const CLOUD_PERIODS = ['6h', '24h', '3d', '7d', '30d'];

/** Short label for a metrics timestamp: the hour within a day, the date beyond. */
function timeLabel(x: string, period: string) {
    const d = new Date(x);
    if (Number.isNaN(d.getTime())) return x;
    return ['6h', '24h'].includes(period)
        ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function initials(name: string) {
    return (
        name
            .split(/[\s._-]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase())
            .join('') || '?'
    );
}
const metricCfg = {
    cpu: { label: 'CPU', color: 'var(--working)' },
    mem: { label: 'Memory', color: 'var(--claude)' },
} satisfies ChartConfig;

export function cloudHealth(store: Store, pid: string) {
    const app = store.cloud[pid];
    if (!app)
        return {
            label: 'Not deployed',
            note: 'No Laravel Cloud app',
            cls: 'text-muted-foreground',
            dot: 'bg-idle',
        };
    const prod = app.envs.find((e) => e.name === 'production') ?? app.envs[0];
    if (!prod)
        return {
            label: 'No environments',
            note: app.app,
            cls: 'text-muted-foreground',
            dot: 'bg-idle',
        };
    const latest = app.deployments.find((d) => d.env === prod.id);
    const failing =
        prod.probes.some((p) => !p.ok) ||
        prod.scheduled.some((s) => !s.ok) ||
        (prod.queue?.failed ?? 0) > 5;
    const deploying = app.deployments.some(
        (d) => d.env === prod.id && live(d.status),
    );
    if (deploying)
        return {
            label: 'Deploying',
            note: 'production',
            cls: 'text-needs',
            dot: 'bg-needs status-glow',
        };
    if (
        app.linked &&
        latest &&
        ['build.failed', 'deployment.failed', 'failed'].includes(latest.status)
    )
        return {
            label: 'Deploy failed',
            note: `${prod.name} · ${latest.commit}`,
            cls: 'text-blocked',
            dot: 'bg-blocked',
        };
    if (failing)
        return {
            label: 'Degraded',
            note:
                prod.scheduled
                    .find((s) => !s.ok)
                    ?.cmd.replace('php artisan ', '') + ' failing',
            cls: 'text-needs',
            dot: 'bg-needs',
        };
    return {
        label: 'Healthy',
        note:
            prod.instances[0]?.replicas !== undefined
                ? `${prod.domain} · ${prod.instances[0].replicas} replicas`
                : prod.domain || prod.name,
        cls: 'text-done',
        dot: 'bg-done',
    };
}

function DeployBadge({ s }: { s: DeployStatus }) {
    const ok = s === 'deployment.succeeded',
        bad = s.endsWith('failed'),
        run = live(s);
    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 rounded-full font-normal whitespace-nowrap',
                ok && 'border-done/40 text-done',
                bad && 'border-blocked/40 text-blocked',
                run && 'border-needs/40 text-needs',
            )}
        >
            {ok ? (
                <CheckCircle2Icon className="size-3.5" />
            ) : bad ? (
                <XCircleIcon className="size-3.5" />
            ) : (
                <LoaderCircleIcon className="size-3.5 animate-spin" />
            )}
            {ok
                ? 'Deployed'
                : bad
                  ? s === 'build.failed'
                      ? 'Build failed'
                      : 'Deploy failed'
                  : s.startsWith('build')
                    ? 'Building'
                    : s === 'pending'
                      ? 'Queued'
                      : 'Deploying'}
        </Badge>
    );
}

export function CloudPanel({
    store,
    bump,
    pid,
}: {
    store: Store;
    bump: () => void;
    pid: string;
}) {
    const app = store.cloud[pid];
    const save = useSave();
    const [period, setPeriod] = useState('24h');
    const [target, setTarget] = useState('prod');
    const [series, setSeries] = useState<{
        period: string;
        cpu: number[];
        mem: number[];
        times: string[];
    } | null>(null);
    const [logsFor, setLogsFor] = useState<Deployment | null>(null);
    const linked = !!app?.linked;
    const anyLive = !!app?.deployments.some((d) => live(d.status));

    // A linked app: refresh from Laravel Cloud every few seconds while a deployment runs.
    useEffect(() => {
        if (!linked || !anyLive) return;
        const t = setInterval(() => {
            if (document.hidden) return;
            request(
                'POST',
                `/factory/projects/${encodeURIComponent(pid)}/cloud/refresh`,
            )
                .then((res) => {
                    applyServerStore(store, res.store);
                    bump();
                })
                .catch(() => {
                    /* try again on the next tick */
                });
        }, 6000);
        return () => clearInterval(t);
    }, [linked, anyLive, pid, store, bump]);

    // Sample data: simulate live deployment progress (what `cloud deploy:monitor` shows)
    useEffect(() => {
        const t = setInterval(() => {
            if (!app || app.linked) return;
            let changed = false;
            app.deployments.forEach((d) => {
                if (!live(d.status)) return;
                const step = ((d as Deployment & { step?: number }).step =
                    ((d as Deployment & { step?: number }).step ?? 2) + 1);
                const env = app.envs.find((e) => e.id === d.env)!;
                d.status =
                    step < 1
                        ? 'pending'
                        : step <= 5
                          ? 'build.running'
                          : step === 6
                            ? 'build.succeeded'
                            : step <= 8
                              ? 'deployment.running'
                              : 'deployment.succeeded';
                env.status =
                    d.status === 'deployment.succeeded'
                        ? 'running'
                        : 'deploying';
                if (d.status === 'deployment.succeeded') {
                    d.duration = '2m 36s';
                    toast.success(`Deployed to ${env.name}`, {
                        description: `${d.commit} · ${d.message}`,
                    });
                    if (env.id === 'pr423') env.probes[1].ok = true;
                }
                changed = true;
            });
            if (changed) bump();
        }, 1400);
        return () => clearInterval(t);
    }, [app, bump]);

    if (!app)
        return (
            <div className="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                <CloudIcon className="size-8 text-muted-foreground" />
                <div className="text-lg font-semibold">
                    Not on Laravel Cloud
                </div>
                <p className="max-w-md text-muted-foreground">
                    Link a Laravel Cloud application to see its environments,
                    deployments, health and logs here, and deploy from Droids.
                </p>
                <LinkCloudDialog
                    store={store}
                    pid={pid}
                    repo={store.projects.find((p) => p.id === pid)?.repo}
                />
            </div>
        );

    const prod = app.envs[0];
    const running = app.deployments.find((d) => live(d.status));
    const tgt = app.envs.some((e) => e.id === target)
        ? target
        : (app.envs[0]?.id ?? '');
    const project = store.projects.find((p) => p.id === pid);
    const deploy = () => {
        const env = app.envs.find((e) => e.id === tgt);
        if (!env) return;
        if (linked) {
            void save(
                'POST',
                `/factory/projects/${encodeURIComponent(pid)}/cloud/deploy`,
                { environment: env.id },
            ).then(
                (res) =>
                    res &&
                    toast(`Deploying ${env.name}`, {
                        description: 'Laravel Cloud is building it',
                    }),
            );
            return;
        }
        app.deployments.unshift({
            id: `d${Date.now() % 100000}`,
            env: env.id,
            status: 'pending',
            commit: '4fd2b77',
            message:
                env.id === 'prod'
                    ? 'fix(checkout): apply gift card to discounted subtotal (#421)'
                    : 'Redeploy',
            author: 'vtalbot',
            branch: env.branch,
            started: 'just now',
            duration: '',
            via: 'Manual deploy',
        });
        (app.deployments[0] as Deployment & { step?: number }).step = -1;
        env.status = 'deploying';
        bump();
        toast(`Deploying ${env.name}`, {
            description: 'cloud deploy · monitoring progress',
        });
    };
    const shown =
        linked && series && series.period === period
            ? series
            : { cpu: app.cpu, mem: app.mem, times: app.times ?? [] };
    const cpu = shown.cpu.map((v, h) => ({
        t: shown.times[h]
            ? timeLabel(shown.times[h], linked ? period : '24h')
            : `${String(h).padStart(2, '0')}:00`,
        cpu: v,
        mem: shown.mem[h],
    }));
    const changePeriod = (v: string) => {
        setPeriod(v);
        if (!linked || v === '24h') return;
        request(
            'GET',
            `/factory/projects/${encodeURIComponent(pid)}/cloud/metrics?period=${v}`,
        )
            .then((res) =>
                setSeries({
                    period: v,
                    cpu: res.cpu as unknown as number[],
                    mem: res.mem as unknown as number[],
                    times: res.times as unknown as string[],
                }),
            )
            .catch((e: Error) => toast.error(e.message));
    };
    const handToDroid = async (d: Deployment, env: CloudEnv) => {
        const res = await save(
            'POST',
            `/factory/projects/${encodeURIComponent(pid)}/tasks`,
            {
                tasks: [
                    {
                        title: `Fix failed deployment to ${env.name}: ${d.message}`.slice(
                            0,
                            200,
                        ),
                        labels: ['bug'],
                        body: `Laravel Cloud could not deploy ${d.commit} (${d.branch}) to ${env.name}.\n\n\`\`\`\n${d.failure ?? ''}\n\`\`\``,
                    },
                ],
            },
        );
        if (!res) return;
        const n = (res.numbers as number[])[0];
        toast.success(`Created #${n} in the Backlog`, {
            description: 'Assign a builder to have a droid fix it',
        });
    };
    const who = (name: string) =>
        store.droids.find((d) => name.startsWith(d.unit));

    return (
        <div className="grid gap-5">
            <div className="flex flex-wrap items-center gap-3">
                <div className="mr-auto min-w-0">
                    <div className="flex items-center gap-2 font-semibold">
                        <CloudIcon className="size-4" />
                        {app.app}
                        <span className="font-normal text-muted-foreground">
                            · {app.org} · {app.region}
                        </span>
                    </div>
                    <div className="text-sm text-muted-foreground">
                        {linked ? (
                            <>
                                {prod?.pushToDeploy && prod.branch && (
                                    <>
                                        Push-to-deploy on{' '}
                                        <span className="font-mono">
                                            {prod.branch}
                                        </span>{' '}
                                        ·{' '}
                                    </>
                                )}
                                Synced{' '}
                                {app.synced
                                    ? new Date(app.synced).toLocaleTimeString(
                                          [],
                                          {
                                              hour: '2-digit',
                                              minute: '2-digit',
                                          },
                                      )
                                    : 'never'}
                            </>
                        ) : (
                            <>
                                Usage this period ${(app.usage ?? 0).toFixed(2)}{' '}
                                · push-to-deploy on{' '}
                                <span className="font-mono">main</span>
                            </>
                        )}
                    </div>
                </div>
                <Select value={tgt} onValueChange={setTarget}>
                    <SelectTrigger
                        className="h-9 w-48"
                        aria-label="Environment"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {app.envs.map((e) => (
                            <SelectItem key={e.id} value={e.id}>
                                {e.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    onClick={deploy}
                    disabled={
                        !tgt ||
                        !!app.deployments.find(
                            (d) => d.env === tgt && live(d.status),
                        )
                    }
                >
                    <RocketIcon />
                    Deploy
                </Button>
                <Button
                    variant="outline"
                    onClick={() =>
                        linked
                            ? window.open(
                                  'https://cloud.laravel.com',
                                  '_blank',
                                  'noopener',
                              )
                            : toast('Opens cloud.laravel.com', {
                                  description: `${app.org} / ${app.app}`,
                              })
                    }
                >
                    <ExternalLinkIcon />
                    Open in Cloud
                </Button>
                {linked && (
                    <>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Refresh from Laravel Cloud"
                            onClick={() =>
                                void save(
                                    'POST',
                                    `/factory/projects/${encodeURIComponent(pid)}/cloud/refresh`,
                                )
                            }
                        >
                            <RotateCwIcon />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Unlink ${app.app} from ${project?.name ?? 'this project'}`}
                            onClick={() =>
                                void save(
                                    'DELETE',
                                    `/factory/projects/${encodeURIComponent(pid)}/cloud`,
                                ).then(
                                    (res) =>
                                        res &&
                                        toast(`Unlinked ${app.app}`, {
                                            description:
                                                'Link it again any time from this tab',
                                        }),
                                )
                            }
                        >
                            <UnlinkIcon />
                        </Button>
                    </>
                )}
            </div>

            {running && (
                <LiveDeploy
                    d={running}
                    env={app.envs.find((e) => e.id === running.env)!}
                    who={who}
                    demo={!linked}
                />
            )}

            <div className="grid [grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))] gap-4">
                {app.envs.map((e) => (
                    <EnvCard key={e.id} e={e} />
                ))}
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <Card className="gap-3 py-4">
                    <CardHeader className="flex flex-row flex-wrap items-start gap-2 px-4">
                        <div className="mr-auto">
                            <CardTitle className="text-base">
                                {linked
                                    ? `${prod?.name ?? 'Default'} compute`
                                    : 'Production compute'}
                            </CardTitle>
                            <CardDescription>
                                CPU and memory, averaged across replicas (%)
                            </CardDescription>
                        </div>
                        <ToggleGroup
                            type="single"
                            size="sm"
                            variant="outline"
                            value={period}
                            onValueChange={(v) => v && changePeriod(v)}
                            aria-label="Metrics period"
                        >
                            {(linked
                                ? CLOUD_PERIODS
                                : ['1h', '6h', '24h', '7d', '30d']
                            ).map((x) => (
                                <ToggleGroupItem
                                    key={x}
                                    value={x}
                                    className="px-2.5"
                                >
                                    {x}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </CardHeader>
                    <CardContent className="px-2">
                        {cpu.length === 0 && (
                            <p className="px-2 text-sm text-muted-foreground">
                                No metrics yet. The API token may need
                                permission to read environment metrics.
                            </p>
                        )}
                        <ChartContainer
                            config={metricCfg}
                            className="aspect-auto h-[340px] w-full"
                        >
                            <LineChart
                                data={cpu}
                                margin={{ left: 0, right: 12, top: 8 }}
                            >
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="t"
                                    tickLine={false}
                                    axisLine={false}
                                    tickMargin={8}
                                    interval="preserveStartEnd"
                                    minTickGap={24}
                                />
                                <YAxis
                                    width={30}
                                    domain={[0, 100]}
                                    tickLine={false}
                                    axisLine={false}
                                />
                                <ChartTooltip
                                    content={<ChartTooltipContent />}
                                />
                                <Line
                                    dataKey="cpu"
                                    type="monotone"
                                    stroke="var(--color-cpu)"
                                    strokeWidth={2}
                                    dot={false}
                                />
                                <Line
                                    dataKey="mem"
                                    type="monotone"
                                    stroke="var(--color-mem)"
                                    strokeWidth={2}
                                    dot={false}
                                />
                                <ChartLegend content={<ChartLegendContent />} />
                            </LineChart>
                        </ChartContainer>
                    </CardContent>
                </Card>
                <Card className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ActivityIcon className="size-4" />
                            {linked
                                ? `${prod?.name ?? ''} health`
                                : 'Production health'}
                        </CardTitle>
                        <CardDescription>{prod?.domain}</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-4 text-sm">
                        {linked && prod && (
                            <LatestDeploy
                                d={app.deployments.find(
                                    (d) => d.env === prod.id,
                                )}
                                env={prod}
                            />
                        )}
                        <ul className="grid gap-1.5">
                            {prod?.probes.map((p) => (
                                <li
                                    key={p.kind}
                                    className="flex items-center gap-2"
                                >
                                    {p.ok ? (
                                        <CheckCircle2Icon className="size-4 text-done" />
                                    ) : (
                                        <XCircleIcon className="size-4 text-blocked" />
                                    )}
                                    <span className="capitalize">{p.kind}</span>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {p.target}
                                    </span>
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {p.last}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {prod?.db && (
                            <div className="grid gap-2 rounded-lg border p-3">
                                <div className="flex items-center gap-2 font-medium">
                                    <DatabaseIcon className="size-4" />
                                    {prod.db.name}
                                    <span className="ml-auto text-xs font-normal text-muted-foreground">
                                        {prod.db.engine} · {prod.db.preset}
                                    </span>
                                </div>
                                <Meter
                                    label="Storage"
                                    used={prod.db.storage}
                                    note={`Connections ${prod.db.conns}`}
                                />
                            </div>
                        )}
                        {prod?.cache && (
                            <div className="grid gap-2 rounded-lg border p-3">
                                <div className="flex items-center gap-2 font-medium">
                                    <ZapIcon className="size-4" />
                                    {prod.cache.name}
                                    <span className="ml-auto text-xs font-normal text-muted-foreground">
                                        {prod.cache.engine} · hit rate{' '}
                                        {Math.round(prod.cache.hit * 100)}%
                                    </span>
                                </div>
                                <Meter label="Memory" used={prod.cache.mem} />
                            </div>
                        )}
                        {prod?.queue && (
                            <div className="flex items-center gap-2 rounded-lg border p-3">
                                <GaugeIcon className="size-4" />
                                <span>Queue: {prod.queue.pending} pending</span>
                                <span
                                    className={cn(
                                        'ml-1',
                                        prod.queue.failed
                                            ? 'text-blocked'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    · {prod.queue.failed} failed
                                </span>
                                {prod.queue.failed > 0 && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="ml-auto h-7"
                                        onClick={() => {
                                            prod.queue!.failed = 0;
                                            bump();
                                            toast.success(
                                                'Retrying failed jobs',
                                            );
                                        }}
                                    >
                                        <RotateCwIcon />
                                        Retry
                                    </Button>
                                )}
                            </div>
                        )}
                        {(prod?.scheduled.length ?? 0) > 0 && (
                            <div className="grid gap-1.5">
                                <div className="flex items-center gap-2 font-medium">
                                    <TimerIcon className="size-4" />
                                    Scheduled commands
                                </div>
                                {prod?.scheduled.map((s) => (
                                    <div
                                        key={s.cmd}
                                        className="flex items-center gap-2 text-xs"
                                    >
                                        {s.ok ? (
                                            <CheckCircle2Icon className="size-3.5 text-done" />
                                        ) : (
                                            <XCircleIcon className="size-3.5 text-blocked" />
                                        )}
                                        <span className="truncate font-mono">
                                            {s.cmd.replace('php artisan ', '')}
                                        </span>
                                        <span className="font-mono text-muted-foreground">
                                            {s.cron}
                                        </span>
                                        <span
                                            className={cn(
                                                'ml-auto whitespace-nowrap',
                                                s.ok
                                                    ? 'text-muted-foreground'
                                                    : 'text-blocked',
                                            )}
                                        >
                                            {s.last}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="gap-2 py-4">
                <CardHeader className="px-4">
                    <CardTitle className="text-base">Deployments</CardTitle>
                    <CardDescription>
                        {linked
                            ? 'Latest deployments of each environment'
                            : 'Push-to-deploy on merge; droids are credited when their PR triggers it'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-0">
                    <div className="overflow-x-auto">
                        <Table className="min-w-[760px]">
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-4">
                                        Status
                                    </TableHead>
                                    <TableHead>Environment</TableHead>
                                    <TableHead>Commit</TableHead>
                                    <TableHead>By</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead className="pr-4 text-right">
                                        Duration
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {app.deployments.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="pl-4 text-sm text-muted-foreground"
                                        >
                                            No deployments yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {app.deployments.map((d) => {
                                    const env = app.envs.find(
                                        (e) => e.id === d.env,
                                    );
                                    if (!env) return null;
                                    const dr = who(d.author);
                                    return (
                                        <Fragment key={d.id}>
                                            <TableRow>
                                                <TableCell className="pl-4">
                                                    <DeployBadge s={d.status} />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="flex items-center gap-1.5 whitespace-nowrap">
                                                        <span
                                                            className="size-2 rounded-full"
                                                            style={{
                                                                background:
                                                                    ENV_COLOR[
                                                                        env
                                                                            .color
                                                                    ],
                                                            }}
                                                        />
                                                        {env.name}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="max-w-[300px] whitespace-normal">
                                                    <div className="flex items-center gap-1.5">
                                                        <GitCommitHorizontalIcon className="size-3.5 text-muted-foreground" />
                                                        <span className="font-mono text-xs">
                                                            {d.commit}
                                                        </span>
                                                    </div>
                                                    <div className="text-sm">
                                                        {d.message}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {d.via}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="flex items-center gap-1.5 text-sm whitespace-nowrap">
                                                        {dr ? (
                                                            <DroidAvatar
                                                                provider={
                                                                    dr.provider
                                                                }
                                                                status={
                                                                    dr.status
                                                                }
                                                                seed={dr.seed}
                                                                className="size-5"
                                                            />
                                                        ) : (
                                                            <span className="grid size-5 place-items-center rounded-full bg-primary text-[9px] font-semibold text-primary-foreground">
                                                                {initials(
                                                                    d.author,
                                                                )}
                                                            </span>
                                                        )}
                                                        {d.author}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                                    {d.started}
                                                </TableCell>
                                                <TableCell className="pr-4 text-right text-sm tabular-nums">
                                                    {d.duration || '…'}
                                                </TableCell>
                                            </TableRow>
                                            {d.failure && (
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell
                                                        colSpan={6}
                                                        className="px-4 pt-0"
                                                    >
                                                        <div className="grid gap-2 rounded-lg border border-blocked/30 bg-blocked/5 p-3">
                                                            <pre className="overflow-x-auto font-mono text-xs whitespace-pre-wrap text-blocked">
                                                                {d.failure}
                                                            </pre>
                                                            <div className="flex gap-2">
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="h-7"
                                                                    onClick={() =>
                                                                        linked
                                                                            ? void handToDroid(
                                                                                  d,
                                                                                  env,
                                                                              )
                                                                            : toast.success(
                                                                                  'CL-07 Rivet picked it up',
                                                                                  {
                                                                                      description:
                                                                                          'New issue #422: migration order_events already exists',
                                                                                  },
                                                                              )
                                                                    }
                                                                >
                                                                    Hand to a
                                                                    droid
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-7"
                                                                    onClick={() =>
                                                                        linked
                                                                            ? setLogsFor(
                                                                                  d,
                                                                              )
                                                                            : toast(
                                                                                  'cloud deployment:logs ' +
                                                                                      d.id,
                                                                              )
                                                                    }
                                                                >
                                                                    Full logs
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Card className="gap-2 py-4">
                <CardHeader className="flex flex-row items-center gap-2 px-4">
                    <CardTitle className="mr-auto text-base">
                        {linked
                            ? `${prod?.name ?? ''} logs`
                            : 'Production logs'}
                    </CardTitle>
                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        {linked ? (
                            'Last hour, at the last sync'
                        ) : (
                            <>
                                <span className="status-glow size-2 rounded-full bg-done" />
                                Live tail
                            </>
                        )}
                    </span>
                </CardHeader>
                <CardContent className="px-4">
                    <div className="overflow-x-auto rounded-lg bg-muted/60 p-3 font-mono text-xs leading-relaxed">
                        {app.logs.length === 0 && (
                            <div className="text-muted-foreground">
                                No log lines in the last hour.
                            </div>
                        )}
                        {app.logs.map((l, k) => (
                            <div
                                key={k}
                                className={cn(
                                    'whitespace-pre',
                                    l.includes('ERROR') && 'text-blocked',
                                    l.includes('WARN') && 'text-needs',
                                )}
                            >
                                {l}
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
            {linked && (
                <DeploymentLogs
                    pid={pid}
                    d={logsFor}
                    onClose={() => setLogsFor(null)}
                />
            )}
        </div>
    );
}

function LatestDeploy({ d, env }: { d?: Deployment; env: CloudEnv }) {
    if (!d)
        return (
            <p className="text-muted-foreground">
                {env.name} has no deployments yet.
            </p>
        );
    return (
        <div className="grid gap-1.5 rounded-lg border p-3">
            <div className="flex items-center gap-2">
                <span className="font-medium">Latest deployment</span>
                <span className="ml-auto">
                    <DeployBadge s={d.status} />
                </span>
            </div>
            <div className="text-xs text-muted-foreground">
                <span className="font-mono">{d.commit}</span> {d.message} ·{' '}
                {d.started}
            </div>
        </div>
    );
}

type LogStep = { phase: string; step: string; status: string; output: string };

function DeploymentLogs({
    pid,
    d,
    onClose,
}: {
    pid: string;
    d: Deployment | null;
    onClose: () => void;
}) {
    const [steps, setSteps] = useState<LogStep[] | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        if (!d) return;
        setSteps(null);
        setError('');
        request(
            'GET',
            `/factory/projects/${encodeURIComponent(pid)}/cloud/deployments/${encodeURIComponent(d.id)}/logs`,
        )
            .then((res) => setSteps(res.steps as unknown as LogStep[]))
            .catch((e: Error) => setError(e.message));
    }, [d, pid]);
    return (
        <Dialog open={!!d} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Deployment logs</DialogTitle>
                    <DialogDescription>
                        <span className="font-mono">{d?.commit}</span>{' '}
                        {d?.message}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[60vh] gap-3 overflow-y-auto">
                    {error && <p className="text-sm text-blocked">{error}</p>}
                    {!steps && !error && (
                        <p className="text-sm text-muted-foreground">
                            Loading…
                        </p>
                    )}
                    {steps?.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Laravel Cloud has no logs for this deployment.
                        </p>
                    )}
                    {steps?.map((s, i) => (
                        <div key={i} className="grid gap-1">
                            <div className="flex items-center gap-2 text-sm">
                                <span className="text-xs text-muted-foreground capitalize">
                                    {s.phase}
                                </span>
                                <span className="font-medium">{s.step}</span>
                                <span
                                    className={cn(
                                        'ml-auto text-xs',
                                        s.status.includes('fail')
                                            ? 'text-blocked'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {s.status}
                                </span>
                            </div>
                            {s.output && (
                                <pre className="overflow-x-auto rounded-md bg-muted/60 p-2 font-mono text-[11px] whitespace-pre-wrap">
                                    {s.output}
                                </pre>
                            )}
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function LinkCloudDialog({
    store,
    pid,
    repo,
}: {
    store: Store;
    pid: string;
    repo?: string;
}) {
    const save = useSave();
    const [open, setOpen] = useState(false);
    const [apps, setApps] =
        useState<
            { id: string; name: string; region: string; repo: string | null }[]
        >();
    const [error, setError] = useState('');
    const [busy, setBusy] = useState('');
    useEffect(() => {
        if (!open) return;
        setApps(undefined);
        setError('');
        request(
            'GET',
            `/factory/cloud/applications?repo=${encodeURIComponent(repo ?? '')}`,
        )
            .then((res) => setApps(res.applications as unknown as typeof apps))
            .catch((e: Error) => setError(e.message));
    }, [open, repo]);
    if (!store.cloudConnected)
        return (
            <Button variant="outline" asChild>
                <a href="/settings/cloud">
                    <LinkIcon />
                    Connect Laravel Cloud in Settings
                </a>
            </Button>
        );
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <LinkIcon />
                Link a Laravel Cloud app
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Link a Laravel Cloud app</DialogTitle>
                        <DialogDescription>
                            Apps deployed from {repo ?? 'this repository'} are
                            listed first.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        {error && (
                            <p className="text-sm text-blocked">{error}</p>
                        )}
                        {!apps && !error && (
                            <p className="text-sm text-muted-foreground">
                                Loading…
                            </p>
                        )}
                        {apps?.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                The API token can't see any applications.
                            </p>
                        )}
                        {apps?.map((a) => (
                            <button
                                key={a.id}
                                type="button"
                                disabled={!!busy}
                                className="flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-accent disabled:opacity-60"
                                onClick={async () => {
                                    setBusy(a.id);
                                    const res = await save(
                                        'POST',
                                        `/factory/projects/${encodeURIComponent(pid)}/cloud`,
                                        { application: a.id },
                                    );
                                    setBusy('');
                                    if (!res) return;
                                    setOpen(false);
                                    toast.success(`Linked ${a.name}`);
                                }}
                            >
                                <CloudIcon className="size-4 text-muted-foreground" />
                                <span className="font-medium">{a.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {a.region}
                                </span>
                                {a.repo && (
                                    <span
                                        className={cn(
                                            'ml-auto font-mono text-xs',
                                            a.repo === repo
                                                ? 'text-done'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {a.repo}
                                    </span>
                                )}
                                {busy === a.id && (
                                    <LoaderCircleIcon className="size-4 animate-spin" />
                                )}
                            </button>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

function LiveDeploy({
    d,
    env,
    who,
    demo,
}: {
    d: Deployment;
    env: CloudEnv;
    who: (n: string) => Store['droids'][number] | undefined;
    demo: boolean;
}) {
    const step = (d as Deployment & { step?: number }).step ?? 2;
    const cur = DEPLOY_STEPS.findIndex((s) => s.from.includes(d.status));
    const dr = who(d.author);
    return (
        <Card className="gap-4 border-needs/40 py-4">
            <CardHeader className="flex flex-row flex-wrap items-center gap-3 px-4">
                <RocketIcon className="size-5 text-needs" />
                <div className="mr-auto min-w-0">
                    <CardTitle className="text-base">
                        Deploying to {env.name}
                    </CardTitle>
                    <CardDescription className="flex flex-wrap items-center gap-1.5">
                        <span className="font-mono">{d.commit}</span>
                        {d.message}
                        {dr && (
                            <>
                                <span>·</span>
                                <DroidAvatar
                                    provider={dr.provider}
                                    status={dr.status}
                                    seed={dr.seed}
                                    className="size-4"
                                />
                                {dr.unit}
                            </>
                        )}
                    </CardDescription>
                </div>
                <DeployBadge s={d.status} />
            </CardHeader>
            <CardContent className="grid gap-4 px-4">
                <ol className="grid grid-cols-4 gap-2">
                    {DEPLOY_STEPS.map((s, i) => (
                        <li key={s.key} className="grid gap-1.5">
                            <div
                                className={cn(
                                    'h-1.5 rounded-full',
                                    i < cur
                                        ? 'bg-done'
                                        : i === cur
                                          ? 'status-glow bg-needs'
                                          : 'bg-muted',
                                )}
                            />
                            <span
                                className={cn(
                                    'flex items-center gap-1 text-xs',
                                    i <= cur
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {i < cur ? (
                                    <CheckCircle2Icon className="size-3.5 text-done" />
                                ) : i === cur ? (
                                    <LoaderCircleIcon className="size-3.5 animate-spin text-needs" />
                                ) : (
                                    <CircleDashedIcon className="size-3.5" />
                                )}
                                {s.label}
                            </span>
                        </li>
                    ))}
                </ol>
                {demo && (
                    <div className="rounded-lg bg-muted/60 p-3 font-mono text-xs leading-relaxed">
                        {BUILD_LOG.slice(
                            0,
                            Math.max(1, Math.min(BUILD_LOG.length, step + 1)),
                        ).map((l, i, a) => (
                            <div
                                key={l}
                                className={cn(
                                    'flex gap-2',
                                    i === a.length - 1
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {i === a.length - 1 ? (
                                    <LoaderCircleIcon className="mt-0.5 size-3 shrink-0 animate-spin" />
                                ) : (
                                    <CheckCircle2Icon className="mt-0.5 size-3 shrink-0 text-done" />
                                )}
                                {l}
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function EnvCard({ e }: { e: CloudEnv }) {
    const st = {
        running: ['Running', 'border-done/40 text-done'],
        deploying: ['Deploying', 'border-needs/40 text-needs'],
        hibernating: ['Hibernating', 'text-muted-foreground'],
        stopped: ['Stopped', 'text-muted-foreground'],
    }[e.status];
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="flex items-center gap-2 text-base">
                    <span
                        className="size-2.5 rounded-full"
                        style={{ background: ENV_COLOR[e.color] }}
                    />
                    {e.name}
                    <Badge
                        variant="outline"
                        className={cn(
                            'ml-auto gap-1 rounded-full font-normal',
                            st[1],
                        )}
                    >
                        {e.status === 'hibernating' && (
                            <MoonIcon className="size-3" />
                        )}
                        {st[0]}
                    </Badge>
                </CardTitle>
                <CardDescription className="truncate font-mono text-xs">
                    {e.domain}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 px-4 text-sm">
                <div className="flex flex-wrap gap-1.5 text-xs">
                    <Badge
                        variant="secondary"
                        className="rounded-full font-normal"
                    >
                        PHP {e.php}
                    </Badge>
                    {e.octane && (
                        <Badge
                            variant="secondary"
                            className="rounded-full font-normal"
                        >
                            Octane
                        </Badge>
                    )}
                    {e.hibernation && (
                        <Badge
                            variant="secondary"
                            className="rounded-full font-normal"
                        >
                            Scale to zero
                        </Badge>
                    )}
                    <Badge
                        variant="secondary"
                        className="rounded-full font-mono font-normal"
                    >
                        {e.branch}
                    </Badge>
                </div>
                {e.instances.length === 0 && (
                    <div className="text-xs text-muted-foreground">
                        No compute reported
                    </div>
                )}
                {e.instances.map((i) => (
                    <div
                        key={i.name}
                        className="grid gap-1.5 rounded-lg border p-2.5"
                    >
                        <div className="flex items-center gap-2">
                            <ServerIcon className="size-3.5 text-muted-foreground" />
                            <span className="font-medium">{i.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {i.type}
                                {i.queue ? ` · ${i.queue}` : ''}
                            </span>
                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                {i.replicas !== undefined
                                    ? `${i.replicas} of `
                                    : 'replicas '}
                                {i.min}–{i.max}
                                {i.scaling === 'auto' ? ' · autoscale' : ''}
                            </span>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {i.size}
                        </div>
                        {e.status === 'hibernating' ? (
                            <div className="text-xs text-muted-foreground">
                                Asleep · wakes on the next request
                            </div>
                        ) : i.cpu === undefined ||
                          i.mem === undefined ? null : (
                            <div className="grid grid-cols-2 gap-3">
                                {(
                                    [
                                        ['CPU', i.cpu],
                                        ['Memory', i.mem],
                                    ] as const
                                ).map(([l, v]) => (
                                    <div key={l} className="grid gap-1">
                                        <div className="flex justify-between text-xs">
                                            <span className="text-muted-foreground">
                                                {l}
                                            </span>
                                            <span className="tabular-nums">
                                                {v}%
                                            </span>
                                        </div>
                                        <div className="h-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full',
                                                    v >= 75
                                                        ? 'bg-needs'
                                                        : 'bg-working',
                                                )}
                                                style={{ width: `${v}%` }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
