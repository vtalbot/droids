import { Head, router } from '@inertiajs/react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { GithubMark } from '@/factory/logos';

type AppInfo = {
    name: string;
    slug: string;
    url: string;
    owner: string | null;
    installUrl: string;
};

type Installation = {
    id: number;
    account: string;
    type: string;
    selection: string;
    suspended: boolean;
};

type LinkedProject = {
    slug: string;
    repo: string;
    syncedAt: string | null;
};

type Props = {
    app: AppInfo | null;
    installations: Installation[];
    projects: LinkedProject[];
    webhookUrl: string;
    publicUrl: boolean;
};

export default function GitHubSettings({
    app,
    installations,
    projects,
    webhookUrl,
    publicUrl,
}: Props) {
    const [syncing, setSyncing] = useState(false);

    const syncAll = () =>
        router.post(
            '/settings/github/sync',
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
            },
        );

    return (
        <>
            <Head title="GitHub settings" />

            <h1 className="sr-only">GitHub settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="GitHub App"
                    description="Droids reads issues, milestones, pull requests and checks through a GitHub App you own, and writes issues and merges back."
                />

                {!app ? (
                    <div className="space-y-4 rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            No App yet. GitHub will ask you to confirm the name,
                            then send you back here to pick the repositories
                            Droids can work on.
                        </p>
                        <Button asChild>
                            <a href="/settings/github/create">
                                <GithubMark />
                                Create the GitHub App
                            </a>
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="truncate font-medium">
                                    {app.name}
                                </div>
                                {app.owner && (
                                    <div className="text-xs text-muted-foreground">
                                        Owned by {app.owner}
                                    </div>
                                )}
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={app.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    App settings
                                    <ExternalLink />
                                </a>
                            </Button>
                        </div>
                        <Button size="sm" asChild>
                            <a href={app.installUrl}>
                                {installations.length
                                    ? 'Change repository access'
                                    : 'Install on your repositories'}
                            </a>
                        </Button>
                    </div>
                )}

                {!publicUrl && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                        This app isn't on a public URL, so GitHub can't deliver
                        webhooks to {webhookUrl}. Droids still syncs every 5
                        minutes and whenever you press Sync.
                    </p>
                )}
            </div>

            {app && (
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Installations"
                        description="Accounts where the App is installed."
                    />
                    {installations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Not installed anywhere yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {installations.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex items-center justify-between px-4 py-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {i.account}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Badge variant="secondary">
                                            {i.selection === 'all'
                                                ? 'All repositories'
                                                : 'Selected repositories'}
                                        </Badge>
                                        {i.suspended && (
                                            <Badge variant="destructive">
                                                Suspended
                                            </Badge>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {app && (
                <div className="space-y-4">
                    <div className="flex items-end justify-between gap-3">
                        <Heading
                            variant="small"
                            title="Linked projects"
                            description="Projects whose board follows a GitHub repository."
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={syncAll}
                            disabled={syncing || projects.length === 0}
                        >
                            <RefreshCw
                                className={syncing ? 'animate-spin' : ''}
                            />
                            Sync now
                        </Button>
                    </div>
                    {projects.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No linked projects. Use "Connect repository" in the
                            Hangar to add one.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {projects.map((p) => (
                                <li
                                    key={p.slug}
                                    className="flex items-center justify-between px-4 py-3 text-sm"
                                >
                                    <a
                                        href={`/board?project=${p.slug}`}
                                        className="font-mono hover:underline"
                                    >
                                        {p.repo}
                                    </a>
                                    <span className="text-xs text-muted-foreground">
                                        {p.syncedAt
                                            ? `Synced ${p.syncedAt}`
                                            : 'Not synced yet'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </>
    );
}

GitHubSettings.layout = {
    breadcrumbs: [
        {
            title: 'GitHub settings',
            href: '/settings/github',
        },
    ],
};
