import { query } from '../spa/query';
import { shallowRef } from 'vue';
import { request, scope, stamp, owned, status, resumeLease, onClose, bootstrap } from './transport';
import { readGoalsContext } from './api';
import type { GoalsContext } from '../api/goals';
export type GoalsData = GoalsContext;
export { query };
export const current = shallowRef<GoalsData>();
export { confirmDeparture } from '../spa/presentation';
let load = 0;
export async function read(workspace: string, passive = false) {
    const captured = stamp(), serial = ++load;
    try {
        const value = await query.fetchQuery({ queryKey: [scope, workspace, 'goals'], queryFn: async () => {
            const data = await readGoalsContext(workspace, passive ? { 'X-Goals-Passive': '1' } : {});
            if (!('preferences' in data)) throw Error('Context projection required');
            return data;
        } });
        if (!owned(captured) || serial !== load || value.workspace.public_id !== workspace) return;
        const witness = await readGoalsContext(workspace, { 'X-Goals-Witness': '1' });
        if (!owned(captured) || serial !== load) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw new Error('Presentation context changed');
        current.value = value; status.value = 'ready'; resumeLease(); return value;
    } catch { if (owned(captured) && serial === load && status.value !== 'closed') status.value = 'unavailable'; }
}
export function clearDisplay() { load++; current.value = undefined; void query.cancelQueries(); }
onClose(() => { clearDisplay(); query.clear(); });
export async function applyEvent(event: {workspace:string;version:number;binding:unknown}, workspace: string) {
    if (status.value !== 'ready' || event.workspace !== workspace || current.value?.workspace.public_id !== workspace
        || JSON.stringify(event.binding) !== JSON.stringify(bootstrap.identity.binding)
        || event.version <= current.value.preferences.version) return false;
    await query.invalidateQueries({queryKey:[scope,workspace,'goals']});await read(workspace,true);return true;
}
export async function eventProof(workspace: string) {
    const captured = stamp();
    const event=await request<{workspace:string;version:number;binding:unknown}>(`/spa/api/workspaces/${workspace}/goals/events`);
    if(!owned(captured))return false;return applyEvent(event,workspace);
}
