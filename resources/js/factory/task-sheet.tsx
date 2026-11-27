import {
    CheckCircle2Icon,
    CircleDashedIcon,
    CircleDotIcon,
    ExternalLinkIcon,
    GitBranchIcon,
    GitMergeIcon,
    GitPullRequestDraftIcon,
    GitPullRequestClosedIcon,
    GitPullRequestIcon,
    LoaderCircleIcon,
    MessageSquareIcon,
    RotateCwIcon,
    XCircleIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    COLS,
    PRIORITIES,
    PROVIDERS,
    type CheckState,
    type ColKey,
    type PR,
    type Priority,
} from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { EngineLogo } from '@/factory/logos';
import { cn } from '@/lib/utils';
import { useSave } from '@/factory/api';
import type { Run, Store } from '@/factory/app';

export const PR_STATE: Record<
    PR['state'],
    { label: string; cls: string; Icon: typeof GitPullRequestIcon }
> = {
    draft: {
        label: 'Draft',
        cls: 'text-muted-foreground border-border',
        Icon: GitPullRequestDraftIcon,
    },
    open: {
        label: 'Open',
        cls: 'text-done border-done/40',
        Icon: GitPullRequestIcon,
    },
    approved: {
        label: 'Approved',
        cls: 'text-done border-done/40 bg-done/10',
        Icon: GitPullRequestIcon,
    },
    changes: {
        label: 'Changes requested',
        cls: 'text-blocked border-blocked/40',
        Icon: GitPullRequestIcon,
    },
    merged: {
        label: 'Merged',
        cls: 'text-codex border-codex/40 bg-codex/10',
        Icon: GitMergeIcon,
    },
    closed: {
        label: 'Closed',
        cls: 'text-muted-foreground border-border',
        Icon: GitPullRequestClosedIcon,
    },
};
export const CheckIcon = ({
    s,
    className,
}: {
    s: CheckState;
    className?: string;
}) =>
    s === 'pass' ? (
        <CheckCircle2Icon
            className={cn('size-4 text-done', className)}
            aria-label="Passed"
        />
    ) : s === 'fail' ? (
        <XCircleIcon
            className={cn('size-4 text-blocked', className)}
            aria-label="Failed"
        />
    ) : s === 'running' ? (
        <LoaderCircleIcon
            className={cn('size-4 animate-spin text-needs', className)}
            aria-label="Running"
        />
    ) : (
        <CircleDashedIcon
            className={cn('size-4 text-muted-foreground', className)}
            aria-label="Pending"
        />
    );

export function checkSummary(pr: PR) {
    const c = (s: CheckState) => pr.checks.filter((x) => x.state === s).length;
    const state: CheckState = c('fail')
        ? 'fail'
        : c('running') || c('pending')
          ? 'running'
          : 'pass';
    return { state, pass: c('pass'), total: pr.checks.length, fail: c('fail') };
}

/** Compact PR line for board cards. */
export function PrChip({ pr, onClick }: { pr: PR; onClick?: () => void }) {
    const s = checkSummary(pr);
    const st = PR_STATE[pr.state];
    return (
        <button
            onClick={onClick}
            className="flex w-full items-center gap-1.5 rounded-md border bg-muted/40 px-2 py-1 text-left text-xs hover:bg-accent"
        >
            <st.Icon className={cn('size-3.5', st.cls.split(' ')[0])} />
            <span className="font-mono">#{pr.n}</span>
            <span className={cn('truncate', st.cls.split(' ')[0])}>
                {st.label}
            </span>
            {pr.state !== 'merged' && (
                <span className="ml-auto flex items-center gap-1 text-muted-foreground tabular-nums">
                    <CheckIcon s={s.state} className="size-3.5" />
                    {s.pass}/{s.total}
                </span>
            )}
        </button>
    );
}

