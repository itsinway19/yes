import type { components } from '../api/generated/application';
import { api } from '../spa/api';
import { onClose, scope } from '../spa/transport';
export type Team = components['schemas']['OrganizationTeam'];
export type TeamCommand = components['schemas']['OrganizationTeamCommand'];
export type TeamDraft = { recipient: string; confirmed: boolean; pending?: TeamCommand; blocked: boolean; notice: string };
export const teamDrafts = new Map<string, TeamDraft>();
export const teamKey = (organization: string) => JSON.stringify([scope, organization, 'organization-team:1']);
export const hasPendingTeamCommand = () => [...teamDrafts.values()].some(draft => !!draft.pending);
export async function readTeam(organization: string) {
    const result = await api.GET('/api/organizations/{organization}/team', { params: { path: { organization } } });
    if (!result.data) throw Error('Organization team unavailable');
    return result.data.data;
}
export async function writeTeam(organization: string, body: TeamCommand) {
    const result = await api.POST('/api/organizations/{organization}/team', { params: { path: { organization } }, body });
    if (!result.data) throw Error('Organization membership receipt unavailable');
    return result.data.data;
}
onClose(() => teamDrafts.clear());
