import { useEffect, useState } from 'react';
import {
    GlobeIcon,
    LoaderCircleIcon,
    PlusIcon,
    RefreshCwIcon,
    Trash2Icon,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { request } from '@/factory/api';

type DnsRecord = {
    id: string;
    type: string;
    name: string;
    content: string;
    ttl: number;
    proxied: boolean;
    priority: number | null;
};
type Panel = {
    domain: string | null;
    cloudflare: boolean;
    zone: { id: string; name: string } | null;
    dns: DnsRecord[];
    error: string | null;
};

const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SRV'];
const PROXYABLE = ['A', 'AAAA', 'CNAME'];
const EMPTY = {
    type: 'A',
    name: '',
    content: '',
    priority: '10',
    proxied: false,
};

/** A project's domain and its DNS records on Cloudflare. */
export function DnsPanel({ pid }: { pid: string }) {
    const base = `/factory/projects/${encodeURIComponent(pid)}`;
    const [panel, setPanel] = useState<Panel | null>(null);
    const [error, setError] = useState('');
    const [domain, setDomain] = useState('');
    const [draft, setDraft] = useState(EMPTY);
    const [busy, setBusy] = useState('');

    const run = async (
        key: string,
        method: 'GET' | 'PUT' | 'POST' | 'DELETE',
        url: string,
        body?: unknown,
    ) => {
        setBusy(key);
        setError('');
        try {
            const res = (await request(method, url, body)) as unknown as Panel;
            setPanel(res);
            setDomain(res.domain ?? '');
            return true;
        } catch (e) {
            setError((e as Error).message);
            return false;
        } finally {
            setBusy('');
        }
    };

    useEffect(() => {
        setPanel(null);
        void run('load', 'GET', `${base}/domain`);
    }, [pid]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!panel)
        return (
            <div className="p-4 text-sm text-muted-foreground">
                {error || 'Loading DNS…'}
            </div>
        );

    if (!panel.cloudflare)
        return (
            <div className="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                <GlobeIcon className="size-8 text-muted-foreground" />
                <div className="text-lg font-semibold">DNS on Cloudflare</div>
                <p className="max-w-md text-muted-foreground">
                    Add a Cloudflare API token and manage this project's DNS
                    records here, such as the ones Laravel Cloud and Resend ask
                    you to create.
                </p>
                <Button variant="outline" asChild>
                    <a href="/settings/services">Add a token in Settings</a>
                </Button>
            </div>
        );

    const add = async () => {
        const ok = await run('add', 'POST', `${base}/dns`, {
            type: draft.type,
            name: draft.name || '@',
            content: draft.content,
            proxied: PROXYABLE.includes(draft.type) && draft.proxied,
            priority: draft.type === 'MX' ? Number(draft.priority) : null,
        });
        if (ok) {
            toast.success(`Added the ${draft.type} record`);
            setDraft({ ...EMPTY, type: draft.type });
        }
    };

    return (
        <div className="grid gap-4">
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    void run('domain', 'PUT', `${base}/domain`, { domain });
                }}
            >
                <div className="grid min-w-64 flex-1 gap-2 sm:max-w-sm">
                    <Label htmlFor="dns-domain">Domain</Label>
                    <Input
                        id="dns-domain"
                        value={domain}
                        placeholder="example.com"
                        onChange={(e) => setDomain(e.target.value)}
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={!!busy || domain === (panel.domain ?? '')}
                >
                    Save
                </Button>
                {panel.zone && (
                    <span className="flex items-center gap-2 text-sm text-muted-foreground sm:ml-auto">
                        Cloudflare zone{' '}
                        <span className="font-mono text-foreground">
                            {panel.zone.name}
                        </span>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label="Refresh records"
                            disabled={!!busy}
                            onClick={() =>
                                void run('load', 'GET', `${base}/domain`)
                            }
                        >
                            <RefreshCwIcon
                                className={
                                    busy === 'load' ? 'animate-spin' : ''
                                }
                            />
                        </Button>
                    </span>
                )}
            </form>

            {(error || panel.error) && (
                <p className="text-sm text-blocked">{error || panel.error}</p>
            )}

            {panel.zone && (
                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-20">Type</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Content</TableHead>
                                <TableHead className="w-28">Proxy</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {panel.dns.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell>
                                        <Badge variant="secondary">
                                            {r.type}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="max-w-56 truncate font-mono text-xs">
                                        {r.name}
                                    </TableCell>
                                    <TableCell className="max-w-96 truncate font-mono text-xs">
                                        {r.priority !== null && (
                                            <span className="mr-1.5 text-muted-foreground">
                                                {r.priority}
                                            </span>
                                        )}
                                        {r.content}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {PROXYABLE.includes(r.type)
                                            ? r.proxied
                                                ? 'Proxied'
                                                : 'DNS only'
                                            : ''}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            aria-label={`Delete the ${r.type} record ${r.name}`}
                                            disabled={!!busy}
                                            onClick={() =>
                                                void run(
                                                    r.id,
                                                    'DELETE',
                                                    `${base}/dns/${encodeURIComponent(r.id)}`,
                                                )
                                            }
                                        >
                                            {busy === r.id ? (
                                                <LoaderCircleIcon className="animate-spin" />
                                            ) : (
                                                <Trash2Icon />
                                            )}
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {panel.dns.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-sm text-muted-foreground"
                                    >
                                        No records yet.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                    <form
                        className="grid gap-2 border-t p-3 sm:grid-cols-[96px_minmax(0,1fr)_minmax(0,2fr)_auto_auto] sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void add();
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="dns-type" className="text-xs">
                                Type
                            </Label>
                            <Select
                                value={draft.type}
                                onValueChange={(type) =>
                                    setDraft({ ...draft, type })
                                }
                            >
                                <SelectTrigger id="dns-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TYPES.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="dns-name" className="text-xs">
                                Name
                            </Label>
                            <Input
                                id="dns-name"
                                value={draft.name}
                                placeholder={`@ or www, in ${panel.zone.name}`}
                                onChange={(e) =>
                                    setDraft({ ...draft, name: e.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="dns-content" className="text-xs">
                                {draft.type === 'MX'
                                    ? 'Mail server and priority'
                                    : 'Content'}
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="dns-content"
                                    value={draft.content}
                                    required
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            content: e.target.value,
                                        })
                                    }
                                />
                                {draft.type === 'MX' && (
                                    <Input
                                        aria-label="Priority"
                                        type="number"
                                        className="w-20"
                                        value={draft.priority}
                                        onChange={(e) =>
                                            setDraft({
                                                ...draft,
                                                priority: e.target.value,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        </div>
                        <label className="flex h-9 items-center gap-2 text-sm">
                            <Switch
                                checked={draft.proxied}
                                disabled={!PROXYABLE.includes(draft.type)}
                                onCheckedChange={(proxied) =>
                                    setDraft({ ...draft, proxied })
                                }
                            />
                            Proxy
                        </label>
                        <Button
                            type="submit"
                            disabled={!!busy || !draft.content.trim()}
                        >
                            {busy === 'add' ? (
                                <LoaderCircleIcon className="animate-spin" />
                            ) : (
                                <PlusIcon />
                            )}
                            Add record
                        </Button>
                    </form>
                </div>
            )}
        </div>
    );
}