export function TaskSheet({
    store,
    issueKey,
    onClose,
    openChat,
    bump,
    proj,
}: {
    store: Store;
    issueKey: string | null;
    onClose: () => void;
    openChat: (id: string) => void;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
}) {
    const [pid, n] = issueKey ? issueKey.split(':') : ['', ''];
    const i = store.issues.find((x) => x.project === pid && x.n === +n);
    const pr = issueKey ? store.prs[issueKey] : undefined;
    const run = issueKey ? store.runs?.[issueKey] : undefined;
    const p = i ? proj(i.project) : undefined;
    const who = (id?: string) => store.droids.find((d) => d.id === id);
    const b = who(i?.builder),
        r = who(i?.reviewer);
    const s = pr ? checkSummary(pr) : undefined;
    const canMerge = pr && pr.state === 'approved' && s?.state === 'pass';

    const save = useSave();
    const shift = (col: ColKey) => {
        if (!i || !p) return;
        if (col === 'done' && i.col !== 'done') p.done++;
        if (i.col === 'done' && col !== 'done') p.done--;
        i.col = col;
        bump();
    };
    const move = (col: ColKey) => {
        if (!i) return;
        shift(col);
        void save(
            'PATCH',
            `/factory/projects/${encodeURIComponent(i.project)}/tasks/${i.n}`,
            { column: col },
        ).then(
            (res) =>
                res &&
                toast(
                    `#${i.n} moved to ${COLS.find((c) => c.key === col)!.label}`,
                ),
        );
    };
    const prioritize = (priority: Priority | null) => {
        if (!i) return;
        i.priority = priority ?? undefined;
        bump();
        void save(
            'PATCH',
            `/factory/projects/${encodeURIComponent(i.project)}/tasks/${i.n}`,
            { priority },
        );
    };
    const pullUrl = (action: string) =>
        `/factory/projects/${encodeURIComponent(pid)}/pulls/${pr?.n}/${action}`;
    const rerun = () => {
        if (!pr) return;
        const failed = pr.checks.filter((c) => c.state === 'fail');
        failed.forEach((c) => {
            c.state = 'running';
            c.dur = undefined;
        });
        bump();
        if (p?.linked) {
            void save('POST', pullUrl('rerun')).then((res) => {
                if (!res) return;
                const count = Number(res.rerun) || 0;
                toast(
                    count
                        ? `Re-running ${count} failed job${count > 1 ? 's' : ''} on GitHub`
                        : 'Nothing to re-run: these checks are not GitHub Actions jobs',
                );
            });
            return;
        }
        toast('Re-running failed checks (preview)');
        setTimeout(() => {
            failed.forEach((c) => {
                c.state = 'pass';
                c.dur = '1m 04s';
            });
            bump();
        }, 2500);
    };
    const merge = () => {
        if (!pr || !i) return;
        pr.state = 'merged';
        shift('done');
        if (r && r.task === i.n)
            Object.assign(r, { status: 'idle', task: null, step: '' });
        if (p?.linked) {
            void save('POST', pullUrl('merge')).then(
                (res) => res && toast.success(`PR #${pr.n} squash-merged`),
            );
            return;
        }
        toast.success(`PR #${pr.n} merged (preview)`, {
            description:
                'Connect this repository through the GitHub App to merge for real',
        });
    };

    return (
        <Sheet open={!!i} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="w-full gap-0 overflow-y-auto p-0 sm:max-w-[520px]">
                {i && p && (
                    <>
                        <SheetHeader className="gap-2 border-b p-5 pr-12">
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <span
                                    className="size-2 rounded-[2px]"
                                    style={{ background: p.color }}
                                />
                                <span className="font-mono">
                                    {p.repo}#{i.n}
                                </span>
                            </div>
                            <SheetTitle className="text-xl leading-snug">
                                {i.title}
                            </SheetTitle>
                            <SheetDescription className="flex flex-wrap items-center gap-1.5">
                                {i.labels.map((l) => (
                                    <Badge
                                        key={l}
                                        variant="outline"
                                        className={cn(
                                            'rounded-full font-normal',
                                            l === 'bug' &&
                                                'border-blocked/40 text-blocked',
                                        )}
                                    >
                                        {l}
                                    </Badge>
                                ))}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="grid gap-4 p-5">
                            {i.question && i.col === 'backlog' && (
                                <div className="rounded-lg border border-needs/30 bg-needs/10 p-3 text-sm">
                                    <div className="font-medium text-needs">
                                        The planner needs an answer before this
                                        is ready
                                    </div>
                                    <p className="mt-1">{i.question}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Answer in the project discussion, and
                                        the card is triaged again.
                                    </p>
                                </div>
                            )}
                            <div className="grid grid-cols-[96px_minmax(0,1fr)] items-center gap-x-3 gap-y-3 text-sm">
                                <span className="text-muted-foreground">
                                    Status
                                </span>
                                <Select
                                    value={i.col}
                                    onValueChange={(v) => move(v as ColKey)}
                                >
                                    <SelectTrigger
                                        className="h-8 w-44"
                                        aria-label="Status"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {COLS.map((c) => (
                                            <SelectItem
                                                key={c.key}
                                                value={c.key}
                                            >
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <span className="text-muted-foreground">
                                    Priority
                                </span>
                                <Select
                                    value={i.priority ?? 'unset'}
                                    onValueChange={(v) =>
                                        prioritize(
                                            v === 'unset'
                                                ? null
                                                : (v as Priority),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        className="h-8 w-44"
                                        aria-label="Priority"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="unset">
                                            Not set
                                        </SelectItem>
                                        {PRIORITIES.map((x) => (
                                            <SelectItem
                                                key={x.key}
                                                value={x.key}
                                            >
                                                {x.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {[
                                    ['Builder', b],
                                    ['Reviewer', r],
                                ].map(([l, d]) => (
                                    <div key={l as string} className="contents">
                                        <span className="text-muted-foreground">
                                            {l as string}
                                        </span>
                                        {d && typeof d === 'object' ? (
                                            <button
                                                onClick={() => openChat(d.id)}
                                                className="flex w-fit items-center gap-2 rounded-md px-1 py-0.5 hover:bg-accent"
                                            >
                                                <DroidAvatar
                                                    provider={d.provider}
                                                    status={d.status}
                                                    seed={d.seed}
                                                    className="size-6"
                                                />
                                                <span className="font-mono text-xs">
                                                    {d.unit}
                                                </span>
                                                <span>{d.name}</span>
                                                <EngineLogo
                                                    provider={d.provider}
                                                    className="size-3.5"
                                                />
                                                <span className="text-xs text-muted-foreground">
                                                    {d.model}
                                                </span>
                                                <MessageSquareIcon className="size-3.5 text-muted-foreground" />
                                            </button>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Unassigned
                                                {i.engine && l === 'Builder'
                                                    ? ` · suggested ${PROVIDERS[i.engine].label}`
                                                    : ''}
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {run && <RunPanel run={run} />}

                            <Separator />

                            {pr ? (
                                <section
                                    className="grid gap-4"
                                    aria-label="Pull request"
                                >
                                    <div className="grid gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'gap-1 rounded-full',
                                                    PR_STATE[pr.state].cls,
                                                )}
                                            >
                                                {(() => {
                                                    const I =
                                                        PR_STATE[pr.state].Icon;
                                                    return (
                                                        <I className="size-3.5" />
                                                    );
                                                })()}
                                                {PR_STATE[pr.state].label}
                                            </Badge>
                                            <span className="font-mono text-sm">
                                                #{pr.n}
                                            </span>
                                            <span className="ml-auto text-xs tabular-nums">
                                                <span className="text-done">
                                                    +{pr.add}
                                                </span>{' '}
                                                <span className="text-blocked">
                                                    −{pr.del}
                                                </span>{' '}
                                                · {pr.files} files ·{' '}
                                                {pr.commits} commits
                                            </span>
                                        </div>
                                        <div className="font-medium">
                                            {pr.title}
                                        </div>
                                        <div className="flex items-center gap-1.5 font-mono text-xs text-muted-foreground">
                                            <GitBranchIcon className="size-3.5" />
                                            {pr.branch} → main
                                        </div>
                                    </div>

                                    {pr.state !== 'merged' && s && (
                                        <div
                                            className={cn(
                                                'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                                                s.state === 'fail'
                                                    ? 'border-blocked/40 bg-blocked/5'
                                                    : s.state === 'pass'
                                                      ? 'border-done/40 bg-done/5'
                                                      : 'border-needs/40 bg-needs/5',
                                            )}
                                        >
                                            <CheckIcon s={s.state} />
                                            <span>
                                                {s.state === 'fail'
                                                    ? `${s.fail} check${s.fail > 1 ? 's' : ''} failing`
                                                    : s.state === 'pass'
                                                      ? 'All checks passed'
                                                      : 'Checks in progress'}
                                            </span>
                                            <span className="ml-auto text-muted-foreground tabular-nums">
                                                {s.pass} of {s.total} passed
                                            </span>
                                        </div>
                                    )}

                                    <div className="overflow-hidden rounded-lg border">
                                        <div className="flex items-center justify-between border-b bg-muted/50 px-3 py-2 text-sm font-medium">
                                            Checks
                                            {s?.fail ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7"
                                                    onClick={rerun}
                                                >
                                                    <RotateCwIcon />
                                                    Re-run failed
                                                </Button>
                                            ) : null}
                                        </div>
                                        <ul>
                                            {pr.checks.map((c) => (
                                                <li
                                                    key={c.name}
                                                    className="flex items-center gap-2.5 border-b px-3 py-2 text-sm last:border-0"
                                                >
                                                    <CheckIcon s={c.state} />
                                                    <span className="font-mono text-xs">
                                                        {c.name}
                                                    </span>
                                                    <span className="ml-auto text-xs whitespace-nowrap text-muted-foreground">
                                                        {c.state === 'pass' ||
                                                        c.state === 'fail'
                                                            ? c.dur
                                                            : c.state ===
                                                                'running'
                                                              ? 'running'
                                                              : 'queued'}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>

                                    <div className="grid gap-2 text-sm">
                                        <div className="font-medium">
                                            Reviews
                                        </div>
                                        {pr.reviews.length ? (
                                            pr.reviews.map((rv) => {
                                                const d = who(rv.by);
                                                return (
                                                    <div
                                                        key={rv.by}
                                                        className="flex items-center gap-2"
                                                    >
                                                        {d ? (
                                                            <DroidAvatar
                                                                provider={
                                                                    d.provider
                                                                }
                                                                status={
                                                                    d.status
                                                                }
                                                                seed={d.seed}
                                                                className="size-6"
                                                            />
                                                        ) : (
                                                            <span className="grid size-6 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                                                                VT
                                                            </span>
                                                        )}
                                                        <span>
                                                            {d
                                                                ? `${d.unit} ${d.name}`
                                                                : 'vtalbot'}
                                                        </span>
                                                        <span
                                                            className={cn(
                                                                'ml-auto text-xs',
                                                                rv.state ===
                                                                    'approved'
                                                                    ? 'text-done'
                                                                    : rv.state ===
                                                                        'changes'
                                                                      ? 'text-blocked'
                                                                      : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {
                                                                {
                                                                    approved:
                                                                        'Approved',
                                                                    changes:
                                                                        'Requested changes',
                                                                    commented:
                                                                        'Commented',
                                                                    requested:
                                                                        'Review requested',
                                                                }[rv.state]
                                                            }
                                                        </span>
                                                    </div>
                                                );
                                            })
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No reviews yet
                                                {pr.state === 'draft'
                                                    ? ' · PR is still a draft'
                                                    : ''}
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        {pr.state !== 'merged' && (
                                            <Button
                                                size="sm"
                                                disabled={!canMerge}
                                                onClick={merge}
                                            >
                                                <GitMergeIcon />
                                                Merge
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                toast(
                                                    'Opens the pull request on GitHub',
                                                    {
                                                        description: `github.com/${p.repo}/pull/${pr.n}`,
                                                    },
                                                )
                                            }
                                        >
                                            <ExternalLinkIcon />
                                            View on GitHub
                                        </Button>
                                        {b && pr.state !== 'merged' && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => openChat(b.id)}
                                            >
                                                <MessageSquareIcon />
                                                Ask {b.unit}
                                            </Button>
                                        )}
                                    </div>
                                    {pr.state !== 'merged' && !canMerge && (
                                        <p className="text-xs text-muted-foreground">
                                            Merge unlocks when the PR is
                                            approved and every check passes.
                                        </p>
                                    )}
                                </section>
                            ) : (
                                <div className="flex items-center gap-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                    <CircleDotIcon className="size-4" />
                                    {b
                                        ? `No pull request yet. ${b.unit} opens one when the first commit is ready.`
                                        : 'No pull request. Assign a builder to start.'}
                                </div>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

const RUN_STATE: Record<Run['status'], { label: string; cls: string }> = {
    queued: {
        label: 'Waiting for a worker',
        cls: 'text-needs border-needs/40',
    },
    running: { label: 'Running', cls: 'text-working border-working/40' },
    succeeded: { label: 'Done', cls: 'text-done border-done/40' },
    failed: { label: 'Failed', cls: 'text-blocked border-blocked/40' },
    cancelled: { label: 'Cancelled', cls: 'text-muted-foreground' },
};

const RUN_KIND = {
    build: 'Droid run',
    fix: 'Fixing review comments',
    review: 'Review run',
} as const;

function RunPanel({ run }: { run: Run }) {
    const st = RUN_STATE[run.status];
    return (
        <section
            className="grid gap-2"
            aria-label={RUN_KIND[run.kind ?? 'build']}
        >
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span className="font-medium">
                    {RUN_KIND[run.kind ?? 'build']}
                </span>
                <Badge variant="outline" className={st.cls}>
                    {st.label}
                </Badge>
                {run.subscription && (
                    <span className="text-xs text-muted-foreground">
                        on {run.subscription}
                    </span>
                )}
                {run.started && (
                    <span className="text-xs text-muted-foreground">
                        · started {run.started}
                    </span>
                )}
                {run.cost > 0 && (
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        ${run.cost.toFixed(2)}
                    </span>
                )}
            </div>
            {run.error && (
                <p className="rounded-md border border-blocked/40 bg-blocked/5 px-3 py-2 text-xs">
                    {run.error}
                </p>
            )}
            {run.log && (
                <details className="rounded-md border">
                    <summary className="cursor-pointer px-3 py-2 text-xs text-muted-foreground">
                        Log
                    </summary>
                    <pre className="max-h-72 overflow-auto border-t bg-muted/40 p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
                        {run.log}
                    </pre>
                </details>
            )}
        </section>
    );
}
