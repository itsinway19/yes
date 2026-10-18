import { shallowReactive } from 'vue';
import { bootstrap, onClose, status, TransportError } from '../spa/transport';
import { sources, providers } from './api';
import type { SourceCommand, SourceAuthorityCommand } from './client';
import type { Provider, VersionCommand } from './providers';

type Command = { workspace: string } & (
    { effect: 'select'; provider: Provider; body: { selection: string } }
    | { effect: 'disconnect'; provider: Provider; source: string; body: VersionCommand }
    | { effect: 'renew'; provider: 'google' | 'snapchat'; source: string; body: VersionCommand }
    | { effect: 'registry'; body: SourceCommand }
    | { effect: 'grant'; body: SourceAuthorityCommand }
    | { effect: 'service'; body: { service: string; purpose: string } }
    | { effect: 'revoke-service'; source: string }
    | { effect: 'audit'; source: string }
);
export type PendingCommand = { key: string; command: Command; busy: boolean; uncertain: boolean; message: string };
export const pending = shallowReactive(new Map<string, PendingCommand>());
const drafts = new Map<string, unknown>();
const prefix = 'builderad.sources.pending.v1:';
const binding = bootstrap.identity.binding;
const storageKey = prefix + binding.principal + ':' + binding.context;
function persist() {
    sessionStorage.setItem(storageKey, JSON.stringify({ pending: [...pending.values()], drafts: [...drafts] }));
}
try {
    for (const key of Array.from({ length: sessionStorage.length }, (_, index) => sessionStorage.key(index))) {
        if (key?.startsWith(prefix) && key !== storageKey) sessionStorage.removeItem(key);
    }
    const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null');
    for (const item of saved?.pending ?? []) {
        if (item.command && typeof item.command.workspace === 'string' && item.key === keyFor(item.command)) {
            pending.set(item.key, { ...item, busy: false, uncertain: true });
        }
    }
    for (const [key, value] of saved?.drafts ?? []) drafts.set(key, value);
} catch { pending.clear(); drafts.clear(); }
export const draftKey = (workspace: string, feature: string) => JSON.stringify([workspace, feature]);
export function remember(workspace: string, feature: string, value: unknown) {
    if (status.value === 'closed') return false;
    drafts.set(draftKey(workspace, feature), JSON.parse(JSON.stringify(value)));
    try { persist(); return true; } catch { return false; }
}
export function restore<Value>(workspace: string, feature: string): Value | undefined {
    const value = drafts.get(draftKey(workspace, feature)); return value === undefined ? undefined : structuredClone(value) as Value;
}
export const commandsFor = (workspace: string, provider?: Provider) => [...pending.values()].filter(value => value.command.workspace === workspace
    && (provider === undefined ? !('provider' in value.command) : 'provider' in value.command && value.command.provider === provider));
function keyFor(command: Command) {
    return JSON.stringify([command.workspace, 'provider' in command ? command.provider : 'registry', command.effect,
        'source' in command ? command.source : command.effect === 'registry' ? command.body.source_id : 'form']);
}
async function send(command: Command, signal?: AbortSignal) {
    if (command.effect === 'select') return command.provider === 'meta'
        ? providers.selectMeta(command.workspace, command.body.selection, signal)
        : providers.select(command.provider, command.workspace, command.body.selection, signal);
    if (command.effect === 'disconnect') return providers.disconnect(command.provider, command.workspace, command.source, command.body, signal);
    if (command.effect === 'renew') return providers.renew(command.provider, command.workspace, command.source, command.body, signal);
    if (command.effect === 'registry') return sources.command(command.workspace, command.body, signal);
    if (command.effect === 'grant') return sources.grant(command.workspace, command.body, signal);
    if (command.effect === 'service') return sources.authorizeService(command.workspace, command.body, signal);
    if (command.effect === 'revoke-service') return sources.revokeService(command.workspace, command.source, signal);
    return sources.recover(command.workspace, command.source, signal);
}
export async function retry(value: PendingCommand, signal?: AbortSignal) {
    if (value.busy || status.value === 'closed') throw Error('Source command unavailable');
    pending.set(value.key, { ...value, busy: true });
    try {
        persist();
        const result = await send(structuredClone(value.command), signal);
        if (pending.get(value.key)?.command === value.command) pending.delete(value.key);
        try { persist(); } catch {}
        return result;
    } catch (failure) {
        const actionRequired = failure instanceof TransportError && failure.status === 502
            && 'status' in failure.body && failure.body.status === 'PROVIDER_ACTION_REQUIRED';
        const definite = failure instanceof TransportError && [401, 403, 409, 419, 422].includes(failure.status);
        if ((status.value as string) !== 'closed') {
            if (actionRequired || (definite && !value.uncertain)) pending.delete(value.key);
            else pending.set(value.key, { ...value, busy: false, uncertain: true, message: failure instanceof Error ? failure.message : 'Unknown delivery' });
            try { persist(); } catch {}
        }
        throw failure;
    }
}
export function submit(command: Command, signal?: AbortSignal) {
    const key = keyFor(command);
    if (pending.has(key)) throw Error('Resolve the original Source command before replacing it');
    return retry({ key, command: JSON.parse(JSON.stringify(command)) as Command, busy: false, uncertain: false, message: '' }, signal);
}
onClose(() => {
    const unresolved = (value: unknown): boolean => Array.isArray(value) ? value.some(unresolved)
        : Boolean(value && typeof value === 'object' && 'command' in value && value.command);
    const reports = [...drafts].filter(([, value]) => unresolved(value));
    try {
        if (pending.size || reports.length) sessionStorage.setItem(storageKey, JSON.stringify({ pending: [...pending.values()], drafts: reports }));
        else sessionStorage.removeItem(storageKey);
    } catch {}
    pending.clear(); drafts.clear();
});
