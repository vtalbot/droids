import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    connection: { organization: string; since: string | null } | null;
    projects: { name: string; app: string; synced: string | null }[];
};

export default function Cloud({ connection, projects }: Props) {
    const form = useForm({ token: '' });

    return (
        <>
            <Head title="Laravel Cloud" />

            <h1 className="sr-only">Laravel Cloud</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Laravel Cloud"
                    description="Show each project's environments, deployments, health and logs, and deploy from the Cloud panel. Droids reads Laravel Cloud with one API token."
                />

                {connection && (
                    <div className="flex items-center justify-between gap-3 rounded-lg border p-4 text-sm">
                        <div>
                            Connected to{' '}
                            <strong>{connection.organization}</strong>
                            {connection.since && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    since {connection.since}
                                </span>
                            )}
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.delete('/settings/cloud', {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Disconnect
                        </Button>
                    </div>
                )}

                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/cloud', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Label htmlFor="cloud-token">
                        {connection ? 'Replace the API token' : 'API token'}
                    </Label>
                    <div className="flex gap-2">
                        <Input
                            id="cloud-token"
                            type="password"
                            autoComplete="off"
                            value={form.data.token}
                            onChange={(e) =>
                                form.setData('token', e.target.value)
                            }
                            placeholder="Paste a token from Laravel Cloud"
                            required
                        />
                        <Button type="submit" disabled={form.processing}>
                            {connection ? 'Replace' : 'Connect'}
                        </Button>
                    </div>
                    <InputError message={form.errors.token} />
                    <p className="text-sm text-muted-foreground">
                        In Laravel Cloud, open your organization's settings,
                        then <strong>API tokens</strong> &gt;{' '}
                        <strong>Create API Token</strong>. Give it the
                        permissions to view applications and environments, and
                        to deploy if you want the Deploy button. Tokens expire,
                        so pick a long expiration or come back here when it runs
                        out.
                    </p>
                </form>
            </div>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Linked projects"
                    description="Link a project from its Cloud tab. Snapshots refresh every 5 minutes, and every few seconds while a deployment runs."
                />
                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No projects linked yet.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {projects.map((p) => (
                            <li
                                key={p.name}
                                className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <span className="font-medium">{p.name}</span>
                                <span className="text-muted-foreground">
                                    {p.app}
                                    {p.synced ? ` · synced ${p.synced}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Cloud.layout = {
    breadcrumbs: [
        {
            title: 'Laravel Cloud',
            href: '/settings/cloud',
        },
    ],
};
