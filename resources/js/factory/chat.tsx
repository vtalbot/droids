import { useEffect, useState } from 'react';
import {
    CheckIcon,
    ListChecksIcon,
    NotebookPenIcon,
    SparklesIcon,
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    Conversation,
    ConversationContent,
    ConversationScrollButton,
} from '@/components/ai-elements/conversation';
import {
    Message,
    MessageContent,
    MessageResponse,
} from '@/components/ai-elements/message';
import {
    Reasoning,
    ReasoningContent,
    ReasoningTrigger,
} from '@/components/ai-elements/reasoning';
import {
    Tool,
    ToolContent,
    ToolHeader,
    ToolInput,
    ToolOutput,
} from '@/components/ai-elements/tool';
import {
    Task,
    TaskContent,
    TaskItem,
    TaskItemFile,
    TaskTrigger,
} from '@/components/ai-elements/task';
import {
    Plan,
    PlanContent,
    PlanDescription,
    PlanHeader,
    PlanTitle,
    PlanTrigger,
} from '@/components/ai-elements/plan';
import { Suggestion, Suggestions } from '@/components/ai-elements/suggestion';
import { Shimmer } from '@/components/ai-elements/shimmer';
import {
    PromptInput,
    PromptInputBody,
    PromptInputFooter,
    PromptInputSelect,
    PromptInputSelectContent,
    PromptInputSelectItem,
    PromptInputSelectTrigger,
    PromptInputSelectValue,
    PromptInputSubmit,
    PromptInputTextarea,
    PromptInputTools,
} from '@/components/ai-elements/prompt-input';
import { PROVIDERS, STATUS, type ChatItem, type Droid } from '@/factory/data';
import { PLAN_DRAFTS, PROPOSALS, RETRO, SUGGEST } from '@/factory/demo';
import { DroidAvatar } from '@/factory/droid-avatar';
import { EngineLogo } from '@/factory/logos';
import { cn } from '@/lib/utils';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';

export type ChatTarget = {
    droid: string;
    plan?: string;
    propose?: boolean;
    reflect?: boolean;
} | null;
type Act = (d: Droid, s: Store) => string;
const ACTIONS: Record<string, Act> = {
    'Merge PR #418': (d, s) => {
        const i = s.issues.find((x) => x.n === 405)!;
        if (i.col !== 'done') {
            i.col = 'done';
            s.projects[0].done++;
        }
        s.prs['store:405'].state = 'merged';
        Object.assign(d, { status: 'idle', task: null, step: '' });
        s.feed.unshift({
            droid: d.id,
            text: 'Merged PR #418 for #405',
            min: 0,
        });
        toast.success('PR #418 merged', {
            description: '#405 moved to Done on Storefront v3',
        });
        return "Merged **PR #418** and moved #405 to Done. I'm docked and ready for the next review.";
    },
    'Send back to CX-12': (d) => {
        d.status = 'working';
        d.step = 'Writing review notes for CX-12';
        return "Sending it back to CX-12 with notes on placeholder sizing. I'll ping you when Codex pushes a fix.";
    },
    'Grant read access': (d, s) => {
        Object.assign(d, {
            status: 'working',
            step: 'Reading runner Terraform in droids/infra',
            progress: 0.35,
        });
        s.feed.unshift({
            droid: d.id,
            text: 'Unblocked: read access to droids/infra',
            min: 0,
        });
        return 'Thanks, I can see `droids/infra` now. Finishing step 3 of the plan; expect it on #203 within the hour.';
    },
    'Plan without it': (d) => {
        Object.assign(d, {
            status: 'working',
            step: 'Drafting plan with infra assumptions',
        });
        return "Will do. I'll list my assumptions about the runners at the top of the plan so you can check them.";
    },
    'Pinned runs first': (d) => {
        Object.assign(d, {
            status: 'working',
            step: 'Fixing deletion of pinned runs',
        });
        return 'Starting with pinned runs, since that one loses data. Old-run retention after.';
    },
    'Old runs first': (d) => {
        Object.assign(d, {
            status: 'working',
            step: 'Fixing retention of old runs with open PRs',
        });
        return 'Starting with old-run retention. Pinned runs keep being deleted until I get to them.';
    },
};
const REFLECT =
    'Reflect on your last tasks: what went wrong, and how should you work differently?';
