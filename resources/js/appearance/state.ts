import { reactive, shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';
import { validColor } from './colors';
export type Appearance = components['schemas']['GlobalAppearanceData'];
export const appearance = shallowRef<Appearance>();
export function createDraft(value: Appearance) { return reactive({ revision: value.revision, primary: value.primary, secondary: value.secondary, outcome: '' as '' | 'pending' | 'unknown' | 'rejected' | 'stale' | 'acknowledged', error: '', reviewed: false }); }
export type AppearanceDraft = ReturnType<typeof createDraft>;
let serial = 0, cancellation: AbortController | undefined;
export function clearAppearance() { serial++; cancellation?.abort(); appearance.value = undefined; }
export function adoptPublished(draft: AppearanceDraft, value: Appearance) { draft.revision = value.revision; draft.primary = value.primary; draft.secondary = value.secondary; draft.outcome = ''; draft.error = ''; }
export async function readAppearance(passive = false, readback = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    const signal = admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal;
    try {
        const result = await api.GET('/internal/administration/appearance', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal });
        if (admission?.signal.aborted || !owned(captured) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/administration/appearance', { headers: { 'X-Builderad-Witness': '1' }, signal });
        if (admission?.signal.aborted || !owned(captured) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/administration/appearance' || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { if (admission) throw Error('Destination witness changed'); close(); return; }
        const value = result.data.data;
        publish(admission, () => { appearance.value = value; status.value = 'ready'; });
        return value;
    } catch (failure) {
        if (admission) throw failure;
        if (!owned(captured) || attempt !== serial || status.value === 'closed') return;
        if (failure instanceof TransportError && [401, 403, 419].includes(failure.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
export async function publishAppearance(draft: AppearanceDraft, signal?: AbortSignal) {
    if (signal?.aborted || ['pending', 'unknown', 'stale', 'acknowledged'].includes(draft.outcome) || draft.revision < 0 || !validColor(draft.primary) || !validColor(draft.secondary)) return;
    const captured = stamp();
    const body = { revision: draft.revision, primary: draft.primary.toLowerCase(), secondary: draft.secondary.toLowerCase() };
    draft.outcome = 'pending'; draft.error = ''; draft.reviewed = false;
    try {
        const result = await api.POST('/internal/administration/appearance', { body, signal });
        if (signal?.aborted || !owned(captured)) return;
        if (!result.data?.saved || !result.data.appearance) throw Error('Missing publication acknowledgement');
        draft.outcome = 'acknowledged';
        location.reload();
    } catch (failure) {
        if (signal?.aborted || !owned(captured)) return;
        if (failure instanceof TransportError && [403, 409, 422, 429].includes(failure.status)) {
            draft.outcome = failure.status === 409 ? 'stale' : 'rejected'; draft.error = failure.body.message ?? '';
            if (failure.status === 403) close();
        } else draft.outcome = 'unknown';
    }
}
onClose(clearAppearance);
