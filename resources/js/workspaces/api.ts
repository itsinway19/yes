import { api } from '../spa/api';
import type { components } from '../api/generated/application';

export type WorkspaceIndexResource = components['schemas']['WorkspaceIndexResource'];

export async function readWorkspaces(headers: Record<string, string> = {}, signal?: AbortSignal) {
    const result = await api.GET('/workspaces', { headers, signal });
    if (!result.data) throw Error('Workspace projection is unavailable');
    return result.data;
}

export async function reassessOwnerGrant(workspace: string) {
    const result = await api.POST('/workspaces/{workspace}/reassess-owner-grant', { params: { path: { workspace } } });
    if (!result.data) throw Error('Owner reassessment acknowledgement is unavailable');
    return result.data;
}
