import { useEffect, useMemo, useState } from 'react';
import {
    FileCode2Icon,
    FileTextIcon,
    LayoutTemplateIcon,
    MonitorIcon,
    PaletteIcon,
    RefreshCwIcon,
    SmartphoneIcon,
    Trash2Icon,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { MessageResponse } from '@/components/ai-elements/message';
import { request, useSave } from '@/factory/api';
import type { Store } from '@/factory/app';
import { cn } from '@/lib/utils';

type Loaded = {
    path: string;
    content: string;
    by?: string | null;
    updated?: string | null;
};

/** Group order in the file list: the system first, then tokens, then screens, then anything else. */
const rank = (path: string) =>
    path === 'system.md'
        ? 0
        : path.startsWith('tokens')
          ? 1
          : path.startsWith('screens/')
            ? 2
            : 3;

const icon = (path: string) =>
    path.endsWith('.html')
        ? LayoutTemplateIcon
        : path.endsWith('.md')
          ? FileTextIcon
          : path.endsWith('.css')
            ? PaletteIcon
            : FileCode2Icon;

/** Color tokens declared in a CSS file, as [name, value] pairs. */
export function colorTokens(css: string): [string, string][] {
    const out: [string, string][] = [];
    const seen = new Set<string>();
    for (const m of css.matchAll(
        /(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{3,8}|(?:rgb|rgba|hsl|hsla|oklch|oklab|lab|lch)\([^)]*\))\s*;/g,
    )) {
        if (seen.has(m[1])) continue;
        seen.add(m[1]);
        out.push([m[1], m[2]]);
    }
    return out;
}

