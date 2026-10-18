import { reactive, ref, shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, resumeLease, stamp, status, TransportError } from '../spa/transport';

export const administration = shallowRef<components['schemas']['InternalAdministrationData']>();
export const adminOutcome = ref<'' | 'pending' | 'acknowledged' | 'unknown' | 'rejected'>('');
export const invitation = reactive({ name: '', email: '', errors: {} as Record<string, string>, processing: false, recentlySuccessful: false });
export const grantDraft = reactive({ staff: '', action: '', reason: '', errors: {} as Record<string, string>, processing: false });
let serial = 0, cancellation: AbortController | undefined;
export function clear() { serial++; cancellation?.abort(); administration.value = undefined; }
export async function readAdministration(passive = false, readback = false) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/internal/administration', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/administration', { headers: { 'X-Builderad-Witness': '1' }, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/administration'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { close(); return; }
        administration.value = result.data.data; status.value = 'ready'; resumeLease(); return result.data.data;
    } catch (error) {
        if (!owned(captured) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
onClose(() => {
    clear(); adminOutcome.value = '';
    Object.assign(invitation, { name: '', email: '', errors: {}, processing: false, recentlySuccessful: false });
    Object.assign(grantDraft, { staff: '', action: '', reason: '', errors: {}, processing: false });
});
