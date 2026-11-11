import { scope } from '../spa/transport';
import { practiceId, type PracticeCommand, type PracticeValue } from './activity';
import type { CreateCommand, DraftResult, FactCommand, Selection } from './api';
import { emptyWizardAnswers, registrationBusinessKey, wizardChannels, type WizardAnswers, type WizardCatalog } from './businessWizard';

type PracticeEntry = Pick<PracticeCommand, 'command_id' | 'practice_id'> & { value: PracticeValue };
type Markets = Extract<FactCommand, { kind: 'markets' }>;
type Preferences = Extract<FactCommand, { kind: 'preferences' }>;
type SetupPlan = {
    create: CreateCommand;
    markets?: Pick<Markets, 'command_id' | 'codes'>;
    preferences?: Pick<Preferences, 'command_id' | 'value'>;
    practices: PracticeEntry[];
};
export type SetupDraft = {
    answers: WizardAnswers;
    step: number;
    language: 'ar' | 'en';
    plan?: SetupPlan;
    created?: boolean;
    uncertain?: string[];
};

export const setupKey = (attempt?: string | null) => attempt
    ? registrationBusinessKey(attempt) : `builderad:business-setup:1:${scope}`;
export function readSetup(key: string): SetupDraft | undefined {
    try {
        const raw = sessionStorage.getItem(key);
        if (!raw) return;
        const { answers, step, language, plan, created, uncertain } = JSON.parse(raw) as SetupDraft;
        if (!answers || typeof answers.name !== 'string' || !Array.isArray(answers.practices) || !Array.isArray(answers.codes)
            || !Array.isArray(answers.channels) || !Number.isInteger(step) || !['ar', 'en'].includes(language)) return;
        return { answers, step, language, plan, created: created === true, uncertain: Array.isArray(uncertain) ? uncertain : [] };
    } catch { return; }
}
export function writeSetup(key: string, draft: SetupDraft) { sessionStorage.setItem(key, JSON.stringify(draft)); }
export function clearSetup(key: string) { sessionStorage.removeItem(key); }
export const emptySetup = (language: 'ar' | 'en'): SetupDraft => ({ answers: emptyWizardAnswers(), step: 0, language });

/** The same attempt produces the same command identities even in a cloned tab. */
async function attemptId(attempt: string, purpose: string, practice = false) {
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`builderad:business-setup:1:${attempt}:${purpose}`)));
    if (practice) {
        const timestamp = attempt.replaceAll('-', '').slice(0, 12);
        for (let index = 0; index < 6; index++) digest[index] = Number.parseInt(timestamp.slice(index * 2, index * 2 + 2), 16);
    }
    digest[6] = (digest[6] & 15) | (practice ? 0x70 : 0x40);
    digest[8] = (digest[8] & 63) | 0x80;
    const hex = Array.from(digest.slice(0, 16), byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export async function prepareSetup(draft: SetupDraft, catalog: WizardCatalog, attempt?: string | null): Promise<SetupPlan> {
    const { answers, language } = draft;
    const create = { command_id: attempt || crypto.randomUUID(), name: answers.name.trim(), business_type_id: answers.type };
    const command = (purpose: string) => attempt ? attemptId(attempt, purpose) : crypto.randomUUID();
    const sector = catalog.business_types.find(row => row.id === answers.type);
    const channels = wizardChannels.filter(row => answers.channels.includes(row[0])).map(row => row[language === 'ar' ? 1 : 2]);
    const practices = await Promise.all(answers.practices.map(async ({ kind, name }): Promise<PracticeEntry> => ({
        command_id: await command(`declare-practice:${kind}`),
        practice_id: attempt ? await attemptId(attempt, `practice:${kind}`, true) : practiceId(),
        value: {
            kind, name: name.trim(), description: '', offering: null, commercial_model: null,
            sectors: sector ? [sector[language === 'ar' ? 'name_ar' : 'name_en']] : [], specializations: [], channels,
            target_markets: { status: 'unspecified', areas: [] },
            fulfillment: { status: 'unspecified', areas: [] },
        },
    })));

    return {
        create, practices,
        ...(answers.codes.length ? { markets: { command_id: await command('markets'), codes: [...answers.codes] } } : {}),
        ...(answers.goal ? { preferences: { command_id: await command('preferences'), value: { goals: [answers.goal], priority: answers.goal, results: {} } } } : {}),
    };
}

const selection = (receipt: DraftResult, action: keyof DraftResult['grant_refs']): Selection => ({
    realm_kind: 'direct', realm_workspace_public_id: null,
    requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(receipt.grant_refs[action]) }],
});

export function setupDeclarations(plan: SetupPlan, receipt: DraftResult) {
    const markets: Markets | undefined = plan.markets && {
        ...plan.markets, kind: 'markets', expected_context: receipt.versions.context,
        authority_selection: selection(receipt, 'business.markets.declare'),
    };
    const preferences: Preferences | undefined = plan.preferences && {
        ...plan.preferences, kind: 'preferences', expected_preference: receipt.versions.preferences,
        authority_selection: selection(receipt, 'business.preferences.manage'),
    };
    const practices: PracticeCommand[] = plan.practices.map(practice => ({
        ...practice, operation: 'create', expected_revision: 0,
        authority_selection: selection(receipt, 'business.context.manage'),
        read_selection: selection(receipt, 'business.context.read'),
    }));
    return { markets, preferences, practices };
}
