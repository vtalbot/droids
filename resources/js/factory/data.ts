// Shared types and constants for the factory UI. Data comes from the server (FactoryState).
export type Provider = 'claude' | 'codex';
export type Status = 'working' | 'needs' | 'blocked' | 'idle';
export type ColKey = 'backlog' | 'ready' | 'progress' | 'review' | 'done';

export type Project = {
    id: string;
    name: string;
    repo: string;
    board: string;
    color: string;
    linked?: boolean;
    synced?: string | null;
    /** The Planner triages Ideas into Ready for droids. */
    autoTriage?: boolean;
    /** Idle builders on the project take cards from Ready for droids. */
    autoBuild?: boolean;
    /** The autopilot merges approved pull requests whose checks pass. */
    autoMerge?: boolean;
    milestone: string;
    due: string;
    done: number;
    total: number;
};
export type Droid = {
    id: string;
    unit: string;
    name: string;
    provider: Provider;
    model: string;
    role: string;
    status: Status;
    /** The project the droid is on: set from the project's crew or the Droids table; null when on none. */
    project: string | null;
    task: number | null;
    step: string;
    progress: number;
    minutes: number;
    cost: number;
    seed: number;
    autonomy?: Autonomy;
    instructions?: string | null;
};
export type Autonomy = 'ask' | 'pr' | 'merge';
export const AUTONOMY: Record<Autonomy, string> = {
    ask: 'Asks before acting',
    pr: 'Opens pull requests',
    merge: 'Merges when checks pass',
};
export type Issue = {
    n: number;
    project: string;
    title: string;
    col: ColKey;
    labels: string[];
    builder?: string;
    reviewer?: string;
    engine?: Provider;
    /** What the triage droid asked before the card can be built. */
    question?: string | null;
    /** The crew role the question went to, or null when it waits on the person. */
    questionFor?: string | null;
    /** Card numbers in the same project that must be merged before a droid builds this one. */
    blockedBy?: number[];
    /** Set by the triage droid or the person; builders take the highest first. */
    priority?: Priority;
};
export type Priority = 'urgent' | 'high' | 'medium' | 'low';
export const PRIORITIES: { key: Priority; label: string; tone: string }[] = [
    { key: 'urgent', label: 'Urgent', tone: 'border-blocked/40 text-blocked' },
    { key: 'high', label: 'High', tone: 'border-needs/40 text-needs' },
    { key: 'medium', label: 'Medium', tone: 'text-muted-foreground' },
    { key: 'low', label: 'Low', tone: 'text-muted-foreground/70' },
];
/** Sort rank, highest priority first; an unset priority counts as medium. */
export const priorityRank = (p?: Priority) =>
    PRIORITIES.findIndex((x) => x.key === (p ?? 'medium'));
/** A chat item as stored on the server; `id` is missing on items not saved yet. */
export type ChatItem = { id?: number } & ChatItemBody;
type ChatItemBody =
    | { kind: 'user'; text: string }
    | {
          kind: 'agent';
          text: string;
          reasoning?: string;
          /** Follow-ups the droid expects, shown as buttons above the prompt. */
          next?: string[];
          /** What the droid added to the project's shared memory with this reply. */
          remembered?: string[];
      }
    | {
          kind: 'tool';
          name: string;
          title: string;
          state: 'output-available' | 'input-available' | 'output-error';
          input: Record<string, unknown>;
          output?: string;
          error?: string;
      }
    | { kind: 'task'; title: string; items: string[] }
    | { kind: 'plan'; title: string; description: string; steps: string[] }
    | {
          kind: 'retro';
          provider: Provider;
          applied?: boolean;
          stats?: [string, string][];
          good?: string[];
          improve?: string[];
          rules?: string[];
      }
    | {
          kind: 'drafts';
          project: string;
          created?: number[];
          items?: Draft[];
      }
    | {
          kind: 'proposals';
          project: string;
          added?: string[];
          items?: Proposal[];
      }
    | {
          kind: 'variables';
          project: string;
          items: { key: string; value: string; environment?: string }[];
          applied?: boolean;
      }
    | {
          kind: 'candidates';
          items: Candidate[];
      }
    | {
          /** Values a droid suggests for a form, which the form fills itself from. */
          kind: 'suggestion';
          form?: string;
          fields?: Record<string, string>;
          text?: string;
      }
    | {
          kind: 'design';
          project: string;
          changed: string[];
          removed: string[];
      };
