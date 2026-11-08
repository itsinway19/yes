import { scope } from '../spa/transport';

/** Recovery data is scoped to the admitted identity and tab, never authority. */
export type PendingActivity<T> = { command: T; uncertain: boolean; denied: boolean };
const storageKey = `builderad.activity.pending:1:${scope}`;
let storageReadable = true;
const commands = new Map<string, PendingActivity<unknown>>();
try {
    const raw = sessionStorage.getItem(storageKey);
    if (raw) {
        if (raw.length > 1000000) throw Error('Invalid recovery data');
        const entries: unknown = JSON.parse(raw);
        if (!Array.isArray(entries) || entries.length > 200) throw Error('Invalid recovery data');
        for (const entry of entries) {
            if (!Array.isArray(entry) || entry.length !== 2 || typeof entry[0] !== 'string'
                || !entry[1] || typeof entry[1].command !== 'object' || !entry[1].command || typeof entry[1].command.command_id !== 'string') throw Error('Invalid recovery data');
            commands.set(entry[0], { command: entry[1].command, uncertain: true, denied: false });
        }
    }
} catch { storageReadable = false; commands.clear(); }

export const activityResource = (business: string, operation: 'practice' | 'practice-assessment') => `${business}:${scope}:${operation}`;
function persist(next: Map<string, PendingActivity<unknown>>) {
    if (!storageReadable) throw Error('Command recovery storage unavailable');
    if (next.size) {
        const raw = JSON.stringify([...next].map(([key, value]) => [key, { ...value, uncertain: true }]));
        if (next.size > 200 || raw.length > 1000000) throw Error('Recovery data limit reached');
        sessionStorage.setItem(storageKey, raw);
    }
    else sessionStorage.removeItem(storageKey);
}

export function activityCommand<T>(resource: string): PendingActivity<T> | undefined {
    return commands.get(resource) as PendingActivity<T> | undefined;
}

export function retainActivityCommand<T>(resource: string, command: T): PendingActivity<T> {
    const existing = activityCommand<T>(resource);
    if (existing) return existing;
    const pending = { command: structuredClone(command), uncertain: false, denied: false };
    persist(new Map(commands).set(resource, pending));
    commands.set(resource, pending);
    return pending;
}

export function settleActivityCommand(resource: string) { const next = new Map(commands); next.delete(resource); persist(next); commands.delete(resource); }
/** Retiring a document removes usable memory, preserving identity-scoped recovery. */
export function retireActivityCommands() { commands.clear(); }
export function clearActivityCommands() { commands.clear(); try { sessionStorage.removeItem(storageKey); storageReadable = true; } catch { storageReadable = false; } }
export function hasPendingActivity(business: string): boolean {
    return [...commands.keys()].some(resource => resource.startsWith(`${business}:`));
}

/** A first 422/typed CAS denial proves no effect; a retry after a lost reply does not. */
export function definitiveActivityRejection(status: number, uncertain: boolean, code?: string): boolean {
    return status === 422 && !uncertain || status === 409 && code === 'practice_revision_rejected';
}
