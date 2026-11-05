import { bootstrap, onClose } from '../spa/transport';
import { selectedAdvertisingPlatform, type AdvertisingPlatform } from './providerDestination';

type BusinessReturn = { business: string; access: string; workspace: string; provider?: AdvertisingPlatform };
const prefix = 'builderad.account-return.v1:';
// Lifecycle is the principal generation. The document lease generation may renew
// across OAuth; a presentation hint never carries a token or grants authority.
const binding = bootstrap.identity.binding;
const storageKey = prefix + JSON.stringify([binding.boundary, binding.principal, binding.lifecycle, binding.context, binding.credential]);
const uuid = (value: unknown): value is string => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
const valid = (value: unknown): value is BusinessReturn => {
    if (!value || typeof value !== 'object') return false;
    const row = value as BusinessReturn;
    return uuid(row.business) && uuid(row.workspace) && typeof row.access === 'string'
        && /^[1-9][0-9]{0,17}$/.test(row.access) && Number.isSafeInteger(Number(row.access))
        && (row.provider === undefined || selectedAdvertisingPlatform(row.provider) !== undefined);
};
let retained: BusinessReturn | undefined;
try {
    for (const key of Array.from({ length: sessionStorage.length }, (_, index) => sessionStorage.key(index))) {
        if (key?.startsWith(prefix) && key !== storageKey) sessionStorage.removeItem(key);
    }
    const saved: unknown = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null');
    if (valid(saved)) retained = saved;
} catch { /* The in-document return still works when storage is unavailable. */ }

export function rememberBusinessReturn(value: BusinessReturn): void {
    if (!valid(value)) return;
    retained = { ...value };
    try { sessionStorage.setItem(storageKey, JSON.stringify(retained)); } catch { /* no authority depends on storage */ }
}
export function businessReturn(workspace: string): BusinessReturn | undefined {
    return retained?.workspace === workspace ? { ...retained } : undefined;
}
export function businessReturnPath(value: BusinessReturn): string {
    // Construct locally; never accept a URL/redirect supplied by storage or query.
    return `/businesses/${encodeURIComponent(value.business)}?access=${encodeURIComponent(value.access)}&section=sources`;
}
onClose(() => { retained = undefined; try { sessionStorage.removeItem(storageKey); } catch {} });