export function DesignPanel({
    store,
    bump,
    pid,
    designWith,
}: {
    store: Store;
    bump: () => void;
    pid: string;
    designWith: (pid: string) => void;
}) {
    const meta = useMemo(
        () =>
            [...(store.design?.[pid] ?? [])].sort(
                (a, b) =>
                    rank(a.path) - rank(b.path) || a.path.localeCompare(b.path),
            ),
        [store.design, pid],
    );
    const stamp = meta.map((f) => `${f.path}@${f.updated}@${f.size}`).join('|');
    const [files, setFiles] = useState<Loaded[] | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const [width, setWidth] = useState<'desktop' | 'mobile'>('desktop');
    const [error, setError] = useState<string | null>(null);
    const [tick, setTick] = useState(0);
    const save = useSave();

    // Load the contents whenever the designer changes something.
    useEffect(() => {
        if (!meta.length) {
            setFiles([]);
            return;
        }
        let gone = false;
        setError(null);
        request('GET', `/factory/projects/${encodeURIComponent(pid)}/design`)
            .then((res) => {
                if (!gone) setFiles((res as { files?: Loaded[] }).files ?? []);
            })
            .catch((e: Error) => !gone && setError(e.message));
        return () => {
            gone = true;
        };
    }, [pid, stamp, tick]); // eslint-disable-line react-hooks/exhaustive-deps

    const current =
        files?.find((f) => f.path === selected) ??
        files?.find((f) => f.path === meta[0]?.path) ??
        null;
    const designer = store.droids.find(
        (d) => d.id === (current?.by ?? meta[0]?.by),
    );

    if (!meta.length)
        return (
            <div className="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                <PaletteIcon className="size-8 text-muted-foreground" />
                <div className="text-lg font-semibold">No design yet</div>
                <p className="max-w-md text-muted-foreground">
                    A designer droid writes the design system and screen mockups
                    here. Every builder, reviewer and planner on this project
                    then works from them.
                </p>
                <Button onClick={() => designWith(pid)}>
                    <PaletteIcon />
                    Ask a designer
                </Button>
            </div>
        );

    const remove = async (path: string) => {
        const res = await save(
            'DELETE',
            `/factory/projects/${encodeURIComponent(pid)}/design`,
            { path },
        );
        if (res) {
            if (selected === path) setSelected(null);
            bump();
        }
    };

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {meta.length} file{meta.length === 1 ? '' : 's'}
                    {designer ? ` by ${designer.unit} ${designer.name}` : ''}.
                    Every droid on this project gets them in{' '}
                    <code className="font-mono text-xs">.droids/design/</code>{' '}
                    and is told to follow them.
                </p>
                <Button variant="outline" onClick={() => designWith(pid)}>
                    <PaletteIcon />
                    Ask the designer
                </Button>
            </div>
            <div className="grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]">
                <nav
                    aria-label="Design files"
                    className="flex flex-col gap-0.5 rounded-xl border p-1.5"
                >
                    {meta.map((f) => {
                        const Icon = icon(f.path);
                        const on = (current?.path ?? meta[0].path) === f.path;
                        return (
                            <button
                                key={f.path}
                                onClick={() => setSelected(f.path)}
                                className={cn(
                                    'flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent',
                                    on && 'bg-accent font-medium',
                                )}
                            >
                                <Icon className="size-4 shrink-0 text-muted-foreground" />
                                <span className="truncate font-mono text-xs">
                                    {f.path}
                                </span>
                            </button>
                        );
                    })}
                </nav>
                <section className="min-w-0 rounded-xl border">
                    {error ? (
                        <div className="flex items-center justify-between gap-3 p-4 text-sm text-blocked">
                            {error}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setTick((t) => t + 1)}
                            >
                                <RefreshCwIcon />
                                Retry
                            </Button>
                        </div>
                    ) : !current ? (
                        <div className="p-4 text-sm text-muted-foreground">
                            Loading the design…
                        </div>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-2 border-b px-4 py-2.5">
                                <span className="font-mono text-sm">
                                    {current.path}
                                </span>
                                {current.updated && (
                                    <span className="text-xs text-muted-foreground">
                                        updated {current.updated}
                                    </span>
                                )}
                                <span className="ml-auto flex items-center gap-1">
                                    {current.path.endsWith('.html') && (
                                        <ToggleGroup
                                            type="single"
                                            size="sm"
                                            value={width}
                                            onValueChange={(v) =>
                                                v &&
                                                setWidth(
                                                    v as 'desktop' | 'mobile',
                                                )
                                            }
                                            aria-label="Preview width"
                                        >
                                            <ToggleGroupItem
                                                value="desktop"
                                                aria-label="Desktop width"
                                            >
                                                <MonitorIcon />
                                            </ToggleGroupItem>
                                            <ToggleGroupItem
                                                value="mobile"
                                                aria-label="Phone width"
                                            >
                                                <SmartphoneIcon />
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                    )}
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label={`Remove ${current.path}`}
                                        onClick={() =>
                                            void remove(current.path)
                                        }
                                    >
                                        <Trash2Icon />
                                    </Button>
                                </span>
                            </div>
                            <Preview file={current} width={width} />
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}

function Preview({
    file,
    width,
}: {
    file: Loaded;
    width: 'desktop' | 'mobile';
}) {
    if (file.path.endsWith('.html'))
        return (
            <div className="bg-muted/40 p-3">
                <iframe
                    title={file.path}
                    // No scripts, no same-origin access: a mockup is only looked at.
                    sandbox=""
                    srcDoc={file.content}
                    className={cn(
                        'mx-auto block h-[680px] w-full rounded-md border bg-white',
                        width === 'mobile' && 'max-w-[390px]',
                    )}
                />
            </div>
        );
    if (file.path.endsWith('.md'))
        return (
            <div className="max-w-none p-5 text-sm">
                <MessageResponse>{file.content}</MessageResponse>
            </div>
        );
    const colors = file.path.endsWith('.css') ? colorTokens(file.content) : [];
    return (
        <div className="grid gap-4 p-4">
            {colors.length > 0 && (
                <ul className="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2">
                    {colors.map(([name, value]) => (
                        <li
                            key={name}
                            className="flex items-center gap-2 rounded-md border p-1.5"
                        >
                            <span
                                className="size-7 shrink-0 rounded border"
                                style={{ background: value }}
                            />
                            <span className="min-w-0">
                                <span className="block truncate font-mono text-xs">
                                    {name}
                                </span>
                                <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                    {value}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <pre className="max-h-[560px] overflow-auto rounded-md bg-muted p-3 font-mono text-xs">
                {file.content}
            </pre>
        </div>
    );
}