const PROPOSE = 'What should come next? Propose features and milestones.';

export function ChatSheet({
    store,
    target,
    onClose,
    bump,
    proj,
}: {
    store: Store;
    target: ChatTarget;
    onClose: () => void;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
}) {
    const d = store.droids.find((x) => x.id === target?.droid);
    const planFor = target?.plan;
    const key = planFor ? `plan:${planFor}` : (d?.id ?? '');
    const [status, setStatus] = useState<'ready' | 'submitted'>('ready');
    const [text, setText] = useState('');
    const [model, setModel] = useState(d?.model ?? '');
    const [usedSugg, setUsedSugg] = useState<Record<string, boolean>>({});
    const save = useSave();
    useEffect(() => {
        if (d) setModel(d.model);
        setStatus('ready');
        setText('');
    }, [key]); // eslint-disable-line
    useEffect(() => {
        if (!d) return;
        const first = target?.reflect
            ? REFLECT
            : target?.propose &&
                planFor &&
                !store.chats[key]?.some((i) => i.kind === 'proposals')
              ? PROPOSE
              : null;
        if (!first) return;
        const t = setTimeout(() => send(first), 350);
        return () => clearTimeout(t);
    }, [target]); // eslint-disable-line

    if (d && !store.chats[key]) {
        store.chats[key] = planFor
            ? [
                  {
                      kind: 'agent',
                      text: `Let's plan **${proj(planFor).name}**. The current milestone is *${proj(planFor).milestone}* (${proj(planFor).done} of ${proj(planFor).total} closed, due ${proj(planFor).due}). Tell me what you want to ship and I'll draft issues for the **${proj(planFor).board}** board, with the engine I'd give each one.`,
                  },
              ]
            : [
                  {
                      kind: 'agent',
                      text:
                          d.status === 'idle'
                              ? `${d.unit} online and docked. Give me an issue from **${proj(d.project).board}**, or ask me anything about \`${proj(d.project).repo}\`.`
                              : `Working on **#${d.task}**. Current step: ${d.step}.`,
                  },
              ];
    }
    const items: ChatItem[] = d ? store.chats[key] : [];
    const last = items[items.length - 1];
    const sugg = !d
        ? []
        : planFor
          ? items.length === 1
              ? [`Plan the rest of ${proj(planFor).milestone}`, PROPOSE]
              : []
          : last?.kind === 'agent' && !usedSugg[d.id]
            ? (SUGGEST[d.id] ?? [])
            : [];
    const issue =
        d && !planFor
            ? store.issues.find(
                  (i) => i.n === d.task && i.project === d.project,
              )
            : undefined;

    const send = (msg: string) => {
        if (!d || !msg.trim()) return;
        const t = msg.trim();
        items.push({ kind: 'user', text: t });
        if (SUGGEST[d.id]?.includes(t))
            setUsedSugg((u) => ({ ...u, [d.id]: true }));
        if (model && model !== d.model) {
            d.model = model;
            void save('PATCH', `/factory/droids/${encodeURIComponent(d.id)}`, {
                model,
            });
        }
        setText('');
        setStatus('submitted');
        bump();
        setTimeout(() => {
            if (t === REFLECT) {
                items.push({
                    kind: 'agent',
                    reasoning: `Looked back over my last ${RETRO[d.provider].stats[0][1]} tasks: PR timelines, review comments, CI runs on first push, and your replies in chat.`,
                    text: "Here's my retrospective. The rule changes below would go into my instructions; approve them and I'll follow them from my next task.",
                });
                items.push({ kind: 'retro', provider: d.provider });
            } else if (planFor && t === PROPOSE) {
                items.push({
                    kind: 'agent',
                    reasoning: `Compared ${proj(planFor).repo}'s roadmap with closed issues, support themes linked in issues, PR review comments and production errors from Laravel Cloud logs over the last 30 days.`,
                    text: "Here's what I'd do next, ranked by impact. Add any of them to the roadmap; features go into the active milestone.",
                });
                items.push({ kind: 'proposals', project: planFor });
            } else if (planFor && !items.some((i) => i.kind === 'drafts')) {
                items.push({
                    kind: 'agent',
                    reasoning: `Read the open issues on ${proj(planFor).board}, the milestone description and the last 2 weeks of merged PRs in ${proj(planFor).repo}. Grouped the remaining work into issues a single droid can finish in under a day.`,
                    text: "Here's a first cut. I picked **Codex** for scoped implementation and **Claude Code** where the task needs design or careful review. Untick anything you don't want.",
                });
                items.push({ kind: 'drafts', project: planFor });
            } else {
                const act = ACTIONS[t];
                items.push({
                    kind: 'agent',
                    text: act
                        ? act(d, store)
                        : planFor
                          ? "Got it. I'll fold that into the plan; ask me to redraft when you're ready."
                          : d.status === 'idle'
                            ? "Sounds good. Assign me an issue from the board and I'll start on a fresh branch."
                            : `Noted. I'll fold that into **#${d.task}** and call it out in the PR description.`,
                });
            }
            setStatus('ready');
            bump();
        }, 1100);
    };

    return (
        <Sheet open={!!d} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-[500px]">
                {d && (
                    <>
                        <SheetHeader className="flex-row items-center gap-3 border-b p-4 pr-12">
                            <DroidAvatar
                                provider={d.provider}
                                status={d.status}
                                seed={d.seed}
                                className="size-11"
                            />
                            <div className="min-w-0 flex-1">
                                <SheetTitle className="flex items-baseline gap-1.5">
                                    <span className="font-mono text-sm">
                                        {d.unit}
                                    </span>
                                    {d.name}
                                </SheetTitle>
                                <SheetDescription className="mt-1 flex flex-wrap items-center gap-1.5">
                                    <EngineLogo
                                        provider={d.provider}
                                        className="size-3.5"
                                    />
                                    <span>
                                        {PROVIDERS[d.provider].label} · {d.role}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'rounded-full',
                                            STATUS[d.status].cls,
                                        )}
                                    >
                                        {STATUS[d.status].label}
                                    </Badge>
                                </SheetDescription>
                            </div>
                            {!planFor && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={status !== 'ready'}
                                            onClick={() => send(REFLECT)}
                                        >
                                            <SparklesIcon />
                                            Reflect
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        Ask {d.unit} to review its past tasks
                                        and propose how to improve
                                    </TooltipContent>
                                </Tooltip>
                            )}
                        </SheetHeader>
                        <div className="flex items-center gap-2 border-b px-4 py-2.5 text-sm text-muted-foreground">
                            {planFor ? (
                                <>
                                    <NotebookPenIcon className="size-4" />
                                    <span>
                                        Planning session for{' '}
                                        <span className="text-foreground">
                                            {proj(planFor).name}
                                        </span>{' '}
                                        · drafts go to {proj(planFor).board}
                                    </span>
                                </>
                            ) : issue ? (
                                <>
                                    <span className="font-mono text-xs">
                                        {proj(d.project).repo}#{issue.n}
                                    </span>
                                    <span className="truncate text-foreground">
                                        {issue.title}
                                    </span>
                                </>
                            ) : (
                                <span>Docked on {proj(d.project).name}</span>
                            )}
                        </div>
                        <Conversation className="min-h-0">
                            <ConversationContent className="gap-5">
                                {items.map((m, k) => (
                                    <ChatRow
                                        key={k}
                                        m={m}
                                        d={d}
                                        store={store}
                                        bump={bump}
                                        proj={proj}
                                    />
                                ))}
                                {status === 'submitted' && (
                                    <Shimmer className="text-sm">{`${d.unit} is ${items[items.length - 1]?.kind === 'user' && (items[items.length - 1] as { text: string }).text === REFLECT ? 'reviewing its past tasks' : 'thinking'}…`}</Shimmer>
                                )}
                            </ConversationContent>
                            <ConversationScrollButton />
                        </Conversation>
                        <div className="grid gap-3 border-t p-4">
                            {sugg.length > 0 && (
                                <Suggestions>
                                    {sugg.map((s) => (
                                        <Suggestion
                                            key={s}
                                            suggestion={s}
                                            onClick={send}
                                        />
                                    ))}
                                </Suggestions>
                            )}
                            <PromptInput onSubmit={(m) => send(m.text)}>
                                <PromptInputBody>
                                    <PromptInputTextarea
                                        value={text}
                                        onChange={(e) =>
                                            setText(e.target.value)
                                        }
                                        placeholder={
                                            planFor
                                                ? `Describe what ${proj(planFor).name} should ship next…`
                                                : `Message ${d.unit} ${d.name}…`
                                        }
                                    />
                                </PromptInputBody>
                                <PromptInputFooter>
                                    <PromptInputTools>
                                        <PromptInputSelect
                                            value={model}
                                            onValueChange={setModel}
                                        >
                                            <PromptInputSelectTrigger className="h-8">
                                                <EngineLogo
                                                    provider={d.provider}
                                                    className="size-3.5"
                                                />
                                                <PromptInputSelectValue />
                                            </PromptInputSelectTrigger>
                                            <PromptInputSelectContent>
                                                {PROVIDERS[
                                                    d.provider
                                                ].models.map((m) => (
                                                    <PromptInputSelectItem
                                                        key={m}
                                                        value={m}
                                                    >
                                                        {m}
                                                    </PromptInputSelectItem>
                                                ))}
                                            </PromptInputSelectContent>
                                        </PromptInputSelect>
                                    </PromptInputTools>
                                    <PromptInputSubmit
                                        status={status}
                                        disabled={
                                            !text.trim() && status === 'ready'
                                        }
                                    />
                                </PromptInputFooter>
                            </PromptInput>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function ChatRow({
    m,
    d,
    store,
    bump,
    proj,
}: {
    m: ChatItem;
    d: Droid;
    store: Store;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
}) {
    if (m.kind === 'user')
        return (
            <Message from="user">
                <MessageContent>
                    <MessageResponse>{m.text}</MessageResponse>
                </MessageContent>
            </Message>
        );
    if (m.kind === 'agent')
        return (
            <Message from="assistant">
                <MessageContent>
                    {m.reasoning && (
                        <Reasoning
                            defaultOpen={false}
                            duration={d.provider === 'claude' ? 6 : 4}
                        >
                            <ReasoningTrigger />
                            <ReasoningContent>{m.reasoning}</ReasoningContent>
                        </Reasoning>
                    )}
                    <MessageResponse>{m.text}</MessageResponse>
                </MessageContent>
            </Message>
        );
    if (m.kind === 'tool')
        return (
            <Tool defaultOpen={m.state === 'output-error'}>
                <ToolHeader
                    type={`tool-${m.name}`}
                    state={m.state}
                    title={m.title}
                />
                <ToolContent>
                    <ToolInput input={m.input} />
                    {(m.output || m.error) && (
                        <ToolOutput output={m.output} errorText={m.error} />
                    )}
                </ToolContent>
            </Tool>
        );
    if (m.kind === 'task')
        return (
            <Task defaultOpen>
                <TaskTrigger title={m.title} />
                <TaskContent>
                    {m.items.map((f) => (
                        <TaskItem key={f}>
                            <TaskItemFile>{f}</TaskItemFile>
                        </TaskItem>
                    ))}
                </TaskContent>
            </Task>
        );
    if (m.kind === 'plan')
        return (
            <Plan defaultOpen>
                <PlanHeader>
                    <div>
                        <div className="flex items-center gap-2">
                            <ListChecksIcon className="size-4" />
                            <PlanTitle>{m.title}</PlanTitle>
                        </div>
                        <PlanDescription>{m.description}</PlanDescription>
                    </div>
                    <PlanTrigger />
                </PlanHeader>
                <PlanContent>
                    <ol className="grid list-decimal gap-1.5 pl-5 text-sm">
                        {m.steps.map((s, i) => (
                            <li key={s} className={cn(i === 2 && 'text-needs')}>
                                {s}
                                {i === 2 && ' (waiting on access)'}
                            </li>
                        ))}
                    </ol>
                </PlanContent>
            </Plan>
        );
    if (m.kind === 'retro')
        return <Retro m={m} d={d} bump={bump} store={store} />;
    if (m.kind === 'proposals')
        return <Proposals m={m} store={store} bump={bump} proj={proj} />;
    return <Drafts m={m} store={store} bump={bump} proj={proj} d={d} />;
}

function Retro({
    m,
    d,
    bump,
}: {
    m: Extract<ChatItem, { kind: 'retro' }>;
    d: Droid;
    bump: () => void;
    store: Store;
}) {
    const r = RETRO[m.provider];
    const save = useSave();
    return (
        <Card className="gap-4 py-4 shadow-none">
            <CardHeader className="px-4">
                <CardTitle className="text-base">
                    Retrospective: last {r.stats[0][1]} tasks
                </CardTitle>
                <CardDescription>
                    {d.unit} {d.name} · {PROVIDERS[d.provider].label}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 px-4 text-sm">
                <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-4">
                    {r.stats.slice(1).map(([k, v]) => (
                        <div key={k} className="bg-card px-3 py-2">
                            <div className="font-semibold tabular-nums">
                                {v}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {k}
                            </div>
                        </div>
                    ))}
                </div>
                <div>
                    <div className="mb-1 font-medium">Went well</div>
                    <ul className="grid list-disc gap-1 pl-5 text-muted-foreground">
                        {r.good.map((x) => (
                            <li key={x}>{x}</li>
                        ))}
                    </ul>
                </div>
                <div>
                    <div className="mb-1 font-medium">Went wrong</div>
                    <ul className="grid list-disc gap-1 pl-5 text-muted-foreground">
                        {r.improve.map((x) => (
                            <li key={x}>{x}</li>
                        ))}
                    </ul>
                </div>
                <div>
                    <div className="mb-1.5 font-medium">
                        Proposed instruction changes
                    </div>
                    <div className="grid gap-1 rounded-lg border bg-muted/40 p-2.5 font-mono text-xs">
                        {r.rules.map((x) => (
                            <div key={x} className="text-done">
                                + {x}
                            </div>
                        ))}
                    </div>
                </div>
            </CardContent>
            <CardFooter className="gap-2 px-4">
                {m.applied ? (
                    <span className="flex items-center gap-1.5 text-sm text-done">
                        <CheckIcon className="size-4" />
                        Added to {d.unit}'s instructions
                    </span>
                ) : (
                    <>
                        <Button
                            size="sm"
                            onClick={async () => {
                                const res = await save(
                                    'PATCH',
                                    `/factory/droids/${encodeURIComponent(d.id)}`,
                                    { add_rules: r.rules },
                                );
                                if (!res) return;
                                m.applied = true;
                                toast.success(
                                    `${d.unit} instructions updated`,
                                    {
                                        description: `${r.rules.length} rules added`,
                                    },
                                );
                                bump();
                            }}
                        >
                            Apply to instructions
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                toast('Kept as a note', {
                                    description:
                                        'Nothing changed in the instructions',
                                })
                            }
                        >
                            Keep as a note
                        </Button>
                    </>
                )}
            </CardFooter>
        </Card>
    );
}

