import { shallowRef } from 'vue';
import { createQuerySerializer } from 'openapi-fetch';
import type { components, paths } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, resumeLease, stamp, status, TransportError } from '../spa/transport';

export type CustomerQuery = NonNullable<paths['/internal/administration/customers']['get']['parameters']['query']>;
export type CustomerCommand = components['schemas']['InternalCustomerStatusCommand'];
export const customers = shallowRef<components['schemas']['InternalCustomersData']>();
let serial = 0, cancellation: AbortController | undefined;
export function clearCustomers() { serial++; cancellation?.abort(); customers.value = undefined; }
export async function readCustomers(query: CustomerQuery = {}, passive = false, readback = false) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    const path = '/internal/administration/customers';
    const suffix = createQuerySerializer()(query);
    try {
        const result = await api.GET(path, { params: { query }, headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET(path, { params: { query }, headers: { 'X-Builderad-Witness': '1' }, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== path + (suffix ? '?' + suffix : '')
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { close(); return; }
        customers.value = result.data.data; status.value = 'ready'; resumeLease(); return result.data.data;
    } catch (error) {
        if (!owned(captured) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
export async function changeCustomerStatus(body: CustomerCommand, signal?: AbortSignal) {
    const result = await api.POST('/internal/administration/customers/status', { body, signal });
    if (!result.data) throw Error('Missing customer command acknowledgement');
    return result.data;
}
onClose(clearCustomers);
