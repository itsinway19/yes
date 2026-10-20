import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';

export const preparationData = shallowRef<components['schemas']['CommercialPreparationData']>();
let serial = 0;
let cancellation: AbortController | undefined;
export function clearPreparation() { serial++; cancellation?.abort(); preparationData.value = undefined; }
export async function readPreparation(passive = false, readback = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/internal/administration/commercial-markets', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/administration/commercial-markets', { headers: { 'X-Builderad-Witness': '1' }, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/administration/commercial-markets'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { if (admission) throw Error('Destination witness changed'); close(); return; }
        const value = result.data.data;
        publish(admission, () => { preparationData.value = value; status.value = 'ready'; }); return value;
    } catch (error) {
        if (admission) throw error;
        if ((!owned(captured)) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
export type PreparationCommand =
    | { kind: 'prepare'; body: components['schemas']['CommercialPreparationCommand'] }
    | { kind: 'operator'; approval: string; body: components['schemas']['CommercialOperatorCommand'] }
    | { kind: 'delegate'; body: components['schemas']['CommercialDelegationCommand'] }
    | { kind: 'newIntent' };
export async function writePreparation(command: PreparationCommand, signal?: AbortSignal) {
    const result = command.kind === 'prepare' ? await api.POST('/internal/administration/commercial-markets', { body: command.body, signal })
        : command.kind === 'operator' ? await api.POST('/internal/administration/commercial-markets/{approval}/operator', { params: { path: { approval: command.approval } }, body: command.body, signal })
        : command.kind === 'delegate' ? await api.POST('/internal/administration/commercial-markets/delegate', { body: command.body, signal })
        : await api.POST('/internal/administration/commercial-markets/new-intent', { signal });
    if (!result.data) throw Error('Missing commercial preparation acknowledgement');
    return result.data;
}
onClose(clearPreparation);
