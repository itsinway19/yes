import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { ref, shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';

export const businessTypes = shallowRef<components['schemas']['InternalBusinessTypesData']>();
export const businessOutcome = ref<'' | 'pending' | 'acknowledged' | 'unknown' | 'rejected'>('');
export const businessBusy = ref(false);
let serial = 0, cancellation: AbortController | undefined;
export function clearBusinessTypes() { serial++; cancellation?.abort(); businessTypes.value = undefined; }
export async function readBusinessTypes(passive = false, readback = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/internal/business-types', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/business-types', { headers: { 'X-Builderad-Witness': '1' }, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/business-types'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { if (admission) throw Error('Destination witness changed'); close(); return; }
        const value = result.data.data;
        publish(admission, () => { businessTypes.value = value; status.value = 'ready'; }); return value;
    } catch (error) {
        if (admission) throw error;
        if ((!owned(captured)) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
export type BusinessCommand =
    | { kind: 'create'; body: components['schemas']['InternalBusinessTypeCreate'] }
    | { kind: 'update'; type: number; body: components['schemas']['InternalBusinessTypeUpdate'] }
    | { kind: 'guidance'; type: number; body: components['schemas']['InternalBusinessGuidanceCommand'] }
    | { kind: 'prior'; type: number; body: components['schemas']['InternalBusinessPriorCommand'] };
export async function writeBusiness(command: BusinessCommand, signal?: AbortSignal) {
    const result = command.kind === 'create' ? await api.POST('/internal/business-types', { body: command.body, signal })
        : command.kind === 'update' ? await api.POST('/internal/business-types/{type}', { params: { path: { type: command.type } }, body: command.body, signal })
        : command.kind === 'guidance' ? await api.POST('/internal/business-types/{type}/guidance', { params: { path: { type: command.type } }, body: command.body, signal })
        : await api.POST('/internal/business-types/{type}/prior', { params: { path: { type: command.type } }, body: command.body, signal });
    if (!result.data) throw Error('Missing business command acknowledgement');
    return result.data;
}
onClose(() => { clearBusinessTypes(); businessOutcome.value = ''; businessBusy.value = false; });
