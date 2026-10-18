import { ref, shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, resumeLease, stamp, status, TransportError } from '../spa/transport';

export const services = shallowRef<components['schemas']['InternalServicesData']>();
export const serviceOutcome = ref<'' | 'pending' | 'acknowledged' | 'unknown' | 'rejected'>('');
let serial = 0, cancellation: AbortController | undefined;
export function clearServices() { serial++; cancellation?.abort(); services.value = undefined; }
export async function readServices(passive = false, readback = false) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/internal/services', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/services', { headers: { 'X-Builderad-Witness': '1' }, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/services'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { close(); return; }
        services.value = result.data.data; status.value = 'ready'; resumeLease(); return result.data.data;
    } catch (error) {
        if (!owned(captured) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
onClose(() => { clearServices(); serviceOutcome.value = ''; });
