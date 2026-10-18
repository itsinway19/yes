import { api } from '../spa/api';
import type { components } from '../api/generated/application';

export type ContextData = components['schemas']['BusinessContextData'];
export type ContextResource = components['schemas']['BusinessContextResource'];
export type ContextUpdate = components['schemas']['UpdateBusinessContext'];
export type MarketsUpdate = components['schemas']['ReplaceTargetMarkets'];
export type IntentCommand = components['schemas']['CurrentIntentCommand'];
export type AuthorityRequest = components['schemas']['RequestBusinessContextAuthority'];

export async function readContext(workspace: string, headers: Record<string, string>) {
    const result = await api.GET('/workspaces/{workspace}/business', { params: { path: { workspace } }, headers });
    if (!result.data) throw Error('Missing business context');
    return result.data;
}
export async function updateContext(workspace: string, body: ContextUpdate) {
    const result = await api.POST('/workspaces/{workspace}/business', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing business context acknowledgement');
    return result.data;
}
export async function updateMarkets(workspace: string, body: MarketsUpdate) {
    const result = await api.POST('/workspaces/{workspace}/business/target-markets', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing target markets acknowledgement');
    return result.data;
}
export async function updateIntent(workspace: string, body: IntentCommand) {
    const result = await api.POST('/workspaces/{workspace}/business/current-intent', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing Current Intent acknowledgement');
    return result.data;
}
export async function requestAuthority(workspace: string, body: AuthorityRequest) {
    const result = await api.POST('/workspaces/{workspace}/business/authority', { params: { path: { workspace } }, body });
    if (!result.data) throw Error('Missing authority request acknowledgement');
    return result.data;
}
