import { useState } from 'react';
import { PlusIcon, UsersIcon, XIcon } from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';
import { STATUS, type Droid } from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

const NONE = '__none';

/** Move a droid onto a project, or off every project. */
export function useMoveDroid(store: Store) {
    const save = useSave();
    return async (d: Droid, project: string | null) => {
        const res = await save(
            'PATCH',
            `/factory/droids/${encodeURIComponent(d.id)}`,
            { project },
        );
        if (!res) return false;
        const name = project
            ? store.projects.find((p) => p.id === project)?.name
            : null;
        toast.success(
            name
                ? `${d.unit} ${d.name} joined ${name}`
                : `${d.unit} ${d.name} left its project`,
        );
        return true;
    };
}

/** The project picker on a droid's row in the Droids table. */
export function DroidProjectSelect({ store, d }: { store: Store; d: Droid }) {
    const move = useMoveDroid(store);
    const [busy, setBusy] = useState(false);
    return (
        <Select
            value={d.project ?? NONE}
            disabled={busy || !!d.task}
            onValueChange={async (v) => {
                setBusy(true);
                await move(d, v === NONE ? null : v);
                setBusy(false);
            }}
        >
            <SelectTrigger
                size="sm"
                className="h-8 w-44 text-xs"
                aria-label={`Project of ${d.unit} ${d.name}`}
                title={d.task ? 'Unassign its issue to move it' : undefined}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>
                    <span className="text-muted-foreground">No project</span>
                </SelectItem>
                {store.projects.map((p) => (
                    <SelectItem key={p.id} value={p.id}>
                        <span
                            className="size-2 rounded-[2px]"
                            style={{ background: p.color }}
                        />
                        {p.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** The droids on a project, with a picker to bring in more. */
export function CrewCard({
    store,
    pid,
    openChat,
}: {
    store: Store;
    pid: string;
    openChat?: (id: string) => void;
}) {
    const move = useMoveDroid(store);
    const [busy, setBusy] = useState('');
    const crew = store.droids.filter((d) => d.project === pid);
    const others = store.droids.filter((d) => d.project !== pid);
    const run = async (d: Droid, project: string | null) => {
        setBusy(d.id);
        await move(d, project);
        setBusy('');
    };
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="flex flex-row flex-wrap items-center gap-3 px-4">
                <UsersIcon className="size-5 text-muted-foreground" />
                <div className="mr-auto">
                    <CardTitle className="text-base">Crew</CardTitle>
                    <CardDescription>
                        {crew.length
                            ? `${crew.length} droid${crew.length === 1 ? '' : 's'} on this project`
                            : 'No droid on this project yet'}
                    </CardDescription>
                </div>
                {others.length > 0 && (
                    <Select
                        value=""
                        disabled={!!busy}
                        onValueChange={(id) => {
                            const d = others.find((x) => x.id === id);
                            if (d) void run(d, pid);
                        }}
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-auto"
                            aria-label="Add a droid to this project"
                        >
                            <PlusIcon />
                            <span>Add a droid</span>
                        </SelectTrigger>
                        <SelectContent align="end">
                            {others.map((d) => (
                                <SelectItem
                                    key={d.id}
                                    value={d.id}
                                    disabled={!!d.task}
                                >
                                    <span className="font-mono text-xs">
                                        {d.unit}
                                    </span>
                                    {d.name}
                                    <span className="text-xs text-muted-foreground">
                                        {d.role}
                                        {d.project
                                            ? ` · on ${store.projects.find((p) => p.id === d.project)?.name ?? d.project}`
                                            : ''}
                                        {d.task ? ' · busy' : ''}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </CardHeader>
            {crew.length > 0 && (
                <CardContent className="flex flex-wrap gap-2 px-4">
                    {crew.map((d) => (
                        <div
                            key={d.id}
                            className="flex items-center gap-2 rounded-lg border py-1.5 pr-1 pl-2"
                        >
                            <button
                                type="button"
                                className="flex items-center gap-2 text-left"
                                onClick={() => openChat?.(d.id)}
                            >
                                <DroidAvatar
                                    provider={d.provider}
                                    status={d.status}
                                    seed={d.seed}
                                    className="size-7"
                                />
                                <span className="leading-tight">
                                    <span className="block text-sm font-medium">
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {d.unit}
                                        </span>{' '}
                                        {d.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {d.role}
                                    </span>
                                </span>
                            </button>
                            <Badge
                                variant="outline"
                                className={cn(
                                    'rounded-full',
                                    STATUS[d.status].cls,
                                )}
                            >
                                {STATUS[d.status].label}
                            </Badge>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                aria-label={`Take ${d.unit} ${d.name} off this project`}
                                title={
                                    d.task
                                        ? 'Unassign its issue first'
                                        : undefined
                                }
                                disabled={!!busy || !!d.task}
                                onClick={() => void run(d, null)}
                            >
                                <XIcon />
                            </Button>
                        </div>
                    ))}
                </CardContent>
            )}
        </Card>
    );
}
