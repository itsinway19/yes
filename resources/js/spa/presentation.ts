import { computed, ref, shallowRef } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import { documentStatus, onClose } from './transport';

export const confirmDeparture = shallowRef<() => boolean>(() => true);
const readiness = ref<'checking' | 'ready' | 'unavailable' | 'closed'>('checking');
export const concealed = ref(true);
export const admittedRoute = shallowRef<RouteLocationNormalized>();
export const navigating = ref(false);
export const navigationError = ref(false);
export const status = computed({
    get: () => documentStatus.value === 'closed' ? 'closed'
        : documentStatus.value === 'unavailable' ? 'unavailable'
        : documentStatus.value === 'retiring' ? 'checking' : concealed.value ? (readiness.value === 'unavailable' ? 'unavailable' : 'checking') : readiness.value,
    set: value => { readiness.value = value; },
});
/** A candidate publication capability is local to that candidate, never authority. */
export type PresentationAdmission = { signal: AbortSignal; publish: (commit: () => void) => void };
export function publish(admission: PresentationAdmission | undefined, commit: () => void) {
    if (documentStatus.value !== 'active') return;
    if (admission) { admission.signal.throwIfAborted(); admission.publish(commit); }
    else commit();
}
export function conceal() { concealed.value = true; }
export async function prepare(route: RouteLocationNormalized, passive: boolean, signal: AbortSignal) {
    if (documentStatus.value !== 'active' || !route.meta.presentation) throw Error('Destination unavailable');
    let commit: (() => void) | undefined;
    await route.meta.presentation.load(route, passive, { signal, publish: value => { signal.throwIfAborted(); commit = value; } });
    signal.throwIfAborted();
    if (!commit || documentStatus.value !== 'active') throw Error('Destination was not admitted');
    return commit;
}
export function commitRoute(route: RouteLocationNormalized, commit: () => void) {
    if (documentStatus.value !== 'active') return;
    const previous = admittedRoute.value?.meta.presentation;
    if (previous && previous !== route.meta.presentation) previous.clear();
    commit(); admittedRoute.value = route; readiness.value = 'ready'; concealed.value = false;
    navigating.value = false; navigationError.value = false;
}
export function failed() {
    navigating.value = false; navigationError.value = true;
    if (!admittedRoute.value || concealed.value) readiness.value = 'unavailable';
}
onClose(() => { conceal(); navigating.value = false; admittedRoute.value = undefined; });
