import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const routerModule = url(`export const router={on:(n,fn)=>globalThis.__visibility.router[n]=fn,
 init:options=>globalThis.__visibility.swap=options.swapComponent,
 cancelAll(){globalThis.__visibility.active?.onCancel();},flushAll(){},clearHistory(){globalThis.__visibility.cleared++;},
 visit:(path,options)=>globalThis.__visibility.visits.push({path,options})};`);
const coreModule = url('export class HttpCancelledError extends Error {} export class HttpNetworkError extends Error {} export const configureNavigation=hooks=>{globalThis.__visibility.navigationHooks=hooks;}; export const navigationPhase=()=>undefined;');
const vueModule = url('export const nextTick=()=>Promise.resolve();');
// Identity protocol has its own contract probes; this harness isolates retained
// navigation/History/BFCache containment without bypassing production admission.
const identityModule = url(`export const acceptIdentityPage=()=>{},attachIdentityPage=()=>{},
requireIdentity=value=>value||{receipt:'isolated-visibility-fixture',binding:{boundary:'advertiser',epoch:1}},recoverIdentity=async()=>{},retryIdentityRecovery=async()=>{},identityBlocked=()=>false,receiveIdentityHint=()=>{},closeIdentityDocument=()=>{},resumeIdentityRenewal=()=>{globalThis.__visibility.renewalResumes=(globalThis.__visibility.renewalResumes||0)+1};`);
const settlementModule = url('export const beginNavigationSwap=()=>{},confirmNavigationSwap=()=>{},abortNavigationSwap=()=>{};');
const source = ts.transpileModule(await readFile(new URL('../resources/js/clientVisibility.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    .replace("from '@inertiajs/vue3'", `from ${JSON.stringify(routerModule)}`)
    .replace("from '@inertiajs/core'", `from ${JSON.stringify(coreModule)}`)
    .replace("from 'vue'", `from ${JSON.stringify(vueModule)}`)
    .replace("from './identityProtocol'", `from ${JSON.stringify(identityModule)}`)
    .replace("from './inertiaNavigation'", `from ${JSON.stringify(coreModule)}`)
    .replace("from './navigationSettlement'", `from ${JSON.stringify(settlementModule)}`);
const { installClientVisibility, VisibilityUnavailable } = await import(url(source));
const { router } = await import(routerModule);
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
function setup() {
    const state = globalThis.__visibility = { router: {}, window: {}, document: {}, requests: [], cleared: 0,
        replaced: [], visits: [], mounted: [], inert: false, notice: { hidden: true }, buttons: {}, reloads: 0 };
    globalThis.BroadcastChannel = undefined;
    globalThis.PopStateEvent = class { constructor(type, options) { this.type = type; this.state = options.state; } };
    globalThis.document = { documentElement: { dataset: { visibility: 'checking' } }, visibilityState: 'visible', title: 'Private',
        getElementById: id => id === 'app' ? { setAttribute() { state.inert = true; }, removeAttribute() { state.inert = false; } }
            : id === 'visibility-navigation-error' ? state.notice : id === 'logout-status' ? { removeAttribute() { state.terminalNotice = true; } } : { addEventListener: (_, fn) => state.buttons[id] = fn },
        addEventListener: (name, fn) => state.document[name] = fn };
    globalThis.window = { location: { origin: 'http://localhost', pathname: '/a', search: '', href: 'http://localhost/a',
        replace: path => state.replaced.push(path), reload: () => state.reloads++ }, crypto: { subtle: {} },
        addEventListener: (name, fn) => state.window[name] = fn,
        dispatchEvent: event => { state.window[event.type](event); state.restore?.(); } };
    globalThis.fetch = (path, options) => new Promise((resolve, reject) => state.requests.push({ path, options, resolve, reject }));
    state.boundary = installClientVisibility();
    router.init({ swapComponent: async update => state.mounted.push(update.page) });
    state.reply = (index, receipt, status = 200) => state.requests[index].resolve({ ok: status === 200, status,
        type: 'basic', json: async () => receipt });
    state.resolve = (p, load = async () => 'component') => state.boundary.resolve(p, load);
    state.start = p => {
        const visit = { id: 'visit-' + state.visits.length + '-' + state.requests.length, prefetch: false,
            async: false, only: [], except: [], reset: [],
            method: 'get', url: new URL(p?.url ?? '/next', 'http://localhost'), replace: false, preserveState: false, preserveScroll: false,
            onBeforeUpdate() {}, onCancel() {} };
        state.router.start({ detail: { visit } }); state.active = visit;
        if (p) visit.onBeforeUpdate(p);
        return visit;
    };
    state.commit = async p => {
        window.location.pathname = p.url; window.location.href = 'http://localhost' + p.url;
        await state.swap({ page: p, component: 'component', preserveState: true });
    };
    return state;
}
function page(path = '/a', context = 'context-a') {
    return { url: path, encryptHistory: true, props: { clientVisibility: { protected: true, path, context } } };
}
async function initial(s, p = page()) {
    const waiting = s.resolve(p); s.reply(s.requests.length - 1, p.props.clientVisibility);
    await waiting; s.boundary.mounted(p); return p;
}

test('initial render is hidden and the adapter initial clone receives its own check', async () => {
    const s = setup(), p = page(); const waiting = s.resolve(p);
    assert.equal(document.documentElement.dataset.visibility, 'checking');
    assert.equal(s.inert, true);
    s.reply(0, p.props.clientVisibility); await waiting;
    s.boundary.beforeMount(p); s.boundary.mounted(p);
    const clone = { ...p }, replay = s.resolve(clone);
    s.reply(1, clone.props.clientVisibility); await replay; await s.commit(clone);
    assert.equal(s.requests.length, 2); assert.equal(document.documentElement.dataset.visibility, 'ready');
});
test('ordinary navigation and replace/validation events leave the approved page visible until commit', async () => {
    const s = setup(), a = await initial(s), b = page('/b', 'context-b');
    s.start(b); const waiting = s.resolve(b);
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(s.inert, false);
    assert.equal(window.location.pathname, '/a'); assert.deepEqual(s.mounted, []);
    s.reply(1, b.props.clientVisibility); await waiting; await s.commit(b);
    s.router.error({ detail: { page: b } });
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.deepEqual(s.mounted, [b]);
    s.router.success({ detail: { page: a } }); assert.deepEqual(s.mounted, [b]);
});
test('same-page deferred visits keep independent approvals but a genuine navigation invalidates them',async()=>{
    const s=setup(),a=await initial(s),first=page(),second=page();
    const start=p=>{const visit={id:p===first?'partial-a':'partial-b',prefetch:false,method:'get',async:true,deferredProps:true,
        url:new URL('/a','http://localhost'),only:['part'],except:[],reset:[],onBeforeUpdate(){},onCancel(){}};
        s.router.start({detail:{visit}});return visit;};
    const v1=start(first),v2=start(second);v2.onBeforeUpdate(second);let wait=s.resolve(second);s.reply(1,second.props.clientVisibility);await wait;await s.commit(second);
    v1.onBeforeUpdate(first);wait=s.resolve(first);s.reply(2,first.props.clientVisibility);await wait;await s.commit(first);
    assert.equal(document.documentElement.dataset.visibility,'ready');assert.deepEqual(s.mounted,[second,first]);
    s.start(page('/b'));assert.throws(()=>v1.onBeforeUpdate(first),/superseded/);assert.equal(s.requests.length,3);
});
test('initial render failure closes the document without reload, key deletion or automatic retry',async()=>{
    const s=setup(),p=await initial(s);const log=console.error;let errors=0;console.error=()=>errors++;
    try{s.boundary.initialFailure(Error('mount fault'),()=>{throw Error('Unexpected automatic retry');});
        assert.equal(document.documentElement.dataset.visibility,'unavailable');assert.equal(s.inert,true);
        s.router.navigate({detail:{page:p}});s.router.success({detail:{page:p}});s.window.focus();s.buttons['visibility-retry']();
        assert.notEqual(document.documentElement.dataset.visibility,'ready');assert.deepEqual(s.replaced,[]);assert.equal(s.cleared,0);assert.equal(errors,1);
    }finally{console.error=log;}
});
test('History and BFCache require fresh checks even for an identical page object', async () => {
    const s = setup(), p = await initial(s);
    const renewalResumes=s.renewalResumes;
    s.window.pagehide(); assert.equal(document.documentElement.dataset.visibility, 'checking');
    s.window.pageshow({ persisted: true }); await flush();
    assert.equal(document.documentElement.dataset.visibility, 'checking');
    assert.equal(s.renewalResumes,renewalResumes,'Untrusted restoration must not resume renewal');
    s.reply(1, p.props.clientVisibility); await flush();
    assert.equal(document.documentElement.dataset.visibility, 'ready');
    assert.equal(s.renewalResumes,renewalResumes+1,'Existing successful visibility admission resumes renewal');
    s.window.popstate({ state: 'encrypted-entry' }); const restored = s.resolve(p);
    assert.equal(document.documentElement.dataset.visibility, 'checking');
    s.reply(2, p.props.clientVisibility); await restored; await s.commit(p);
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(s.cleared, 0);
});
test('late probe from a superseded workspace settles without loading or mounting it', async () => {
    const s = setup(); await initial(s); const a = page('/old', 'old'), b = page('/b', 'new');
    s.start(a); let loaded = false; const first = s.resolve(a, async () => { loaded = true; });
    const rejected = assert.rejects(first, /superseded/);
    s.start(b); const second = s.resolve(b); await rejected;
    s.reply(2, b.props.clientVisibility); await second; await s.commit(b);
    s.reply(1, a.props.clientVisibility); await flush();
    assert.equal(loaded, false); assert.deepEqual(s.mounted, [b]);
    assert.equal(s.requests[1].options.signal.aborted, true); assert.equal(s.cleared, 0);
    assert.equal(document.documentElement.dataset.visibility, 'ready');
});
test('cancel during Vue component loading settles the visit and never calls the adapter swap', async () => {
    const s = setup(); await initial(s); const p = page('/slow'); s.start(p);
    let release; const load = new Promise(resolve => release = resolve);
    const first = s.resolve(p, () => load); const rejected = assert.rejects(first, /superseded/);
    s.reply(1, p.props.clientVisibility); await flush(); s.active.onCancel(); await rejected;
    const next = page('/next'); s.start(next); const second = s.resolve(next);
    s.reply(2, next.props.clientVisibility); await second; await s.commit(next); release('component'); await flush();
    assert.deepEqual(s.mounted, [next]); assert.equal(document.documentElement.dataset.visibility, 'ready');
});
test('the last swap guard rejects cancellation after resolution but before history completion', async () => {
    const s = setup(); await initial(s); const p = page('/slow'); s.start(p);
    const waiting = s.resolve(p); s.reply(1, p.props.clientVisibility); await waiting;
    s.active.onCancel(); await assert.rejects(s.commit(p), /superseded/);
    assert.deepEqual(s.mounted, []); assert.equal(document.documentElement.dataset.visibility, 'checking');
});
test('late network response cannot enter resolution through its cancelled visit callback', async () => {
    const s = setup(); await initial(s); const old = s.start(); s.start();
    assert.throws(() => old.onBeforeUpdate(page('/old')), /superseded/); assert.deepEqual(s.mounted, []);
});
test('candidate network failure retains the visible draft and offers a nonblocking retry', async () => {
    const s = setup(), a = await initial(s), b = page('/b'); a.draft = { unsaved: 'retained' };
    s.start(b); const waiting = s.resolve(b); const rejected = assert.rejects(waiting, VisibilityUnavailable);
    s.requests[1].reject(new Error('offline')); await rejected;
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(s.notice.hidden, false);
    assert.equal(a.draft.unsaved, 'retained'); assert.equal(s.cleared, 0);
    s.buttons['visibility-navigation-retry'](); assert.equal(s.visits[0].path, '/b');
    const c = page('/c'); s.start(c); const next = s.resolve(c); s.reply(2, c.props.clientVisibility);
    await next; await s.commit(c); assert.equal(s.notice.hidden, true);
});
test('offline BFCache recheck keeps mounted draft hidden and retry needs fresh success', async () => {
    const s = setup(), p = await initial(s); p.draft = 'retained';
    s.window.pagehide(); s.window.pageshow({ persisted: true }); await flush();
    s.requests[1].reject(new Error('offline')); await flush();
    assert.equal(document.documentElement.dataset.visibility, 'unavailable'); assert.equal(s.cleared, 0);
    s.window.online(); await flush(); s.reply(2, p.props.clientVisibility); await flush();
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(p.draft, 'retained');
});
test('server denial during a navigation immediately invalidates the currently visible page', async () => {
    const s = setup(); await initial(s); const p = page('/b'); s.start(p);
    const waiting = s.resolve(p); const rejected = assert.rejects(waiting, /superseded/);
    s.reply(1, {}, 403); await rejected;
    assert.equal(document.documentElement.dataset.visibility, 'checking'); assert.equal(s.cleared, 1);
    assert.deepEqual(s.mounted, []); assert.deepEqual(s.replaced, ['http://localhost/a']);
});
test('observed invalidation during a successful delayed probe cannot approve stale data', async () => {
    const s = setup(), a = await initial(s), p = page('/b'); s.start(p);
    const waiting = s.resolve(p); const rejected = assert.rejects(waiting, /superseded/);
    s.window.pagehide(); s.window.pageshow({persisted:true}); await rejected; await flush();
    s.reply(1, p.props.clientVisibility); s.reply(2, a.props.clientVisibility); await flush();
    assert.deepEqual(s.mounted, []); assert.equal(document.documentElement.dataset.visibility, 'ready');
});
test('ordinary focus and tab changes preserve the approved display and candidate without authority probes', async () => {
    const s = setup(); await initial(s); const p = page('/b'); s.start(p);
    const waiting = s.resolve(p);
    document.visibilityState = 'hidden'; s.document.visibilitychange?.(); s.window.focus();
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(s.requests.length,2);
    document.visibilityState = 'visible'; s.document.visibilitychange?.();
    s.reply(1, p.props.clientVisibility); await waiting; await s.commit(p);
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.deepEqual(s.mounted,[p]);
});
test('legacy or mismatched receipts never authorize a component', async () => {
    for (const p of [{ url: '/a', props: { auth: { user: { id: 1 } } } }, page('/different')]) {
        const s = setup(); if (p.props.clientVisibility) p.props.clientVisibility.path = '/a';
        await assert.rejects(s.resolve(p), /superseded/);
        assert.equal(s.requests.length, 0); assert.equal(s.cleared, 1); assert.deepEqual(s.mounted, []);
        assert.notEqual(document.documentElement.dataset.visibility, 'ready');
    }
});
test('initial approval invalidated before mount cannot mount, including crypto unavailable', async () => {
    const s = setup(), p = page(); const waiting = s.resolve(p);
    s.reply(0, p.props.clientVisibility); await waiting;
    s.window.pagehide(); assert.throws(() => s.boundary.beforeMount(p), /superseded/);
    const other = setup(); window.crypto = undefined;
    await assert.rejects(other.resolve(page()), VisibilityUnavailable);
    assert.equal(document.documentElement.dataset.visibility, 'unavailable'); assert.equal(other.requests.length, 0);
});
test('observed account change masks immediately, before its probe or component resolves', async () => {
    const s = setup(), a = page(); a.props.auth = { user: { id: 1 }, sessionContextId: 'session-a' };
    await initial(s, a);
    const b = page('/b'); b.props.auth = { user: { id: 2 }, sessionContextId: 'session-b' };
    s.start(b); const waiting = s.resolve(b);
    assert.equal(document.documentElement.dataset.visibility, 'checking'); assert.equal(s.inert, true);
    s.reply(1, b.props.clientVisibility); await waiting; await s.commit(b);
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.deepEqual(s.mounted, [b]);
});
test('offline History retry uses official restoration and a visibility probe, never a normal GET', async () => {
    const s = setup(), p = await initial(s); p.draft = 'retained';
    s.window.popstate({ state: 'encrypted-entry' });
    const waiting = s.resolve(p); const rejected = assert.rejects(waiting, VisibilityUnavailable);
    s.requests[1].reject(new Error('offline')); await rejected;
    assert.equal(document.documentElement.dataset.visibility, 'unavailable');
    let retried; s.restore = () => retried = s.resolve(p);
    s.buttons['visibility-retry']();
    s.reply(2, p.props.clientVisibility); await retried; await s.commit(p);
    assert.deepEqual(s.visits, []); assert.equal(s.requests[2].options.headers['X-Builderad-Visibility'], '1');
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(p.draft, 'retained');
});
test('expected restoration failures are surfaced but unrelated unhandled errors remain observable', async () => {
    const s = setup(); let handled = 0;
    s.window.unhandledrejection({ reason: new VisibilityUnavailable(), preventDefault() { handled++; } });
    s.window.unhandledrejection({ reason: new Error('unrelated defect'), preventDefault() { handled++; } });
    assert.equal(handled, 1);
});
test('initial offline failure retries the retained page through a fresh probe without reloading', async () => {
    const s = setup(), p = page(); const waiting = s.resolve(p);
    s.requests[0].reject(new Error('offline'));
    let retried;
    await waiting.catch(error => s.boundary.initialFailure(error, () => retried = s.resolve(p)));
    assert.equal(document.documentElement.dataset.visibility, 'unavailable'); assert.equal(s.cleared, 0);
    s.window.online(); s.reply(1, p.props.clientVisibility); await retried; s.boundary.mounted(p);
    assert.equal(document.documentElement.dataset.visibility, 'ready'); assert.equal(s.reloads, 0);
});

test('terminal departure rejects in-flight approval and every same-document reveal path without erasing keys', async () => {
    const s = setup(), p = await initial(s);
    s.window.pageshow({ persisted: true });
    await flush();
    assert.equal(s.requests.length, 2);
    s.boundary.closeDocument();
    s.reply(1, p.props.clientVisibility);
    await flush();
    s.window.pagehide();
    s.window.pageshow({ persisted: true });
    s.window.focus(); s.window.online();
    s.document.visibilitychange?.();
    s.buttons['visibility-retry']();
    s.buttons['visibility-navigation-retry']();
    s.router.navigate({ detail: { page: p } });
    s.router.success({ detail: { page: p } });
    s.router.error({ detail: { page: p } });
    s.boundary.mounted(p);
    await flush();
    await assert.rejects(s.resolve(p), /superseded/);
    assert.notEqual(document.documentElement.dataset.visibility, 'ready');
    assert.equal(s.inert, true);
    assert.equal(s.terminalNotice, true);
    assert.equal(s.requests.length, 2);
    assert.equal(s.cleared, 0);
    assert.deepEqual(s.replaced, []);
    assert.deepEqual(s.visits, []);
});
test('all four advertiser logout entries use the single injected document operation', async () => {
    const entries = [
        ['Layouts/AdvertiserLayout.vue', 1],
        ['Layouts/AuthenticatedLayout.vue', 2],
        ['Pages/Auth/VerifyEmail.vue', 1],
    ];
    for (const [path, count] of entries) {
        const component = await readFile(new URL('../resources/js/' + path, import.meta.url), 'utf8');
        assert.match(component, /inject\(logoutKey\)/);
        assert.match(component, /logout\.start\(/);
        assert.equal((component.match(/@click="leave"/g) ?? []).length, count);
        assert.doesNotMatch(component, /route\(['"]logout['"]\)|createLogoutOperation|fetch\(/);
    }
    const app = await readFile(new URL('../resources/js/app.ts', import.meta.url), 'utf8');
    assert.equal((app.match(/createLogoutOperation\(visibility.closeDocument\)/g) ?? []).length, 1);
    assert.match(app, /provide\(logoutKey, logout\)/);
});
