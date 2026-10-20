import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { onClose, owned, stamp } from '../spa/transport';

export const enrollment = shallowRef<components['schemas']['StaffEnrollmentData']>();
let serial = 0, cancellation: AbortController | undefined;
export function clear() { serial++; cancellation?.abort(); enrollment.value = undefined; }
export async function load(_route?: RouteLocationNormalized, passive = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp();
    cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/staff/onboarding', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/staff/onboarding', { headers: { 'X-Builderad-Witness': '1' }, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/staff/onboarding'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) throw Error('Unowned staff enrollment presentation');
        const value = result.data.data;
        publish(admission, () => { enrollment.value = value; status.value = 'ready'; }); return value;
    } catch (error) { if (admission) throw error; if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable'; }
}
onClose(clear);
