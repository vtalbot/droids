import { PencilIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useSave } from '@/factory/api';
import {
    AUTONOMY,
    PROVIDERS,
    ROLES,
    type Autonomy,
    type Droid,
} from '@/factory/data';
import { DroidAvatar } from '@/factory/droid-avatar';
import { EngineLogo } from '@/factory/logos';

/** Change a droid's name, model, role, autonomy and standing instructions. */
export function EditDroidButton({ d }: { d: Droid }) {
    const save = useSave();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(d.name);
    const [model, setModel] = useState(d.model);
    const [role, setRole] = useState(d.role);
    const [autonomy, setAutonomy] = useState<Autonomy>(d.autonomy ?? 'pr');
    const [instructions, setInstructions] = useState(d.instructions ?? '');
    const [busy, setBusy] = useState(false);
    const guide = ROLES.find((r) => r.role === role);
    const models = PROVIDERS[d.provider].models;

    const reset = () => {
        setName(d.name);
        setModel(d.model);
        setRole(d.role);
        setAutonomy(d.autonomy ?? 'pr');
        setInstructions(d.instructions ?? '');
    };
    const submit = async () => {
        if (!name.trim() || busy) return;
        setBusy(true);
        const res = await save(
            'PATCH',
            `/factory/droids/${encodeURIComponent(d.id)}`,
            {
                name: name.trim(),
                model,
                role,
                autonomy,
                instructions: instructions.trim() || null,
            },
        );
        setBusy(false);
        if (res) {
            toast.success(`${d.unit} ${name.trim()} updated`);
            setOpen(false);
        }
    };

    return (
        <>
            <Button
                size="sm"
                variant="ghost"
                className="h-8"
                aria-label={`Edit ${d.unit} ${d.name}`}
                onClick={() => {
                    reset();
                    setOpen(true);
                }}
            >
                <PencilIcon />
                Edit
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-[520px]">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <DroidAvatar
                                provider={d.provider}
                                status={d.status}
                                seed={d.seed}
                                className="size-7"
                            />
                            Edit {d.unit}
                        </DialogTitle>
                        <DialogDescription>
                            Changes apply from the droid's next run.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void submit();
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="droid-name">Name</Label>
                                <Input
                                    id="droid-name"
                                    value={name}
                                    maxLength={40}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="droid-model">Model</Label>
                                <Select value={model} onValueChange={setModel}>
                                    <SelectTrigger
                                        id="droid-model"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {models.map((m) => (
                                            <SelectItem key={m} value={m}>
                                                <EngineLogo
                                                    provider={d.provider}
                                                    className="size-3.5"
                                                />
                                                {m}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="droid-role">Role</Label>
                                <Select value={role} onValueChange={setRole}>
                                    <SelectTrigger
                                        id="droid-role"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ROLES.map((r) => (
                                            <SelectItem
                                                key={r.role}
                                                value={r.role}
                                            >
                                                {r.role}
                                                {r.light && (
                                                    <span className="text-xs text-muted-foreground">
                                                        small model
                                                    </span>
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="droid-autonomy">Autonomy</Label>
                                <Select
                                    value={autonomy}
                                    onValueChange={(v) =>
                                        setAutonomy(v as Autonomy)
                                    }
                                >
                                    <SelectTrigger
                                        id="droid-autonomy"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.keys(AUTONOMY) as Autonomy[]
                                        ).map((a) => (
                                            <SelectItem key={a} value={a}>
                                                {AUTONOMY[a]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {guide && (
                            <p className="-mt-2 text-xs text-muted-foreground">
                                {guide.does}. Fits {guide.model}.
                            </p>
                        )}
                        <div className="grid gap-1.5">
                            <Label htmlFor="droid-instructions">
                                Standing instructions
                            </Label>
                            <Textarea
                                id="droid-instructions"
                                value={instructions}
                                rows={6}
                                maxLength={5000}
                                onChange={(e) =>
                                    setInstructions(e.target.value)
                                }
                                placeholder="Rules the droid follows on every run"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={busy || !name.trim()}
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
