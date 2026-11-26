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
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    PROVIDERS,
    ROLES,
    type Droid,
    type Project,
    type Provider,
} from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { cn } from '@/lib/utils';

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
    count,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    projects: Project[];
    defaultProject: string;
    onCreate: (d: Droid) => void;
    nextSeed: number;
    count: (p: Provider) => number;
}) {
    const [provider, setProvider] = useState<Provider>('claude');
    const [model, setModel] = useState('Sonnet 5.5');
    const [name, setName] = useState('Bolt');
    const [role, setRole] = useState('Builder');
    const [project, setProject] = useState(defaultProject);
    const [auto, setAuto] = useState('pr');
    const [seed, setSeed] = useState(nextSeed);
    useEffect(() => {
        if (open) {
            setProject(defaultProject);
            setSeed(nextSeed);
            setName(NAMES[nextSeed % NAMES.length]);
        }
    }, [open]); // eslint-disable-line
    const unit = `${provider === 'claude' ? 'CL' : 'CX'}-${String(count(provider) + 20).padStart(2, '0')}`;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100%-2rem)] overflow-y-auto sm:max-w-[600px]">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onCreate({
                            id:
                                unit.toLowerCase().replace('-', '') +
                                (Date.now() % 1000),
                            unit,
                            name: name.trim() || 'Droid',
                            provider,
                            model,
                            role,
                            status: 'idle',
                            project,
                            task: null,
                            step: '',
                            progress: 0,
                            minutes: 0,
                            cost: 0,
                            seed,
                        });
                        onOpenChange(false);
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

                    <div className="grid gap-2.5">
                        <Label>GitHub permissions</Label>
                        {[
                            [
                                'pk1',
                                'Push branches and open pull requests',
                                true,
                            ],
                            [
                                'pk2',
                                'Comment on issues and move cards on the Project board',
                                true,
                            ],
                            [
                                'pk3',
                                'Pick up issues from Ready on its own',
                                false,
                            ],
                        ].map(([id, l, c]) => (
                            <div
                                key={id as string}
                                className="flex items-center gap-2.5"
                            >
                                <Checkbox
                                    id={id as string}
                                    defaultChecked={c as boolean}
                                />
                                <Label
                                    htmlFor={id as string}
                                    className="font-normal"
                                >
                                    {l}
                                </Label>
                            </div>
                        ))}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="d-ins">Instructions</Label>
                        <Textarea
                            id="d-ins"
                            defaultValue="Write tests first. Keep PRs under 400 lines. Ask before adding a dependency."
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
                        <Button type="submit">Assemble {unit}</Button>
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
    onConnect: (repo: string, board: string) => void;
}) {
    const [repo, setRepo] = useState('acme/mobile-app');
    const [board, setBoard] = useState('Mobile launch');
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[480px]">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onConnect(repo.trim() || 'acme/mobile-app', board);
                        onOpenChange(false);
                    }}
                    className="grid gap-5"
                >
                    <DialogHeader>
                        <DialogTitle>Connect a repository</DialogTitle>
                        <DialogDescription>
                            Link a GitHub repo and the GitHub Project board your
                            droids will work from.
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
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="c-board">GitHub Project</Label>
                        <Select value={board} onValueChange={setBoard}>
                            <SelectTrigger id="c-board" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="Mobile launch">
                                    Mobile launch (acme · #12)
                                </SelectItem>
                                <SelectItem value="Bugs">
                                    Bugs (acme · #3)
                                </SelectItem>
                                <SelectItem value="New Project">
                                    Create a new Project
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="c-field">Status field</Label>
                            <Select defaultValue="Status">
                                <SelectTrigger id="c-field" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Status">
                                        Status
                                    </SelectItem>
                                    <SelectItem value="Stage">Stage</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="c-pick">Droids pick from</Label>
                            <Select defaultValue="Ready">
                                <SelectTrigger id="c-pick" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Ready">Ready</SelectItem>
                                    <SelectItem value="Backlog">
                                        Backlog
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit">Connect</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
