import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { stamp, owned, onClose } from '../spa/transport';

export const commercialHistory = shallowRef<components['schemas']['WorkspaceCommercialHistoryResource']>();
let serial = 0;
export function clearCommercialHistory() { serial++; commercialHistory.value = undefined; }
export async function loadCommercialHistory(route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) {
    const workspace = String(route.params.workspace), captured = stamp(), attempt = ++serial;
    const kind = route.query.kind ?? 'context';
    if (typeof kind !== 'string' || !['context', 'markets', 'intent', 'preferences'].includes(kind)) throw Error('Unknown history kind');
    const after = route.query.after;
    if (after !== undefined && (typeof after !== 'string' || !/^[0-9]+$/.test(after) || !Number.isSafeInteger(Number(after)))) throw Error('Invalid history cursor');
    const read = async (headers: Record<string, string>) => {
        const response = await api.GET('/workspaces/{workspace}/commercial-history', { params: { path: { workspace }, query: { kind: kind as 'context' | 'markets' | 'intent' | 'preferences', ...(after === undefined ? {} : { after: Number(after) }) } }, headers, signal: admission?.signal });
        if (!response.data) throw Error('History unavailable');
        return response.data;
    };
    try {
        const value = await read(passive ? { 'X-Builderad-Passive': '1' } : {});
        if (!owned(captured) || attempt !== serial || admission?.signal.aborted) return;
        if (!('data' in value) || value.data.workspace.public_id !== workspace || value.data.kind !== kind || !value.data.read_only || value.data.current_business_inferred) throw Error('Unowned historical resource');
        const witness = await read({ 'X-Builderad-Witness': '1' });
        if (!owned(captured) || attempt !== serial || admission?.signal.aborted) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('History authority changed');
        publish(admission, () => { commercialHistory.value = value; status.value = 'ready'; });
    } catch (error) {
        if (admission) throw error;
        if (owned(captured) && attempt === serial) status.value = 'unavailable';
    }
}
onClose(clearCommercialHistory);
