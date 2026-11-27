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
    /** The project the droid is working on; null until it is given a task. */
    project: string | null;
    task: number | null;
    step: string;
    progress: number;
    minutes: number;
    cost: number;
    seed: number;
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
};
/** A chat item as stored on the server; `id` is missing on items not saved yet. */
export type ChatItem = { id?: number } & ChatItemBody;
type ChatItemBody =
    | { kind: 'user'; text: string }
    | { kind: 'agent'; text: string; reasoning?: string }
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
          kind: 'candidates';
          items: Candidate[];
      }
    | {
          kind: 'design';
          project: string;
          changed: string[];
          removed: string[];
      };
/** A question a droid is answering on a worker, keyed by conversation. */
export type Ask = {
    kind: 'chat' | 'plan' | 'propose' | 'reflect' | 'design' | 'recruit';
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
        models: ['GPT-5.1-Codex', 'GPT-5.1-Codex-Mini'],
    },
};

export const ROLES = [
    'Builder',
    'Reviewer',
    'Designer',
    'Tester',
    'Planner',
    'Docs',
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
        hint: 'Not planned yet. Move a card to Ready for droids when it is clear enough to build.',
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
