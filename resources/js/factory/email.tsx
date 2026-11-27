import { useEffect, useState } from 'react';
import {
    CheckIcon,
    EyeIcon,
    EyeOffIcon,
    GlobeIcon,
    LoaderCircleIcon,
    MailIcon,
    PlusIcon,
    RefreshCwIcon,
    Trash2Icon,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { request } from '@/factory/api';
import type { CloudEnv } from '@/factory/data';
import { SuggestButton } from '@/factory/suggest';
import { cn } from '@/lib/utils';

/** A DNS record another service asked for, and whether Cloudflare has it. */
export type WantedRecord = {
    type: string;
    name: string;
    content: string;
    priority: number | null;
    state: 'present' | 'missing' | 'conflict' | 'no-zone' | null;
};

const STATE: Record<string, [string, string]> = {
    present: ['In Cloudflare', 'border-done/40 text-done'],
    missing: ['Missing', 'border-needs/40 text-needs'],
    conflict: ['Clashes', 'border-blocked/40 text-blocked'],
    'no-zone': ['No zone', 'text-muted-foreground'],
};

/** A hook for the panels below: load JSON, run actions, keep the last error. */
function usePanel<T>(url: string) {
    const [panel, setPanel] = useState<T | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState('');
    const run = async (
        key: string,
        method: 'GET' | 'PUT' | 'POST' | 'DELETE',
        path: string,
        body?: unknown,
    ) => {
        setBusy(key);
        setError('');
        try {
            const res = (await request(method, path, body)) as unknown as T & {
                result?: unknown;
                warnings?: string[];
            };
            setPanel(res);
            return res;
        } catch (e) {
            setError((e as Error).message);
            return null;
        } finally {
            setBusy('');
        }
    };
    useEffect(() => {
        setPanel(null);
        void run('load', 'GET', url);
    }, [url]); // eslint-disable-line react-hooks/exhaustive-deps
    return { panel, error, busy, run };
}

function dnsToast(result: unknown) {
    if (Array.isArray(result)) {
        result.forEach((line, i) =>
            i === result.length - 1
                ? toast.success(String(line))
                : toast(String(line)),
        );
    } else if (result && typeof result === 'object') {
        const r = result as {
            added: number;
            present: number;
            conflicts: string[];
            unzoned: string[];
        };
        toast.success(
            `Added ${r.added} DNS record${r.added === 1 ? '' : 's'} to Cloudflare${r.present ? `, ${r.present} already there` : ''}`,
            {
                description: [
                    r.conflicts.length
                        ? `Left alone, a different record holds: ${r.conflicts.join(', ')}`
                        : '',
                    r.unzoned.length
                        ? `No Cloudflare zone for ${r.unzoned.join(', ')}`
                        : '',
                ]
                    .filter(Boolean)
                    .join('. '),
            },
        );
    }
}

export function RecordsTable({ records }: { records: WantedRecord[] }) {
    if (!records.length)
        return (
            <p className="text-sm text-muted-foreground">
                No DNS records reported yet.
            </p>
        );
    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-20">Type</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Value</TableHead>
                        <TableHead className="w-28 text-right">
                            Cloudflare
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {records.map((r) => (
                        <TableRow key={`${r.type} ${r.name} ${r.content}`}>
                            <TableCell className="font-mono text-xs">
                                {r.type}
                            </TableCell>
                            <TableCell className="max-w-[220px] truncate font-mono text-xs">
                                {r.name}
                            </TableCell>
                            <TableCell
                                className="max-w-[320px] truncate font-mono text-xs"
                                title={r.content}
                            >
                                {r.priority !== null && r.type === 'MX'
                                    ? `${r.priority} `
                                    : ''}
                                {r.content}
                            </TableCell>
                            <TableCell className="text-right">
                                {r.state && (
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'rounded-full font-normal',
                                            STATE[r.state][1],
                                        )}
                                    >
                                        {STATE[r.state][0]}
                                    </Badge>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function Status({ s }: { s: string }) {
    const ok = s === 'verified' || s === 'active';
    const bad = s.includes('fail');
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full font-normal',
                ok
                    ? 'border-done/40 text-done'
                    : bad
                      ? 'border-blocked/40 text-blocked'
                      : 'border-needs/40 text-needs',
            )}
        >
            {s.replaceAll('_', ' ')}
        </Badge>
    );
}

// ---------- Email tab ----------

type EmailPanelData = {
    domain: string | null;
    cloud: boolean;
    cloudflare: boolean;
    resendKey: boolean;
    envs: { id: string; name: string; email: CloudEnv['email'] }[];
    sending: { name: string; status: string; records: WantedRecord[] } | null;
    resend: {
        id: string;
        name: string;
        status: string;
        receiving: boolean;
        records: WantedRecord[];
    } | null;
    errors: string[];
};

/** What can go before the @ of an address: anything after an @ is the domain, so it is dropped. */
const emailLocal = (v: string) =>
    v
        .split('@')[0]
        .toLowerCase()
        .replace(/[^a-z0-9._+-]/g, '');

/** Sending through Laravel Cloud, receiving through Resend, and the DNS both need. */
export function EmailPanel({ pid }: { pid: string }) {
    const base = `/factory/projects/${encodeURIComponent(pid)}/email`;
    const { panel, error, busy, run } = usePanel<EmailPanelData>(base);
    const [env, setEnv] = useState('');
    // Only the part before the @: the domain is the project's.
    const [local, setLocal] = useState('');
    const [name, setName] = useState('');
    const [receiving, setReceiving] = useState(false);
    useEffect(() => {
        if (!panel) return;
        const e = panel.envs.find((x) => x.email) ?? panel.envs[0];
        setEnv((v) => v || e?.id || '');
        const saved = e?.email?.from ?? '';
        setLocal(
            (v) => v || (saved.includes('@') ? emailLocal(saved) : 'hello'),
        );
        setName((v) => v || e?.email?.name || '');
        setReceiving(panel.resend?.receiving ?? false);
    }, [panel]);

    if (!panel)
        return (
            <div className="p-4 text-sm text-muted-foreground">
                {error || 'Loading email…'}
            </div>
        );
    if (!panel.domain)
        return (
            <div className="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                <MailIcon className="size-8 text-muted-foreground" />
                <div className="text-lg font-semibold">
                    Email for this project
                </div>
                <p className="max-w-md text-muted-foreground">
                    Set the project's domain in the DNS tab first. Email is sent
                    and received at that domain.
                </p>
            </div>
        );

    // Cloud sends only from its own sending domain, which can be a parent or child of the project's.
    const sendDomain = panel.sending?.name ?? panel.domain;
    const from = local ? `${local}@${sendDomain}` : '';
    const missing = [
        ...(panel.sending?.records ?? []),
        ...(panel.resend?.records ?? []),
    ].filter((r) => r.state === 'missing').length;

    return (
        <div className="grid gap-4">
            {[...panel.errors, error].filter(Boolean).map((e) => (
                <p key={e} className="text-sm text-blocked">
                    {e}
                </p>
            ))}
            <Card className="gap-4 py-4">
                <CardHeader className="px-4">
                    <CardTitle className="text-base">
                        Set up email for {panel.domain}
                    </CardTitle>
                    <CardDescription>
                        {panel.cloud
                            ? 'Sends from your Laravel Cloud environment with its Resend integration. '
                            : 'Link Laravel Cloud to send from your app. '}
                        {panel.resendKey
                            ? 'Receiving goes through your Resend account. '
                            : 'Add a Resend API key in Settings to receive email. '}
                        {panel.cloudflare
                            ? 'Every DNS record is added to Cloudflare for you.'
                            : 'Connect Cloudflare in Settings to add the DNS records for you.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3 px-4">
                    <div className="grid gap-3 sm:grid-cols-4">
                        {panel.envs.length > 0 && (
                            <div className="grid gap-1.5">
                                <Label htmlFor="email-env">Environment</Label>
                                <select
                                    id="email-env"
                                    className="h-9 rounded-md border bg-background px-2 text-sm"
                                    value={env}
                                    onChange={(e) => setEnv(e.target.value)}
                                >
                                    {panel.envs.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.name}
                                            {e.email ? ' · sends email' : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="email-from">From address</Label>
                            <InputGroup>
                                <InputGroupInput
                                    id="email-from"
                                    value={local}
                                    onChange={(e) =>
                                        setLocal(emailLocal(e.target.value))
                                    }
                                    placeholder="hello"
                                    autoComplete="off"
                                    spellCheck={false}
                                />
                                <InputGroupAddon
                                    align="inline-end"
                                    className="max-w-[60%]"
                                >
                                    <InputGroupText
                                        className="truncate"
                                        title={`@${sendDomain}`}
                                    >
                                        @{sendDomain}
                                    </InputGroupText>
                                </InputGroupAddon>
                            </InputGroup>
                            {panel.cloud &&
                                !['verified', 'active'].includes(
                                    panel.sending?.status ?? '',
                                ) && (
                                    <p className="text-xs text-needs">
                                        {panel.sending
                                            ? `Laravel Cloud sends from ${sendDomain} once it is verified (now ${panel.sending.status}).`
                                            : `Add ${panel.domain} in Laravel Cloud under Email first: Cloud only sends from a verified domain.`}
                                    </p>
                                )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="email-name">From name</Label>
                            <Input
                                id="email-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="Acme"
                            />
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            checked={receiving}
                            disabled={!panel.resendKey}
                            onCheckedChange={setReceiving}
                        />
                        Receive email at {panel.domain}
                        {!panel.resendKey && (
                            <span className="text-muted-foreground">
                                (needs a Resend API key)
                            </span>
                        )}
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <SuggestButton
                            pid={pid}
                            form="email"
                            values={{ from_local: local, from_name: name }}
                            onFill={(f) => {
                                if (f.from_local)
                                    setLocal(emailLocal(f.from_local));
                                if (f.from_name) setName(f.from_name);
                            }}
                        />
                        <Button
                            disabled={!!busy || !from || !name}
                            onClick={async () => {
                                const res = await run(
                                    'setup',
                                    'POST',
                                    `${base}/setup`,
                                    {
                                        environment: env || null,
                                        from_address: from,
                                        from_name: name,
                                        receiving,
                                    },
                                );
                                if (!res) return;
                                res.warnings?.forEach((w) =>
                                    toast.warning(w, { duration: 15000 }),
                                );
                                dnsToast(res.result);
                            }}
                        >
                            {busy === 'setup' && (
                                <LoaderCircleIcon className="animate-spin" />
                            )}
                            Set up email
                        </Button>
                        {panel.cloudflare && missing > 0 && (
                            <Button
                                variant="outline"
                                disabled={!!busy}
                                onClick={async () => {
                                    const res = await run(
                                        'dns',
                                        'POST',
                                        `${base}/dns`,
                                    );
                                    if (res) dnsToast(res.result);
                                }}
                            >
                                <GlobeIcon />
                                Add {missing} missing record
                                {missing === 1 ? '' : 's'} to Cloudflare
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>

            {panel.cloud && (
                <Card className="gap-3 py-4">
                    <CardHeader className="flex flex-row items-center gap-2 px-4">
                        <CardTitle className="text-base">
                            Sending with Laravel Cloud
                        </CardTitle>
                        {panel.sending && <Status s={panel.sending.status} />}
                    </CardHeader>
                    <CardContent className="grid gap-3 px-4 text-sm">
                        {panel.sending ? (
                            <RecordsTable records={panel.sending.records} />
                        ) : (
                            <p className="text-muted-foreground">
                                Laravel Cloud has no sending domain for{' '}
                                {panel.domain} yet. Its API can't create one:
                                add it in Laravel Cloud under Email, then
                                refresh this tab and the DNS records show up
                                here.
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}

            {panel.resend && (
                <Card className="gap-3 py-4">
                    <CardHeader className="flex flex-row flex-wrap items-center gap-2 px-4">
                        <CardTitle className="text-base">
                            {panel.resend.name} in your Resend account
                        </CardTitle>
                        <Status s={panel.resend.status} />
                        <div className="ml-auto flex items-center gap-2">
                            <label className="flex items-center gap-2 text-sm">
                                <Switch
                                    checked={panel.resend.receiving}
                                    disabled={!!busy}
                                    onCheckedChange={async (v) => {
                                        const res = await run(
                                            'receiving',
                                            'PUT',
                                            `${base}/receiving`,
                                            { enabled: v },
                                        );
                                        if (res) dnsToast(res.result);
                                    }}
                                />
                                Receiving
                            </label>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!!busy}
                                onClick={async () => {
                                    const res = await run(
                                        'verify',
                                        'POST',
                                        `${base}/verify`,
                                    );
                                    if (res) dnsToast(res.result);
                                }}
                            >
                                <RefreshCwIcon />
                                Verify
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="px-4">
                        <RecordsTable records={panel.resend.records} />
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

// ---------- Laravel Cloud domains (DNS tab) ----------

type CloudDomain = {
    id: string;
    environment: string;
    environmentName: string;
    name: string;
    hostname: string;
    ssl: string;
    origin: string;
    action: string | null;
    records: WantedRecord[];
};
type DomainsPanel = {
    linked: boolean;
    cloudflare: boolean;
    domains: CloudDomain[];
    error: string | null;
};

/** Custom domains on Laravel Cloud, with the DNS records Cloud asks for. */
export function CloudDomains({
    pid,
    envs,
}: {
    pid: string;
    envs: { id: string; name: string }[];
}) {
    const base = `/factory/projects/${encodeURIComponent(pid)}/cloud/domains`;
    const { panel, error, busy, run } = usePanel<DomainsPanel>(base);
    const [env, setEnv] = useState('');
    const [name, setName] = useState('');
    if (!envs.length) return null;
    if (!panel)
        return (
            <div className="p-4 text-sm text-muted-foreground">
                {error || 'Loading Laravel Cloud domains…'}
            </div>
        );
    if (!panel.linked) return null;

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">
                    Domains on Laravel Cloud
                </CardTitle>
                <CardDescription>
                    Add a domain to an environment. The DNS records Laravel
                    Cloud asks for go into Cloudflare, then Verify.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 px-4">
                {(panel.error || error) && (
                    <p className="text-sm text-blocked">
                        {panel.error || error}
                    </p>
                )}
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        const res = await run('add', 'POST', base, {
                            environment: env || envs[0].id,
                            name,
                            dns: true,
                        });
                        if (res) {
                            setName('');
                            toast.success(`${name} added to Laravel Cloud`);
                            dnsToast(res.result);
                        }
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="cloud-domain-env">Environment</Label>
                        <select
                            id="cloud-domain-env"
                            className="h-9 rounded-md border bg-background px-2 text-sm"
                            value={env || envs[0].id}
                            onChange={(e) => setEnv(e.target.value)}
                        >
                            {envs.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid min-w-[220px] flex-1 gap-1.5">
                        <Label htmlFor="cloud-domain-name">Domain</Label>
                        <Input
                            id="cloud-domain-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="app.example.com"
                        />
                    </div>
                    <Button type="submit" disabled={!!busy || !name}>
                        {busy === 'add' ? (
                            <LoaderCircleIcon className="animate-spin" />
                        ) : (
                            <PlusIcon />
                        )}
                        Add domain
                    </Button>
                </form>
                {panel.domains.map((d) => {
                    const missing = d.records.filter(
                        (r) => r.state === 'missing',
                    ).length;
                    return (
                        <div
                            key={d.id}
                            className="grid gap-2 rounded-lg border p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-sm">
                                    {d.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {d.environmentName}
                                </span>
                                <Status s={`ownership ${d.hostname}`} />
                                <Status s={`ssl ${d.ssl}`} />
                                <Status s={`routing ${d.origin}`} />
                                <div className="ml-auto flex gap-1.5">
                                    {panel.cloudflare && missing > 0 && (
                                        <Button
                                            size="sm"
                                            disabled={!!busy}
                                            onClick={async () => {
                                                const res = await run(
                                                    `dns-${d.id}`,
                                                    'POST',
                                                    `${base}/${d.id}/dns`,
                                                );
                                                if (res) dnsToast(res.result);
                                            }}
                                        >
                                            <GlobeIcon />
                                            Add {missing} to Cloudflare
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={!!busy}
                                        onClick={() =>
                                            void run(
                                                `verify-${d.id}`,
                                                'POST',
                                                `${base}/${d.id}/verify`,
                                            )
                                        }
                                    >
                                        {busy === `verify-${d.id}` ? (
                                            <LoaderCircleIcon className="animate-spin" />
                                        ) : (
                                            <CheckIcon />
                                        )}
                                        Verify
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label={`Remove ${d.name} from Laravel Cloud`}
                                        disabled={!!busy}
                                        onClick={() =>
                                            void run(
                                                `delete-${d.id}`,
                                                'DELETE',
                                                `${base}/${d.id}`,
                                            )
                                        }
                                    >
                                        <Trash2Icon />
                                    </Button>
                                </div>
                            </div>
                            <RecordsTable records={d.records} />
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

// ---------- Environment variables (Laravel Cloud tab) ----------

type Variable = { key: string; value: string };

/** An environment's custom variables on Laravel Cloud. */
export function VariablesCard({
    pid,
    envs,
}: {
    pid: string;
    envs: CloudEnv[];
}) {
    const [env, setEnv] = useState(envs[0]?.id ?? '');
    const url = `/factory/projects/${encodeURIComponent(pid)}/cloud/environments/${encodeURIComponent(env)}/variables`;
    const { panel, error, busy, run } = usePanel<{ variables: Variable[] }>(
        url,
    );
    const [shown, setShown] = useState<Record<string, boolean>>({});
    const [edit, setEdit] = useState<Record<string, string>>({});
    const [draft, setDraft] = useState({ key: '', value: '' });
    const envName = envs.find((e) => e.id === env)?.name ?? '';
    const saved = (what: string) =>
        toast.success(what, {
            description: `Deploy ${envName} for the app to pick it up.`,
        });

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="flex flex-row flex-wrap items-center gap-2 px-4">
                <div className="min-w-0 flex-1">
                    <CardTitle className="text-base">
                        Environment variables
                    </CardTitle>
                    <CardDescription>
                        Your own variables on Laravel Cloud. They take priority
                        over the ones Cloud injects.
                    </CardDescription>
                </div>
                <select
                    aria-label="Environment"
                    className="h-8 rounded-md border bg-background px-2 text-sm"
                    value={env}
                    onChange={(e) => setEnv(e.target.value)}
                >
                    {envs.map((e) => (
                        <option key={e.id} value={e.id}>
                            {e.name}
                        </option>
                    ))}
                </select>
            </CardHeader>
            <CardContent className="grid gap-3 px-4">
                {error && <p className="text-sm text-blocked">{error}</p>}
                {!panel ? (
                    <p className="text-sm text-muted-foreground">
                        {busy === 'load' ? 'Loading variables…' : ''}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableBody>
                                {panel.variables.length === 0 && (
                                    <TableRow>
                                        <TableCell className="text-sm text-muted-foreground">
                                            No custom variables on {envName}.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {panel.variables.map((v) => (
                                    <TableRow key={v.key}>
                                        <TableCell className="w-[38%] font-mono text-xs">
                                            {v.key}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {edit[v.key] !== undefined ? (
                                                <form
                                                    className="flex gap-1.5"
                                                    onSubmit={async (e) => {
                                                        e.preventDefault();
                                                        const res = await run(
                                                            `set-${v.key}`,
                                                            'PUT',
                                                            url,
                                                            {
                                                                variables: [
                                                                    {
                                                                        key: v.key,
                                                                        value: edit[
                                                                            v
                                                                                .key
                                                                        ],
                                                                    },
                                                                ],
                                                            },
                                                        );
                                                        if (res) {
                                                            setEdit((x) => {
                                                                const next = {
                                                                    ...x,
                                                                };
                                                                delete next[
                                                                    v.key
                                                                ];
                                                                return next;
                                                            });
                                                            saved(
                                                                `${v.key} updated`,
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <Input
                                                        aria-label={`Value of ${v.key}`}
                                                        className="h-8 font-mono text-xs"
                                                        value={edit[v.key]}
                                                        autoFocus
                                                        onChange={(e) =>
                                                            setEdit((x) => ({
                                                                ...x,
                                                                [v.key]:
                                                                    e.target
                                                                        .value,
                                                            }))
                                                        }
                                                    />
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                        disabled={!!busy}
                                                    >
                                                        Save
                                                    </Button>
                                                </form>
                                            ) : (
                                                <button
                                                    type="button"
                                                    className="max-w-[360px] truncate text-left hover:underline"
                                                    title="Edit"
                                                    onClick={() =>
                                                        setEdit((x) => ({
                                                            ...x,
                                                            [v.key]: v.value,
                                                        }))
                                                    }
                                                >
                                                    {shown[v.key]
                                                        ? v.value || '(empty)'
                                                        : '••••••••'}
                                                </button>
                                            )}
                                        </TableCell>
                                        <TableCell className="w-20 text-right">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label={`${shown[v.key] ? 'Hide' : 'Show'} ${v.key}`}
                                                onClick={() =>
                                                    setShown((x) => ({
                                                        ...x,
                                                        [v.key]: !x[v.key],
                                                    }))
                                                }
                                            >
                                                {shown[v.key] ? (
                                                    <EyeOffIcon />
                                                ) : (
                                                    <EyeIcon />
                                                )}
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label={`Remove ${v.key}`}
                                                disabled={!!busy}
                                                onClick={async () => {
                                                    const res = await run(
                                                        `del-${v.key}`,
                                                        'POST',
                                                        `${url}/delete`,
                                                        { keys: [v.key] },
                                                    );
                                                    if (res)
                                                        saved(
                                                            `${v.key} removed`,
                                                        );
                                                }}
                                            >
                                                <Trash2Icon />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        const res = await run('add', 'PUT', url, {
                            variables: [draft],
                        });
                        if (res) {
                            saved(`${draft.key} set on ${envName}`);
                            setDraft({ key: '', value: '' });
                        }
                    }}
                >
                    <Input
                        aria-label="Variable name"
                        className="h-9 w-56 font-mono text-xs"
                        placeholder="KEY"
                        value={draft.key}
                        onChange={(e) =>
                            setDraft((d) => ({
                                ...d,
                                key: e.target.value
                                    .toUpperCase()
                                    .replace(/[^A-Z0-9_]/g, '_'),
                            }))
                        }
                    />
                    <Input
                        aria-label="Variable value"
                        className="h-9 min-w-[200px] flex-1 font-mono text-xs"
                        placeholder="value"
                        value={draft.value}
                        onChange={(e) =>
                            setDraft((d) => ({ ...d, value: e.target.value }))
                        }
                    />
                    <Button type="submit" disabled={!!busy || !draft.key}>
                        <PlusIcon />
                        Set
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
