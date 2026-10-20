import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { query } from '../spa/query';
import { stamp, owned, scope, onClose } from '../spa/transport';
import { readContext, type ContextResource } from './api';

export const current = shallowRef<ContextResource>();
let serial = 0;
export function clear() { serial++; current.value = undefined; void query.cancelQueries({ queryKey: [scope, 'business-context'] }); }
export async function read(workspace: string, passive = false, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    const queryKey = [scope, 'business-context', workspace];
    try {
        await query.cancelQueries({ queryKey });
        const value = await query.fetchQuery({ queryKey, queryFn: ({ signal }) => readContext(workspace, passive ? { 'X-Builderad-Passive': '1' } : {}, admission ? AbortSignal.any([signal, admission.signal]) : signal) });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !('data' in value) || value.data.workspace.public_id !== workspace) return;
        const witness = await readContext(workspace, { 'X-Builderad-Witness': '1' }, admission?.signal);
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Business context changed');
        publish(admission, () => { current.value = value; status.value = 'ready'; }); return value.data;
    } catch (error) { if (admission) throw error;
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}
export const load = (route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) => read(String(route.params.workspace), passive, admission);
onClose(() => { clear(); });
