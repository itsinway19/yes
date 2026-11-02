import { api } from '../spa/api';
import { TransportError } from '../spa/transport';
import type { components, operations } from '../api/generated/application';
export type DeclarationValue = components['schemas']['InquiryHistoricalDeclarationValue'];
export type DeclarationWriteValue = components['schemas']['InquiryDeclarationValue'];
export type Eligibility = components['schemas']['InquiryEvidenceEligibility'];
export type EvidenceRead = components['schemas']['InquiryEvidenceRead'];
export type Receipt = components['schemas']['InquiryEvidenceReceipt'];
export type Action = 'business.inquiry_evidence.record' | 'business.inquiry_evidence.correct' | 'business.inquiry_evidence.read';
export const selection = (grant: string): components['schemas']['InquiryAuthoritySelection'] => ({realm_kind:'direct',realm_workspace_public_id:null,requirements:[{requirement_kind:'business',leaf_grant_id:grant}]});
export async function eligibility(business: string, access: string): Promise<Eligibility> {
 const r=await api.GET('/api/businesses/{business}/inquiry-evidence/eligibility',{params:{path:{business},query:{access}}});
 if(!r.data) throw Error('Eligibility unavailable'); return r.data.data;
}
export async function read(business: string, access: string, cursor?: string): Promise<EvidenceRead> {
 const r=await api.GET('/api/businesses/{business}/inquiry-evidence',{params:{path:{business},query:{access,...(cursor?{cursor}:{})}}});
 if(!r.data) throw Error('Readback unavailable'); return r.data.data;
}
export async function history(business: string, declaration: string, access: string, after=0): Promise<EvidenceRead> {
 const r=await api.GET('/api/businesses/{business}/inquiry-evidence/{declaration}/history',{params:{path:{business,declaration},query:{access,after}}});
 if(!r.data) throw Error('History unavailable'); return r.data.data;
}
export type Pending =
 | {kind:'enroll';body:operations['enrollInquiryEvidence']['requestBody']['content']['application/json']}
 | {kind:'record';body:operations['recordInquiryEvidence']['requestBody']['content']['application/json']}
 | {kind:'revise';declaration:string;body:operations['reviseInquiryEvidence']['requestBody']['content']['application/json']}
 | {kind:'revoke';body:operations['revokeInquiryEvidenceRight']['requestBody']['content']['application/json']};
export class RejectedCommand extends Error {constructor(public kind:'invalid'|'stale'|'denied'){super(kind);}}
function reject(response:Response,error:unknown):never {
 if(response.status===422||response.status===413)throw new RejectedCommand('invalid');
 if(response.status===403)throw new RejectedCommand('denied');
 if(response.status===409&&(error as {error?:string})?.error==='inquiry_revision_rejected')throw new RejectedCommand('stale');
 throw Error('Command outcome unknown');
}
export function confirmedRejection(error:unknown):'invalid'|'stale'|'denied'|undefined {
 if(error instanceof RejectedCommand)return error.kind;
 if(error instanceof TransportError && [422,413,403].includes(error.status))return error.status===403?'denied':'invalid';
 if(error instanceof TransportError && error.status===409 && (error.body as {error?:string}).error==='inquiry_revision_rejected')return 'stale';
 return undefined;
}
export async function send(business:string,pending:Pending) {
 if(pending.kind==='enroll') {const r=await api.POST('/api/businesses/{business}/inquiry-evidence/enrollment',{params:{path:{business}},body:pending.body});if(!r.data)reject(r.response,r.error);return r.data.data;}
 if(pending.kind==='record') {const r=await api.POST('/api/businesses/{business}/inquiry-evidence',{params:{path:{business}},body:pending.body});if(!r.data)reject(r.response,r.error);return r.data.data;}
 if(pending.kind==='revise') {const r=await api.POST('/api/businesses/{business}/inquiry-evidence/{declaration}/revisions',{params:{path:{business,declaration:pending.declaration}},body:pending.body});if(!r.data)reject(r.response,r.error);return r.data.data;}
 const r=await api.POST('/api/businesses/{business}/inquiry-evidence/rights',{params:{path:{business}},body:pending.body});if(!r.data)reject(r.response,r.error);return r.data.data;
}
