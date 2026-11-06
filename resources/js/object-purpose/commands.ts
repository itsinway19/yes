import { status } from '../spa/presentation';
import { nextTick, shallowReactive } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { owned, stamp, onClose, TransportError } from '../spa/transport';
import { writePurpose, type PurposeCommand, type PurposeAcknowledgement } from './api';
import { read } from './state';

export type PurposeWriter = (body: PurposeCommand) => Promise<PurposeAcknowledgement>;
type Pending = { body: PurposeCommand; busy: boolean; uncertain: boolean; writer?: PurposeWriter };
type Options = { onSuccess?: (reply: PurposeAcknowledgement) => void; onError?: (errors: Record<string, string>) => void; onHttpException?: (response: { status: number }) => unknown; onNetworkError?: () => unknown; onFinish?: () => void };
const pending = shallowReactive(new Map<string, Pending>());
const drafts = new Map<string, unknown>();
const key = (workspace: string, purpose: string | null) => JSON.stringify([workspace, purpose]);
export const unresolved = (workspace: string, purpose: string | null) => pending.get(key(workspace, purpose));
/** Read-only owner query; a pending command never establishes authority. */
export const hasPendingPurposeScope = (scopePrefix: string) => [...pending.keys()]
    .some(commandKey => (JSON.parse(commandKey)[0] as string).startsWith(scopePrefix));
export function remember(workspace: string, purpose: string | null, value: unknown) {
    if (status.value !== 'closed') drafts.set(key(workspace, purpose), JSON.parse(JSON.stringify(value)));
}
export function restore<T>(workspace: string, purpose: string | null): T | undefined {
    const value = drafts.get(key(workspace, purpose)); return value === undefined ? undefined : structuredClone(value) as T;
}
export function forget(workspace: string, purpose: string | null) { drafts.delete(key(workspace, purpose)); }
export async function refreshPurpose(route: RouteLocationNormalized, options: { onSuccess?: () => void }) {
    const captured = stamp(); const latest = await read(route, true); await nextTick();
    if (owned(captured) && latest) options.onSuccess?.();
}
async function execute(workspace: string, body: PurposeCommand, options: Options, uncertain: boolean, writer?: PurposeWriter) {
    const captured = stamp(), commandKey = key(workspace, body.purpose_id);
    const original = JSON.parse(JSON.stringify(body)) as PurposeCommand;
    pending.set(commandKey, { body: original, busy: true, uncertain, writer });
    try {
        const reply = await (writer ?? (command => writePurpose(workspace, command)))(JSON.parse(JSON.stringify(original)) as PurposeCommand);
        if (pending.get(commandKey)?.body.command_id === original.command_id) pending.delete(commandKey);
        if (!owned(captured)) return;
        forget(workspace, body.purpose_id); options.onSuccess?.(reply);
    } catch (failure) {
        const definite = failure instanceof TransportError && [403, 409, 422].includes(failure.status);
        if (status.value !== 'closed' && pending.get(commandKey)?.body.command_id === original.command_id) {
            if (definite && !uncertain) pending.delete(commandKey);
            else pending.set(commandKey, { body: original, busy: false, uncertain: true, writer });
        }
        if (!owned(captured)) return;
        if (failure instanceof TransportError) {
            options.onHttpException?.({ status: failure.status });
            const errors = Object.fromEntries(Object.entries(failure.body.errors ?? {}).map(([name, messages]) => [name, messages[0]]));
            options.onError?.(Object.keys(errors).length ? errors : { declaration: failure.body.message ?? String(failure.status) });
        } else options.onNetworkError?.();
    } finally {
        const value = pending.get(commandKey);
        if (value?.body.command_id === original.command_id && value.busy) pending.set(commandKey, { ...value, busy: false });
        options.onFinish?.();
    }
}
export function submitPurpose(workspace: string, body: PurposeCommand, options: Options, writer?: PurposeWriter) {
    if (status.value === 'closed' || pending.has(key(workspace, body.purpose_id))) return;
    return execute(workspace, body, options, false, writer);
}
export function retryPurpose(workspace: string, purpose: string | null, options: Options) {
    const value = unresolved(workspace, purpose);
    if (!value || value.busy || status.value === 'closed') return;
    return execute(workspace, value.body, options, value.uncertain, value.writer);
}
onClose(() => { drafts.clear(); pending.clear(); });
