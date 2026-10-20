import type { Router } from 'vue-router';
import { documentStatus, internalBoundary } from './transport';
import { conceal, prepare, commitRoute, failed } from './presentation';

/** Browser hints only request a current passive read. They never supply authority. */
export function installBrowserLifecycle(router: Router & { cancelCandidate: () => void }) {
    let restoration: { controller: AbortController; promise: Promise<void> } | undefined;
    let channel: BroadcastChannel | undefined;
    let pendingHint: string | undefined;
    let disposed = false;
    function cancelRestoration() {
        restoration?.controller.abort(); restoration = undefined;
    }
    function hide() {
        conceal(); router.cancelCandidate(); cancelRestoration();
    }
    function resume(): Promise<void> {
        if (disposed || documentStatus.value !== 'active' || document.hidden) return Promise.resolve();
        if (restoration) return restoration.promise;
        conceal(); router.cancelCandidate();
        const controller = new AbortController(), route = router.currentRoute.value;
        const current = { controller, promise: Promise.resolve() };
        restoration = current;
        current.promise = Promise.resolve().then(async () => {
            try {
                const publish = await prepare(route, true, controller.signal);
                if (!controller.signal.aborted && !document.hidden && router.currentRoute.value === route) commitRoute(route, publish);
            } catch { if (!controller.signal.aborted) failed(); }
            finally { if (restoration === current) restoration = undefined; }
        });
        return current.promise;
    }
    function connect() {
        channel?.close();
        if (typeof BroadcastChannel === 'undefined') return;
        channel = new BroadcastChannel('builderad-visibility');
        channel.onmessage = event => {
            if (event.data?.boundary === (internalBoundary ? 'internal' : 'advertiser')) {
                const hint = JSON.stringify([event.data.boundary, event.data.epoch]);
                if (restoration && pendingHint === hint) return;
                pendingHint = hint;
                hide(); void resume();
            }
        };
    }
    const leave = () => { hide(); channel?.close(); };
    const show = (event: PageTransitionEvent) => { if (event.persisted) { connect(); void resume(); } };
    const visibility = () => { if (document.hidden) hide(); else void resume(); };
    const removeNavigationGuard = router.beforeEach(() => { cancelRestoration(); });
    addEventListener('pagehide', leave); addEventListener('pageshow', show);
    document.addEventListener('visibilitychange', visibility); connect();
    return { resume, dispose() {
        disposed = true; hide(); channel?.close(); removeNavigationGuard();
        removeEventListener('pagehide', leave); removeEventListener('pageshow', show);
        document.removeEventListener('visibilitychange', visibility);
    } };
}
