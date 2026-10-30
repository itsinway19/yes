import { onClose, scope } from '../spa/transport';
import type { ContextCommand, CreateCommand, DraftResult, FactCommand, FactKind, Goals, LinkCommand, GrantCommand, ResourceCommand, BindingCommand } from './api';
export type EditorDraft = { name: string; type: number | null; baselineName: string; baselineType: number | null; version: number; selected: string; pending?: ContextCommand; notice: string };
export const drafts = new Map<string, EditorDraft>();
export type FactFields = { codes: string[]; goals: Goals; clearing: boolean; direction: string; description: string; meaning: string;
    clarification: string; countUnit: string; quantity: string; date: string; timezone: string };
export type FactDraft = { kind: FactKind; fields: FactFields; baseline: string; context: number; intent: number; preferences: number;
    selected: string; pending?: FactCommand; notice: string; confirmed: boolean; blocked: boolean; flags: Record<string, boolean> };
export const factDrafts = new Map<string, FactDraft>();
export type LinkDraft = { workspace: string; kind: 'collaboration' | 'agency'; selected: string; version: number; linkRef: string | null;
    pending?: LinkCommand; notice: string; confirmed: boolean; blocked: boolean };
export const linkDrafts = new Map<string, LinkDraft>();
export type DelegationDraft = { selected: string; recipient: string; agency: string; expires: string; delegable: boolean;
    pending?: GrantCommand; notice: string; confirmed: boolean; blocked: boolean };
export const delegationDrafts = new Map<string, DelegationDraft>();
export type BoundaryDraft = { choice: string; selected: string; revision: number; membership: string | null;
    pending?: ResourceCommand; confirmed: boolean; notice: string; blocked: boolean };
export const boundaryDrafts = new Map<string, BoundaryDraft>();
export type SourceAction = 'source.read' | 'source.register' | 'source.manage' | 'source.audit.recover' | 'provider.connect' | 'provider.read' | 'provider.revoke';
export type SourceDraft = { label: string; revision: number; action: SourceAction; pending?: ResourceCommand; notice: string; confirmed: boolean; acknowledged: boolean; blocked: boolean };
export const sourceDrafts = new Map<string, SourceDraft>();
export type DataUseDraft = { selected: string; manager: string; baselineScope: string; baselineRevision: number; confirmed: boolean;
    pending?: { kind: 'binding'; business: string; body: BindingCommand } | { kind: 'surrender'; body: ResourceCommand }; notice: string; acknowledged: boolean; blocked: boolean };
export const dataUseDrafts = new Map<string, DataUseDraft>();
export const creation: { name: string; type: number | null; pending?: CreateCommand; receipt?: DraftResult } = { name: '', type: null };
// This in-document store is deliberately not shared with another principal or lifecycle.
export function key(business: string, realm: unknown) { return JSON.stringify([scope, business, realm, 'business.context:1']); }
onClose(() => { drafts.clear(); factDrafts.clear(); linkDrafts.clear(); delegationDrafts.clear(); boundaryDrafts.clear(); sourceDrafts.clear(); dataUseDrafts.clear(); Object.assign(creation, { name: '', type: null, pending: undefined, receipt: undefined }); });
