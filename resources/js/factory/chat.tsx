import { useEffect, useState } from 'react';
import {
    CheckIcon,
    ListChecksIcon,
    NotebookPenIcon,
    PaletteIcon,
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
import { PLAN_DRAFTS, PROPOSALS, RETRO } from '@/factory/demo';
import { DroidAvatar } from '@/factory/droid-avatar';
import { EngineLogo } from '@/factory/logos';
import { cn } from '@/lib/utils';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';

export type ChatTarget = {
    droid: string;
    plan?: string;
    /** A design session for this project's design system and screens. */
    design?: string;
    propose?: boolean;
    reflect?: boolean;
} | null;
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
    const designFor = target?.design;
    const key = planFor
        ? `plan:${planFor}`
        : designFor
          ? `design:${designFor}`
          : (d?.id ?? '');
    const [sending, setSending] = useState(false);
    const [text, setText] = useState('');
    const [model, setModel] = useState(d?.model ?? '');
    const save = useSave();
    const ask = store.asks?.[key];
    const thinking = sending || !!ask;
    useEffect(() => {
        if (d) setModel(d.model);
        setSending(false);
        setText('');
    }, [key]); // eslint-disable-line
    useEffect(() => {
        if (!d || store.asks?.[key]) return;
        const first = target?.reflect
            ? REFLECT
            : target?.propose &&
                planFor &&
                !store.chats[key]?.some((i) => i.kind === 'proposals')
              ? PROPOSE
              : null;
        if (!first) return;
        const t = setTimeout(() => void send(first), 350);
        return () => clearTimeout(t);
    }, [target]); // eslint-disable-line

    if (d && !store.chats[key]) {
        const designed = designFor
            ? (store.design?.[designFor]?.length ?? 0) > 0
            : false;
        store.chats[key] = designFor
            ? [
                  {
                      kind: 'agent',
                      text: designed
                          ? `I'm the designer for **${proj(designFor).name}**. Its design is in the Design tab. Tell me what to change, or which screen to design next.`
                          : `I'm the designer for **${proj(designFor).name}**. I'll read \`${proj(designFor).repo}\` and write a design system and screen mockups that every droid on the project will follow. What should it feel like, and which screens matter most?`,
                  },
              ]
            : planFor
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
    const sugg =
        d && items.length === 1 && !thinking
            ? planFor
                ? [`Plan the rest of ${proj(planFor).milestone}`, PROPOSE]
                : designFor
                  ? [
                        `Create a design system for ${proj(designFor).name}`,
                        'Design the main screens',
                    ]
                  : []
            : [];
    const issue =
        d && !planFor && !designFor
            ? store.issues.find(
                  (i) => i.n === d.task && i.project === d.project,
              )
            : undefined;
    const waiting =
        ask?.status === 'queued'
            ? store.workers?.online
                ? 'Waiting for your worker to pick this up…'
                : 'No worker is online. Start php artisan droids:work to get an answer…'
            : `${d?.unit} is ${ask?.kind === 'reflect' ? 'reviewing its past tasks' : ask?.kind === 'design' ? 'designing' : 'thinking'}…`;

    // Every message goes to a worker, which answers with Claude Code or Codex.
    const send = async (msg: string) => {
        if (!d || !msg.trim() || thinking) return;
        const t = msg.trim();
        const kind = designFor
            ? 'design'
            : t === REFLECT && !planFor
              ? 'reflect'
              : planFor
                ? t === PROPOSE
                    ? 'propose'
                    : 'plan'
                : 'chat';
        const first = items[0];
        const intro =
            first && first.id === undefined && first.kind === 'agent'
                ? first.text
                : undefined;
        if (model && model !== d.model) {
            d.model = model;
            void save('PATCH', `/factory/droids/${encodeURIComponent(d.id)}`, {
                model,
            });
        }
        items.push({ kind: 'user', text: t });
        setText('');
        setSending(true);
        bump();
        const res = await save(
            'POST',
            `/factory/droids/${encodeURIComponent(d.id)}/ask`,
            { kind, project: planFor ?? designFor, text: t, intro },
        );
        if (!res) {
            items.pop();
            setText(t);
        }
        setSending(false);
        bump();
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
                            {!planFor && !designFor && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={thinking}
                                            onClick={() => void send(REFLECT)}
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
                            {designFor ? (
                                <>
                                    <PaletteIcon className="size-4" />
                                    <span>
                                        Design session for{' '}
                                        <span className="text-foreground">
                                            {proj(designFor).name}
                                        </span>{' '}
                                        · files go to its Design tab
                                    </span>
                                </>
                            ) : planFor ? (
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
                                {thinking && (
                                    <Shimmer className="text-sm">
                                        {waiting}
                                    </Shimmer>
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
                                            onClick={(x) => void send(x)}
                                        />
                                    ))}
                                </Suggestions>
                            )}
                            <PromptInput onSubmit={(m) => void send(m.text)}>
                                <PromptInputBody>
                                    <PromptInputTextarea
                                        value={text}
                                        onChange={(e) =>
                                            setText(e.target.value)
                                        }
                                        placeholder={
                                            planFor
                                                ? `Describe what ${proj(planFor).name} should ship next…`
                                                : designFor
                                                  ? `Describe the look, or a screen to design…`
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
                                        status={
                                            thinking ? 'submitted' : 'ready'
                                        }
                                        disabled={thinking || !text.trim()}
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
    if (m.kind === 'design')
        return (
            <Task defaultOpen>
                <TaskTrigger
                    title={`Updated the design of ${proj(m.project).name}: see its Design tab`}
                />
                <TaskContent>
                    {m.changed.map((f) => (
                        <TaskItem key={f}>
                            <TaskItemFile>{f}</TaskItemFile>
                        </TaskItem>
                    ))}
                    {m.removed.map((f) => (
                        <TaskItem key={`-${f}`}>
                            Removed <TaskItemFile>{f}</TaskItemFile>
                        </TaskItem>
                    ))}
                </TaskContent>
            </Task>
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
    if (m.kind === 'candidates')
        return (
            <Task defaultOpen>
                <TaskTrigger
                    title={`Suggested droids for ${proj(m.project).name}: pick one in Assemble droid`}
                />
                <TaskContent>
                    {m.items.map((c) => (
                        <TaskItem key={`${c.name}-${c.role}`}>
                            {c.name}, {c.role.toLowerCase()} on{' '}
                            {PROVIDERS[c.provider].short} {c.model}
                        </TaskItem>
                    ))}
                </TaskContent>
            </Task>
        );
    return <Drafts m={m} bump={bump} proj={proj} d={d} />;
}

/** Save what the person did with a card, or keep it locally for demo items. */
function useMark() {
    const save = useSave();
    return async (
        m: ChatItem,
        patch: { created?: number[]; added?: string[]; applied?: boolean },
        bump: () => void,
    ) => {
        if (m.id === undefined) {
            Object.assign(m, patch);
            bump();
            return;
        }
        await save('PATCH', `/factory/chat/${m.id}`, patch);
    };
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
    const r = m.good
        ? {
              stats: m.stats ?? [],
              good: m.good,
              improve: m.improve ?? [],
              rules: m.rules ?? [],
          }
        : RETRO[m.provider];
    const save = useSave();
    const mark = useMark();
    return (
        <Card className="gap-4 py-4 shadow-none">
            <CardHeader className="px-4">
                <CardTitle className="text-base">
                    Retrospective: last {r.stats[0]?.[1] ?? 0} tasks
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
                {r.rules.length > 0 && (
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
                )}
            </CardContent>
            <CardFooter className="gap-2 px-4">
                {r.rules.length === 0 ? (
                    <span className="text-sm text-muted-foreground">
                        No instruction changes proposed
                    </span>
                ) : m.applied ? (
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
                                await mark(m, { applied: true }, bump);
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
    bump,
    proj,
    d,
}: {
    m: Extract<ChatItem, { kind: 'drafts' }>;
    bump: () => void;
    proj: (id: string) => Store['projects'][number];
    d: Droid;
}) {
    const list = m.items ?? PLAN_DRAFTS[m.project] ?? [];
    const [on, setOn] = useState<boolean[]>(() => list.map(() => true));
    const p = proj(m.project);
    const count = on.filter(Boolean).length;
    const save = useSave();
    const mark = useMark();
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
                        body: t.body,
                    })),
            },
        );
        setBusy(false);
        if (!res) return;
        const made = res.numbers as number[];
        await mark(m, { created: made }, bump);
        toast.success(`${made.length} issues added to the Backlog`, {
            description: `${p.name} · ${d.unit} drafted them. Move them to Ready when you want droids to pick them up.`,
        });
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
    const list = m.items ?? PROPOSALS[m.project] ?? [];
    const p = proj(m.project);
    const save = useSave();
    const mark = useMark();
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
        await mark(m, { added: [...(m.added ?? []), t] }, bump);
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