function Drafts({
    m,
    store,
    bump,
    proj,
    d,
}: {
    m: Extract<ChatItem, { kind: 'drafts' }>;
    store: Store;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
    d: Droid;
}) {
    const list = PLAN_DRAFTS[m.project] ?? [];
    const [on, setOn] = useState<boolean[]>(() => list.map(() => true));
    const p = proj(m.project);
    const count = on.filter(Boolean).length;
    const save = useSave();
    const [busy, setBusy] = useState(false);
    const create = async () => {
        setBusy(true);
        const res = await save(
            'POST',
            `/factory/projects/${encodeURIComponent(m.project)}/tasks`,
            {
                tasks: list
                    .filter((_, i) => on[i])
                    .map((t) => ({
                        title: t.title,
                        labels: t.labels,
                        engine: t.engine,
                    })),
            },
        );
        setBusy(false);
        if (!res) return;
        const made = res.numbers as number[];
        m.created = made;
        store.chats[`plan:${m.project}`]?.push({
            kind: 'agent',
            text: `Created ${made.map((x) => `#${x}`).join(', ')} in the **Backlog** of ${p.name}. Move them to Ready when you want droids to pick them up.`,
        });
        toast.success(`${made.length} issues added to the Backlog`, {
            description: `${p.name} · ${d.unit} drafted them`,
        });
        bump();
    };
    return (
        <Card className="gap-3 py-4 shadow-none">
            <CardHeader className="px-4">
                <CardTitle className="text-base">
                    Draft issues for {p.name}
                </CardTitle>
                <CardDescription>
                    {p.repo} · milestone {p.milestone}
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-1 px-2">
                {list.map((t, i) => (
                    <label
                        key={t.title}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 rounded-md px-2 py-2 hover:bg-accent',
                            m.created && 'cursor-default opacity-80',
                        )}
                    >
                        <Checkbox
                            checked={on[i]}
                            disabled={!!m.created}
                            onCheckedChange={(v) =>
                                setOn((o) =>
                                    o.map((x, k) => (k === i ? !!v : x)),
                                )
                            }
                            className="mt-0.5"
                        />
                        <span className="grid min-w-0 flex-1 gap-1">
                            <span className="text-sm leading-snug">
                                {t.title}
                            </span>
                            <span className="flex flex-wrap items-center gap-1.5">
                                {t.labels.map((l) => (
                                    <Badge
                                        key={l}
                                        variant="outline"
                                        className="rounded-full px-1.5 py-0 text-[11px] font-normal"
                                    >
                                        {l}
                                    </Badge>
                                ))}
                                <span className="ml-auto flex items-center gap-1 text-xs text-muted-foreground">
                                    <EngineLogo
                                        provider={t.engine}
                                        className="size-3.5"
                                    />
                                    {PROVIDERS[t.engine].short} · size {t.size}
                                </span>
                            </span>
                        </span>
                    </label>
                ))}
            </CardContent>
            <CardFooter className="px-4">
                {m.created ? (
                    <span className="flex items-center gap-1.5 text-sm text-done">
                        <CheckIcon className="size-4" />
                        Created {m.created.map((x) => `#${x}`).join(', ')}
                    </span>
                ) : (
                    <Button
                        size="sm"
                        disabled={!count || busy}
                        onClick={create}
                    >
                        Create {count} issue{count === 1 ? '' : 's'} on GitHub
                    </Button>
                )}
            </CardFooter>
        </Card>
    );
}

