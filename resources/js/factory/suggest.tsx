import { LoaderCircleIcon, SparklesIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSave, useStore } from '@/factory/api';

/**
 * Asks a creative droid (a Copywriter when there is one) to prefill a form.
 * The answer comes back from a worker; when it lands, onFill gets the fields
 * so the person can review and edit them before saving.
 */
export function SuggestButton({
    pid,
    form,
    values,
    onFill,
    className,
}: {
    pid: string;
    form: 'email' | 'project';
    values?: Record<string, string>;
    onFill: (fields: Record<string, string>) => void;
    className?: string;
}) {
    const store = useStore();
    const save = useSave();
    const topic = `suggest:${pid}:${form}`;
    const ask = store?.asks?.[topic];
    const last = (store?.chats[topic] ?? []).findLast(
        (i) => i.kind === 'suggestion',
    );
    const lastId = last?.id ?? 0;
    // The newest suggestion when the person asked: a newer one is the answer.
    const waiting = useRef<number | null>(null);
    const fill = useRef(onFill);
    fill.current = onFill;

    useEffect(() => {
        if (waiting.current === null || lastId <= waiting.current) return;
        waiting.current = null;
        if (last?.kind !== 'suggestion') return;
        if (last.fields && Object.keys(last.fields).length) {
            fill.current(last.fields);
            toast.success('Filled in from a droid', {
                description: last.text ?? 'Review it before saving.',
            });
        } else toast.info(last.text ?? 'No suggestion this time.');
    }, [lastId]); // eslint-disable-line react-hooks/exhaustive-deps

    const droid = store?.droids.find((d) => d.role === 'Copywriter');
    const busy = !!ask;
    const tip = busy
        ? ask.status === 'running'
            ? 'A droid is writing a suggestion…'
            : store?.workers?.online
              ? (ask.why ?? 'Waiting for your worker to pick this up…')
              : 'No worker is online. Start php artisan droids:work.'
        : droid
          ? `${droid.unit} ${droid.name} suggests values you can edit`
          : 'A droid suggests values you can edit. Assemble a Copywriter for this.';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className={className}
                    disabled={busy}
                    onClick={async () => {
                        waiting.current = lastId;
                        const res = await save(
                            'POST',
                            `/factory/projects/${encodeURIComponent(pid)}/suggest`,
                            { form, values },
                        );
                        if (!res) waiting.current = null;
                    }}
                >
                    {busy ? (
                        <LoaderCircleIcon className="animate-spin" />
                    ) : (
                        <SparklesIcon />
                    )}
                    Suggest
                </Button>
            </TooltipTrigger>
            <TooltipContent>{tip}</TooltipContent>
        </Tooltip>
    );
}
