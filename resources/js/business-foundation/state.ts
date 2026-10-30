import { shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { publish, status, type PresentationAdmission } from '../spa/presentation';
import { request, stamp, owned, onClose } from '../spa/transport';
import type { Catalog, Choices, Surface, BoundaryChoices, BoundarySurface, SourceChoices, SourceSurface } from './api';

type Visibility = { protected: boolean; path: string; context: string | null };
export type Page = { data: Choices | Surface | BoundaryChoices | BoundarySurface | SourceChoices | SourceSurface | { catalog: Catalog }; locale: string; visibility: Visibility };
export const current = shallowRef<Page>();
let serial = 0;
export function clear() { serial++; current.value = undefined; }
export async function load(route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) {
    const captured = stamp(), attempt = ++serial;
    const options = { signal: admission?.signal, headers: { Accept: 'application/json', ...(passive ? { 'X-Builderad-Passive': '1' } : {}) } };
    const value = await request<Page>(route.fullPath, options);
    if (!owned(captured) || attempt !== serial || admission?.signal.aborted) return;
    const witness = await request<{ visibility: Visibility }>(route.fullPath, { signal: admission?.signal, headers: { Accept: 'application/json', 'X-Builderad-Witness': '1' } });
    if (!owned(captured) || attempt !== serial || admission?.signal.aborted) return;
    if (!value.visibility.protected || JSON.stringify(value.visibility) !== JSON.stringify(witness.visibility)) throw Error('Business presentation changed');
    publish(admission, () => { current.value = value; status.value = 'ready'; });
}
onClose(clear);