/** A question a droid is answering on a worker, keyed by conversation. */
export type Ask = {
    kind:
        | 'chat'
        | 'plan'
        | 'propose'
        | 'reflect'
        | 'design'
        | 'recruit'
        | 'triage'
        | 'suggest'
        | 'unblock'
        | 'answer';
    status: 'queued' | 'running';
    /** Why no worker can take it yet, when queued. */
    why?: string;
};

export const PROVIDERS: Record<
    Provider,
    { label: string; short: string; models: string[] }
> = {
    claude: {
        label: 'Claude Code',
        short: 'Claude',
        models: ['Opus 5.5', 'Sonnet 5.5', 'Haiku 5.5'],
    },
    codex: {
        label: 'Codex',
        short: 'Codex',
        models: [
            'GPT-6.2-Sol',
            'GPT-6.2-Astra',
            'GPT-6.1-Terra',
            'GPT-6.1-Sol',
            'GPT-6-Astra',
            'GPT-6-Sol',
            'GPT-6-Luna',
        ],
    },
};

/** What each role does and the model that fits it; light roles run well on small models. */
/** The models to offer for an engine: what the workers found, else the built-in list. */
export const modelsFor = (
    store: { models?: Partial<Record<Provider, string[]>> } | null,
    provider: Provider,
) => store?.models?.[provider] ?? PROVIDERS[provider].models;

export const ROLES: {
    role: string;
    does: string;
    model: string;
    light?: boolean;
}[] = [
    {
        role: 'Builder',
        does: 'Builds Ready cards and opens a pull request for each',
        model: 'Sonnet 5.5, Opus 5.5 or the newest GPT model on Codex',
    },
    {
        role: 'Reviewer',
        does: 'Reviews pull requests and sends changes back to the builder',
        model: 'Opus 5.5, on the other engine than the builder',
    },
    {
        role: 'Planner',
        does: 'Plans tasks and milestones with you in the project discussion',
        model: 'Opus 5.5 or Sonnet 5.5',
    },
    {
        role: 'Designer',
        does: 'Keeps the design files of the project up to date',
        model: 'Opus 5.5',
    },
    {
        role: 'Tester',
        does: 'Takes Ready cards labelled test and writes the missing tests',
        model: 'Sonnet 5.5 or a lighter GPT model on Codex',
    },
    {
        role: 'Docs',
        does: 'Takes Ready cards labelled docs and writes the documentation',
        model: 'Haiku 5.5 or the lightest GPT model on Codex',
        light: true,
    },
    {
        role: 'Triager',
        does: 'Triages Ideas: acceptance criteria, priority and questions',
        model: 'Haiku 5.5',
        light: true,
    },
    {
        role: 'Fixer',
        does: 'Takes small Ready cards labelled bug or chore',
        model: 'Haiku 5.5 or the lightest GPT model on Codex',
        light: true,
    },
    {
        role: 'Copywriter',
        does: 'Suggests names, sender addresses and wording to prefill forms',
        model: 'Haiku 5.5',
        light: true,
    },
    {
        role: 'Mechanic',
        does: 'Gets blocked droids going again: retries, switches a missing model, or asks you',
        model: 'Sonnet 5.5 or Haiku 5.5',
    },
    { role: 'HR', does: 'Proposes new droids to hire', model: 'Sonnet 5.5' },
];

export const STATUS: Record<
    Status,
    { label: string; cls: string; dot: string }
