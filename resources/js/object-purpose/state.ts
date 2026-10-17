import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { query } from '../spa/query';
import { stamp, owned, scope, status, resumeLease, onClose } from '../spa/transport';
import { readPurpose, type PurposeResource, type TemporalQuery } from './api';

export const current = shallowRef<PurposeResource>();
let serial = 0;
export function clear() { serial++; current.value = undefined; void query.cancelQueries(); }
export async function read(route: Pick<RouteLocationNormalized, 'params' | 'query' | 'fullPath'>, passive = false) {
    const captured = stamp(), attempt = ++serial;
    const workspace = String(route.params.workspace), purpose = typeof route.params.purpose === 'string' ? route.params.purpose : null;
    try {
        const filters: TemporalQuery = {};
        for (const name of ['effective_at', 'known_at'] as const) {
            const value = route.query[name];
            if (value !== undefined) { if (typeof value !== 'string') throw Error('A single temporal instant is required'); filters[name] = value; }
        }
        const queryKey = [scope, 'object-purpose', route.fullPath];
        await query.cancelQueries({ queryKey });
        const value = await query.fetchQuery({ queryKey, queryFn: () => readPurpose(workspace, purpose, filters, passive ? { 'X-Builderad-Passive': '1' } : {}) });
        if (!owned(captured) || attempt !== serial || !('data' in value) || value.data.workspace.public_id !== workspace || value.data.selectedPurpose !== purpose) return;
        const witness = await readPurpose(workspace, purpose, filters, { 'X-Builderad-Witness': '1' });
        if (!owned(captured) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Object Purpose context changed');
        current.value = value; status.value = 'ready'; resumeLease(); return value.data;
    } catch {
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}
export const load = (route: RouteLocationNormalized, passive: boolean) => read(route, passive);
onClose(() => { clear(); query.clear(); });
