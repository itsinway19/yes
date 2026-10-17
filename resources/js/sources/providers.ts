import type { createApplicationClient } from '../api/application';
import type { components } from '../api/generated/application';

type Schemas = components['schemas'];
export type Provider = 'meta' | 'google' | 'tiktok' | 'snapchat' | 'x';
export type ProviderAccess = Schemas['ProviderAccess'];
export type ProviderSnapshot = Schemas['ProviderSnapshot'];
export type VersionCommand = Schemas['ProviderVersionCommand'];
export type SnapchatRenewalOutcome = Schemas['SnapchatRenewalOutcome'];
type BasicProvider = 'google' | 'tiktok' | 'snapchat';
type ReportScope = { workspace: string; source: string; signal?: AbortSignal };
export type ReportPeriod = { provider: BasicProvider; period: Schemas['ProviderReportPeriod'] }
    | { provider: 'meta'; period: Schemas['MetaReportPeriod'] }
    | { provider: 'x'; period: Schemas['XReportPeriod'] };
export type ReportCommand = { provider: BasicProvider; command: Schemas['ProviderReportCommand'] }
    | { provider: 'meta'; command: Schemas['MetaReportCommand'] }
    | { provider: 'x'; command: Schemas['XReportCommand'] };

function received<Value>(result: { data?: Value }, operation: string): Value {
    if (result.data === undefined) throw Error(`Missing ${operation} acknowledgement`);
    return result.data;
}

export function providerOperations(api: ReturnType<typeof createApplicationClient>) {
    return {
        async snapshot(provider: Provider, workspace: string, signal?: AbortSignal, passive = true) {
            return received(await api.GET(`/workspaces/{workspace}/providers/${provider}`, {
                params: { path: { workspace } }, signal, headers: passive ? { 'X-Builderad-Passive': '1' } : {},
            }), 'provider snapshot');
        },
        async connect(provider: Provider, workspace: string, intent: string, signal?: AbortSignal) {
            return received(await api.POST(`/workspaces/{workspace}/providers/${provider}/connect`, {
                params: { path: { workspace } }, body: { intent }, signal,
            }), 'provider connection');
        },
        async connectGoogle(workspace: string, body: Schemas['GoogleConnectCommand'], signal?: AbortSignal) {
            return received(await api.POST('/workspaces/{workspace}/providers/google/connect', {
                params: { path: { workspace } }, body, signal,
            }), 'Google connection');
        },
        async discover(provider: Exclude<Provider, 'google'>, workspace: string, flow: string, signal?: AbortSignal) {
            return received(await api.GET(`/workspaces/{workspace}/providers/${provider}/discovery/{flow}`, {
                params: { path: { workspace, flow } }, signal,
            }), 'provider discovery');
        },
        async googlePage(workspace: string, kind: 'discovery' | 'hierarchy', flow: string, signal?: AbortSignal) {
            return received(await api.GET(`/workspaces/{workspace}/providers/google/${kind}/{flow}`, {
                params: { path: { workspace, flow } }, signal,
            }), 'Google account page');
        },
        async selectMeta(workspace: string, selection: string, signal?: AbortSignal) {
            return received(await api.POST('/workspaces/{workspace}/providers/meta/select', {
                params: { path: { workspace } }, body: { selection }, signal,
            }), 'Meta selection');
        },
        async select(provider: Exclude<Provider, 'meta'>, workspace: string, selection: string, signal?: AbortSignal) {
            return received(await api.POST(`/workspaces/{workspace}/providers/${provider}/select`, {
                params: { path: { workspace } }, body: { selection }, signal,
            }), 'provider selection');
        },
        async disconnect(provider: Provider, workspace: string, source: string, body: VersionCommand, signal?: AbortSignal) {
            return received(await api.POST(`/workspaces/{workspace}/providers/${provider}/{source}/disconnect`, {
                params: { path: { workspace, source } }, body, signal,
            }), 'local disconnection');
        },
        async renew(provider: 'google' | 'snapchat', workspace: string, source: string, body: VersionCommand, signal?: AbortSignal) {
            return received(await api.POST(`/workspaces/{workspace}/providers/${provider}/{source}/renew`, {
                params: { path: { workspace, source } }, body, signal,
            }), 'provider renewal');
        },
        async reportStatus(scope: ReportScope, selection: ReportPeriod) {
            const path = { workspace: scope.workspace, source: scope.source };
            if (selection.provider === 'x') {
                const { campaign_ids, ...period } = selection.period;
                return received(await api.GET('/workspaces/{workspace}/providers/x/{source}/report', {
                    params: { path, query: { ...period, 'campaign_ids[]': campaign_ids } }, signal: scope.signal,
                }), 'X report status');
            }
            if (selection.provider === 'meta') return received(await api.GET('/workspaces/{workspace}/providers/meta/{source}/report', {
                params: { path, query: selection.period }, signal: scope.signal,
            }), 'Meta report status');
            return received(await api.GET(`/workspaces/{workspace}/providers/${selection.provider}/{source}/report`, {
                params: { path, query: selection.period }, signal: scope.signal,
            }), 'provider report status');
        },
        async report(scope: ReportScope, selection: ReportCommand) {
            const path = { workspace: scope.workspace, source: scope.source };
            if (selection.provider === 'x') return received(await api.POST('/workspaces/{workspace}/providers/x/{source}/report', {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'X report queue');
            if (selection.provider === 'meta') return received(await api.POST('/workspaces/{workspace}/providers/meta/{source}/report', {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'Meta report queue');
            return received(await api.POST(`/workspaces/{workspace}/providers/${selection.provider}/{source}/report`, {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'provider report queue');
        },
        async campaigns(workspace: string, source: string, continuation?: string, signal?: AbortSignal) {
            return received(await api.GET('/workspaces/{workspace}/providers/x/{source}/campaigns', {
                params: { path: { workspace, source }, query: continuation ? { continuation } : {} }, signal,
            }), 'X campaign discovery');
        },
    };
}
