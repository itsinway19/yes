import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { reactive, shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import type { RouteLocationNormalized } from 'vue-router';
import { query } from '../spa/query';
import { onClose, owned, scope, stamp } from '../spa/transport';
import { readDevices, readProfile } from './api';
export const profile = shallowRef<components['schemas']['AccountProfileResource']>();
export const devices = shallowRef<components['schemas']['AccountDevicesResource'] | components['schemas']['InternalDevicesResource']>();
export const outcomes = reactive({ profile: '', password: '', devices: '' });
let serial = 0;
export function clear() { serial++; profile.value = undefined; devices.value = undefined; void query.cancelQueries({ queryKey: [scope, 'account'] }); }
export async function read(path: '/profile' | '/security/devices' | '/internal/security/devices', passive = false, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    const queryKey = [scope, 'account', path];
    try {
        await query.cancelQueries({ queryKey });
        const reader = path === '/profile' ? readProfile : readDevices;
        const value = await query.fetchQuery<Awaited<ReturnType<typeof readProfile>> | Awaited<ReturnType<typeof readDevices>>>({ queryKey, queryFn: ({ signal }) => reader(passive ? { 'X-Builderad-Passive': '1' } : {}, admission ? AbortSignal.any([signal, admission.signal]) : signal) });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        const witness = await reader({ 'X-Builderad-Witness': '1' }, admission?.signal);
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!('data' in value) || !value.visibility.protected || value.visibility.path !== path
            || JSON.stringify(value.visibility) !== JSON.stringify(witness.visibility)) throw Error('Unowned account presentation');
        publish(admission, () => {
        if (path === '/profile') profile.value = value as components['schemas']['AccountProfileResource'];
        else devices.value = value as components['schemas']['AccountDevicesResource'] | components['schemas']['InternalDevicesResource'];
        status.value = 'ready';
        }); return value;
    } catch (error) { if (admission) throw error;
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}
export const load = (route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) => read(route.path as '/profile' | '/security/devices' | '/internal/security/devices', passive, admission);
onClose(() => { clear(); outcomes.profile = ''; outcomes.password = ''; outcomes.devices = ''; });
