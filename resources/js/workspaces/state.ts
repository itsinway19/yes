import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { reactive, shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { query } from '../spa/query';
import { stamp, owned, onClose, scope } from '../spa/transport';
import { readWorkspaces, readCreation, type WorkspaceIndexResource, type OrganizationCreationResource, type CreateOrganization } from './api';

export const current = shallowRef<WorkspaceIndexResource | OrganizationCreationResource>();
export const creationDraft = reactive({ name: '', creation_key: '' });
export const unresolvedCreation = shallowRef<CreateOrganization>();
let serial = 0;

export function resetCreationDraft() {
    Object.assign(creationDraft, { name: '', creation_key: '' });
    unresolvedCreation.value = undefined;
}

export function clear() { serial++; current.value = undefined; void query.cancelQueries({ queryKey: [scope, 'workspaces'] }); }

export async function load(route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    const read = route.name === 'application.workspaces' ? readWorkspaces : readCreation;
    try {
        await query.cancelQueries({ queryKey: [scope, 'workspaces', route.fullPath] });
        const value = await query.fetchQuery<Awaited<ReturnType<typeof read>>>({ queryKey: [scope, 'workspaces', route.fullPath], queryFn: ({ signal }) => read(passive ? { 'X-Builderad-Passive': '1' } : {}, admission ? AbortSignal.any([signal, admission.signal]) : signal) });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !('data' in value)) return;
        const witness = await read({ 'X-Builderad-Witness': '1' }, admission?.signal);
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Presentation context changed');
        publish(admission, () => {
        current.value = value;
        if ('creationKey' in value.data && typeof value.data.creationKey === 'string' && !creationDraft.creation_key) creationDraft.creation_key = value.data.creationKey;
        status.value = 'ready';
        });
    } catch (error) { if (admission) throw error;
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}

onClose(() => {
    clear();
    resetCreationDraft();
});
