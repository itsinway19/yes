import { createRouter, createWebHistory, isNavigationFailure, NavigationFailureType, type RouteRecordRaw, type RouteLocationNormalized, type RouterHistory } from 'vue-router';
import type { PresentationAdmission } from './presentation';

export type ApplicationPresentation = {
    load: (route: RouteLocationNormalized, passive: boolean, admission?: PresentationAdmission) => unknown;
    clear: () => void;
};
declare module 'vue-router' { interface RouteMeta { presentation?: ApplicationPresentation } }
type Presentation = {
    pending: (active: boolean, passive?: boolean) => void;
    prepare: (route: RouteLocationNormalized, passive: boolean, signal: AbortSignal) => Promise<() => void>;
    commit: (route: RouteLocationNormalized, publish: () => void) => void;
    failed: () => void;
};
/** Vue Router owns one candidate. Its source route stays current until admission. */
export function createApplicationRouter(routes: RouteRecordRaw[], presentation: Presentation,
    history: RouterHistory = createWebHistory()) {
    let traversal = false;
    let candidate: { route: RouteLocationNormalized; controller: AbortController; passive: boolean; timer: ReturnType<typeof setTimeout>; publish?: () => void } | undefined;
    history.listen((_to, _from, info) => {
        traversal = info.type === 'pop';
        if (traversal) presentation.pending(true, true);
    });
    const router = createRouter({ history, routes });
    function cancel() {
        if (candidate) { clearTimeout(candidate.timer); candidate.controller.abort(); candidate = undefined; presentation.pending(false); }
    }
    router.beforeEach(to => {
        cancel();
        const controller = new AbortController();
        const current = { route: to, controller, passive: traversal, timer: setTimeout(() => {
            if (candidate !== current) return;
            cancel(); presentation.failed();
        }, 30000) };
        candidate = current; traversal = false; presentation.pending(true, current.passive);
    });
    router.beforeResolve(async to => {
        const current = candidate;
        if (!current || current.route !== to) return false;
        const passive = current.passive;
        try {
            current.publish = await Promise.race([
                presentation.prepare(to, passive, current.controller.signal),
                new Promise<never>((_resolve, reject) => current.controller.signal.addEventListener('abort', () => reject(new DOMException('Superseded candidate', 'AbortError')), { once: true })),
            ]);
            return candidate === current && !current.controller.signal.aborted;
        } catch {
            if (candidate === current) { cancel(); presentation.failed(); }
            return false;
        }
    });
    router.afterEach((to, _from, failure) => {
        if (failure && traversal) { traversal = false; presentation.failed(); }
        if (failure && isNavigationFailure(failure, NavigationFailureType.duplicated)) { cancel(); return; }
        if (candidate?.route !== to) return;
        const current = candidate;
        if (!failure && current.publish) {
            clearTimeout(current.timer); candidate = undefined;
            presentation.commit(to, current.publish);
        } else { cancel(); presentation.failed(); }
    });
    router.onError((_error, to) => { if (candidate?.route === to) { cancel(); presentation.failed(); } });
    return Object.assign(router, { cancelCandidate: cancel });
}
