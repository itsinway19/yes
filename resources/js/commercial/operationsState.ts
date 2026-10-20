import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { shallowRef } from 'vue';
import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { close, onClose, owned, stamp, TransportError } from '../spa/transport';

export const operationsData = shallowRef<components['schemas']['CommercialOperationsData']>();
let serial = 0;
let cancellation: AbortController | undefined;
export function clearOperations() { serial++; cancellation?.abort(); operationsData.value = undefined; }
export async function readOperations(passive = false, readback = false, admission?: PresentationAdmission) {
    const attempt = ++serial, captured = stamp(); cancellation?.abort(); cancellation = new AbortController();
    try {
        const result = await api.GET('/internal/commercial', { headers: passive ? { 'X-Builderad-Passive': '1' } : {}, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial || !result.data || !('data' in result.data)) return;
        const witness = await api.GET('/internal/commercial', { headers: { 'X-Builderad-Witness': '1' }, signal: admission ? AbortSignal.any([admission.signal, cancellation.signal]) : cancellation.signal });
        if ((admission?.signal.aborted || !owned(captured)) || attempt !== serial) return;
        if (!result.data.visibility.protected || result.data.visibility.path !== '/internal/commercial'
            || JSON.stringify(result.data.visibility) !== JSON.stringify(witness.data?.visibility)) { if (admission) throw Error('Destination witness changed'); close(); return; }
        const value = result.data.data;
        publish(admission, () => { operationsData.value = value; status.value = 'ready'; }); return value;
    } catch (error) {
        if (admission) throw error;
        if ((!owned(captured)) || attempt !== serial || status.value === 'closed') return;
        if (error instanceof TransportError && [401, 403, 419].includes(error.status)) close();
        else if (!readback) status.value = 'unavailable';
    }
}
type Schemas = components['schemas'];
export type CommercialCommand =
    | { kind: 'market'; body: Schemas['CommercialMarketCommand'] }
    | { kind: 'catalog'; body: Schemas['CommercialCatalogCommand'] }
    | { kind: 'configuration'; body: Schemas['CommercialConfigurationCommand'] }
    | { kind: 'assignability'; body: Schemas['CommercialAssignabilityCommand'] }
    | { kind: 'register'; body: Schemas['CommercialRegistrationCommand'] };
export async function writeCommercial(command: CommercialCommand, signal?: AbortSignal) {
    const result = command.kind === 'market' ? await api.POST('/internal/commercial/markets', { body: command.body, signal })
        : command.kind === 'catalog' ? await api.POST('/internal/commercial/catalog-references', { body: command.body, signal })
        : command.kind === 'configuration' ? await api.POST('/internal/commercial/configurations', { body: command.body, signal })
        : command.kind === 'assignability' ? await api.POST('/internal/commercial/assignability', { body: command.body, signal })
        : await api.POST('/internal/commercial/registrations', { body: command.body, signal });
    if (!result.data) throw Error('Missing original commercial command acknowledgement');
    return result.data;
}
export async function readCommercialReceipt(command: string, signal?: AbortSignal) {
    const result = await api.GET('/internal/commercial/receipts/{command}', { params: { path: { command } }, signal });
    if (!result.data) throw Error('Missing commercial receipt');
    return result.data;
}
export async function readCommercialWorkspace(scope_id: string, workspace_public_id: string, signal?: AbortSignal) {
    const result = await api.GET('/internal/commercial/workspace-reference', { params: { query: { scope_id, workspace_public_id } }, signal });
    return result.data;
}
export async function readCommercialContext(scope: string, signal?: AbortSignal) {
    const result = await api.GET('/internal/commercial/contexts/{scope}', { params: { path: { scope } }, signal });
    return result.data;
}
export async function recoverCommercialAudit(obligation: string, signal?: AbortSignal) {
    const result = await api.POST('/internal/commercial/audit/{obligation}/recover', { params: { path: { obligation } }, signal });
    if (!result.data) throw Error('Missing audit recovery acknowledgement');
    return result.data;
}
export async function issueCommercialGrant(body: Schemas['CommercialGrantCommand'], signal?: AbortSignal) {
    return (await api.POST('/internal/commercial/grants', { body, signal })).data;
}
export async function revokeCommercialGrant(grant: number, signal?: AbortSignal) {
    return api.DELETE('/internal/commercial/grants/{grant}', { params: { path: { grant } }, signal });
}
export async function executeCommercialMandate(mandate_id: string, signal?: AbortSignal) {
    return (await api.POST('/internal/commercial/owner-mandate', { body: { mandate_id }, signal })).data;
}
onClose(clearOperations);
