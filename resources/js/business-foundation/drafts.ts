import { onClose, scope, TransportError } from '../spa/transport';
import { hasPendingDataLicense } from './dataLicenseJourney';
import type { ContextCommand, FactCommand, FactKind, Goals, GrantCommand, ResourceCommand, BindingCommand, LifecycleCommand } from './api';
type DeliveryState = { uncertain?: boolean; blocked?: boolean };
// Delivery uncertainty is document state, not authority. A first validation
// rejection is definitive; a canonical typed CAS rejection follows receipt lookup.
export function beginDelivery(draft: DeliveryState): boolean { const uncertain = !!draft.uncertain; draft.uncertain = true; return uncertain; }
export function definitiveRejection(error: unknown, uncertain: boolean): boolean {
    return error instanceof TransportError && (error.status === 422 && !uncertain
        || error.status === 409 && 'error' in error.body && error.body.error === 'business_relation_revision_rejected');
}
export type EditorDraft = DeliveryState & { name: string; type: number | null; baselineName: string; baselineType: number | null; version: number; selected: string; pending?: ContextCommand; notice: string };
export const drafts = new Map<string, EditorDraft>();
export type LifecycleDraft = DeliveryState & { selected: string; revision: number; pending?: LifecycleCommand; blocked: boolean;
    outcome: '' | 'unknown' | 'rejected' | 'denied' | 'archived' };
export const lifecycleDrafts = new Map<string, LifecycleDraft>();
export type FactFields = { codes: string[]; goals: Goals; clearing: boolean; direction: string; description: string; meaning: string;
    clarification: string; countUnit: string; quantity: string; date: string; timezone: string };
export type FactDraft = DeliveryState & { kind: FactKind; fields: FactFields; baseline: string; context: number; intent: number; preferences: number;
    selected: string; pending?: FactCommand; notice: string; confirmed: boolean; blocked: boolean; flags: Record<string, boolean> };
export const factDrafts = new Map<string, FactDraft>();
export type DelegationDraft = DeliveryState & { selected: string; recipient: string; agency: string; expires: string; delegable: boolean;
    pending?: GrantCommand; notice: string; confirmed: boolean; blocked: boolean };
export const delegationDrafts = new Map<string, DelegationDraft>();
export const directTeamDrafts = new Map<string, { confirmed: boolean; pending?: import('./api').DirectTeamEnrollment; blocked: boolean; notice: string }>();
export type AgencyTeamDraft = { mode: 'mandate' | 'assign'; agency: string; recipient: string; action: string; expires: string;
    confirmed: boolean; pending?: import('./api').AgencyGrantCommand; uncertain: boolean; blocked: boolean;
    outcome: '' | 'unavailable' | 'empty' | 'unknown' | 'denied' | 'rejected' | 'recorded' | 'context' };
export const agencyTeamDrafts = new Map<string, AgencyTeamDraft>();
export type EmployeeAssignmentReference = { business: string; readGrant: string; resource: string; employee: string };
export type TeamMembersDraft = { mode: '' | 'add' | 'edit' | 'remove'; email: string; recipient_public_id: string; permissions: string[];
    expected: { grant_id: string; version: number }[]; expiryMode: 'unreviewed' | 'indefinite' | 'until'; expires: string;
    confirmed: boolean; pending?: import('./api').BusinessTeamCommand; uncertain: boolean; blocked: boolean;
    outcome: '' | 'unavailable' | 'recipient_unavailable' | 'unknown' | 'denied' | 'rejected' | 'recorded'; assignment?: EmployeeAssignmentReference };
export const teamMembersDrafts = new Map<string, TeamMembersDraft>();
export type BusinessMandatesDraft = { agency: string; expected: { grant_id: string; version: number }[]; confirmed: boolean;
    pending?: import('./api').AgencyMandatesCommand; uncertain: boolean; blocked: boolean;
    outcome: '' | 'unavailable' | 'unknown' | 'denied' | 'rejected' | 'withdrawn' };
export const businessMandatesDrafts = new Map<string, BusinessMandatesDraft>();
export const purposeEnrollmentDrafts = new Map<string, { confirmed: boolean; pending?: import('./api').PurposeStatementEnrollment; blocked: boolean; notice: string }>();
export type BoundaryDraft = DeliveryState & { choice: string; selected: string; revision: number; membership: string | null;
    pending?: ResourceCommand; confirmed: boolean; notice: string; blocked: boolean };
export const boundaryDrafts = new Map<string, BoundaryDraft>();
export type SourceAction = 'source.read' | 'source.register' | 'source.manage' | 'source.audit.recover' | 'provider.connect' | 'provider.read' | 'provider.revoke';
export type SourceDraft = DeliveryState & { label: string; revision: number; action: SourceAction; pending?: ResourceCommand; notice: string; confirmed: boolean; acknowledged: boolean; blocked: boolean };
export const sourceDrafts = new Map<string, SourceDraft>();
export type DataUseDraft = DeliveryState & { selected: string; manager: string; baselineScope: string; baselineRevision: number; confirmed: boolean;
    pending?: { kind: 'binding'; business: string; body: BindingCommand } | { kind: 'surrender'; body: ResourceCommand }; notice: string; acknowledged: boolean; blocked: boolean };
export const dataUseDrafts = new Map<string, DataUseDraft>();
export const creation = new Map<string, string>();
// This in-document store is deliberately not shared with another principal or lifecycle.
export function key(business: string, realm: unknown) { return JSON.stringify([scope, business, realm, 'business.context:1']); }
export const businessDraftPrefix = (business: string) => `${JSON.stringify([scope, business]).slice(0, -1)},`;
export function hasPendingBusinessDraft(business: string) {
    if (hasPendingDataLicense(business)) return true;
    if ([...creation.values()].includes(business)) return true;
    const prefix = businessDraftPrefix(business);
    if ([...teamMembersDrafts].some(([resource, draft]) => resource.startsWith(prefix) && draft.assignment
        && (!!agencyTeamDrafts.get(draft.assignment.resource)?.pending || !!delegationDrafts.get(`${draft.assignment.resource}:delegations:1`)?.pending))) return true;
    return [internalTeamDrafts, drafts, factDrafts, delegationDrafts, directTeamDrafts, agencyTeamDrafts, teamMembersDrafts, businessMandatesDrafts, purposeEnrollmentDrafts, dataUseDrafts, lifecycleDrafts]
        .some(store => [...store].some(([resource, draft]) => resource.startsWith(prefix) && !!draft.pending));
}
onClose(() => { drafts.clear(); lifecycleDrafts.clear(); factDrafts.clear(); delegationDrafts.clear(); directTeamDrafts.clear(); agencyTeamDrafts.clear(); teamMembersDrafts.clear(); businessMandatesDrafts.clear(); purposeEnrollmentDrafts.clear(); boundaryDrafts.clear(); sourceDrafts.clear(); dataUseDrafts.clear(); creation.clear(); });

export type InternalTeamDraft = { recipient:string; confirmed:boolean; pending?: import('./api').InternalTeamCommand; uncertain?:boolean; notice:string };
export const internalTeamDrafts = new Map<string,InternalTeamDraft>();
onClose(()=>internalTeamDrafts.clear());