function Proposals({
    m,
    store,
    bump,
    proj,
}: {
    m: Extract<ChatItem, { kind: 'proposals' }>;
    store: Store;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
}) {
    const list = PROPOSALS[m.project] ?? [];
    const p = proj(m.project);
    const save = useSave();
    const add = async (t: string) => {
        const pr = list.find((x) => x.title === t)!;
        const ms = store.roadmap[m.project] ?? [];
        const target =
            ms.find((x) => x.state === 'active') ?? ms[ms.length - 1];
        if (pr.kind === 'feature' && !target) {
            toast.error('Add a milestone first, then add features to it');
            return;
        }
        const res =
            pr.kind === 'milestone'
                ? await save(
                      'POST',
                      `/factory/projects/${encodeURIComponent(m.project)}/milestones`,
                      { name: pr.title, goal: pr.why },
                  )
                : await save(
                      'POST',
                      `/factory/milestones/${target.id.slice(1)}/features`,
                      { name: pr.title },
                  );
        if (!res) return;
        (m.added ??= []).push(t);
        bump();
        toast.success(
            `${pr.kind === 'milestone' ? 'Milestone' : 'Feature'} added to ${p.name}`,
            { description: pr.title },
        );
    };
    return (
        <div className="grid gap-2">
            {list.map((x) => {
                const added = m.added?.includes(x.title);
                return (
                    <Card key={x.title} className="gap-2 py-3 shadow-none">
                        <CardHeader className="flex flex-row items-start gap-2 px-4">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <Badge
                                        variant="outline"
                                        className="rounded-full font-normal capitalize"
                                    >
                                        {x.kind}
                                    </Badge>
                                    <span className="font-medium">
                                        {x.title}
                                    </span>
                                </div>
                            </div>
                            {added ? (
                                <span className="flex items-center gap-1 text-xs text-done">
                                    <CheckIcon className="size-3.5" />
                                    Added
                                </span>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-7"
                                    onClick={() => add(x.title)}
                                >
                                    Add to roadmap
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="grid gap-1.5 px-4 text-sm">
                            <p className="text-muted-foreground">{x.why}</p>
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <EngineLogo
                                    provider={x.engine}
                                    className="size-3.5"
                                />
                                Best fit {PROVIDERS[x.engine].label} ·{' '}
                                {x.effort}
                            </div>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
