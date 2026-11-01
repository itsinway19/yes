import { shallowRef, ref } from 'vue';
import { createQuerySerializer } from 'openapi-fetch';
import type { components, paths } from '../api/generated/application';
import { api } from '../spa/api';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';

export type MarketOperationsQuery = NonNullable<paths['/internal/administration/market-knowledge']['get']['parameters']['query']>;
export const operations = shallowRef<components['schemas']['MarketOperationsData']>();
export const reading = ref(false), refreshFailed = ref(false);
let serial = 0, cancellation: AbortController | undefined;
export function clearOperations() {
    serial++; cancellation?.abort(); operations.value = undefined; reading.value = false; refreshFailed.value = false;
}
export async function readOperations(query: MarketOperationsQuery = {}, passive = false, readback = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    const signal = admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal;
    const path = '/internal/administration/market-knowledge';
    const suffix = createQuerySerializer()(query);
    reading.value = true;
    try {
        signal.throwIfAborted();
        const result = await api.GET(path, { params: { query }, headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal });
        if (signal.aborted || !owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET(path, { params: { query }, headers: { 'X-Builderad-Witness': '1' }, signal });
        if (signal.aborted || !owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== path + (suffix ? '?' + suffix : '')
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) {
            if (admission) throw Error('Operational destination witness changed');
            close(); return;
        }
        const value = result.data.data;
        publish(admission, () => { operations.value = value; refreshFailed.value = false; status.value = 'ready'; });
        return value;
    } catch (error) {
        if (admission && !admission.signal.aborted) throw error;
        if (!owned(captured) || attempt !== serial || signal.aborted) return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else { refreshFailed.value = true; if (!readback) status.value = 'unavailable'; }
    } finally {
        if (attempt === serial) reading.value = false;
    }
}
onClose(clearOperations);
