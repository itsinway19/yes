import { api } from '../spa/api';
import type { components } from '../api/generated/application';

const required = <Value>(value: Value | undefined): Value => { if (value === undefined) throw Error('Missing command acknowledgement'); return value; };
export const inviteEmployee = async (body: components['schemas']['InternalStaffInvitation'], signal?: AbortSignal) => required((await api.POST('/internal/staff', { body, signal })).data);
const actions = { attest: '/internal/staff/{staff}/attest', activate: '/internal/staff/{staff}/activate', revoke: '/internal/staff/{staff}/revoke', 'resend-invitation': '/internal/staff/{staff}/resend-invitation', 'attest-factors': '/internal/staff/{staff}/attest-factors' } as const;
export const staffAction = async (staff: string, action: keyof typeof actions, signal?: AbortSignal) => required((await api.POST(actions[action], { params: { path: { staff } }, signal })).data);
export const issueGrant = async (body: components['schemas']['InternalStaffGrantCommand'], signal?: AbortSignal) => required((await api.POST('/internal/grants', { body, signal })).data);
export const revokeGrant = async (grant: number, signal?: AbortSignal) => required((await api.DELETE('/internal/grants/{grant}', { params: { path: { grant } }, signal })).data);
