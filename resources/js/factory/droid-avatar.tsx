import { cn } from '@/lib/utils';
import type { Provider, Status } from '@/factory/data';
import { EngineLogo } from '@/factory/logos';

const EYE: Record<Status, string> = {
    working: 'var(--working)',
    needs: 'var(--needs)',
    blocked: 'var(--blocked)',
    idle: 'var(--idle)',
};

/** Original geometric droid head. Hull = provider, eye light = status, shape from seed. */
export function DroidAvatar({
    provider,
    status,
    seed,
    className,
    title,
}: {
    provider: Provider;
    status: Status;
    seed: number;
    className?: string;
    title?: string;
}) {
    const hull = provider === 'claude' ? 'var(--claude)' : 'var(--codex)';
    const head = seed % 3;
    const eyes = Math.floor(seed / 3) % 3;
    const ant = Math.floor(seed / 2) % 3;
    const eye = EYE[status];
    const scan = status === 'working';
    return (
        <svg
            viewBox="0 0 48 48"
            className={cn('size-10 shrink-0', className)}
            role={title ? 'img' : undefined}
            aria-label={title}
            aria-hidden={title ? undefined : true}
        >
            <rect
                x="0"
                y="0"
                width="48"
                height="48"
                rx="12"
                fill="color-mix(in oklch, var(--muted) 80%, transparent)"
            />
            {ant === 1 && (
                <g>
                    <rect
                        x="23"
                        y="5"
                        width="2"
                        height="7"
                        rx="1"
                        fill={hull}
                    />
                    <circle cx="24" cy="5.5" r="2.5" fill={eye} />
                </g>
            )}
            {ant === 2 && (
                <g fill={hull}>
                    <rect x="10" y="11" width="4" height="9" rx="2" />
                    <rect x="34" y="11" width="4" height="9" rx="2" />
                </g>
            )}
            {head === 0 && (
                <path
                    d="M10 27a14 14 0 0 1 28 0v9a4 4 0 0 1-4 4H14a4 4 0 0 1-4-4z"
                    fill={hull}
                />
            )}
            {head === 1 && (
                <rect x="10" y="12" width="28" height="28" rx="7" fill={hull} />
            )}
            {head === 2 && (
                <rect x="9" y="14" width="30" height="26" rx="13" fill={hull} />
            )}
            {/* light edge */}
            <path
                d="M14 39h20"
                stroke="black"
                strokeOpacity=".18"
                strokeWidth="2"
                strokeLinecap="round"
            />
            {/* face plate */}
            <rect
                x="14"
                y="20"
                width="20"
                height="12"
                rx="6"
                fill="oklch(0.2 0.01 260)"
            />
            <g className={scan ? 'eye-scan' : undefined}>
                {eyes === 0 && <circle cx="24" cy="26" r="3.6" fill={eye} />}
                {eyes === 1 && (
                    <g fill={eye}>
                        <circle cx="19.5" cy="26" r="2.4" />
                        <circle cx="28.5" cy="26" r="2.4" />
                    </g>
                )}
                {eyes === 2 && (
                    <rect
                        x="17"
                        y="24.4"
                        width="14"
                        height="3.2"
                        rx="1.6"
                        fill={eye}
                    />
                )}
            </g>
            <circle cx="17" cy="35.5" r="1.1" fill="white" fillOpacity=".55" />
            <circle
                cx="20.5"
                cy="35.5"
                r="1.1"
                fill="white"
                fillOpacity=".35"
            />
        </svg>
    );
}

export function ProviderMark({
    provider,
    className,
}: {
    provider: Provider;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                provider === 'claude'
                    ? 'border-claude/35 text-claude'
                    : 'border-codex/35 text-codex',
                className,
            )}
        >
            <EngineLogo provider={provider} className="size-3" />
            {provider === 'claude' ? 'Claude Code' : 'Codex'}
        </span>
    );
}
