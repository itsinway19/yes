import { reactive, shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { query } from '../spa/query';
import { stamp, owned, status, resumeLease, onClose, scope } from '../spa/transport';
import { readWorkspaces, readCreation, type WorkspaceIndexResource, type BusinessCreationResource, type CreateBusiness } from './api';

export const current = shallowRef<WorkspaceIndexResource | BusinessCreationResource>();
export const creationDraft = reactive({ name: '', business_type_id: '' as number | '', creation_key: '' });
export const unresolvedCreation = shallowRef<CreateBusiness>();
let serial = 0;

export function clear() { serial++; current.value = undefined; void query.cancelQueries(); }

export async function load(route: RouteLocationNormalized, passive: boolean) {
    const captured = stamp(), attempt = ++serial;
    const read = route.name === 'application.workspaces' ? readWorkspaces
        : (headers: Record<string, string>) => readCreation(route.query.new === '1', headers);
    try {
        const value = await query.fetchQuery<Awaited<ReturnType<typeof read>>>({ queryKey: [scope, 'workspaces', route.fullPath], queryFn: () => read(passive ? { 'X-Builderad-Passive': '1' } : {}) });
        if (!owned(captured) || attempt !== serial || !('data' in value)) return;
        const witness = await read({ 'X-Builderad-Witness': '1' });
        if (!owned(captured) || attempt !== serial) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw Error('Presentation context changed');
        current.value = value;
        if ('creationKey' in value.data && !creationDraft.creation_key) creationDraft.creation_key = value.data.creationKey;
        status.value = 'ready';
        resumeLease();
    } catch {
        if (owned(captured) && attempt === serial && status.value !== 'closed') status.value = 'unavailable';
    }
}

onClose(() => {
    clear(); query.clear();
    Object.assign(creationDraft, { name: '', business_type_id: '', creation_key: '' });
    unresolvedCreation.value = undefined;
});
