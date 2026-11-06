import { onClose, scope, TransportError } from '../spa/transport';
import type { ContextCommand, CreateCommand, DraftResult, FactCommand, FactKind, Goals, LinkCommand, GrantCommand, ResourceCommand, BindingCommand, InternalTeamCommand, LifecycleCommand } from './api';
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
export type LinkDraft = DeliveryState & { workspace: string; kind: 'collaboration' | 'agency'; selected: string; version: number; linkRef: string | null;
    pending?: LinkCommand; notice: string; confirmed: boolean; blocked: boolean };
export const linkDrafts = new Map<string, LinkDraft>();
export type DelegationDraft = DeliveryState & { selected: string; recipient: string; agency: string; expires: string; delegable: boolean;
    pending?: GrantCommand; notice: string; confirmed: boolean; blocked: boolean };
export const delegationDrafts = new Map<string, DelegationDraft>();
export type InternalTeamDraft = { workspace: string; recipient: string; confirmed: boolean; pending?: InternalTeamCommand; uncertain?: boolean; notice: string };
export const internalTeamDrafts = new Map<string, InternalTeamDraft>();
export const directTeamDrafts = new Map<string, { confirmed: boolean; pending?: import('./api').DirectTeamEnrollment; blocked: boolean; notice: string }>();
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
export const creation: { name: string; type: number | null; codes: string[]; pending?: CreateCommand; receipt?: DraftResult; marketPending?: Extract<FactCommand, {kind:'markets'}>; marketsSaved: boolean } = { name: '', type: null, codes: [], marketsSaved: false };
// This in-document store is deliberately not shared with another principal or lifecycle.
export function key(business: string, realm: unknown) { return JSON.stringify([scope, business, realm, 'business.context:1']); }
export const businessDraftPrefix = (business: string) => `${JSON.stringify([scope, business]).slice(0, -1)},`;
export function hasPendingBusinessDraft(business: string) {
    if (creation.receipt?.business_public_id === business && creation.marketPending) return true;
    const prefix = businessDraftPrefix(business);
    return [drafts, factDrafts, linkDrafts, delegationDrafts, internalTeamDrafts, directTeamDrafts, purposeEnrollmentDrafts, dataUseDrafts, lifecycleDrafts]
        .some(store => [...store].some(([resource, draft]) => resource.startsWith(prefix) && !!draft.pending));
}
onClose(() => { drafts.clear(); lifecycleDrafts.clear(); factDrafts.clear(); linkDrafts.clear(); delegationDrafts.clear(); internalTeamDrafts.clear(); directTeamDrafts.clear(); purposeEnrollmentDrafts.clear(); boundaryDrafts.clear(); sourceDrafts.clear(); dataUseDrafts.clear(); Object.assign(creation, { name: '', type: null, codes: [], pending: undefined, receipt: undefined, marketPending: undefined, marketsSaved: false }); });
