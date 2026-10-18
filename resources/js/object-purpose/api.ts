import { api } from '../spa/api';
import type { components } from '../api/generated/application';

export type PurposeData = components['schemas']['ObjectPurposeData'];
export type PurposeResource = components['schemas']['ObjectPurposeResource'];
export type PurposeCommand = components['schemas']['PurposeCommand'];
export type PurposeAcknowledgement = components['schemas']['PurposeAcknowledgement'];
export type TemporalQuery = { effective_at?: string; known_at?: string };

export async function readPurpose(workspace: string, purpose: string | null, query: TemporalQuery, headers: Record<string, string>) {
    const result = purpose
        ? await api.GET('/workspaces/{workspace}/object-purposes/{purpose}/context', { params: { path: { workspace, purpose }, query }, headers })
        : await api.GET('/workspaces/{workspace}/object-purposes/context', { params: { path: { workspace }, query }, headers });
    if (!result.data) throw Error('Missing Object Purpose context');
    return result.data;
}
export async function writePurpose(workspace: string, body: PurposeCommand) {
    const result = body.purpose_id
        ? await api.POST('/workspaces/{workspace}/object-purposes/{purpose}/revisions', { params: { path: { workspace, purpose: body.purpose_id } }, body })
        : await api.POST('/workspaces/{workspace}/object-purposes', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing Object Purpose acknowledgement');
    return result.data;
}
