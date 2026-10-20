import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { bootstrap, onClose, owned, stamp } from '../spa/transport';

export const entry = shallowRef<components['schemas']['AuthEntryData']>();
let serial = 0, cancellation: AbortController | undefined;
export function clear() { serial++; cancellation?.abort(); entry.value = undefined; }
export async function load(route: RouteLocationNormalized, passive = false, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    cancellation?.abort(); cancellation = new AbortController();
    const path = route.path.startsWith('/reset-password/') ? '/reset-password/{token}' : route.path as '/login' | '/register' | '/identity/switch' | '/forgot-password' | '/verify-email' | '/confirm-password' | '/internal/login' | '/internal/identity/switch';
    const reader = (headers: Record<string, string> = {}) => path === '/reset-password/{token}'
        ? api.GET(path, { signal: admission ? AbortSignal.any([admission.signal, cancellation!.signal]) : cancellation!.signal, params: { path: { token: String(route.params.token) } }, headers })
        : api.GET(path, { signal: admission ? AbortSignal.any([admission.signal, cancellation!.signal]) : cancellation!.signal, headers });
    try {
        const result = await reader(passive ? { 'X-Builderad-Passive': '1' } : {});
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const value = result.data;
        const witness = await reader({ 'X-Builderad-Witness': '1' });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (value.visibility.path !== route.path || value.visibility.protected !== (bootstrap.identity.binding.principal > 0)
            || JSON.stringify(value.visibility) !== JSON.stringify(witness.data?.visibility)) throw Error('Unowned authentication presentation');
        publish(admission, () => { entry.value = value.data; status.value = 'ready'; });
    } catch (error) { if (admission) throw error; if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable'; }
}
onClose(clear);
