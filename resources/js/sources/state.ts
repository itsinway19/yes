import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { query } from '../spa/query';
import { stamp, owned, scope, onClose } from '../spa/transport';
import { sources } from './api';

export const current = shallowRef<components['schemas']['WorkspaceSourcesResource']>();
let serial = 0;
export function clear() { serial++; current.value = undefined; void query.cancelQueries({ queryKey: [scope, 'workspace-sources'] }); }
export async function read(workspace: string, passive = false, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    const queryKey = [scope, 'workspace-sources', workspace];
    try {
        await query.cancelQueries({ queryKey });
        const value = await query.fetchQuery({ queryKey, queryFn: ({ signal }) => sources.read(workspace, passive ? { 'X-Builderad-Passive': '1' } : {}, admission ? AbortSignal.any([signal, admission.signal]) : signal) });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!('data' in value) || value.data.workspace.public_id !== workspace) throw Error('Unowned Source resource');
        const witness = await sources.read(workspace, { 'X-Builderad-Witness': '1' }, admission?.signal);
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Source presentation changed');
        publish(admission, () => { current.value = value; status.value = 'ready'; }); return value.data;
    } catch (error) { if (admission) throw error;
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}
export const load = (route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) => read(String(route.params.workspace), passive, admission);
onClose(() => { clear(); });
