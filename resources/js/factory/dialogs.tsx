import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PROVIDERS, ROLES, type Project, type Provider } from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

export type NewDroid = {
    name: string;
    provider: Provider;
    model: string;
    role: string;
    project: string;
    autonomy: string;
    instructions: string;
    seed: number;
};

const NAMES = [
    'Bolt',
    'Cog',
    'Dyna',
    'Echo',
    'Hex',
    'Ion',
    'Jolt',
    'Kilo',
    'Nova',
    'Orbit',
    'Piston',
    'Volt',
];

export function AssembleDialog({
    open,
    onOpenChange,
    projects,
    defaultProject,
    onCreate,
    nextSeed,
    nextUnit,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    projects: Project[];
    defaultProject: string;
    onCreate: (d: NewDroid) => Promise<boolean>;
    nextSeed: number;
    nextUnit: (p: Provider) => string;
}) {
    const [provider, setProvider] = useState<Provider>('claude');
    const [model, setModel] = useState('Sonnet 5.5');
    const [name, setName] = useState('Bolt');
    const [role, setRole] = useState('Builder');
    const [project, setProject] = useState(defaultProject);
    const [auto, setAuto] = useState('pr');
    const [seed, setSeed] = useState(nextSeed);
    const [instructions, setInstructions] = useState(
        'Write tests first. Keep PRs under 400 lines. Ask before adding a dependency.',
    );
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (open) {
            setProject(defaultProject);
            setSeed(nextSeed);
            setName(NAMES[nextSeed % NAMES.length]);
        }
    }, [open]); // eslint-disable-line
    const unit = nextUnit(provider);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100%-2rem)] overflow-y-auto sm:max-w-[600px]">
                <form
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        const ok = await onCreate({
                            name: name.trim() || 'Droid',
                            provider,
                            model,
                            role,
                            project,
                            autonomy: auto,
                            instructions,
                            seed,
                        });
                        setBusy(false);
                        if (ok) onOpenChange(false);
                    }}
                    className="grid gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Assemble a droid</DialogTitle>
                        <DialogDescription>
                            Pick an engine, give it a job and a project. Droids
                            work on their own branch and open pull requests.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="blueprint flex items-center gap-4 rounded-xl border bg-muted/40 p-3">
                        <DroidAvatar
                            provider={provider}
                            status="idle"
                            seed={seed}
                            className="size-16"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="flex items-baseline gap-2">
                                <span className="font-mono text-sm">
                                    {unit}
                                </span>
                                <span className="truncate text-lg font-semibold">
                                    {name || 'Droid'}
                                </span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {PROVIDERS[provider].label} · {model} · {role}
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setSeed((s) => s + 1)}
                        >
                            New look
                        </Button>
                    </div>

                    <div className="grid gap-2">
                        <Label>Engine</Label>
                        <RadioGroup
                            value={provider}
                            onValueChange={(v) => {
                                const p = v as Provider;
                                setProvider(p);
                                setModel(
                                    PROVIDERS[p].models[p === 'claude' ? 1 : 0],
                                );
                            }}
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                        >
                            {(['claude', 'codex'] as Provider[]).map((p) => (
                                <Label
                                    key={p}
                                    htmlFor={`eng-${p}`}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 font-normal',
                                        provider === p &&
                                            'border-ring bg-accent',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={`eng-${p}`}
                                        value={p}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="flex items-center gap-2 font-medium">
                                            <span
                                                className={cn(
                                                    'size-2 rounded-full',
                                                    p === 'claude'
                                                        ? 'bg-claude'
                                                        : 'bg-codex',
                                                )}
                                            />
                                            {PROVIDERS[p].label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {p === 'claude'
                                                ? 'Strong on planning, reviews and long refactors'
                                                : 'Fast at scoped implementation and test fixes'}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </RadioGroup>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="d-name">Name</Label>
                            <Input
                                id="d-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                maxLength={20}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-model">Model</Label>
                            <Select value={model} onValueChange={setModel}>
                                <SelectTrigger id="d-model" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PROVIDERS[provider].models.map((m) => (
                                        <SelectItem key={m} value={m}>
                                            {m}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-role">Role</Label>
                            <Select value={role} onValueChange={setRole}>
                                <SelectTrigger id="d-role" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {ROLES.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {r}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="d-proj">Project</Label>
                            <Select value={project} onValueChange={setProject}>
                                <SelectTrigger id="d-proj" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {projects.map((p) => (
                                        <SelectItem key={p.id} value={p.id}>
                                            {p.name} · {p.repo}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Autonomy</Label>
                        <RadioGroup
                            value={auto}
                            onValueChange={setAuto}
                            className="grid gap-2 sm:grid-cols-3"
                        >
                            {[
                                [
                                    'ask',
                                    'Ask first',
                                    'Checks in before each PR',
                                ],
                                ['pr', 'Open PRs', 'You review and merge'],
                                [
                                    'merge',
                                    'Merge when green',
                                    'Only with passing checks and a review',
                                ],
                            ].map(([v, l, s]) => (
                                <Label
                                    key={v}
                                    htmlFor={`au-${v}`}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 font-normal',
                                        auto === v && 'border-ring bg-accent',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={`au-${v}`}
                                        value={v}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">{l}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {s}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </RadioGroup>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="d-ins">Instructions</Label>
                        <Textarea
                            id="d-ins"
                            value={instructions}
                            onChange={(e) => setInstructions(e.target.value)}
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy}>
                            Assemble {unit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function ConnectDialog({
    open,
    onOpenChange,
    onConnect,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    onConnect: (repo: string, board: string) => Promise<boolean>;
}) {
    const [repo, setRepo] = useState('');
    const [board, setBoard] = useState('');
    const [busy, setBusy] = useState(false);
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[480px]">
                <form
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        const ok = await onConnect(
                            repo.trim(),
                            board.trim() || 'Roadmap',
                        );
                        setBusy(false);
                        if (ok) {
                            setRepo('');
                            setBoard('');
                            onOpenChange(false);
                        }
                    }}
                    className="grid gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Connect a repository</DialogTitle>
                        <DialogDescription>
                            Link a GitHub repo and the GitHub Project board your
                            droids will work from. Syncing issues and the board
                            starts once the GitHub App is installed.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="c-repo">Repository</Label>
                        <Input
                            id="c-repo"
                            className="font-mono"
                            value={repo}
                            onChange={(e) => setRepo(e.target.value)}
                            placeholder="owner/repo"
                            required
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="c-board">GitHub Project</Label>
                        <Input
                            id="c-board"
                            value={board}
                            onChange={(e) => setBoard(e.target.value)}
                            placeholder="Roadmap"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy}>
                            Connect
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
