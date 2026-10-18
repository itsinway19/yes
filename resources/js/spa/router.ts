import { createRouter, createWebHistory, isNavigationFailure, NavigationFailureType, type RouteRecordRaw, type RouteLocationNormalized, type RouterHistory } from 'vue-router';

type Presentation = {
    suspend: () => void;
    load: (route: RouteLocationNormalized, passive: boolean) => unknown;
    unavailable: () => void;
};

export type ApplicationPresentation = {
    load: (route: RouteLocationNormalized, passive: boolean) => unknown;
    clear: () => void;
};

declare module 'vue-router' {
    interface RouteMeta {
        presentation?: ApplicationPresentation;
    }
}

export function createApplicationRouter(routes: RouteRecordRaw[], presentation: Presentation,
    history: RouterHistory = createWebHistory()) {
    let traversal = false;
    let candidate: RouteLocationNormalized | undefined;
    history.listen((_to, _from, info) => { traversal = info.type === 'pop'; });
    const router = createRouter({ history, routes });
    router.beforeEach(to => { candidate = to; presentation.suspend(); });
    router.afterEach((to, from, failure) => {
        const passive = traversal;
        traversal = false;
        if (!failure) void presentation.load(to, passive);
        else if (isNavigationFailure(failure, NavigationFailureType.aborted) && from.matched.length) {
            void presentation.load(from, true);
        } else if (isNavigationFailure(failure, NavigationFailureType.duplicated)) {
            const suspended = candidate && candidate.fullPath !== to.fullPath;
            candidate = to;
            if (suspended) void presentation.load(to, true);
        }
    });
    router.onError((_error, to) => {
        if (to !== candidate) return;
        presentation.suspend();
        presentation.unavailable();
    });
    return router;
}