> = {
    working: {
        label: 'Working',
        cls: 'text-working bg-working/10 border-working/25',
        dot: 'bg-working',
    },
    needs: {
        label: 'Needs you',
        cls: 'text-needs bg-needs/10 border-needs/30',
        dot: 'bg-needs',
    },
    blocked: {
        label: 'Blocked',
        cls: 'text-blocked bg-blocked/10 border-blocked/30',
        dot: 'bg-blocked',
    },
    idle: {
        label: 'Docked',
        cls: 'text-muted-foreground bg-muted border-border',
        dot: 'bg-idle',
    },
};

/** Board columns, named for who acts on a card there. */
export const COLS: { key: ColKey; label: string; hint: string }[] = [
    {
        key: 'backlog',
        label: 'Ideas',
        hint: 'Not planned yet. Move a card to Ready for droids when it is clear enough to build, or turn on triage in the project crew.',
    },
    {
        key: 'ready',
        label: 'Ready for droids',
        hint: 'Clear enough to build. Pair a builder with it to start.',
    },
    {
        key: 'progress',
        label: 'Droid building',
        hint: 'A builder droid is working on it in a branch.',
    },
    {
        key: 'review',
        label: 'Droid reviewing',
        hint: 'A pull request is open: a reviewer droid checks it, then you merge.',
    },
    { key: 'done', label: 'Shipped', hint: 'Merged or closed.' },
];

export type FeedItem = { droid: string; text: string; min: number };

// ---------- Subscriptions ----------
export type Window = { label: string; used: number; resets: string };
export type Subscription = {
    id: string;
    provider: Provider;
    name: string;
    account: string;
    auth: string;
    color: string;
    windows: Window[];
    week: number[];
    spend?: { used: number; cap: number };
    /** True when the subscription runs on an API key rather than a CLI login. */
    api?: boolean;
    /** When a worker last read the plan's usage, like "4 minutes ago". */
    usageAt?: string | null;
    /** Tokens used through Droids this week: per day (Monday first) and in all. */
    tokens?: { days: number[]; week: number };
    /** Workers that reported a CLI login for this subscription. */
    signedInOn?: string[];
};
export const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
/** Current weekday (0 = Monday) and hour in the viewer's local time, for the schedule grid. */
export function nowSlot(date = new Date()) {
    return { day: (date.getDay() + 6) % 7, hour: date.getHours() };
}

// ---------- Design ----------
/** A file of a project's design, as listed in the store (the Design tab loads the content). */
export type DesignFile = {
    path: string;
    size: number;
    /** Slug of the droid that last wrote it. */
    by?: string | null;
    updated?: string | null;
};

// ---------- Pull requests ----------
export type CheckState = 'pass' | 'fail' | 'running' | 'pending';
export type PR = {
    n: number;
    title: string;
    branch: string;
    state: 'draft' | 'open' | 'approved' | 'changes' | 'merged' | 'closed';
    add: number;
    del: number;
    commits: number;
    files: number;
    checks: {
        name: string;
        state: CheckState;
        dur?: string;
        job?: number | null;
        url?: string | null;
    }[];
    reviews: {
        by: string;
        state: 'approved' | 'changes' | 'commented' | 'requested';
    }[];
    url?: string | null;
};

export type Draft = {
    title: string;
    body?: string;
    labels: string[];
    engine: Provider;
    size: string;
    /** Name of the milestone, and of its feature, the droid filed it under. */
    milestone?: string;
    feature?: string;
};

// ---------- Roadmap: milestones > features > tasks ----------
export type FTask = { n: number; title: string; done?: boolean };
export type Feature = { id: string; name: string; tasks: FTask[] };
export type Milestone = {
    id: string;
    name: string;
    due: string;
    state: 'done' | 'active' | 'planned';
    goal: string;
    features: Feature[];
};

/** A droid the planner suggests assembling for a project. */
export type Candidate = {
    name: string;
    role: string;
    provider: Provider;
    model: string;
    /** Two sentences in the droid's own voice. */
    pitch: string;
    /** Why the project needs it now. */
    why: string;
    instructions: string;
};

