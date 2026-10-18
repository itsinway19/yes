import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { onClose, owned, resumeLease, stamp, status } from '../spa/transport';

export const enrollment = shallowRef<components['schemas']['StaffEnrollmentData']>();
let serial = 0, cancellation: AbortController | undefined;
export function clear() { serial++; cancellation?.abort(); enrollment.value = undefined; }
export async function load(_route?: RouteLocationNormalized, passive = false) {
    const attempt = ++serial, captured = stamp();
    cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/staff/onboarding', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/staff/onboarding', { headers: { 'X-Builderad-Witness': '1' }, signal: cancellation.signal });
        if (!owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/staff/onboarding'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) throw Error('Unowned staff enrollment presentation');
        enrollment.value = result.data.data; status.value = 'ready'; resumeLease(); return result.data.data;
    } catch { if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable'; }
}
onClose(clear);
