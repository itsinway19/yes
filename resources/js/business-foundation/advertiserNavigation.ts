// Presentation labels only. This inventory grants no resource or operation authority.
export const advertiserDestinations = [
    { id: 'overview', ar: 'الرئيسية', en: 'Overview' },
    { id: 'market', ar: 'السوق والعملاء', en: 'Market and customers' },
    { id: 'direction', ar: 'العروض والخطة', en: 'Offers and plan' },
    { id: 'ads', ar: 'الإعلانات', en: 'Advertising' },
    { id: 'sales', ar: 'المبيعات والتحويل', en: 'Sales and conversion' },
    { id: 'results', ar: 'النتائج والعائد', en: 'Outcomes and return' },
    { id: 'decisions', ar: 'القرارات والتحسين', en: 'Decisions and improvement' },
] as const;
export const deferredAdvertiserDestinations = advertiserDestinations.filter(item => ['market', 'sales', 'results', 'decisions'].includes(item.id));
export const isAdvertiserWorkspacePath = (path: string) => path === '/business/start' || /^\/(?:businesses|source-rights|data-rights)(?:\/|$)/.test(path) || /^\/workspaces(?:\/[^/]+(?:\/(?:goals(?:\/review)?|business|object-purposes(?:\/[^/]+)?))?)?\/?$/.test(path);
export const advertiserNavigationCurrent = (path: string, destination: string) => path === destination || path.startsWith(destination + '/');
