import { request, onClose } from '../spa/transport';
import { retireActivityCommands } from './activityPending';
import type { Surface } from './api';
import type { components } from '../api/generated/application';

export type ActivitySelection = Surface['authority_selection'];
export type PracticeValue = components['schemas']['BusinessPracticeValue'];
export type Practice = components['schemas']['BusinessPractice'];
export type PracticeKind = PracticeValue['kind'];
export type PracticeOperation = Practice['operation'];
export type PracticeGeography = PracticeValue['target_markets'];
export type PracticeArea = PracticeGeography['areas'][number];
export type PracticeList = components['schemas']['BusinessPracticesResult']['data'];
export type PracticeCommand = components['schemas']['BusinessPracticeCommand'];
export type PracticeReceipt = components['schemas']['BusinessPracticeReceipt']['data'];
export type PracticeKnowledgeChoices = components['schemas']['PracticeKnowledgeOptionsResult']['data'];
export type PracticeKnowledgeOption = PracticeKnowledgeChoices['options'][number];
export type PracticeMaterialRef = PracticeKnowledgeOption['material_ref'];
export type PracticeSignal = PracticeKnowledgeOption['practice_basis']['signals'][number];
export type PracticeAssessmentCommand = components['schemas']['PracticeKnowledgeCommand'];
export type PracticeRelation = PracticeAssessmentCommand['relation'];
export type PracticeQuestion = PracticeAssessmentCommand['question'];
export type PracticeAssessment = components['schemas']['PracticeKnowledgeResult']['data'];

export async function readPractices(business: string, access: number, practice?: string): Promise<PracticeList> {
    const query = new URLSearchParams({ access: String(access), ...(practice ? { practice } : {}) });
    return (await request<{ data: PracticeList }>(`/api/businesses/${business}/practices?${query}`)).data;
}
export async function writePractice(business: string, body: PracticeCommand): Promise<PracticeReceipt> {
    return (await request<{ data: PracticeReceipt }>(`/api/businesses/${business}/practices`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    })).data;
}
export async function practiceKnowledge(business: string, access: number, after = 0): Promise<PracticeKnowledgeChoices> {
    return (await request<{ data: PracticeKnowledgeChoices }>(`/api/businesses/${business}/practices/knowledge?${new URLSearchParams({ access: String(access), after: String(after) })}`)).data;
}
export async function assessPractice(business: string, body: PracticeAssessmentCommand): Promise<PracticeAssessment> {
    return (await request<{ data: PracticeAssessment }>(`/api/businesses/${business}/knowledge/customer-discovery/practice`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    })).data;
}

/** UUIDv7 identifies the declaration; server-side version and authority remain decisive. */
export function practiceId(now = Date.now()): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    let timestamp = now;
    for (let index = 5; index >= 0; index--) { bytes[index] = timestamp % 256; timestamp = Math.floor(timestamp / 256); }
    bytes[6] = (bytes[6] & 15) | 0x70;
    bytes[8] = (bytes[8] & 63) | 0x80;
    const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
onClose(retireActivityCommands);
