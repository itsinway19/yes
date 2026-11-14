import type { createApplicationClient } from '../api/application';
import type { components } from '../api/generated/application';

export type SourceCommand = components['schemas']['SourceCommand'];
export type SourceAuthorityCommand = components['schemas']['SourceAuthorityCommand'];
export type ServiceApprovalEnrollmentCommand = components['schemas']['BusinessServiceApprovalEnrollmentCommand'];
export type ServiceApprovalCommand = components['schemas']['BusinessServiceApprovalCommand'];
export type SourcesData = components['schemas']['BusinessSourceRegistryData'];

export function sourceOperations(api: ReturnType<typeof createApplicationClient>) {
    return {
        async enrollServiceApproval(business: string, body: ServiceApprovalEnrollmentCommand, signal?: AbortSignal) {
            const result = await api.POST('/api/businesses/{business}/service-approval/enrollment', { params: { path: { business } }, body, signal });
            if (!result.data) throw Error('Service approval qualification acknowledgement unavailable');
            return result.data;
        },
        async read(business: string, headers: Record<string, string> = {}, signal?: AbortSignal) {
            const result = await api.GET('/businesses/{business}/source-registry', { params: { path: { business } }, headers, signal });
            if (!result.data) throw Error('Missing Source resource');
            return result.data;
        },
        async grant(business: string, body: SourceAuthorityCommand, signal?: AbortSignal) {
            const result = await api.POST('/businesses/{business}/source-grants', { params: { path: { business } }, body, signal });
            if (!result.data) throw Error('Missing Source authority acknowledgement');
            return result.data;
        },
        async command(business: string, body: SourceCommand, signal?: AbortSignal) {
            const result = await api.POST('/businesses/{business}/sources', { params: { path: { business } }, body, signal });
            if (!result.data) throw Error('Missing Source command receipt');
            return result.data;
        },
        async recover(business: string, obligation: string, signal?: AbortSignal) {
            const result = await api.POST('/businesses/{business}/source-audit/{obligation}/recover', { params: { path: { business, obligation } }, signal });
            if (!result.data) throw Error('Missing Source audit acknowledgement');
            return result.data;
        },
        async authorizeService(business: string, body: ServiceApprovalCommand, signal?: AbortSignal) {
            const result = await api.POST('/businesses/{business}/services', { params: { path: { business } }, body, signal });
            if (!result.data) throw Error('Missing service scope acknowledgement');
            return result.data;
        },
        async revokeService(business: string, service: string, body: ServiceApprovalCommand, signal?: AbortSignal) {
            const result = await api.DELETE('/businesses/{business}/services/{service}', { params: { path: { business, service } }, body, signal });
            if (!result.data) throw Error('Missing service revocation acknowledgement');
            return result.data;
        },
    };
}
