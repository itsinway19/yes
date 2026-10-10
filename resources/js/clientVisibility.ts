import { router } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';

type Evidence = { protected: boolean; path: string; context: string | null };

// Install before Inertia's restoration listeners. resolve() waits for authority
// before mounting; the initial HTML and BFCache snapshot are already masked.
export function installClientVisibility() {
    const root = document.documentElement;
    let epoch = 0;
    let request: AbortController | undefined;
    let live: Page | undefined;
    let approved: Page | undefined;
    let checkingPage: Page | undefined;
    let retry: (() => void) | undefined;
    let leaving = false;
    const channel = typeof BroadcastChannel === 'undefined' ? undefined : new BroadcastChannel('builderad-visibility');
    const announced = new WeakSet<Page>();
    const evidence = (page: Page) => page.props.clientVisibility as Evidence | undefined;
    const path = () => window.location.pathname + window.location.search;
    const suspended = () => new Promise<never>(() => {});

    function mask() {
        epoch++;
        request?.abort();
        const wake = retry;
        retry = undefined;
        wake?.();
        approved = undefined;
        root.dataset.visibility = 'checking';
        document.getElementById('app')?.setAttribute('inert', '');
        document.title = 'Builderad';
    }
    function reveal(page: Page) {
        if (approved !== page || document.visibilityState === 'hidden' || evidence(page)?.path !== path()) return;
        root.dataset.visibility = 'ready';
        document.getElementById('app')?.removeAttribute('inert');
    }
    function invalidate() {
        if (leaving) return;
        leaving = true;
        mask();
        router.cancelAll(); router.flushAll(); router.clearHistory();
        channel?.postMessage('invalidate');
        window.location.replace(window.location.href);
    }
    async function authorize(page: Page): Promise<void> {
        checkingPage = page;
        mask();
        if (page.clearHistory && !announced.has(page)) {
            announced.add(page);
            channel?.postMessage('invalidate');
        }
        const receipt = evidence(page);
        if (!receipt || typeof receipt.protected !== 'boolean' || typeof receipt.path !== 'string') {
            invalidate(); return suspended(); // Reject legacy entries without this boundary.
        }
        if (!receipt.protected) { approved = page; checkingPage = undefined; return; }
        if (!receipt.context || !page.encryptHistory || !window.crypto?.subtle) {
            root.dataset.visibility = 'unavailable'; return suspended();
        }
        const target = new URL(page.url, window.location.origin);
        if (target.origin !== window.location.origin || target.pathname + target.search !== receipt.path) {
            invalidate(); return suspended();
        }
        while (checkingPage === page && !leaving) {
            const ticket = epoch;
            request = new AbortController();
            try {
                const response = await fetch(target.href, { credentials: 'same-origin', cache: 'no-store',
                    redirect: 'manual', signal: request.signal,
                    headers: { 'X-Inertia': 'true', Accept: 'text/html', 'X-Builderad-Visibility': '1',
                        'X-Builderad-Visibility-Context': receipt.context } });
                if (ticket !== epoch) continue;
                if (response.type === 'opaqueredirect' || [401, 403, 404, 409, 410].includes(response.status)) {
                    invalidate(); return suspended();
                }
                if (!response.ok) throw new Error('Visibility check unavailable');
                const fresh: Evidence = await response.json();
                if (ticket !== epoch) continue;
                if (fresh.protected !== true || fresh.context !== receipt.context || fresh.path !== receipt.path) {
                    invalidate(); return suspended();
                }
                approved = page;
                checkingPage = undefined;
                return;
            } catch {
                if (ticket !== epoch) continue;
                root.dataset.visibility = 'unavailable';
                // Network failure does not prove revocation. Retain the draft hidden.
                await new Promise<void>(resolve => { retry = resolve; });
                retry = undefined;
            }
        }
        return suspended();
    }
    async function checkLive() {
        if (checkingPage) { mask(); return; }
        if (!live || leaving) return;
        const candidate = live;
        await authorize(candidate);
        if (live === candidate) reveal(candidate);
    }
    function retryCheck() { if (retry) retry(); else void checkLive(); }
    window.addEventListener('popstate', mask, true);
    window.addEventListener('pagehide', mask, true);
    window.addEventListener('pageshow', event => { if (event.persisted) void checkLive(); }, true);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') mask(); else retryCheck();
    });
    window.addEventListener('focus', retryCheck);
    window.addEventListener('online', retryCheck);
    channel?.addEventListener('message', () => { mask(); retryCheck(); });
    document.getElementById('visibility-retry')?.addEventListener('click', retryCheck);
    router.on('beforeUpdate', mask);
    router.on('navigate', event => { live = event.detail.page; reveal(live); });
    router.on('success', event => { live = event.detail.page; reveal(live); });
    router.on('httpException', event => {
        if (root.dataset.visibility !== 'ready' && [401, 403, 404, 410].includes(event.detail.response.status)) {
            event.preventDefault(); invalidate();
        }
    });
    return { authorize, mounted(page: Page) { live = page; reveal(page); } };
}
