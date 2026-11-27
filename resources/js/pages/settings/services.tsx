import { Head, router, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Key = { label: string | null; since: string | null } | null;
type Props = { keys: { cloudflare: Key } };

const SERVICES = {
    cloudflare: {
        name: 'Cloudflare',
        what: "Manages the DNS records of each project's domain, such as the ones a Resend sending domain or a custom domain on Laravel Cloud needs.",
        how: (
            <>
                In Cloudflare, open <strong>My Profile</strong> &gt;{' '}
                <strong>API Tokens</strong> &gt; <strong>Create Token</strong>{' '}
                and use the <strong>Edit zone DNS</strong> template. Add{' '}
                <strong>Zone &gt; Zone &gt; Read</strong> and include the zones
                of your projects.
            </>
        ),
        placeholder: 'Paste a Cloudflare API token',
    },
} as const;

function ServiceForm({
    service,
    current,
}: {
    service: keyof typeof SERVICES;
    current: Key;
}) {
    const s = SERVICES[service];
    const form = useForm({ service, token: '' });
    return (
        <div className="space-y-4">
            <Heading variant="small" title={s.name} description={s.what} />
            {current && (
                <div className="flex items-center justify-between gap-3 rounded-lg border p-4 text-sm">
                    <div>
                        Connected
                        {current.label && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {current.label}
                            </span>
                        )}
                        {current.since && (
                            <span className="text-muted-foreground">
                                {' '}
                                · since {current.since}
                            </span>
                        )}
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.delete(`/settings/services/${service}`, {
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
                    form.post('/settings/services', {
                        preserveScroll: true,
                        onSuccess: () => form.reset('token'),
                    });
                }}
            >
                <Label htmlFor={`${service}-token`}>
                    {current ? 'Replace the API token' : 'API token'}
                </Label>
                <div className="flex gap-2">
                    <Input
                        id={`${service}-token`}
                        type="password"
                        autoComplete="off"
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                        placeholder={s.placeholder}
                        required
                    />
                    <Button type="submit" disabled={form.processing}>
                        {current ? 'Replace' : 'Connect'}
                    </Button>
                </div>
                <InputError message={form.errors.token} />
                <p className="text-sm text-muted-foreground">{s.how}</p>
            </form>
        </div>
    );
}

export default function Services({ keys }: Props) {
    return (
        <>
            <Head title="DNS" />
            <h1 className="sr-only">DNS</h1>
            <ServiceForm service="cloudflare" current={keys.cloudflare} />
            <p className="text-sm text-muted-foreground">
                Set each project's domain in its <strong>DNS</strong> tab. The
                token is stored encrypted. Email goes through Laravel Cloud's
                Resend integration, from each project's Laravel Cloud tab.
            </p>
        </>
    );
}

Services.layout = {
    breadcrumbs: [{ title: 'DNS', href: '/settings/services' }],
};
