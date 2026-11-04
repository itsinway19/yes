export type PurposeObject = { provider: string; account_namespace: string; account_id: string; object_kind: string; object_id: string; incarnation: string | null };
export type PurposeIntent = { direction: string; scope: 'business_wide'; description: string | null; outcome: { meaning: string; clarification: string | null; count_unit: string | null }; quantity: string | null; horizon: { deadline_date: string; timezone: string } | null };
export type PurposeSnapshot = {
    meaning: string; qualifications: string | null; phase: string | null; included_objects: PurposeObject[]; excluded_objects: PurposeObject[];
    provenance: { speaker: string | null; statement_at: string | null; source: string; identity_basis: string; authority_basis: string; historical_support: string | null; effective_basis: string };
    dtm: { mode: string; version: number; countries: string[]; subset: string[]; statement: string } | null;
    intent: { version: number; value: PurposeIntent; statement: string } | null;
    end_condition: string | null; limitations: string | null; conflicts: string[];
};
export type CurrentPurposeRevision = { purpose_id: string; revision_id: string; version: number; operation: string; effective_from: string; effective_until: string | null; accepted_at: string; snapshot: PurposeSnapshot };
export type PurposeRevision = CurrentPurposeRevision & { actor_user_id?: number; actor_generation?: number; ownership_version?: number; reason: string | null; supersedes_revision_id: string | null; affected_revision_ids: string[] };
export type PurposeItem = { purpose_id: string; head_version: number; state: string; applicable: CurrentPurposeRevision | null; history: PurposeRevision[]; relationship_revalidation_required: boolean; continuity: string; dtm_standing: string };
