import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { query } from '../spa/query';
import { stamp, owned, scope, status, resumeLease, onClose } from '../spa/transport';
import { sources } from './api';

export const current = shallowRef<components['schemas']['WorkspaceSourcesResource']>();
let serial = 0;
export function clear() { serial++; current.value = undefined; void query.cancelQueries(); }
export async function read(workspace: string, passive = false) {
    const captured = stamp(), attempt = ++serial;
    const queryKey = [scope, 'workspace-sources', workspace];
    try {
        await query.cancelQueries({ queryKey });
        const value = await query.fetchQuery({ queryKey, queryFn: ({ signal }) => sources.read(workspace, passive ? { 'X-Builderad-Passive': '1' } : {}, signal) });
        if (!owned(captured) || attempt !== serial) return;
        if (!('data' in value) || value.data.workspace.public_id !== workspace) throw Error('Unowned Source resource');
        const witness = await sources.read(workspace, { 'X-Builderad-Witness': '1' });
        if (!owned(captured) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Source presentation changed');
        current.value = value; status.value = 'ready'; resumeLease(); return value.data;
    } catch {
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}
export const load = (route: RouteLocationNormalized, passive: boolean) => read(String(route.params.workspace), passive);
onClose(() => { clear(); query.clear(); });
