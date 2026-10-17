import { onClose, status } from '../spa/transport';

const drafts = new Map<string, unknown>();
export function restore<T>(workspace: string, kind: string): T | undefined {
    const value = drafts.get(`${workspace}:${kind}`);
    return value === undefined ? undefined : structuredClone(value) as T;
}
export function remember(workspace: string, kind: string, value: unknown) {
    if (status.value !== 'closed') drafts.set(`${workspace}:${kind}`, JSON.parse(JSON.stringify(value)));
}
onClose(() => drafts.clear());
