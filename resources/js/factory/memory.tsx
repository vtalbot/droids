import { useState } from 'react';
import { BrainIcon, PencilIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { MessageResponse } from '@/components/ai-elements/message';
import { useSave } from '@/factory/api';
import type { Store } from '@/factory/app';

/** The project's shared memory: what its droids noted for each other, editable by the person. */
export function MemoryPanel({ store, pid }: { store: Store; pid: string }) {
    const memory = store.memory?.[pid];
    const save = useSave();
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const by = store.droids.find((d) => d.id === memory?.by);

    const start = () => {
        setText(memory?.text ?? '');
        setEditing(true);
    };
    const submit = async () => {
        setBusy(true);
        const res = await save(
            'PUT',
            `/factory/projects/${encodeURIComponent(pid)}/memory`,
            { memory: text },
        );
        setBusy(false);
        if (res) setEditing(false);
    };

    if (editing)
        return (
            <div className="grid gap-3">
                <Textarea
                    aria-label="Shared memory"
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    className="min-h-[420px] font-mono text-sm"
                />
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={() => setEditing(false)}>
                        Cancel
                    </Button>
                    <Button disabled={busy} onClick={() => void submit()}>
                        Save memory
                    </Button>
                </div>
            </div>
        );

    if (!memory?.text)
        return (
            <div className="grid place-items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center">
                <BrainIcon className="size-8 text-muted-foreground" />
                <div className="text-lg font-semibold">
                    No shared memory yet
                </div>
                <p className="max-w-md text-muted-foreground">
                    Droids on this project note what the next droid should know
                    here: conventions, commands that work, gotchas and
                    decisions. Every run reads it.
                </p>
                <Button variant="outline" onClick={start}>
                    <PencilIcon />
                    Write the first notes
                </Button>
            </div>
        );

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Every droid on this project reads this before it works, and
                    adds what the next one should know.
                    {memory.updated &&
                        ` Updated ${memory.updated}${by ? ` by ${by.unit} ${by.name}` : ''}.`}
                </p>
                <Button variant="outline" onClick={start}>
                    <PencilIcon />
                    Edit
                </Button>
            </div>
            <div className="rounded-xl border p-5 text-sm">
                <MessageResponse>{memory.text}</MessageResponse>
            </div>
        </div>
    );
}
