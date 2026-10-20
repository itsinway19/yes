import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { query } from '../spa/query';
import { shallowRef } from 'vue';
import { scope, stamp, owned, onClose } from '../spa/transport';
import { readGoalsContext } from './api';
import type { GoalsContext } from '../api/goals';
export type GoalsData = GoalsContext;
export { query };
export const current = shallowRef<GoalsData>();
export { confirmDeparture } from '../spa/presentation';
let load = 0;
export async function read(workspace: string, passive = false, admission?: PresentationAdmission) {
    const captured = stamp(), serial = ++load;
    try {
        await query.cancelQueries({ queryKey: [scope, 'goals', workspace] });
        const value = await query.fetchQuery({ queryKey: [scope, 'goals', workspace], queryFn: async ({ signal }) => {
            const data = await readGoalsContext(workspace, passive ? { 'X-Goals-Passive': '1' } : {}, admission ? AbortSignal.any([signal, admission.signal]) : signal);
            if (!('preferences' in data)) throw Error('Context projection required');
            return data;
        } });
        if ((admission?.signal.aborted || !owned(captured)) || serial !== load || value.workspace.public_id !== workspace) return;
        const witness = await readGoalsContext(workspace, { 'X-Goals-Witness': '1' }, admission?.signal);
        if ((admission?.signal.aborted || !owned(captured)) || serial !== load) return;
        if (!witness.visibility.protected || JSON.stringify(witness.visibility) !== JSON.stringify(value.visibility)) throw new Error('Presentation context changed');
        publish(admission, () => { current.value = value; status.value = 'ready'; }); return value;
    } catch (error) { if (admission) throw error; if (owned(captured) && serial === load && status.value !== 'closed') status.value = 'unavailable'; }
}
export function clearDisplay() { load++; current.value = undefined; void query.cancelQueries({ queryKey: [scope, 'goals'] }); }
onClose(() => { clearDisplay(); });
