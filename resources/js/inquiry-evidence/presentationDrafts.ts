import { onClose } from '../spa/transport';
import type { EditSnapshot } from './editor';
export type InquiryFormFields = {
    channel: string; country: string; cohortVersion: string; start: string; end: string; asOf: string; timezone: string;
    cost: string; currency: string; precision: string; costBasis: string; allocationBasis: string;
    inquiries: string; inquiryCoverage: string; acceptanceEnabled: boolean; accepted: string; pendingCount: string;
    criterion: string; criterionVersion: string; criterionDefinition: string; cutoff: string; maturity: string; acceptanceCoverage: string;
};
export type InquiryFormDraft = { fields: InquiryFormFields; edit?: EditSnapshot; basis: string; basisRevision: number };
// Unsent form work only, confined to this document and exact actor-generation/Business/basis access.
// This is neither an evidence cache nor authority. Current server qualification is required before restore.
export const inquiryFormDrafts = new Map<string, InquiryFormDraft>();
onClose(() => inquiryFormDrafts.clear());
