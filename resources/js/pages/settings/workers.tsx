import { Head, router, useForm } from '@inertiajs/react';
import { Copy, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WorkerRow = {
    id: number;
    name: string;
    online: boolean;
    lastSeen: string | null;
    subscriptions: string[];
};

type Props = {
    workers: WorkerRow[];
    newToken: { name: string; token: string } | null;
    server: string;
};

export default function Workers({ workers, newToken, server }: Props) {
    const form = useForm({ name: '' });
    const env = newToken
        ? `DROIDS_SERVER=${server}\nDROIDS_WORKER_TOKEN=${newToken.token}`
        : '';

    return (
        <>
            <Head title="Workers" />

            <h1 className="sr-only">Workers</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Workers"
                    description="Machines that run your droids. A worker picks up queued tasks, runs Claude Code or Codex in a fresh clone, and pushes a branch for the pull request."
                />

                {newToken && (
                    <div className="space-y-3 rounded-lg border border-done/40 bg-done/5 p-4">
                        <p className="text-sm">
                            Token for <strong>{newToken.name}</strong>. Copy it
                            now: it won't be shown again. Add these lines to the{' '}
                            <code>.env</code> of your Droids checkout on that
                            machine, then run{' '}
                            <code>php artisan droids:work</code>.
                        </p>
                        <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
                            {env}
                        </pre>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(env)
                                    .then(() => toast.success('Copied'))
                            }
                        >
                            <Copy />
                            Copy
                        </Button>
                    </div>
                )}

                <form
                    className="flex items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/workers', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="worker-name">New worker</Label>
                        <Input
                            id="worker-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Vincent's MacBook"
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Create token
                    </Button>
                </form>
            </div>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Your workers"
                    description="A worker shows as online while it polls for work."
                />
                {workers.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No workers yet. Until one runs, droids on linked
                        projects wait in the queue.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {workers.map((w) => (
                            <li
                                key={w.id}
                                className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2 font-medium">
                                        <span
                                            className={
                                                w.online
                                                    ? 'size-2 rounded-full bg-done'
                                                    : 'size-2 rounded-full bg-muted-foreground/40'
                                            }
                                        />
                                        {w.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {w.lastSeen
                                            ? `Last seen ${w.lastSeen}`
                                            : 'Never connected'}
                                    </div>
                                    {w.subscriptions.length > 0 && (
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {w.subscriptions.map((s) => (
                                                <Badge
                                                    key={s}
                                                    variant="secondary"
                                                    className="font-mono text-[10px]"
                                                >
                                                    {s}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${w.name}`}
                                    onClick={() =>
                                        router.delete(
                                            `/settings/workers/${w.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Workers.layout = {
    breadcrumbs: [
        {
            title: 'Workers',
            href: '/settings/workers',
        },
    ],
};
