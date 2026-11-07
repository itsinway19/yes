import { reactive, ref } from 'vue';
import type { components, operations } from '../api/generated/application';
import { api } from '../spa/api';
import { onClose, request, scope } from '../spa/transport';
import type { BindingCommand, ResourceCommand } from './api';

export type Journey = components['schemas']['DataLicenseJourney'];
export type License = Journey['licenses'][number];
export type LicenseCommand = components['schemas']['BusinessDataUseLicenseCommand'];
export type MandateCommand = components['schemas']['BusinessOperatingMandateCommand'];
export type BeneficiaryCommand = components['schemas']['BusinessDataUseBeneficiaryCommand'];
export type MaterialCommand = operations['readQualifiedOriginalMaterial']['requestBody']['content']['application/json'];
export type MaterialResult = operations['readQualifiedOriginalMaterial']['responses'][200]['content']['application/json']['data'];
export type SummaryCommand = operations['prepareExpenditureSummary']['requestBody']['content']['application/json'];
export type PublishCommand = operations['publishQualifiedRegisteredMaterial']['requestBody']['content']['application/json'];
export type Pending = { business: string } & ({ kind: 'license'; body: LicenseCommand } | { kind: 'mandate'; body: MandateCommand } | { kind: 'beneficiary'; body: BeneficiaryCommand; expectedGrant?: string } | { kind: 'summary'; body: SummaryCommand } | { kind: 'summary-process'; body: MaterialCommand; license: string } | { kind: 'summary-publish'; body: PublishCommand } | { kind: 'coverage'; body: ResourceCommand } | { kind: 'binding'; body: BindingCommand });
export type JourneyDraft = { source: string; dataset: string; license: string; businessUse: string; binding: string; purpose: string; expires: string; holder: string; reference: string; capacity: 'data_holder' | 'authorized_representative'; reviewNeeded: boolean; withdrawConfirmed: boolean; mandateCapacity: 'responsible_business' | 'client_mandate'; mandateReference: string; mandateExpires: string; mandateConfirmed: boolean; beneficiary: string; assignmentAuthority: string; assignmentExpires: string; assignmentConfirmed: boolean; confirmed: boolean; pending?: Pending; uncertain: boolean; blocked: boolean; outcome: '' | 'unknown' | 'rejected' | 'revision' | 'denied' | 'acknowledged' | 'unavailable' };
export type SummaryRead = { license: string; command: string; input: MaterialCommand['material_refs'][number] };
export type SummaryDraft = { operation: 'display' | 'process'; summaryRecord: string; summaryExpires: string; summaryConfirmed: boolean; summaryRead?: SummaryRead };
export const journeyDrafts = reactive(new Map<string, JourneyDraft & SummaryDraft>());
export const journeyClosed = ref(false);
export const journeyDeliveries = reactive(new Set<string>());
export const journeyKey = (surface: import('./api').Surface) => JSON.stringify([scope, surface.business.business_public_id, surface.authority_selection]);
export const emptyJourneyDraft = (): JourneyDraft & SummaryDraft => ({ source: '', dataset: '', license: '', businessUse: '', binding: '', purpose: '', expires: '', holder: '', reference: '', capacity: 'data_holder', reviewNeeded: false, withdrawConfirmed: false, mandateCapacity: 'responsible_business', mandateReference: '', mandateExpires: '', mandateConfirmed: false, beneficiary: '', assignmentAuthority: '', assignmentExpires: '', assignmentConfirmed: false, confirmed: false, uncertain: false, blocked: false, outcome: '', operation: 'display', summaryRecord: '', summaryExpires: '', summaryConfirmed: false, summaryRead: undefined });
export function clearJourneyPrivate(draft: JourneyDraft) { const { pending, uncertain, blocked, outcome } = draft; Object.assign(draft, emptyJourneyDraft(), { pending, uncertain, blocked, outcome }); }
export function hasPendingDataLicense(business: string) { return [...journeyDrafts.values()].some(draft => draft.pending?.business === business); }
onClose(() => { journeyClosed.value = true; for (const draft of journeyDrafts.values()) Object.assign(draft, emptyJourneyDraft()); journeyDrafts.clear(); journeyDeliveries.clear(); });
export function newLicenseId(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    const timestamp = Date.now();
    const view = new DataView(bytes.buffer);
    view.setUint16(0, Math.floor(timestamp / 2 ** 32));
    view.setUint32(2, timestamp);
    bytes[6] = 0x70 | (bytes[6] & 0x0f);
    bytes[8] = 0x80 | (bytes[8] & 0x3f);
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}
export async function readJourney(business: string, access: number): Promise<Journey> {
    // The fetch client's read/write type filters drop properties whose only legal value is null.
    // These two calls preserve the contract's required partition_ref: null on the same protected transport.
    const url = `/api/businesses/${encodeURIComponent(business)}/data-use-journey?access=${access}`;
    return (await request<components['schemas']['DataLicenseJourneyResult']>(url)).data;
}
export async function deliverJourney(command: Pending): Promise<Record<string, unknown>> {
    if (journeyDeliveries.has(command.body.command_id)) throw Error('Original delivery is still in flight');
    journeyDeliveries.add(command.body.command_id);
    try {
        const params = { path: { business: command.business } };
        if (command.kind === 'license') {
            const url = `/api/businesses/${encodeURIComponent(command.business)}/data-use-licenses`;
            return (await request<components['schemas']['BusinessDataUseLicenseResult']>(url, {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(command.body),
            })).data;
        }
        const result = command.kind === 'summary' ? await api.POST('/api/businesses/{business}/expenditure-summaries', { params, body: command.body })
            : command.kind === 'summary-process' ? await api.POST('/api/businesses/{business}/materials/read', { params, body: command.body })
            : command.kind === 'summary-publish' ? await api.POST('/api/businesses/{business}/materials/publish', { params, body: command.body })
            : command.kind === 'mandate' ? await api.POST('/api/businesses/{business}/operating-mandate', { params, body: command.body })
            : command.kind === 'beneficiary' ? await api.POST('/api/businesses/{business}/data-use-beneficiaries', { params, body: command.body })
            : command.kind === 'coverage' ? await api.POST('/api/data-rights/commands', { body: command.body })
            : await api.POST('/api/businesses/{business}/data-use-bindings', { params, body: command.body });
        if (!result.data) throw Error('Original command receipt unavailable');
        return result.data.data;
    }
    finally { journeyDeliveries.delete(command.body.command_id); }
}
export async function readLicensedMaterials(business: string, body: MaterialCommand): Promise<MaterialResult> {
    const result = await api.POST('/api/businesses/{business}/materials/read', { params: { path: { business } }, body });
    if (!result.data) throw Error('Licensed material response unavailable');
    return result.data.data;
}
