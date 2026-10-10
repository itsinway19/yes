import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_DATABASE, 'builderad_test_disposable');
assert.equal(process.env.DB_PORT, '5433');
assert.ok(process.env.BUILDERAD_TEST_ISOLATION_TOKEN);
const run = process.env.CURRENT_INTENT_PROOF_RUN;
const fixture = JSON.parse(await readFile(run + '/client-state-fixture.json', 'utf8'));
const base = 'http://localhost:8027';
const goalUrl = id => base + '/workspaces/' + id + '/goals';
const aName = 'نشاط اختبار أهداف المعلن';
const evidence = { environment: { base, database: process.env.DB_DATABASE, port: process.env.DB_PORT }, mechanism: 'Official Inertia plus Client Visibility Boundary', checks: [], errors: [], leaks: [], responses: [], pageshow: [] };
const browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--disable-back-forward-cache'], executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const pendingRequests = new WeakMap();
const control = (page, key) => page.locator('[data-goals="' + key + '"]');
const mounted = page => page.waitForFunction(() => !!document.getElementById('app')?.__vue_app__);
const fixtureCommand = mode => {
    const result = spawnSync('C:/tools/php85/php.exe', ['tests/Support/ProtectedClientStateFixture.php', mode], { encoding: 'utf8', env: process.env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout.trim());
};
async function check(name, category, action) {
    try { const details = await action(); evidence.checks.push({ name, category, result: details?.result ?? 'PASS', details, source: 'Real Laravel / real Chromium; synthetic isolated users' }); console.log((details?.result ?? 'PASS') + ' ' + name); }
    catch (error) { evidence.checks.push({ name, category, result: 'FAIL', message: error.message }); console.log('FAIL ' + name + ': ' + error.message); }
}
async function context() {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    ctx.setDefaultTimeout(15000);
    await ctx.addInitScript(aId => {
        window.__officialLeaks = [];
        window.__officialShows = [];
        addEventListener('pageshow', event => {
            const show = { persisted: event.persisted, url: location.href };
            window.__officialShows.push(show); console.log('OFFICIAL_PAGESHOW ' + JSON.stringify(show));
        });
        const sample = () => {
            let forbidden = false;
            try { forbidden = !!sessionStorage.getItem('official-proof-forbid-a'); } catch {}
            const app = document.getElementById('app');
            const restoredIdentity = app?.__vue_app__?.config.globalProperties.$page?.props.auth?.user?.id;
            if (forbidden && app && getComputedStyle(app).visibility !== 'hidden' && ((sessionStorage.getItem('official-proof-forbid-a') === '1' && restoredIdentity === aId) || app.textContent.includes('نشاط اختبار أهداف المعلن'))) {
                if (!window.__officialLeaks.length) console.log('OFFICIAL_LEAK ' + location.href);
                window.__officialLeaks.push(location.href);
            }
            requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
    }, fixture.a);
    ctx.on('page', page => {
        const pending = new Set(); pendingRequests.set(page, pending);
        page.on('request', request => {
            if (request.url().startsWith(base) && ['document', 'fetch', 'xhr'].includes(request.resourceType())) pending.add(request);
        });
        page.on('requestfinished', request => pending.delete(request));
        page.on('requestfailed', request => pending.delete(request));
        page.on('dialog', dialog => dialog.accept());
        page.on('pageerror', error => evidence.errors.push(error.message));
        page.on('console', message => {
            if (message.text().startsWith('OFFICIAL_LEAK ')) evidence.leaks.push(message.text());
            if (message.text().startsWith('OFFICIAL_PAGESHOW ')) evidence.pageshow.push(JSON.parse(message.text().slice('OFFICIAL_PAGESHOW '.length)));
        });
        page.on('response', response => { if (response.url().startsWith(base) && response.request().resourceType() !== 'script' && !response.url().includes('/build/')) evidence.responses.push({ url: response.url(), status: response.status(), inertia: response.request().headers()['x-inertia'] === 'true' }); });
    });
    return ctx;
}
async function login(page, account = 'a') {
    await page.goto(base + '/login'); await mounted(page);
    await page.locator('#email').fill(account === 'a' ? 'goals-proof@example.test' : 'boundary-b@example.test');
    await page.locator('#password').fill('DisposableGoalsProof42!');
    await page.locator('form button').click();
    await page.waitForURL(url => url.pathname !== '/login'); await mounted(page);
}
async function visit(page, url) {
    await page.evaluate(url => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit(url), url);
    await page.waitForURL(url); await mounted(page);
}
async function draft(page, workspace = fixture.first) {
    await visit(page, goalUrl(workspace));
    await control(page, 'select-sales').check(); await control(page, 'select-customers').check(); await control(page, 'result-actual_sales').check();
}
async function logout(page) {
    await page.locator('.business-account summary').click(); await page.locator('.business-account-menu button').click();
    await page.waitForURL(base + '/'); await mounted(page);
}
async function assertDraft(page) {
    assert.equal(await control(page, 'select-sales').isChecked(), true);
    assert.equal(await control(page, 'select-customers').isChecked(), true);
    assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
}
async function historyStep(page, direction) {
    if (direction === 'back') await page.goBack({ waitUntil: 'commit' }); else await page.goForward({ waitUntil: 'commit' });
    // BFCache does not emit another load event. Missing encrypted history can
    // also trigger a second real navigation. Observe a quiet, stable document
    // after both transitions; rAF leak monitoring remains active throughout.
    const deadline = Date.now() + 15000;
    let previous = '', quietSince = 0;
    while (Date.now() < deadline) {
        try {
            const snapshot = await page.evaluate(() => ({ url: location.href,
                identity: document.getElementById('app')?.__vue_app__?.config.globalProperties.$page?.props.auth?.user?.id ?? null,
                text: document.getElementById('app')?.textContent ?? '', ready: document.readyState }));
            const signature = JSON.stringify(snapshot);
            if (signature !== previous || pendingRequests.get(page)?.size) { previous = signature; quietSince = Date.now(); }
            else if (Date.now() - quietSince >= 500 && snapshot.ready !== 'loading') return snapshot;
        } catch (error) {
            if (!/context was destroyed|Cannot find context/.test(error.message)) throw error;
            quietSince = Date.now();
        }
        await page.waitForTimeout(50);
    }
    throw new Error('Real browser document did not settle within the existing 15s test timeout');
}
async function deniedWrite(page, url) {
    const csrf = (await page.context().cookies(base)).find(cookie => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post(url, { data: { value: { goals: ['sales'], priority: 'sales', results: {} }, version: 0 }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) } });
    assert.equal(response.status(), 403);
    return response.status();
}
async function ready(page) { await page.waitForFunction(() => document.documentElement.dataset.visibility === 'ready'); }
async function arm(page, all = false) { await page.evaluate(all => sessionStorage.setItem('official-proof-forbid-a', all ? '1' : 'goals'), all); }
function cleanLeaks(start) { assert.equal(evidence.leaks.length, start, evidence.leaks.slice(start).join('\n')); }
try {
    await check('Protected initial HTML stays hidden and unmounted until its initial probe completes', 'BA_UX_001', async () => {
        const ctx = await context(); let release; try {
            const p = await ctx.newPage(); await login(p); await ready(p);
            let entered, probes = 0; const gate = new Promise(r => release = r), seen = new Promise(r => entered = r);
            await p.route(base + '/profile', async route => {
                if (route.request().headers()['x-builderad-visibility'] !== '1') return route.continue();
                probes++; entered(); await gate; await route.continue();
            });
            await p.goto(base + '/profile', { waitUntil: 'domcontentloaded' }); await seen;
            assert.equal(await p.locator('#app').evaluate(e => getComputedStyle(e).visibility), 'hidden');
            assert.equal(await p.locator('#app').evaluate(e => !!e.__vue_app__), false);
            // Core 3.7.1 clones initialPage in init: each exact Page is checked.
            release(); await ready(p); await p.waitForTimeout(150); assert.equal(probes, 2);
            return { protectedInitialPixelsHidden: true, componentUnmountedBeforeCheck: true, initialProbeCount: probes };
        } finally { release?.(); await ctx.close(); }
    });
    await check('Initial network failure retries only the visibility probe without renewing session activity', 'BA_UX_001', async () => {
        const ctx = await context(); try {
            const p = await ctx.newPage(); await login(p); await ready(p);
            let probes = 0, documents = 0;
            p.on('request', request => { if (request.resourceType() === 'document' && request.url() === base + '/profile') documents++; });
            await p.route(base + '/profile', route => {
                if (route.request().headers()['x-builderad-visibility'] !== '1') return route.continue();
                probes++; return probes === 1 ? route.abort('failed') : route.continue();
            });
            await p.goto(base + '/profile', { waitUntil: 'domcontentloaded' });
            await p.waitForFunction(() => document.documentElement.dataset.visibility === 'unavailable');
            assert.equal(await p.locator('#app').evaluate(e => !!e.__vue_app__), false);
            const before = fixtureCommand('inspect');
            await p.locator('#visibility-retry').click(); await ready(p); await p.waitForTimeout(150);
            assert.equal(documents, 1); assert.equal(probes, 3);
            assert.deepEqual(fixtureCommand('inspect').sessions, before.sessions);
            return { initialDocumentRequests: documents, failedProbes: 1, successfulProbes: 2, idleAndLastSeenUnchanged: true };
        } finally { await ctx.close(); }
    });
    await check('Routine navigation, replace and validation never mask an approved display', 'BA_UX_001', async () => {
        const ctx = await context(); try {
            const p = await ctx.newPage(); await login(p); await ready(p);
            await p.evaluate(() => {
                window.__uxFrames = []; window.__uxSampling = true;
                const sample = () => {
                    if (!window.__uxSampling) return;
                    const app = document.getElementById('app');
                    window.__uxFrames.push({ state: document.documentElement.dataset.visibility, url: location.pathname,
                        visible: getComputedStyle(app).visibility, component: app.__vue_app__.config.globalProperties.$page.component });
                    requestAnimationFrame(sample);
                }; requestAnimationFrame(sample);
            });
            await visit(p, base + '/profile'); await ready(p);
            await p.evaluate(url => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit(url, { replace: true }), goalUrl(fixture.first));
            await p.waitForURL(goalUrl(fixture.first)); await ready(p);
            await visit(p, base + '/profile'); await ready(p);
            await p.evaluate(() => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.patch('/profile', { name: '', email: 'invalid' }));
            await p.waitForFunction(() => Object.keys(document.getElementById('app').__vue_app__.config.globalProperties.$page.props.errors).length > 0);
            await ready(p);
            const frames = await p.evaluate(() => { window.__uxSampling = false; return window.__uxFrames; });
            assert.ok(frames.length > 0); assert.deepEqual(frames.filter(f => f.state !== 'ready' || f.visible === 'hidden'), []);
            assert.deepEqual(frames.filter(f => f.url === '/profile' && f.component !== 'Profile/Edit'), []);
            return { sampledFrames: frames.length, maskedFrames: 0, replace: true, validation: true };
        } finally { await ctx.close(); }
    });
    await check('Candidate probe failure leaves current draft visible and retry does not block later visits', 'BA_UX_001', async () => {
        const ctx = await context(); try {
            const p = await ctx.newPage(); await login(p); await ready(p); await draft(p); await ready(p);
            await p.route(base + '/profile', route => route.request().headers()['x-builderad-visibility'] === '1' ? route.abort('failed') : route.continue());
            await p.evaluate(() => { window.confirm = () => true; document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile'); });
            await p.locator('#visibility-navigation-error').waitFor({ state: 'visible' });
            assert.equal(new URL(p.url()).pathname, '/workspaces/' + fixture.first + '/goals');
            assert.equal(await p.locator('#app').evaluate(e => getComputedStyle(e).visibility), 'visible'); await assertDraft(p);
            await p.unroute(base + '/profile'); await p.locator('#visibility-navigation-retry').click();
            await p.waitForURL(base + '/profile'); await ready(p);
            await historyStep(p, 'back'); await ready(p); await assertDraft(p);
            return { currentDraftVisible: true, retrySucceeded: true, rememberedDraftRetained: true };
        } finally { await ctx.close(); }
    });
    await check('A cancelled delayed Inertia response cannot mount or replace a newer workspace', 'BA_UX_001', async () => {
        const ctx = await context(); let release; try {
            const p = await ctx.newPage(); await login(p); await ready(p);
            let entered; const gate = new Promise(r => release = r), seen = new Promise(r => entered = r);
            await p.route(base + '/profile', async route => {
                if (route.request().headers()['x-builderad-visibility'] === '1') return route.continue();
                const response = await route.fetch(); entered(); await gate; await route.fulfill({ response });
            });
            await p.evaluate(() => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile'));
            await seen;
            await p.evaluate(url => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit(url), goalUrl(fixture.second));
            await p.waitForURL(goalUrl(fixture.second)); await ready(p); release(); await p.waitForTimeout(200);
            assert.equal(new URL(p.url()).pathname, '/workspaces/' + fixture.second + '/goals');
            assert.equal(await p.evaluate(() => document.getElementById('app').__vue_app__.config.globalProperties.$page.component), 'Business/Goals');
            return { delayedResponseReleased: true, latestWorkspaceRetained: true };
        } finally { release?.(); await ctx.close(); }
    });
    await check('Ordinary offline request keeps the draft visible and retry preserves its History entry', 'BA_UX_001', async () => {
        const ctx = await context(); try {
            const p = await ctx.newPage(); await login(p); await ready(p); await draft(p); await ready(p);
            await ctx.setOffline(true);
            await p.evaluate(() => { window.confirm = () => true; document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile'); });
            await p.locator('#visibility-navigation-error').waitFor({ state: 'visible' }); await assertDraft(p);
            assert.equal(await p.locator('#app').evaluate(e => getComputedStyle(e).visibility), 'visible');
            await ctx.setOffline(false); await p.waitForURL(base + '/profile'); await ready(p);
            await historyStep(p, 'back'); await ready(p); await assertDraft(p);
            return { failedRequestRetainedDraft: true, retryRetainedHistoryEntry: true };
        } finally { await ctx.close(); }
    });
    await check('Cancellation while a real Vue chunk is delayed prevents candidate mounting', 'BA_UX_001', async () => {
        const ctx = await context(); let release; try {
            const p = await ctx.newPage(); await login(p); await ready(p);
            let entered; const gate = new Promise(r => release = r), seen = new Promise(r => entered = r);
            const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
            const asset = manifest['resources/js/Pages/Profile/Edit.vue'].file;
            await p.route(base + '/build/' + asset, async route => { entered(); await gate; await route.continue(); });
            await p.evaluate(() => {
                window.__uxComponents = []; window.__uxWatch = true;
                const sample = () => { if (!window.__uxWatch) return; window.__uxComponents.push(document.getElementById('app').__vue_app__.config.globalProperties.$page.component); requestAnimationFrame(sample); }; sample();
                document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile');
            });
            await Promise.race([seen, new Promise((_, reject) => setTimeout(() => reject(new Error('Vue chunk not requested')), 15000))]);
            assert.equal(await p.locator('#app').evaluate(e => getComputedStyle(e).visibility), 'visible');
            await p.evaluate(url => { const r = document.getElementById('app').__vue_app__.config.globalProperties.$inertia; r.cancelAll(); r.visit(url); }, goalUrl(fixture.second));
            await p.waitForURL(goalUrl(fixture.second)); await ready(p); release(); await p.waitForTimeout(200);
            const components = await p.evaluate(() => { window.__uxWatch = false; return window.__uxComponents; });
            assert.equal(components.includes('Profile/Edit'), false);
            return { actualChunkDelayed: true, cancelledCandidateMounts: 0, subsequentVisitSucceeded: true };
        } finally { release?.(); await ctx.close(); }
    });
    await check('Authorized navigation preserves drafts, results, baseline and workspace isolation', 'VISIBILITY', async () => {
        const ctx = await context(); try {
            const p = await ctx.newPage(); await login(p); await ready(p); await draft(p); await ready(p);
            const baseline = await p.evaluate(id => { const g=document.getElementById('app').__vue_app__.config.globalProperties; return g.$inertia.restore(`advertiser-goals:${g.$page.props.auth.user.id}:${g.$page.props.auth.sessionContextId}:${id}`); },fixture.first);
            await visit(p,goalUrl(fixture.second)); await ready(p); assert.equal(await control(p,'select-sales').isChecked(),false); await control(p,'select-growth').check();
            for(let i=0;i<2;i++){await historyStep(p,'back');await ready(p);await assertDraft(p);await historyStep(p,'forward');await ready(p);assert.equal(await control(p,'select-growth').isChecked(),true);}
            await historyStep(p,'back');await ready(p);
            const restored=await p.evaluate(id=>{const g=document.getElementById('app').__vue_app__.config.globalProperties;return g.$inertia.restore(`advertiser-goals:${g.$page.props.auth.user.id}:${g.$page.props.auth.sessionContextId}:${id}`);},fixture.first);
            assert.deepEqual(restored,baseline);return {baseline:baseline.baseline.version,cycles:2};
        } finally {await ctx.close();}
    });
    for(let attempt=1;attempt<=3;attempt++) await check('A logout B login same-tab restoration '+attempt,'VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);await visit(p,goalUrl(fixture.first));await ready(p);await logout(p);await ready(p);await login(p,'b');await ready(p);await arm(p,true);const start=evidence.leaks.length;for(let i=0;i<5;i++)await historyStep(p,'back');for(let i=0;i<3;i++)await historyStep(p,'forward');cleanLeaks(start);await deniedWrite(p,goalUrl(fixture.first));return {sampledLeaks:0};}finally{await ctx.close();}
    });
    await check('Idle expiry cannot restore pixels or renew the idle deadline','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);const before=fixtureCommand('expire');await arm(p);const start=evidence.leaks.length;await historyStep(p,'back');await p.waitForURL('**/login');cleanLeaks(start);assert.deepEqual(fixtureCommand('inspect').sessions,before.sessions);return {sampledLeaks:0};}finally{await ctx.close();}
    });
    await check('Revoked workspace cannot restore content; real reads and writes stay forbidden','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);fixtureCommand('revoke');await arm(p);const start=evidence.leaks.length;await historyStep(p,'back');cleanLeaks(start);assert.equal((await p.request.get(goalUrl(fixture.first))).status(),403);await deniedWrite(p,goalUrl(fixture.first));return {sampledLeaks:0};}finally{fixtureCommand('restore');await ctx.close();}
    });
    await check('Cross-tab identity change is rejected on restoring protected content','VISIBILITY',async()=>{
        const ctx=await context();try{const first=await ctx.newPage();await login(first);await ready(first);await draft(first);await ready(first);await visit(first,base+'/profile');await ready(first);const second=await ctx.newPage();await second.goto(goalUrl(fixture.first));await ready(second);await logout(second);await ready(second);await login(second,'b');await ready(second);await arm(first,true);const start=evidence.leaks.length;await historyStep(first,'back');cleanLeaks(start);await deniedWrite(first,goalUrl(fixture.first));return {sampledLeaks:0};}finally{await ctx.close();}
    });
    await check('Same account with a new session does not recover the prior draft','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await logout(p);await ready(p);await login(p);await ready(p);await visit(p,goalUrl(fixture.first));await ready(p);assert.equal(await control(p,'select-sales').isChecked(),false);return {oldDraftAbsent:true};}finally{await ctx.close();}
    });
    await check('Plaintext history without boundary evidence is rejected by the updated document','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await p.waitForTimeout(500);
            // This entry emulates the old data schema; the executing document has loaded the new boundary.
            await p.evaluate(()=>{const state=history.state;const g=document.getElementById('app').__vue_app__.config.globalProperties;const old=JSON.parse(JSON.stringify(g.$page));old.encryptHistory=false;delete old.props.clientVisibility;history.replaceState({...state,page:old},'');});
            await ctx.setExtraHTTPHeaders({});await p.goto(base+'/profile');await ready(p);await p.goto(goalUrl(fixture.first));await ready(p);await logout(p);await ready(p);await login(p,'b');await ready(p);await arm(p,true);const start=evidence.leaks.length;for(let i=0;i<5;i++)await historyStep(p,'back');cleanLeaks(start);return {sampledLeaks:0,scope:'Legacy entry handled by updated document; pre-fix documents excluded'};}finally{await ctx.close();}
    });
    await check('Actual BFCache snapshot is masked before restoration of expired authority','BFCACHE',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await p.goto(base+'/');await ready(p);fixtureCommand('expire');await arm(p);const start=evidence.leaks.length,shows=evidence.pageshow.length;await historyStep(p,'back');cleanLeaks(start);assert.ok(evidence.pageshow.slice(shows).some(s=>s.persisted),'No actual BFCache restoration observed');return {actualPersistedPageshow:true,sampledLeaks:0};}finally{await ctx.close();}
    });
    await check('Offline restoration stays hidden and recovers the authorized draft after retry','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);const idleBefore=fixtureCommand('inspect');await ctx.setOffline(true);await p.goBack({waitUntil:'commit'});await p.waitForFunction(()=>document.documentElement.dataset.visibility==='unavailable');assert.equal(await p.locator('#app').evaluate(e=>getComputedStyle(e).visibility),'hidden');await ctx.setOffline(false);await p.locator('#visibility-retry').click();await ready(p);await assertDraft(p);assert.deepEqual(fixtureCommand('inspect').sessions,idleBefore.sessions);return {draftRetained:true,idleUnchanged:true};}finally{await ctx.close();}
    });
    await check('Revocation while a successful probe response is delayed cannot reveal the page','VISIBILITY',async()=>{
        const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);let release,entered;const gate=new Promise(r=>release=r),seen=new Promise(r=>entered=r);await p.route(goalUrl(fixture.first),async route=>{if(route.request().headers()['x-builderad-visibility']!=='1')return route.continue();const response=await route.fetch();entered();await gate;await route.fulfill({response});});await p.goBack({waitUntil:'commit'});await seen;fixtureCommand('revoke');await arm(p);const start=evidence.leaks.length; // trigger a new authority boundary while the old response is in flight
            await p.evaluate(()=>dispatchEvent(new Event('focus')));release();await p.waitForTimeout(700);cleanLeaks(start);
            assert.equal((await p.locator('body').innerText()).includes(aName),false);
            assert.ok(evidence.responses.some(r=>r.url===goalUrl(fixture.first)&&r.status===403));
            return {staleResponseRejected:true,freshServerDenial:403};}finally{fixtureCommand('restore');await ctx.close();}
    });
    await check('Workspace switch during an in-flight probe rejects the old response; rapid history remains isolated','VISIBILITY',async()=>{
        const ctx=await context();let release;try{
            const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await visit(p,base+'/profile');await ready(p);
            let entered;const gate=new Promise(r=>release=r),seen=new Promise(r=>entered=r);let captured=false;
            await p.route(goalUrl(fixture.first),async route=>{
                if(captured||route.request().headers()['x-builderad-visibility']!=='1')return route.continue();
                captured=true;const response=await route.fetch();entered();await gate;await route.fulfill({response});
            });
            await p.goBack({waitUntil:'commit'});
            await Promise.race([seen,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Probe was not observed within 15s')),15000))]);
            await visit(p,goalUrl(fixture.second));await ready(p);release();await p.waitForTimeout(200);
            assert.equal(await control(p,'select-sales').isChecked(),false);await control(p,'select-growth').check();
            await p.unroute(goalUrl(fixture.first));
            for(let i=0;i<3;i++){await p.goBack({waitUntil:'commit'});await p.goForward({waitUntil:'commit'});await ready(p);assert.equal(await control(p,'select-growth').isChecked(),true);assert.equal(await control(p,'select-sales').isChecked(),false);}
            return {lateWorkspaceResponseRejected:true,rapidCycles:3};
        }finally{release?.();await ctx.close();}
    });
} finally {await writeFile(run+'/client-visibility-browser-evidence.json',JSON.stringify(evidence,null,2));await browser.close();}
if(evidence.checks.some(c=>c.result!=='PASS')||evidence.errors.length)process.exitCode=1;
