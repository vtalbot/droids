import { createContext, useContext } from 'react';
import type { Store } from '@/factory/app';

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
export type SaveResult = Record<string, unknown> & { store: Store };
export type Save = (
    method: Method,
    url: string,
    body?: unknown,
) => Promise<SaveResult | null>;

export class RequestError extends Error {
    constructor(
        message: string,
        public status: number,
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** JSON request to the factory endpoints, with Laravel's CSRF cookie. */
export async function request(
    method: Method,
    url: string,
    body?: unknown,
): Promise<SaveResult> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = (await res.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : '';
        throw new RequestError(
            first || data.message || `Request failed (${res.status})`,
            res.status,
        );
    }
    return data as SaveResult;
}

/**
 * Copy the server's store into the live client store. A conversation the
 * server doesn't know yet (only the droid's greeting so far) stays as it is.
 */
export function applyServerStore(target: Store, next: Store) {
    const chats = target.chats;
    Object.assign(target, next);
    target.chats = { ...chats, ...next.chats };
}

export const SaveContext = createContext<Save>(async () => null);
/** The live client store, for panels that are not handed it as a prop. */
export const StoreContext = createContext<Store | null>(null);
export const useStore = () => useContext(StoreContext);
export const useSave = () => useContext(SaveContext);
