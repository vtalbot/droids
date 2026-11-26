import { useEffect, useRef, useState } from 'react';
import { CalendarClockIcon, CopyIcon, GaugeIcon, PlusIcon } from 'lucide-react';
import { toast } from 'sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DAYS,
    nowSlot,
    PROVIDERS,
    type Provider,
    type Subscription,
} from '@/factory/data';
import { EngineLogo } from '@/factory/logos';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';

export const activeSub = (store: Store, pv: Provider) => {
    const now = nowSlot();
    return store.subs.find(
        (s) => s.id === store.schedule[pv]?.[now.day]?.[now.hour],
    );
};
const pct = (x: number) => Math.round(x * 100);

export function Meter({
    label,
    used,
    note,
    className,
}: {
    label: string;
    used: number;
    note?: string;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <div className="flex items-baseline justify-between gap-2 text-sm">
                <span>{label}</span>
                <span className="font-medium tabular-nums">{pct(used)}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full',
                        used >= 0.85
                            ? 'bg-blocked'
                            : used >= 0.6
                              ? 'bg-needs'
                              : 'bg-done',
                    )}
                    style={{ width: `${pct(used)}%` }}
                />
            </div>
            {note && (
                <div className="text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}

export function SubscriptionsView({
    store,
    bump,
    onAdd,
}: {
    store: Store;
    bump: () => void;
    onAdd: () => void;
}) {
    return (
        <div>
            <div className="blueprint flex flex-wrap items-end justify-between gap-4 border-b px-4 pt-7 pb-5 md:px-6">
                <div className="min-w-0">
                    <h1 className="text-3xl font-semibold tracking-tight md:text-4xl">
                        Subscriptions
                    </h1>
                    <p className="mt-1.5 text-muted-foreground">
                        {
                            store.subs.filter((s) => s.provider === 'claude')
                                .length
                        }{' '}
                        Claude and{' '}
                        {
                            store.subs.filter((s) => s.provider === 'codex')
                                .length
                        }{' '}
                        Codex subscriptions power your droids. The schedule
                        decides which one each engine uses.
                    </p>
                </div>
                <Button onClick={onAdd}>
                    <PlusIcon />
                    Add subscription
                </Button>
            </div>
            <Tabs defaultValue="usage" className="p-4 md:p-6">
                <TabsList>
                    <TabsTrigger value="usage">
                        <GaugeIcon />
                        Usage
                    </TabsTrigger>
                    <TabsTrigger value="schedule">
                        <CalendarClockIcon />
                        Schedule
                    </TabsTrigger>
                </TabsList>
                <TabsContent value="usage" className="mt-4 grid gap-8">
                    <Usage store={store} bump={bump} />
                </TabsContent>
                <TabsContent value="schedule" className="mt-4 grid gap-8">
                    <Schedule store={store} bump={bump} />
                </TabsContent>
            </Tabs>
        </div>
    );
}

/** The commands that sign a worker's CLI in to this subscription, in its own login folder. */
export function loginCommand(sub: Pick<Subscription, 'id' | 'provider'>) {
    const dir = `~/.droids/logins/${sub.id}`;
    return sub.provider === 'codex'
        ? `mkdir -p ${dir}\nCODEX_HOME=${dir} codex login`
        : `mkdir -p ${dir}\nCLAUDE_CONFIG_DIR=${dir} claude\n# then type /login, sign in, and /exit`;
}

function WorkerLogin({ sub }: { sub: Subscription }) {
    const signedIn = (sub.signedInOn ?? []).length > 0;
    const cmd = loginCommand(sub);
    return (
        <details
            className="group rounded-lg border bg-muted/30 text-xs"
            open={!signedIn}
        >
            <summary className="cursor-pointer px-3 py-2 font-medium select-none">
                {signedIn ? 'Worker login' : 'Sign in on your worker'}
            </summary>
            <div className="grid gap-2 border-t px-3 py-2">
                <p className="text-muted-foreground">
                    Run this on the computer that runs{' '}
                    <code>php artisan droids:work</code>. The worker picks up
                    the login on its next poll. Slug:{' '}
                    <code className="font-mono">{sub.id}</code>
                </p>
                <pre className="overflow-x-auto rounded-md bg-muted p-2 font-mono text-[11px]">
                    {cmd}
                </pre>
                <Button
                    size="sm"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        void navigator.clipboard
                            .writeText(cmd)
                            .then(() => toast.success('Copied'))
                    }
                >
                    <CopyIcon />
                    Copy
                </Button>
            </div>
        </details>
    );
}

function Usage({ store, bump }: { store: Store; bump: () => void }) {
    const NOW = nowSlot();
    const save = useSave();
    return (['claude', 'codex'] as Provider[]).map((pv) => {
        const now = activeSub(store, pv);
        const droids = store.droids.filter(
            (d) => d.provider === pv && d.status !== 'idle',
        );
        return (
            <section key={pv} className="grid gap-3">
                <h2 className="flex items-center gap-2 font-semibold">
                    <EngineLogo provider={pv} className="size-5" />
                    {PROVIDERS[pv].label}
                </h2>
                <div className="grid [grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))] gap-4">
                    {store.subs
                        .filter((s) => s.provider === pv)
                        .map((s) => {
                            const live = now?.id === s.id;
                            return (
                                <Card
                                    key={s.id}
                                    className={cn(
                                        'gap-4 py-4',
                                        live && 'border-ring/60',
                                        store.paused[s.id] && 'opacity-60',
                                    )}
                                >
                                    <CardHeader className="flex flex-row items-start gap-3 px-4">
                                        <span
                                            className="mt-1 size-3 shrink-0 rounded-[3px]"
                                            style={{ background: s.color }}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <CardTitle className="text-base">
                                                {s.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {s.account} · {s.auth}
                                            </CardDescription>
                                        </div>
                                        {live ? (
                                            <Badge
                                                className="rounded-full bg-working/10 text-working"
                                                variant="outline"
                                            >
                                                In use now
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="rounded-full text-muted-foreground"
                                            >
                                                Standby
                                            </Badge>
                                        )}
                                    </CardHeader>
                                    <CardContent className="grid gap-4 px-4">
                                        {s.windows.map((w) => (
                                            <Meter
                                                key={w.label}
                                                label={w.label}
                                                used={w.used}
                                                note={w.resets}
                                            />
                                        ))}
                                        {s.spend && (
                                            <Meter
                                                label="Monthly spend cap"
                                                used={
                                                    s.spend.used / s.spend.cap
                                                }
                                                note={`$${s.spend.used.toFixed(2)} of $${s.spend.cap} · resets Dec 1`}
                                            />
                                        )}
                                        <div>
                                            <div className="mb-1.5 text-xs text-muted-foreground">
                                                Last 7 days, share of daily
                                                limit
                                            </div>
                                            <div
                                                className="flex h-14 items-end gap-1.5"
                                                role="img"
                                                aria-label={`Daily usage: ${s.week.map((v, k) => `${DAYS[k]} ${pct(v)}%`).join(', ')}`}
                                            >
                                                {s.week.map((v, k) => (
                                                    <div
                                                        key={k}
                                                        className="flex flex-1 flex-col items-center gap-1"
                                                    >
                                                        <div
                                                            className="w-full rounded-sm"
                                                            style={{
                                                                height: `${Math.max(3, v * 40)}px`,
                                                                background:
                                                                    s.color,
                                                                opacity:
                                                                    k ===
                                                                    NOW.day
                                                                        ? 1
                                                                        : 0.55,
                                                            }}
                                                        />
                                                        <span
                                                            className={cn(
                                                                'text-[11px] text-muted-foreground',
                                                                k === NOW.day &&
                                                                    'font-medium text-foreground',
                                                            )}
                                                        >
                                                            {DAYS[k].slice(
                                                                0,
                                                                2,
                                                            )}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                        {!s.api && <WorkerLogin sub={s} />}
                                    </CardContent>
                                    <CardFooter className="flex items-center gap-2 px-4 text-xs text-muted-foreground">
                                        {live ? (
                                            <>
                                                <span className="flex -space-x-1.5">
                                                    {droids
                                                        .slice(0, 5)
                                                        .map((d) => (
                                                            <DroidAvatar
                                                                key={d.id}
                                                                provider={
                                                                    d.provider
                                                                }
                                                                status={
                                                                    d.status
                                                                }
                                                                seed={d.seed}
                                                                className="size-5"
                                                            />
                                                        ))}
                                                </span>
                                                {droids.length} droids on it
                                            </>
                                        ) : (
                                            <span>Takes over per schedule</span>
                                        )}
                                        <span className="flex-1" />
                                        <Label
                                            htmlFor={`en-${s.id}`}
                                            className="text-xs font-normal"
                                        >
                                            Enabled
                                        </Label>
                                        <Switch
                                            id={`en-${s.id}`}
                                            checked={!store.paused[s.id]}
                                            onCheckedChange={(v) => {
                                                store.paused[s.id] = !v;
                                                bump();
                                                void save(
                                                    'PATCH',
                                                    `/factory/subscriptions/${encodeURIComponent(s.id)}`,
                                                    { enabled: v },
                                                );
                                                toast(
                                                    `${s.name} ${v ? 'enabled' : 'paused'}`,
                                                );
                                            }}
                                        />
                                    </CardFooter>
                                </Card>
                            );
                        })}
                </div>
            </section>
        );
    });
}

function Schedule({ store, bump }: { store: Store; bump: () => void }) {
    const NOW = nowSlot();
    const [brush, setBrush] = useState<Record<Provider, string>>(() => ({
        claude: store.subs.find((s) => s.provider === 'claude')?.id ?? '',
        codex: store.subs.find((s) => s.provider === 'codex')?.id ?? '',
    }));
    const painting = useRef<Provider | null>(null);
    const save = useSave();
    const saveRef = useRef(save);
    saveRef.current = save;
    const dirty = useRef(new Set<Provider>());
    useEffect(() => {
        const up = () => {
            painting.current = null;
            dirty.current.forEach((pv) => {
                void saveRef.current('PUT', `/factory/schedules/${pv}`, {
                    grid: store.schedule[pv],
                });
            });
            dirty.current.clear();
        };
        addEventListener('pointerup', up);
        return () => removeEventListener('pointerup', up);
    }, [store]);
    const paint = (pv: Provider, d: number, h: number) => {
        store.schedule[pv][d][h] = brush[pv] === 'off' ? '' : brush[pv];
        dirty.current.add(pv);
        bump();
    };
    const sub = (id: string) => store.subs.find((s) => s.id === id);

    return (
        <>
            {(['claude', 'codex'] as Provider[]).map((pv) => {
                const subs = store.subs.filter((s) => s.provider === pv);
                const hours = (id: string) =>
                    store.schedule[pv].flat().filter((x) => x === id).length;
                return (
                    <section key={pv} className="grid gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <EngineLogo provider={pv} className="size-5" />
                                {PROVIDERS[pv].label} schedule
                            </h2>
                            <span className="text-sm text-muted-foreground">
                                Pick a subscription, then click or drag across
                                hours.
                            </span>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                className="ml-auto"
                                value={brush[pv]}
                                onValueChange={(v) =>
                                    v && setBrush((b) => ({ ...b, [pv]: v }))
                                }
                                aria-label={`Brush for ${PROVIDERS[pv].label}`}
                            >
                                {subs.map((s) => (
                                    <ToggleGroupItem
                                        key={s.id}
                                        value={s.id}
                                        className="px-3"
                                    >
                                        <span
                                            className="size-2.5 rounded-[2px]"
                                            style={{ background: s.color }}
                                        />
                                        {s.name}
                                    </ToggleGroupItem>
                                ))}
                                <ToggleGroupItem value="off" className="px-3">
                                    <span className="size-2.5 rounded-[2px] border border-dashed border-muted-foreground" />
                                    Paused
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                        <div className="overflow-x-auto rounded-xl border p-3">
                            <div
                                className="grid min-w-[720px] [grid-template-columns:40px_repeat(24,minmax(0,1fr))] gap-1 select-none"
                                onPointerLeave={() => {
                                    painting.current = null;
                                }}
                            >
                                <span />
                                {Array.from({ length: 24 }, (_, h) => (
                                    <span
                                        key={h}
                                        className="text-center text-[11px] text-muted-foreground"
                                    >
                                        {h % 3 === 0
                                            ? h === 0
                                                ? '12a'
                                                : h < 12
                                                  ? `${h}a`
                                                  : h === 12
                                                    ? '12p'
                                                    : `${h - 12}p`
                                            : ''}
                                    </span>
                                ))}
                                {DAYS.map((day, d) => (
                                    <div key={day} className="contents">
                                        <span
                                            className={cn(
                                                'self-center text-xs text-muted-foreground',
                                                d === NOW.day &&
                                                    'font-semibold text-foreground',
                                            )}
                                        >
                                            {day}
                                        </span>
                                        {store.schedule[pv][d].map((id, h) => {
                                            const s = sub(id);
                                            const now =
                                                d === NOW.day && h === NOW.hour;
                                            return (
                                                <button
                                                    key={h}
                                                    type="button"
                                                    aria-label={`${day} ${h}:00 ${s ? s.name : 'paused'}`}
                                                    onPointerDown={(e) => {
                                                        e.preventDefault();
                                                        painting.current = pv;
                                                        paint(pv, d, h);
                                                    }}
                                                    onPointerEnter={() =>
                                                        painting.current ===
                                                            pv &&
                                                        paint(pv, d, h)
                                                    }
                                                    className={cn(
                                                        'h-6 rounded-[4px] transition-[filter] hover:brightness-110',
                                                        !s &&
                                                            'border border-dashed border-border bg-transparent',
                                                        now &&
                                                            'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                                                    )}
                                                    style={
                                                        s
                                                            ? {
                                                                  background:
                                                                      s.color,
                                                              }
                                                            : undefined
                                                    }
                                                />
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground">
                            {subs.map((s) => (
                                <span
                                    key={s.id}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        className="size-2.5 rounded-[2px]"
                                        style={{ background: s.color }}
                                    />
                                    {s.name} · {hours(s.id)} h/week
                                </span>
                            ))}
                            <span className="flex items-center gap-1.5">
                                <span className="size-2.5 rounded-[2px] border border-dashed border-muted-foreground" />
                                Paused · {hours('')} h/week
                            </span>
                            <span className="ml-auto">
                                Outlined cell is now (Wed, 10 PM)
                            </span>
                        </div>
                    </section>
                );
            })}
            <Card className="gap-3 py-4">
                <CardHeader className="px-4">
                    <CardTitle className="text-base">Fallback rules</CardTitle>
                    <CardDescription>
                        Applied on top of the schedule.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3 px-4">
                    {[
                        [
                            'r1',
                            'When a subscription reaches 90% of its 5-hour window, switch to the other subscription for that engine',
                            true,
                        ],
                        [
                            'r2',
                            "During paused hours, finish the current task but don't start new ones",
                            true,
                        ],
                        [
                            'r3',
                            'If both Claude subscriptions are exhausted, hand queued tasks to Codex',
                            false,
                        ],
                        ['r4', 'Never use the Codex API key on weekends', true],
                    ].map(([id, l, c]) => (
                        <div
                            key={id as string}
                            className="flex items-start justify-between gap-4"
                        >
                            <Label
                                htmlFor={id as string}
                                className="leading-snug font-normal"
                            >
                                {l as string}
                            </Label>
                            <Switch
                                id={id as string}
                                defaultChecked={c as boolean}
                            />
                        </div>
                    ))}
                </CardContent>
            </Card>
        </>
    );
}

export type NewSubscription = {
    provider: Provider;
    plan: string;
    account: string;
    api_key?: string;
};

export function AddSubscriptionDialog({
    open,
    onOpenChange,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onAdd: (s: NewSubscription) => Promise<boolean>;
}) {
    const [pv, setPv] = useState<Provider>('claude');
    const [plan, setPlan] = useState('Claude Pro');
    const [name, setName] = useState('');
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const plans: Record<Provider, string[]> = {
        claude: [
            'Claude Pro',
            'Claude Max',
            'Claude Team',
            'Anthropic API key',
        ],
        codex: [
            'ChatGPT Plus',
            'ChatGPT Pro',
            'ChatGPT Business',
            'Codex API key',
        ],
    };
    const api = plan.includes('API');
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <form
                    className="grid gap-5"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        const ok = await onAdd({
                            provider: pv,
                            plan,
                            account: name.trim(),
                            api_key: api ? key.trim() : undefined,
                        });
                        setBusy(false);
                        if (ok) {
                            setKey('');
                            onOpenChange(false);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Add a subscription</DialogTitle>
                        <DialogDescription>
                            Droids on this engine can draw from it. Paint it
                            into the schedule to decide when.
                        </DialogDescription>
                    </DialogHeader>
                    <RadioGroup
                        value={pv}
                        onValueChange={(v) => {
                            setPv(v as Provider);
                            setPlan(plans[v as Provider][0]);
                        }}
                        className="grid grid-cols-2 gap-2"
                    >
                        {(['claude', 'codex'] as Provider[]).map((p) => (
                            <Label
                                key={p}
                                htmlFor={`sp-${p}`}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-lg border p-3 font-normal',
                                    pv === p && 'border-ring bg-accent',
                                )}
                            >
                                <RadioGroupItem id={`sp-${p}`} value={p} />
                                <EngineLogo provider={p} className="size-5" />
                                <span className="font-medium">
                                    {PROVIDERS[p].label}
                                </span>
                            </Label>
                        ))}
                    </RadioGroup>
                    <div className="grid gap-2">
                        <Label>Plan</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={plan}
                            onValueChange={(v) => v && setPlan(v)}
                            className="flex flex-wrap justify-start"
                        >
                            {plans[pv].map((p) => (
                                <ToggleGroupItem
                                    key={p}
                                    value={p}
                                    className="px-3"
                                >
                                    {p}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sub-name">Label</Label>
                        <Input
                            id="sub-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="e.g. Personal, Acme workspace"
                        />
                    </div>
                    {api ? (
                        <div className="grid gap-2">
                            <Label htmlFor="sub-key">API key</Label>
                            <Input
                                id="sub-key"
                                type="password"
                                value={key}
                                onChange={(e) => setKey(e.target.value)}
                                required
                                autoComplete="off"
                                placeholder={
                                    pv === 'claude' ? 'sk-ant-…' : 'sk-…'
                                }
                            />
                        </div>
                    ) : (
                        <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                            You'll sign in with your{' '}
                            {pv === 'claude' ? 'Claude' : 'ChatGPT'} account on
                            your worker. The new card shows the command to run
                            there. Droids never see your password.
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy}>
                            {api ? 'Add key' : 'Add subscription'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
