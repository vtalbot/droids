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
    LoaderCircleIcon,
    MoonIcon,
    RocketIcon,
    RotateCwIcon,
    ServerIcon,
    TimerIcon,
    XCircleIcon,
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
import { BUILD_LOG } from '@/factory/demo';
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
        'cancelled',
    ].includes(s);
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
    const prod = app.envs.find((e) => e.name === 'production')!;
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
        note: `${prod.domain} · ${prod.instances[0].replicas} replicas`,
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
    const [period, setPeriod] = useState('24h');
    const [target, setTarget] = useState('prod');

    // simulate live deployment progress (what `cloud deploy:monitor` shows)
    useEffect(() => {
        const t = setInterval(() => {
            if (!app) return;
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
                    This project ships as a CLI, so there's nothing to host.
                    Link a Laravel Cloud application to see deployments, health
                    and logs here.
                </p>
                <Button
                    variant="outline"
                    onClick={() =>
                        toast('Sign in to Laravel Cloud', {
                            description:
                                'Uses an API token from cloud auth:token',
                        })
                    }
                >
                    Link a Laravel Cloud app
                </Button>
            </div>
        );

    const prod = app.envs[0];
    const running = app.deployments.find((d) => live(d.status));
    const deploy = () => {
        const env = app.envs.find((e) => e.id === target)!;
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
    const cpu = app.cpu.map((v, h) => ({
        t: `${String(h).padStart(2, '0')}:00`,
        cpu: v,
        mem: app.mem[h],
    }));
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
                        Usage this period ${app.usage.toFixed(2)} ·
                        push-to-deploy on{' '}
                        <span className="font-mono">main</span>
                    </div>
                </div>
                <Select value={target} onValueChange={setTarget}>
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
                        !!app.deployments.find(
                            (d) => d.env === target && live(d.status),
                        )
                    }
                >
                    <RocketIcon />
                    Deploy
                </Button>
                <Button
                    variant="outline"
                    onClick={() =>
                        toast('Opens cloud.laravel.com', {
                            description: `${app.org} / ${app.app}`,
                        })
                    }
                >
                    <ExternalLinkIcon />
                    Open in Cloud
                </Button>
            </div>

            {running && (
                <LiveDeploy
                    d={running}
                    env={app.envs.find((e) => e.id === running.env)!}
                    who={who}
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
                                Production compute
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
                            onValueChange={(v) => v && setPeriod(v)}
                            aria-label="Metrics period"
                        >
                            {['1h', '6h', '24h', '7d', '30d'].map((x) => (
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
                                    interval={5}
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
                            Production health
                        </CardTitle>
                        <CardDescription>{prod.domain}</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-4 text-sm">
                        <ul className="grid gap-1.5">
                            {prod.probes.map((p) => (
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
                        {prod.db && (
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
                        {prod.cache && (
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
                        {prod.queue && (
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
                        <div className="grid gap-1.5">
                            <div className="flex items-center gap-2 font-medium">
                                <TimerIcon className="size-4" />
                                Scheduled commands
                            </div>
                            {prod.scheduled.map((s) => (
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
                    </CardContent>
                </Card>
            </div>

            <Card className="gap-2 py-4">
                <CardHeader className="px-4">
                    <CardTitle className="text-base">Deployments</CardTitle>
                    <CardDescription>
                        Push-to-deploy on merge; droids are credited when their
                        PR triggers it
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
                                {app.deployments.map((d) => {
                                    const env = app.envs.find(
                                        (e) => e.id === d.env,
                                    )!;
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
                                                                VT
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
                                                                        toast.success(
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
                                                                        toast(
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
                        Production logs
                    </CardTitle>
                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="status-glow size-2 rounded-full bg-done" />
                        Live tail
                    </span>
                </CardHeader>
                <CardContent className="px-4">
                    <div className="overflow-x-auto rounded-lg bg-muted/60 p-3 font-mono text-xs leading-relaxed">
                        {app.logs.map((l) => (
                            <div
                                key={l}
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
        </div>
    );
}

function LiveDeploy({
    d,
    env,
    who,
}: {
    d: Deployment;
    env: CloudEnv;
    who: (n: string) => Store['droids'][number] | undefined;
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
                                {i.replicas} of {i.min}–{i.max}
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
                        ) : (
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
