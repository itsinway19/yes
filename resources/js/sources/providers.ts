import { providerRoute, type NativeSourceReference } from './nativeOwner.ts';
import type { createApplicationClient } from '../api/application';
import type { components } from '../api/generated/application';

type Schemas = components['schemas'];
export type Provider = 'meta' | 'google' | 'tiktok' | 'snapchat' | 'x';
export type ProviderAccess = Schemas['ProviderAccess'];
export type ProviderSnapshot = Schemas['ProviderSnapshot'];
export type VersionCommand = Schemas['ProviderVersionCommand'];
export type SnapchatRenewalOutcome = Schemas['SnapchatRenewalOutcome'];
type BasicProvider = 'google' | 'tiktok' | 'snapchat';
type ReportScope = { workspace: NativeSourceReference; source: string; signal?: AbortSignal };
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
        async snapshot(provider: Provider, workspace: NativeSourceReference, signal?: AbortSignal, passive = true) {
            const owner = providerRoute(workspace);
            return received(await api.GET(`${owner.root}/providers/${provider}`, {
                params: { path: owner.path }, signal, headers: passive ? { 'X-Builderad-Passive': '1' } : {},
            }), 'provider snapshot');
        },
        async connect(provider: Provider, workspace: NativeSourceReference, intent: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/${provider}/connect`, {
                params: { path: owner.path }, body: { intent }, signal,
            }), 'provider connection');
        },
        async connectGoogle(workspace: NativeSourceReference, body: Schemas['GoogleConnectCommand'], signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/google/connect`, {
                params: { path: owner.path }, body, signal,
            }), 'Google connection');
        },
        async discover(provider: Exclude<Provider, 'google'>, workspace: NativeSourceReference, flow: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.GET(`${owner.root}/providers/${provider}/discovery/{flow}`, {
                params: { path: { ...owner.path, flow } }, signal,
            }), 'provider discovery');
        },
        async googlePage(workspace: NativeSourceReference, kind: 'discovery' | 'hierarchy', flow: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.GET(`${owner.root}/providers/google/${kind}/{flow}`, {
                params: { path: { ...owner.path, flow } }, signal,
            }), 'Google account page');
        },
        async selectMeta(workspace: NativeSourceReference, selection: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/meta/select`, {
                params: { path: owner.path }, body: { selection }, signal,
            }), 'Meta selection');
        },
        async select(provider: Exclude<Provider, 'meta'>, workspace: NativeSourceReference, selection: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/${provider}/select`, {
                params: { path: owner.path }, body: { selection }, signal,
            }), 'provider selection');
        },
        async disconnect(provider: Provider, workspace: NativeSourceReference, source: string, body: VersionCommand, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/${provider}/{source}/disconnect`, {
                params: { path: { ...owner.path, source } }, body, signal,
            }), 'local disconnection');
        },
        async renew(provider: 'google' | 'snapchat', workspace: NativeSourceReference, source: string, body: VersionCommand, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.POST(`${owner.root}/providers/${provider}/{source}/renew`, {
                params: { path: { ...owner.path, source } }, body, signal,
            }), 'provider renewal');
        },
        async reportStatus(scope: ReportScope, selection: ReportPeriod) {
            const owner = providerRoute(scope.workspace);
            const path = { ...owner.path, source: scope.source };
            if (selection.provider === 'x') {
                const { campaign_ids, ...period } = selection.period;
                return received(await api.GET(`${owner.root}/providers/x/{source}/report`, {
                    params: { path, query: { ...period, 'campaign_ids[]': campaign_ids } }, signal: scope.signal,
                }), 'X report status');
            }
            if (selection.provider === 'meta') return received(await api.GET(`${owner.root}/providers/meta/{source}/report`, {
                params: { path, query: selection.period }, signal: scope.signal,
            }), 'Meta report status');
            return received(await api.GET(`${owner.root}/providers/${selection.provider}/{source}/report`, {
                params: { path, query: selection.period }, signal: scope.signal,
            }), 'provider report status');
        },
        async report(scope: ReportScope, selection: ReportCommand) {
            const owner = providerRoute(scope.workspace);
            const path = { ...owner.path, source: scope.source };
            if (selection.provider === 'x') return received(await api.POST(`${owner.root}/providers/x/{source}/report`, {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'X report queue');
            if (selection.provider === 'meta') return received(await api.POST(`${owner.root}/providers/meta/{source}/report`, {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'Meta report queue');
            return received(await api.POST(`${owner.root}/providers/${selection.provider}/{source}/report`, {
                params: { path }, body: selection.command, signal: scope.signal,
            }), 'provider report queue');
        },
        async campaigns(workspace: NativeSourceReference, source: string, continuation?: string, signal?: AbortSignal) {
            const owner = providerRoute(workspace);
            return received(await api.GET(`${owner.root}/providers/x/{source}/campaigns`, {
                params: { path: { ...owner.path, source }, query: continuation ? { continuation } : {} }, signal,
            }), 'X campaign discovery');
        },
    };
}
