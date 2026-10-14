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
    if(process.env.LOGOUT_NATIVE_PROBE_FILTER && !name.includes(process.env.LOGOUT_NATIVE_PROBE_FILTER)) return;
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
function cleanLeaks(start) { assert.equal(evidence.leaks.length, start, evidence.leaks.slice(start).join('\n')); }const inspect = () => { const r=spawnSync('C:/tools/php85/php.exe',['tests/Support/LogoutOwnerInspect.php'],{encoding:'utf8',env:process.env}); assert.equal(r.status,0,r.stderr); return JSON.parse(r.stdout); };
const capture = p => p.evaluate(async()=>{const app=document.getElementById('app'),pg=app?.__vue_app__?.config.globalProperties.$page;const hash=async v=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(v))))).map(v=>v.toString(16).padStart(2,'0')).join('');return {url:location.href,context:pg?.props.auth?.sessionContextId,component:pg?.component,state:document.documentElement.dataset.visibility,inert:app?.inert,sales:document.querySelector('[data-goals="select-sales"]')?.checked,key:await hash(sessionStorage.getItem('historyKey')),iv:await hash(sessionStorage.getItem('historyIv')),cipher:await hash(history.state?.page instanceof ArrayBuffer?Array.from(new Uint8Array(history.state.page)):history.state?.page)};});
try {
 await check('Official logout cancellation preserves encrypted draft; ordinary SPA restores it','baseline',async()=>{
  const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await p.waitForTimeout(200);const before=await capture(p);const sessions=inspect();let posts=0;p.on('request',r=>{if(r.url()===base+'/logout'&&r.method()==='POST')posts++;});p.removeAllListeners('dialog');p.on('dialog',d=>d.dismiss());await p.locator('.business-account summary').click();await p.locator('.business-account-menu button').click();await p.waitForTimeout(300);assert.equal(posts,0);assert.deepEqual(await capture(p),before);assert.deepEqual(inspect(),sessions);p.removeAllListeners('dialog');p.on('dialog',d=>d.accept());await visit(p,base+'/profile');await ready(p);await historyStep(p,'back');await ready(p);await assertDraft(p);return {posts,before,after:await capture(p),serverSessionUnchanged:true};}finally{await ctx.close();}
 });
 await check('Native submit precedes cancelable Goals beforeunload; confirmation acceptance is not dispatch','native ordering probe',async()=>{
  const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await p.waitForTimeout(200);const before=await capture(p),sessions=inspect();let posts=0;const dialogs=[];p.on('request',r=>{if(r.url()===base+'/logout'&&r.method()==='POST')posts++;});p.removeAllListeners('dialog');p.on('dialog',async d=>{dialogs.push(d.type());if(d.type()==='confirm')await d.accept();else await d.dismiss();});await p.evaluate(()=>{window.__nativeOrder=[];addEventListener('beforeunload',()=>window.__nativeOrder.push('beforeunload'));const form=document.createElement('form');form.method='POST';form.action='/logout';form.id='native-order-probe';form.innerHTML='<input name="_token" value="not-sent-probe"><button type="submit">Native ordering probe</button>';form.addEventListener('submit',e=>{window.__nativeOrder.push('submit');if(!confirm('One-attempt confirmation probe'))e.preventDefault();else window.__nativeOrder.push('confirmation-accepted');});document.body.append(form);});await p.locator('#native-order-probe button').click({noWaitAfter:true});await p.waitForTimeout(500);const order=await p.evaluate(()=>window.__nativeOrder);assert.deepEqual(dialogs,['confirm','beforeunload']);assert.deepEqual(order,['submit','confirmation-accepted','beforeunload']);assert.equal(posts,0);assert.deepEqual(await capture(p),before);assert.deepEqual(inspect(),sessions);await assertDraft(p);return {finding:'BOUNDARY CONFLICT: submit and one-attempt custom confirmation precede a second cancelable native departure; neither proves POST dispatch',order,dialogs,posts,before,after:await capture(p),serverSessionUnchanged:true,fixture:'DOM form and ordering observer only; existing Goals beforeunload unchanged; token never sent'};}finally{await ctx.close();}
 });
 await check('Official accepted logout revokes the actual Laravel session','baseline',async()=>{
  const ctx=await context();try{const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);const before=inspect();await logout(p);const after=inspect();const revoked=after.filter(s=>s.revoked_at&&before.some(b=>b.id===s.id&&!b.revoked_at));assert.equal(revoked.length,1);const denied=await p.request.get(goalUrl(fixture.first),{maxRedirects:0});assert.ok([302,303,401,403].includes(denied.status()));return {url:p.url(),revokedSessionId:revoked[0].id,protectedGetStatus:denied.status()};}finally{await ctx.close();}
 });
}finally{await writeFile(run+'/logout-native-boundary-evidence.json',JSON.stringify(evidence,null,2));await browser.close();}
console.log(JSON.stringify({checks:evidence.checks,errors:evidence.errors}));process.exitCode=evidence.checks.some(c=>c.result!=='PASS')||evidence.errors.length?1:0;