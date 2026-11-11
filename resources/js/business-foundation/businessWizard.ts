import type { Catalog } from './api';
import type { PracticeKind } from './activity';

export type WizardCatalog = {
    business_types: Pick<Catalog['business_types'][number], 'id' | 'key' | 'name_ar' | 'name_en' | 'active'>[];
    countries: Catalog['countries'];
    goals: Catalog['goals'];
};
export type WizardPractice = { kind: PracticeKind; name: string };
export type WizardAnswers = {
    type: number | null;
    practices: WizardPractice[];
    codes: string[];
    channels: string[];
    goal: string | null;
    name: string;
};
export const emptyWizardAnswers = (): WizardAnswers => ({ type: null, practices: [], codes: [], channels: [], goal: null, name: '' });
export const wizardSteps = ['sector', 'practices', 'market', 'channels', 'goal', 'review'] as const;

const practiceOptions = {
    sale: ['بيع المنتجات', 'Sell products'],
    service: ['تقديم الخدمات', 'Provide services'],
    rental: ['التأجير', 'Rentals'],
    brokerage: ['الوساطة', 'Brokerage'],
    production: ['الإنتاج أو التحضير', 'Production or preparation'],
    installation: ['التركيب', 'Installation'],
    maintenance: ['الصيانة', 'Maintenance'],
    other: ['عمل آخر', 'Other work'],
} satisfies Record<PracticeKind, readonly [string, string]>;
const sectorPractices: Record<string, readonly PracticeKind[]> = {
    real_estate: ['sale', 'rental', 'brokerage', 'service'],
    ecommerce: ['sale', 'production', 'service'],
    healthcare: ['service', 'sale'],
    hospitality: ['production', 'service', 'sale'],
    professional_services: ['service', 'installation', 'maintenance'],
};
const sectorPracticeLabels: Record<string, Partial<Record<PracticeKind, readonly [string, string]>>> = {
    real_estate: {
        sale: ['بيع العقارات', 'Property sales'],
        rental: ['تأجير العقارات', 'Property rentals'],
        brokerage: ['الوساطة العقارية', 'Real estate brokerage'],
        service: ['إدارة الأملاك', 'Property management'],
    },
    hospitality: {
        production: ['إعداد الطعام والمشروبات', 'Prepare food and drinks'],
        service: ['خدمة الضيوف والطلبات', 'Guest and order service'],
        sale: ['بيع الوجبات والمنتجات', 'Sell meals and products'],
    },
    ecommerce: {
        sale: ['بيع المنتجات عبر الإنترنت', 'Sell products online'],
        production: ['إنتاج المنتجات', 'Make products'],
        service: ['خدمات مرتبطة بالمنتجات', 'Product services'],
    },
    healthcare: {
        service: ['تقديم الرعاية والاستشارات', 'Care and consultations'],
        sale: ['بيع المنتجات الصحية', 'Sell healthcare products'],
    },
    professional_services: {
        service: ['الخدمات والاستشارات المتخصصة', 'Specialist services and consulting'],
        installation: ['خدمات التركيب', 'Installation services'],
        maintenance: ['خدمات الصيانة', 'Maintenance services'],
    },
};
const sectorGoals: Record<string, readonly string[]> = {
    real_estate: ['customers', 'sales'],
    ecommerce: ['sales', 'customers'],
    healthcare: ['customers', 'growth'],
    hospitality: ['sales', 'growth'],
    professional_services: ['customers', 'sales'],
};
export const wizardChannels = [
    ['whatsapp', 'واتساب', 'WhatsApp'],
    ['phone', 'الهاتف', 'Phone'],
    ['social', 'وسائل التواصل الاجتماعي', 'Social media'],
    ['website', 'موقع أو متجر إلكتروني', 'Website or online store'],
    ['in_person', 'الزيارات المباشرة', 'In-person visits'],
] as const;

export function suggestedPractices(sector: string | undefined) {
    const recommended = (sector && sectorPractices[sector]) || ['sale', 'service', 'production'];
    return [...recommended, ...Object.keys(practiceOptions).filter(kind => !recommended.includes(kind as PracticeKind))]
        .map(kind => ({ kind: kind as PracticeKind,
            label: sectorPracticeLabels[sector ?? '']?.[kind as PracticeKind] ?? practiceOptions[kind as PracticeKind] }));
}

export function suggestedGoals(catalog: WizardCatalog, sector: string | undefined) {
    const preferred = (sector && sectorGoals[sector]) || [];
    return [...catalog.goals].sort((a, b) => {
        const rank = (id: string) => preferred.includes(id) ? preferred.indexOf(id) : preferred.length;
        return rank(a.id) - rank(b.id);
    });
}

export const registrationBusinessKey = (attempt: string) => `builderad:registration-business:1:${attempt}`;
export function storeRegistrationBusiness(attempt: string, answers: WizardAnswers, language: 'ar' | 'en' = 'ar') {
    const key = registrationBusinessKey(attempt);
    const current = sessionStorage.getItem(key);
    if (current && JSON.parse(current).plan) throw Error('A business creation command already exists for this attempt');
    sessionStorage.setItem(key, JSON.stringify({ answers, step: wizardSteps.length - 1, language }));
}

export function hasRegistrationBusiness(attempt?: string | null) {
    try { return !!attempt && !!sessionStorage.getItem(registrationBusinessKey(attempt)); }
    catch { return false; }
}

export function wizardAnswersValid(answers: WizardAnswers, catalog: WizardCatalog) {
    return answers.name.trim().length >= 2 && answers.name.length <= 120
        && (answers.type === null || catalog.business_types.some(row => row.active && row.id === answers.type))
        && answers.practices.length <= 8 && answers.practices.every(item => item.kind in practiceOptions && item.name.trim().length >= 2 && item.name.length <= 120)
        && answers.codes.length <= 50 && answers.codes.every(code => catalog.countries.some(row => row.code === code))
        && answers.channels.every(id => wizardChannels.some(row => row[0] === id))
        && (!answers.channels.length || !!answers.practices.length)
        && (answers.goal === null || catalog.goals.some(row => row.id === answers.goal));
}
