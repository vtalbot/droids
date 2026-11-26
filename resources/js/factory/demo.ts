// Placeholder droid output for chat, planning and reflection until the runners
// and the planner (phases 4 and 5) generate it for real.
import type { Draft, Proposal, Provider } from '@/factory/data';

export const SUGGEST: Record<string, string[]> = {
    cl02: ['Merge PR #418', 'Send back to CX-12'],
    cl01: ['Grant read access', 'Plan without it'],
    cx15: ['Pinned runs first', 'Old runs first'],
};

// ---------- Reflection and planning content ----------
export const RETRO: Record<
    Provider,
    {
        stats: [string, string][];
        good: string[];
        improve: string[];
        rules: string[];
    }
> = {
    claude: {
        stats: [
            ['Tasks', '10'],
            ['PRs merged', '8'],
            ['Review rounds', '1.6 avg'],
            ['Red CI on first push', '3'],
            ['Cost per task', '$1.42'],
        ],
        good: [
            'Regression tests came with every bug fix',
            'PRs stayed under 400 lines on 9 of 10 tasks',
        ],
        improve: [
            '3 PRs failed typecheck on first push because I ran only the unit tests locally',
            'Twice I touched files another droid was editing (#415 and #412), which caused a rebase',
            'I asked you 4 questions that the issue already answered',
        ],
        rules: [
            'Run lint, typecheck and unit tests before every push',
            'Before editing, check open PRs from other droids for the same files and coordinate in the issue',
            'Re-read the issue and linked PRs before asking a question',
        ],
    },
    codex: {
        stats: [
            ['Tasks', '12'],
            ['PRs merged', '9'],
            ['Review rounds', '2.1 avg'],
            ['Red CI on first push', '4'],
            ['Cost per task', '$0.88'],
        ],
        good: [
            'Fast first drafts: median 14 min to an open PR',
            'Small, well-named commits',
        ],
        improve: [
            'Reviewers asked for missing tests on 5 PRs',
            'Typecheck failed on 4 first pushes',
            'PR descriptions skipped how I verified the change',
        ],
        rules: [
            'Add or update tests in the same commit as the change',
            'Run typecheck before pushing',
            "End each PR description with a 'How I verified' section",
        ],
    },
};

export const PLAN_DRAFTS: Record<string, Draft[]> = {
    store: [
        {
            title: 'Show saved payment methods in express checkout',
            labels: ['feature', 'checkout'],
            engine: 'codex',
            size: 'M',
        },
        {
            title: 'Validate coupon + gift card stacking rules on the server',
            labels: ['bug', 'checkout'],
            engine: 'claude',
            size: 'S',
        },
        {
            title: 'Checkout e2e: cover Apple Pay, coupons and gift cards together',
            labels: ['tests'],
            engine: 'codex',
            size: 'M',
        },
        {
            title: 'Write the v3.2 checkout release notes',
            labels: ['docs'],
            engine: 'claude',
            size: 'S',
        },
    ],
    billing: [
        {
            title: 'Tax rate lookup by billing address',
            labels: ['feature'],
            engine: 'claude',
            size: 'L',
        },
        {
            title: 'Backfill tax fields on existing invoices',
            labels: ['migration'],
            engine: 'codex',
            size: 'M',
        },
        {
            title: 'Tax summary on invoice PDF',
            labels: ['feature'],
            engine: 'codex',
            size: 'S',
        },
    ],
    core: [
        {
            title: 'Workspace model: one primary repo, many linked repos',
            labels: ['feature'],
            engine: 'claude',
            size: 'L',
        },
        {
            title: 'Linked PRs across repos with one branch name',
            labels: ['feature'],
            engine: 'codex',
            size: 'M',
        },
        {
            title: 'Route droids to subscriptions by schedule',
            labels: ['feature'],
            engine: 'codex',
            size: 'M',
        },
    ],
    ink: [
        {
            title: 'Shell completions for bash, zsh and fish',
            labels: ['feature'],
            engine: 'codex',
            size: 'S',
        },
        {
            title: '1.0 migration guide',
            labels: ['docs'],
            engine: 'claude',
            size: 'S',
        },
        {
            title: 'Signed release binaries',
            labels: ['release'],
            engine: 'codex',
            size: 'M',
        },
    ],
};

export const PROPOSALS: Record<string, Proposal[]> = {
    store: [
        {
            kind: 'feature',
            title: 'Saved carts and wishlists',
            why: '18% of sessions add to cart and leave; checkout v3.2 makes the return path one tap.',
            effort: 'M · 5 tasks',
            engine: 'codex',
        },
        {
            kind: 'feature',
            title: 'Address autocomplete at checkout',
            why: 'Address errors cause most failed shipments in support tickets this month.',
            effort: 'S · 2 tasks',
            engine: 'codex',
        },
        {
            kind: 'feature',
            title: 'Fraud screening before capture',
            why: 'Apple Pay and gift cards together raise chargeback risk.',
            effort: 'M · 4 tasks',
            engine: 'claude',
        },
        {
            kind: 'milestone',
            title: 'v3.4 accounts',
            why: 'Logged-in shoppers convert better; group saved carts, order history and returns.',
            effort: '6 weeks',
            engine: 'claude',
        },
    ],
    billing: [
        {
            kind: 'feature',
            title: 'Customer billing portal',
            why: 'Most support emails ask to update cards or download invoices.',
            effort: 'M · 5 tasks',
            engine: 'codex',
        },
        {
            kind: 'milestone',
            title: 'Multi-currency',
            why: 'EU customers pay FX fees on USD invoices.',
            effort: '5 weeks',
            engine: 'claude',
        },
    ],
    core: [
        {
            kind: 'feature',
            title: 'Deploy-aware droids',
            why: 'Let droids watch Laravel Cloud deploys after merge and roll back on failure.',
            effort: 'M · 4 tasks',
            engine: 'claude',
        },
        {
            kind: 'feature',
            title: 'Subscription routing by schedule',
            why: "Already designed in the UI; the runner doesn't read it yet.",
            effort: 'S · 3 tasks',
            engine: 'codex',
        },
        {
            kind: 'milestone',
            title: 'Self-improving droids',
            why: 'Turn retrospectives into instruction changes you approve.',
            effort: '4 weeks',
            engine: 'claude',
        },
    ],
    ink: [
        {
            kind: 'feature',
            title: 'Shell completions',
            why: 'Most requested issue after 1.0 beta.',
            effort: 'S · 2 tasks',
            engine: 'codex',
        },
        {
            kind: 'milestone',
            title: '1.1 config profiles',
            why: 'Users switch between work and personal setups.',
            effort: '3 weeks',
            engine: 'claude',
        },
    ],
};

export const BUILD_LOG = [
    'Cloning droid/cx-12/415 at 9c41e2a',
    'composer install --no-dev --optimize-autoloader',
    'npm ci && npm run build',
    'php artisan optimize',
    'Building image (PHP 8.4, Node 22)',
    'Pushing image to registry',
    'php artisan migrate --force',
    'Starting web replicas · readiness GET /up',
    'Traffic switched to new release',
];
