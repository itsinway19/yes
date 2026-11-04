import { api } from '../spa/api';
import type { components } from '../api/generated/application';

export type PurposeData = components['schemas']['ObjectPurposeData'];
export type PurposeResource = components['schemas']['ObjectPurposeResource'];
export type PurposeCommand = components['schemas']['PurposeCommand'];
export type PurposeAcknowledgement = components['schemas']['PurposeAcknowledgement'];
export type TemporalQuery = { effective_at?: string; known_at?: string };

export async function readPurpose(workspace: string, purpose: string | null, query: TemporalQuery, headers: Record<string, string>, signal?: AbortSignal) {
    const result = purpose
        ? await api.GET('/workspaces/{workspace}/purpose-history/{purpose}/context', { params: { path: { workspace, purpose }, query }, headers, signal })
        : await api.GET('/workspaces/{workspace}/purpose-history/context', { params: { path: { workspace }, query }, headers, signal });
    if (!result.data) throw Error('Missing Object Purpose context');
    return result.data;
}
export async function writePurpose(workspace: string, body: PurposeCommand) {
    if (!body.purpose_id || !['correct', 'withdraw'].includes(body.operation)) throw Error('Only an original history correction or withdrawal is admitted');
    const result = await api.POST('/workspaces/{workspace}/purpose-history/{purpose}/revisions', { params: { path: { workspace, purpose: body.purpose_id } }, body: { ...body, purpose_id: body.purpose_id, operation: body.operation as 'correct' | 'withdraw' } });
    if (!result.data) throw Error('Missing Object Purpose acknowledgement');
    return result.data;
}
