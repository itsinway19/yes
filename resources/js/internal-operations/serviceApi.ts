import { api } from '../spa/api';
import type { components } from '../api/generated/application';

function required<Value>(value: Value | undefined): Value { if (value === undefined) throw Error('Missing service command acknowledgement'); return value; }
export const createService = async (body: components['schemas']['InternalServiceCreate'], signal?: AbortSignal) => required((await api.POST('/internal/services', { body, signal })).data);
export const rotateService = async (service: string, signal?: AbortSignal) => required((await api.POST('/internal/services/{service}/rotate', { params: { path: { service } }, signal })).data);
export const revokeService = async (service: string, signal?: AbortSignal) => required((await api.POST('/internal/services/{service}/revoke', { params: { path: { service } }, signal })).data);
export const issueServiceGrant = async (body: components['schemas']['InternalServiceGrantCommand'], signal?: AbortSignal) => required((await api.POST('/internal/service-grants', { body, signal })).data);
export const revokeServiceGrant = async (grant: number, signal?: AbortSignal) => required((await api.DELETE('/internal/service-grants/{grant}', { params: { path: { grant } }, signal })).data);
