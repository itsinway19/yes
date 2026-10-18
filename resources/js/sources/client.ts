import type { createApplicationClient } from '../api/application';
import type { components } from '../api/generated/application';

export type SourceCommand = components['schemas']['SourceCommand'];
export type SourceAuthorityCommand = components['schemas']['SourceAuthorityCommand'];
export type SourcesData = components['schemas']['WorkspaceSourcesData'];

export function sourceOperations(api: ReturnType<typeof createApplicationClient>) {
    return {
        async read(workspace: string, headers: Record<string, string> = {}, signal?: AbortSignal) {
            const result = await api.GET('/workspaces/{workspace}', { params: { path: { workspace } }, headers, signal });
            if (!result.data) throw Error('Missing Source resource');
            return result.data;
        },
        async grant(workspace: string, body: SourceAuthorityCommand, signal?: AbortSignal) {
            const result = await api.POST('/workspaces/{workspace}/source-grants', { params: { path: { workspace } }, body, signal });
            if (!result.data) throw Error('Missing Source authority acknowledgement');
            return result.data;
        },
        async command(workspace: string, body: SourceCommand, signal?: AbortSignal) {
            const result = await api.POST('/workspaces/{workspace}/sources', { params: { path: { workspace } }, body, signal });
            if (!result.data) throw Error('Missing Source command receipt');
            return result.data;
        },
        async recover(workspace: string, obligation: string, signal?: AbortSignal) {
            const result = await api.POST('/workspaces/{workspace}/source-audit/{obligation}/recover', { params: { path: { workspace, obligation } }, signal });
            if (!result.data) throw Error('Missing Source audit acknowledgement');
            return result.data;
        },
        async authorizeService(workspace: string, body: { service: string; purpose: string }, signal?: AbortSignal) {
            const result = await api.POST('/workspaces/{workspace}/services', { params: { path: { workspace } }, body, signal });
            if (!result.data) throw Error('Missing service scope acknowledgement');
            return result.data;
        },
        async revokeService(workspace: string, service: string, signal?: AbortSignal) {
            const result = await api.DELETE('/workspaces/{workspace}/services/{service}', { params: { path: { workspace, service } }, signal });
            if (!result.data) throw Error('Missing service revocation acknowledgement');
            return result.data;
        },
    };
}