export type Proposal = {
    kind: 'feature' | 'milestone';
    title: string;
    why: string;
    effort: string;
    engine: Provider;
    /** Due date of a proposed milestone, YYYY-MM-DD. */
    due?: string;
};

export type Stats = {
    weeks: string[];
    burn: { scope: number; done: number }[];
    thru: { claude: number; codex: number }[];
    cycle: number[];
    spend: { claude: number; codex: number }[];
    ci: number;
};

// ---------- Laravel Cloud ----------
export type DeployStatus =
    | 'pending'
    | 'build.pending'
    | 'build.created'
    | 'build.queued'
    | 'build.running'
    | 'build.succeeded'
    | 'build.failed'
    | 'deployment.pending'
    | 'deployment.created'
    | 'deployment.queued'
    | 'deployment.running'
    | 'deployment.succeeded'
    | 'deployment.failed'
    | 'failed'
    | 'cancelled';
export type CloudEnv = {
    id: string;
    name: string;
    color: string;
    status: 'deploying' | 'running' | 'hibernating' | 'stopped';
    domain: string;
    branch: string;
    php: string;
    octane: boolean;
    hibernation: boolean;
    pushToDeploy?: boolean;
    /** Laravel Cloud's Resend integration: who the environment sends email as. */
    email?: { from: string; name: string } | null;
    region: string;
    instances: {
        name: string;
        type: 'App' | 'Worker';
        size: string;
        /** Current replicas, CPU and memory: only in the sample data, the Cloud API doesn't report them per instance. */
        replicas?: number;
        min: number;
        max: number;
        scaling: 'auto' | 'custom' | 'none';
        cpu?: number;
        mem?: number;
        queue?: string | null;
    }[];
    probes: {
        kind: 'startup' | 'readiness' | 'liveness';
        target: string;
        ok: boolean;
        last: string;
    }[];
    db?: {
        name: string;
        engine: string;
        preset: string;
        storage: number;
        conns: string;
    };
    cache?: { name: string; engine: string; mem: number; hit: number };
    queue?: { pending: number; failed: number };
    scheduled: { cmd: string; cron: string; last: string; ok: boolean }[];
};
export type Deployment = {
    id: string;
    env: string;
    status: DeployStatus;
    commit: string;
    message: string;
    author: string;
    branch: string;
    started: string;
    duration: string;
    via: string;
    failure?: string;
};
export type CloudApp = {
    /** Set when the snapshot comes from a linked Laravel Cloud application rather than sample data. */
    linked?: boolean;
    id?: string;
    app: string;
    org: string;
    region: string;
    repo?: string | null;
    usage?: number;
    envs: CloudEnv[];
    deployments: Deployment[];
    cpu: number[];
    mem: number[];
    /** Labels for the cpu and mem points; sample data uses hours of the day. */
    times?: string[];
    logs: string[];
    synced?: string;
};

export const DEPLOY_STEPS: {
    key: string;
    label: string;
    from: DeployStatus[];
}[] = [
    {
        key: 'queue',
        label: 'Queued',
        from: ['pending', 'build.pending', 'build.created', 'build.queued'],
    },
    { key: 'build', label: 'Build', from: ['build.running'] },
    {
        key: 'deploy',
        label: 'Deploy',
        from: [
            'build.succeeded',
            'deployment.pending',
            'deployment.created',
            'deployment.queued',
            'deployment.running',
        ],
    },
    { key: 'live', label: 'Live', from: ['deployment.succeeded'] },
];

/** The cards blocking an issue that are not merged yet. */
export function openBlockers(
    issues: Issue[],
    i: Pick<Issue, 'project' | 'blockedBy'>,
): number[] {
    return (i.blockedBy ?? []).filter((n) => {
        const b = issues.find((x) => x.project === i.project && x.n === n);
        return b && b.col !== 'done';
    });
}
