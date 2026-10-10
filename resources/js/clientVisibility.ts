import { router } from '@inertiajs/vue3';
import { HttpCancelledError, HttpNetworkError, type ActiveVisit, type Page, type RouterInitParams } from '@inertiajs/core';
import { nextTick } from 'vue';

type Evidence = { protected: boolean; path: string; context: string | null };
type Visit = Pick<ActiveVisit, 'replace' | 'preserveState' | 'preserveScroll'> &
    { id: string; generation: number; sequence: number; cancelled: boolean };
type Approval = { page: Page; generation: number; sequence: number; visit?: Visit; controller: AbortController };
export class VisibilityUnavailable extends Error {}
class VisibilityCancelled extends HttpCancelledError {}

// Inertia owns visits/history. Candidates are checked before resolution AND swap.
export function installClientVisibility() {
    const root = document.documentElement;
    document.getElementById('app')?.setAttribute('inert', '');
    let generation = 0, sequence = 0;
    let pending: Approval | undefined;
    let live: Page | undefined;
    let displayed: Approval | undefined;
    let leaving = false;
    let retry: (() => void) | undefined;
    let retryUrl: string | undefined;
    let retryOptions: Pick<ActiveVisit, 'replace' | 'preserveState' | 'preserveScroll'> | undefined;
    let restoration: { state: unknown; path: string } | undefined;
    const approvals = new WeakMap<Page, Approval>();
    const visits = new WeakMap<Page, Visit>();
    const announced = new WeakSet<Page>();
    const channel = typeof BroadcastChannel === 'undefined' ? undefined : new BroadcastChannel('builderad-visibility');
    const evidence = (page: Page) => page.props.clientVisibility as Evidence | undefined;
    const path = () => window.location.pathname + window.location.search;
    const cancelled = () => new VisibilityCancelled('Page visibility operation superseded');

    function valid(ticket: Approval) {
        return !leaving && ticket.generation === generation && ticket.sequence === sequence &&
            !ticket.controller.signal.aborted && !ticket.visit?.cancelled;
    }
    function assert(ticket: Approval) { if (!valid(ticket)) throw cancelled(); }
    function stopPending() {
        pending?.controller.abort();
        pending = undefined;
        retry = undefined;
    }
    function hide() {
        root.dataset.visibility = 'checking';
        document.getElementById('app')?.setAttribute('inert', '');
        document.title = 'Builderad';
    }
    function breakTrust() {
        generation++; sequence++;
        displayed = undefined;
        stopPending();
        notice(false);
        hide();
        router.cancelAll();
    }
    function trustedDisplay() {
        // Superseding a candidate does not revoke the already displayed page.
        return displayed && displayed.generation === generation && !leaving &&
            document.visibilityState !== 'hidden' && evidence(displayed.page)?.path === path();
    }
    function reveal(page: Page) {
        const approval = approvals.get(page);
        if (displayed !== approval || !trustedDisplay()) return;
        root.dataset.visibility = 'ready';
        document.getElementById('app')?.removeAttribute('inert');
    }
    function notice(show: boolean) {
        const element = document.getElementById('visibility-navigation-error');
        if (element) element.hidden = !show;
    }
    function invalidate() {
        if (leaving) return;
        breakTrust(); leaving = true;
        router.flushAll(); router.clearHistory();
        channel?.postMessage('invalidate');
        window.location.replace(window.location.href);
    }
    async function abortable<T>(promise: Promise<T>, ticket: Approval): Promise<T> {
        assert(ticket);
        let abort: () => void = () => {};
        const interrupted = new Promise<never>((_, reject) => {
            abort = () => reject(cancelled());
            ticket.controller.signal.addEventListener('abort', abort, { once: true });
        });
        try { return await Promise.race([promise, interrupted]); }
        finally { ticket.controller.signal.removeEventListener('abort', abort); }
    }
    async function authorize(ticket: Approval) {
        assert(ticket);
        const page = ticket.page, receipt = evidence(page);
        const target = new URL(page.url, window.location.origin);
        if (!receipt || typeof receipt.protected !== 'boolean' || typeof receipt.path !== 'string' ||
            target.origin !== window.location.origin || target.pathname + target.search !== receipt.path) {
            invalidate(); throw cancelled();
        }
        if (page.clearHistory && !announced.has(page)) {
            announced.add(page);
            displayed = undefined; hide();
            channel?.postMessage('invalidate');
        }
        const identity = (value: Page) => {
            const auth = value.props.auth as { user?: { id: unknown }; sessionContextId?: unknown } | undefined;
            return [auth?.user?.id, auth?.sessionContextId];
        };
        if (live && identity(live).some((value, index) => value !== identity(page)[index])) {
            displayed = undefined; hide();
        }
        if (!receipt.protected) return;
        if (!receipt.context || !page.encryptHistory || !window.crypto?.subtle) throw new VisibilityUnavailable();
        const response = await abortable(fetch(target.href, {
            credentials: 'same-origin', cache: 'no-store', redirect: 'manual', signal: ticket.controller.signal,
            headers: { 'X-Inertia': 'true', Accept: 'text/html', 'X-Builderad-Visibility': '1',
                'X-Builderad-Visibility-Context': receipt.context },
        }), ticket);
        assert(ticket);
        if (response.type === 'opaqueredirect' || [401, 403, 404, 409, 410].includes(response.status)) {
            invalidate(); throw cancelled();
        }
        if (!response.ok) throw new VisibilityUnavailable();
        const fresh: Evidence = await abortable(response.json(), ticket);
        assert(ticket);
        if (fresh.protected !== true || fresh.context !== receipt.context || fresh.path !== receipt.path) {
            invalidate(); throw cancelled();
        }
    }
    function failed(ticket: Approval) {
        assert(ticket);
        retry = () => {
            if (!ticket.visit && restoration?.path === evidence(ticket.page)?.path) restoreHistory();
            else if (live === ticket.page) void checkLive();
            else if (!live) return; // Bootstrap installs its probe-only retry below.
            else router.visit(ticket.page.url, { preserveState: ticket.visit?.preserveState ?? true,
                preserveScroll: ticket.visit?.preserveScroll ?? true, replace: ticket.visit?.replace ?? true });
        };
        if (trustedDisplay()) notice(true);
        else { hide(); root.dataset.visibility = 'unavailable'; }
    }
    function restoreHistory() {
        if (!restoration || restoration.path !== path() || leaving) return;
        // Retry Inertia's own restoration of the retained encrypted entry. A GET
        // here would touch session activity and could replace remembered drafts.
        window.dispatchEvent(new PopStateEvent('popstate', { state: restoration.state }));
    }
    async function resolve<T>(page: Page, load: () => Promise<T>): Promise<T> {
        stopPending();
        const visit = visits.get(page);
        const ticket: Approval = { page, generation: visit?.generation ?? generation,
            sequence: visit?.sequence ?? sequence, visit, controller: new AbortController() };
        pending = ticket;
        try {
            await authorize(ticket);
            const component = await abortable(load(), ticket);
            assert(ticket);
            approvals.set(page, ticket);
            return component;
        } catch (error) {
            if (error instanceof HttpCancelledError || !valid(ticket)) throw cancelled();
            failed(ticket);
            throw new VisibilityUnavailable('Page verification unavailable');
        } finally { if (pending === ticket) pending = undefined; }
    }
    async function checkLive() {
        if (!live || leaving) return;
        breakTrust();
        const page = live;
        const ticket: Approval = { page, generation, sequence, controller: new AbortController() };
        pending = ticket;
        try {
            await authorize(ticket); assert(ticket);
            if (live !== page) throw cancelled();
            approvals.set(page, ticket); displayed = ticket;
            notice(false); reveal(page);
        } catch (error) {
            if (!(error instanceof HttpCancelledError) && valid(ticket)) failed(ticket);
        } finally { if (pending === ticket) pending = undefined; }
    }
    function retryCheck() {
        if (leaving) return;
        const action = retry; retry = undefined;
        if (action) action();
        else if (restoration?.path === path()) restoreHistory();
        else void checkLive();
    }
    router.on('start', event => {
        // 3.7.1 fires start with RequestParams.all(), including active callbacks.
        const visit = event.detail.visit as ActiveVisit;
        if (visit.prefetch) return;
        restoration = undefined;
        sequence++; stopPending(); notice(false);
        retryUrl = visit.method === 'get' ? visit.url.href : live?.url;
        retryOptions = { replace: visit.replace, preserveState: visit.preserveState, preserveScroll: visit.preserveScroll };
        const ticket: Visit = { id: visit.id, generation, sequence, cancelled: false,
            replace: visit.replace, preserveState: visit.preserveState, preserveScroll: visit.preserveScroll };
        const beforeUpdate = visit.onBeforeUpdate;
        visit.onBeforeUpdate = page => {
            if (ticket.cancelled || ticket.generation !== generation || ticket.sequence !== sequence || leaving) throw cancelled();
            visits.set(page, ticket); beforeUpdate(page);
        };
        const onCancel = visit.onCancel;
        visit.onCancel = () => {
            ticket.cancelled = true;
            if (pending?.visit === ticket) stopPending();
            onCancel();
        };
    });
    // Guard the existing adapter swap as well as resolve: encrypted history writes
    // introduce another await after resolution. Preserve the adapter's draft/layout behavior.
    const init: typeof router.init = router.init.bind(router);
    router.init = function<ComponentType>(options: RouterInitParams<ComponentType>) {
        router.init = init;
        const swap = options.swapComponent;
        init<ComponentType>({ ...options, swapComponent: async update => {
            const ticket = approvals.get(update.page);
            if (!ticket || !valid(ticket)) { hide(); throw cancelled(); }
            await swap(update);
            await nextTick();
            if (!valid(ticket)) { hide(); throw cancelled(); }
            live = update.page; displayed = ticket;
            restoration = undefined;
            notice(false); reveal(live);
        } });
    };
    window.addEventListener('popstate', event => {
        restoration = { state: event.state, path: path() };
        breakTrust();
    }, true);
    window.addEventListener('pagehide', breakTrust, true);
    window.addEventListener('pageshow', event => { if (event.persisted) void checkLive(); }, true);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') breakTrust(); else retryCheck();
    });
    window.addEventListener('focus', () => {
        if (restoration?.path === path()) restoreHistory(); else if (live) void checkLive();
    });
    window.addEventListener('online', retryCheck);
    channel?.addEventListener('message', () => { breakTrust(); void checkLive(); });
    document.getElementById('visibility-retry')?.addEventListener('click', retryCheck);
    document.getElementById('visibility-navigation-retry')?.addEventListener('click', retryCheck);
    // Inertia 3.7.1's popstate listener does not return/catch setQuietly's inner
    // promise. Handle only our already surfaced failures/expected cancellations;
    // leave all unrelated errors observable. The rejected resolver never swaps.
    window.addEventListener('unhandledrejection', event => {
        if (event.reason instanceof VisibilityCancelled || event.reason instanceof VisibilityUnavailable) event.preventDefault();
    });
    router.on('navigate', event => reveal(event.detail.page));
    router.on('success', event => reveal(event.detail.page));
    router.on('error', event => { if (event.detail.page) reveal(event.detail.page); });
    router.on('httpException', event => {
        if ([401, 403, 404, 410].includes(event.detail.response.status)) {
            event.preventDefault(); invalidate();
        }
    });
    router.on('networkError', event => {
        if (!(event.detail.error instanceof VisibilityUnavailable) && !(event.detail.error instanceof HttpNetworkError)) return;
        event.preventDefault();
        if (event.detail.error instanceof VisibilityUnavailable) return;
        const target = retryUrl, options = retryOptions;
        if (target) retry = () => router.visit(target, options);
        if (trustedDisplay()) notice(true);
        else { hide(); root.dataset.visibility = 'unavailable'; }
    });
    return { resolve, beforeMount(page: Page) {
        const ticket = approvals.get(page);
        if (!ticket) throw cancelled();
        assert(ticket);
    }, mounted(page: Page) {
        const ticket = approvals.get(page);
        if (ticket && valid(ticket)) { live = page; displayed = ticket; reveal(page); }
    }, initialFailure(error: unknown, retryInitial: () => void) {
        if (!(error instanceof HttpCancelledError) && !(error instanceof VisibilityUnavailable)) throw error;
        if (!live && !leaving) {
            retry = retryInitial;
            root.dataset.visibility = 'unavailable';
        }
    } };
}
