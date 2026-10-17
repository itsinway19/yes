import { api } from '../spa/api';
import type { components } from '../api/generated/application';

export type CreateBusiness = components['schemas']['CreateBusiness'];
export type WorkspaceIndexResource = components['schemas']['WorkspaceIndexResource'];
export type BusinessCreationResource = components['schemas']['BusinessCreationResource'];

export async function readWorkspaces(headers: Record<string, string> = {}) {
    const result = await api.GET('/workspaces', { headers });
    if (!result.data) throw Error('Workspace projection is unavailable');
    return result.data;
}

export async function readCreation(additional: boolean, headers: Record<string, string> = {}) {
    const result = await api.GET('/business/start', { params: { query: additional ? { new: '1' } : {} }, headers });
    if (!result.data) throw Error('Business creation projection is unavailable');
    return result.data;
}

export async function createBusiness(body: CreateBusiness) {
    const result = await api.POST('/business/start', { body });
    if (!result.data) throw Error('Business creation acknowledgement is unavailable');
    return result.data;
}

export async function reassessOwnerGrant(workspace: string) {
    const result = await api.POST('/workspaces/{workspace}/reassess-owner-grant', { params: { path: { workspace } } });
    if (!result.data) throw Error('Owner reassessment acknowledgement is unavailable');
    return result.data;
}
