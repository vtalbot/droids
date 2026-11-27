import { useEffect, useState } from 'react';

const read = <T extends string>(
    name: string,
    allowed: readonly T[],
    fallback: T,
): T => {
    const v = new URLSearchParams(location.search).get(name) as T | null;
    return v && allowed.includes(v) ? v : fallback;
};

/**
 * A value kept in the URL's query string (like ?tab=email), so a reload or a
 * shared link lands on the same tab. Changing it replaces the history entry.
 */
export function useUrlParam<T extends string>(
    name: string,
    allowed: readonly T[],
    fallback: T,
): [T, (v: string) => void] {
    const [value, setValue] = useState<T>(() => read(name, allowed, fallback));
    useEffect(() => {
        const f = () => setValue(read(name, allowed, fallback));
        addEventListener('popstate', f);
        return () => removeEventListener('popstate', f);
    }, [name, allowed, fallback]);
    const set = (v: string) => {
        if (!allowed.includes(v as T)) return;
        setValue(v as T);
        const q = new URLSearchParams(location.search);
        if (v === fallback) q.delete(name);
        else q.set(name, v);
        const s = q.toString();
        history.replaceState(
            history.state,
            '',
            `${location.pathname}${s ? `?${s}` : ''}`,
        );
    };
    return [value, set];
}
