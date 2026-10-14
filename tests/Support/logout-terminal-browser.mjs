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
    if(process.env.LOGOUT_TERMINAL_FILTER && !new RegExp(process.env.LOGOUT_TERMINAL_FILTER).test(name)) return;
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
function cleanLeaks(start) { assert.equal(evidence.leaks.length, start, evidence.leaks.slice(start).join('\n')); }const { access } = await import('node:fs/promises');
const inspect = () => {const r=spawnSync('C:/tools/php85/php.exe',['tests/Support/LogoutOwnerInspect.php'],{encoding:'utf8',env:process.env});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);};
const capture = p => p.evaluate(async()=>{const app=document.getElementById('app'),pg=app?.__vue_app__?.config.globalProperties.$page;const hash=async v=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(v))))).map(v=>v.toString(16).padStart(2,'0')).join('');return {url:location.href,component:pg?.component,context:pg?.props.auth?.sessionContextId,receipt:pg?.props.clientVisibility,state:document.documentElement.dataset.visibility,visible:app?getComputedStyle(app).visibility:null,inert:app?.inert,sales:document.querySelector('[data-goals="select-sales"]')?.checked,key:await hash(sessionStorage.getItem('historyKey')),iv:await hash(sessionStorage.getItem('historyIv')),cipher:await hash(history.state?.page instanceof ArrayBuffer?Array.from(new Uint8Array(history.state.page)):history.state?.page),exit:document.getElementById('logout-document-exit')?.getAttribute('href'),terminalNotice:document.getElementById('logout-status')?.hidden===false};});
function unchangedKeys(a,b){for(const k of ['key','iv','cipher','context'])assert.deepEqual(b[k],a[k],k);}
function closed(s){assert.notEqual(s.state,'ready');assert.equal(s.visible,'hidden');assert.equal(s.inert,true);assert.equal(s.terminalNotice,true);assert.equal(s.exit,'/');}
async function setup(){const ctx=await context();const p=await ctx.newPage();await login(p);await ready(p);await draft(p);await ready(p);await p.waitForTimeout(200);await p.evaluate(()=>window.__sameDocument=crypto.randomUUID());return {ctx,p};}
async function trigger(p){await p.locator('.business-account summary').click();await p.locator('.business-account-menu button').click();}
async function wake(p){await p.evaluate(()=>{dispatchEvent(new Event('focus'));dispatchEvent(new Event('online'));document.dispatchEvent(new Event('visibilitychange'));document.getElementById('visibility-retry').click();});await p.waitForTimeout(150);}
async function blockDeparture(p){p.removeAllListeners('dialog');p.on('dialog',d=>d.type()==='beforeunload'?d.dismiss():d.accept());await p.evaluate(()=>{window.__blockDeparture=e=>{e.preventDefault();e.returnValue='';};addEventListener('beforeunload',window.__blockDeparture);});}
async function exitDocument(p){await p.evaluate(()=>removeEventListener('beforeunload',window.__blockDeparture));await p.locator('#logout-document-exit').click();await p.waitForURL(base+'/');await mounted(p);}
async function arrived(){for(let i=0;i<200;i++){try{await access(run+'/logout-arrived');return;}catch{await new Promise(r=>setTimeout(r,20));}}throw new Error('POST barrier not reached');}
async function waitRevocation(p,before){for(let i=0;i<200;i++){const rows=inspect(),revoked=rows.filter(s=>s.revoked_at&&before.some(b=>b.id===s.id&&!b.revoked_at));if(revoked.length)return revoked;await p.waitForTimeout(20);}throw new Error('Laravel revocation not observed');}
try {
 await check('Rejecting confirmation preserves draft and does not approve later ordinary departure','pre-handoff',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect();let posts=0,confirms=0;p.on('request',r=>{if(r.url()===base+'/logout'&&r.method()==='POST')posts++;});p.removeAllListeners('dialog');p.on('dialog',async d=>{confirms++;await d.dismiss();});await trigger(p);await p.waitForTimeout(150);assert.equal(posts,0);assert.deepEqual(await capture(p),before);assert.deepEqual(inspect(),sessions);await p.evaluate(()=>document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile'));await p.waitForTimeout(150);assert.equal(p.url(),goalUrl(fixture.first));assert.equal(confirms,2);assert.equal(posts,0);await assertDraft(p);await p.evaluate(()=>{const a=document.createElement('a');a.id='ordinary-document-probe';a.href='/profile';a.textContent='Leave document';document.body.append(a);});await p.locator('#ordinary-document-probe').click({noWaitAfter:true});await p.waitForTimeout(150);assert.equal(confirms,3);assert.equal(p.url(),goalUrl(fixture.first));assert.equal(posts,0);await assertDraft(p);p.removeAllListeners('dialog');p.on('dialog',d=>d.accept());await visit(p,base+'/profile');await ready(p);await historyStep(p,'back');await ready(p);await assertDraft(p);unchangedKeys(before,await capture(p));return {posts,confirmationCount:confirms,draftRestoredByRealBack:true,before,after:await capture(p)};}finally{await ctx.close();}
 });
 await check('Local missing CSRF validation does not hand off or seal','pre-handoff',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect();let posts=0;p.on('request',r=>{if(r.url()===base+'/logout'&&r.method()==='POST')posts++;});await ctx.clearCookies({name:'XSRF-TOKEN'});await trigger(p);await p.waitForTimeout(150);assert.equal(posts,0);assert.deepEqual(await capture(p),before);assert.deepEqual(inspect(),sessions);await assertDraft(p);return {posts,ready:true,keyMaterialUnchanged:true};}finally{await ctx.close();}
 });
 await check('Aborted wait followed by delayed Laravel revocation cannot reopen and repeated clicks create one POST','late POST',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect(),documentId=await p.evaluate(()=>window.__sameDocument);let posts=0;p.on('request',r=>{if(r.url()===base+'/logout'&&r.method()==='POST')posts++;});await p.route(base+'/logout',r=>r.continue({url:'http://localhost:8028/logout'}));await p.evaluate(()=>{const original=window.fetch;window.__logoutAbort=new AbortController();window.fetch=(url,options)=>{if(url==='/logout')return original(url,{...options,signal:window.__logoutAbort.signal}).then(r=>{window.__logoutSettled='response';return r;},e=>{window.__logoutSettled=e.name;throw e;});return original(url,options);};});await trigger(p);await arrived();closed(await capture(p));assert.deepEqual(inspect().map(s=>s.revoked_at),sessions.map(s=>s.revoked_at));await p.evaluate(()=>{window.__logoutAbort.abort();document.querySelector('.business-account-menu button').click();document.querySelector('.business-account-menu button').click();});await p.waitForFunction(()=>window.__logoutSettled==='AbortError');const probe=await p.request.get(goalUrl(fixture.first),{maxRedirects:0,headers:{'X-Inertia':'true','X-Builderad-Visibility':'1','X-Builderad-Visibility-Context':before.receipt.context}});assert.equal(probe.status(),200);await wake(p);closed(await capture(p));unchangedKeys(before,await capture(p));await writeFile(run+'/logout-release','1');const revoked=await waitRevocation(p,sessions);const denied=await p.request.get(goalUrl(fixture.first),{maxRedirects:0});assert.ok([302,303,401,403].includes(denied.status()));await wake(p);const after=await capture(p);closed(after);unchangedKeys(before,after);assert.equal(posts,1);assert.equal(await p.evaluate(()=>window.__sameDocument),documentId);return {posts,clientOutcome:'AbortError',freshProbeStatus:probe.status(),revokedSessionId:revoked[0].id,protectedGetStatus:denied.status(),before,after,fixture:'second real Laravel worker pauses before unchanged route; browser aborts only its wait'};}finally{await writeFile(run+'/logout-release','1');await ctx.close();}
 });
 await check('Unknown network failure remains closed and the document exit works','unknown transport',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect();await p.route(base+'/logout',r=>r.abort('failed'));await trigger(p);await p.waitForTimeout(300);closed(await capture(p));unchangedKeys(before,await capture(p));assert.deepEqual(inspect(),sessions);const probe=await p.request.get(goalUrl(fixture.first),{maxRedirects:0});assert.equal(probe.status(),200);await wake(p);closed(await capture(p));assert.match(await p.locator('#logout-status').innerText(),/لم نؤكد/);await exitDocument(p);return {protectedGetStatus:probe.status(),sessionNotRevoked:true,exitUrl:p.url(),newDocument:await p.evaluate(()=>!window.__sameDocument),unknownWasNotReportedAsRevoked:true};}finally{await ctx.close();}
 });
 await check('Real revocation with blocked departure leaves hidden inert Goals and a working exit','server revocation',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect();await blockDeparture(p);await trigger(p);const revoked=await waitRevocation(p,sessions);await p.waitForTimeout(300);const after=await capture(p);closed(after);unchangedKeys(before,after);assert.equal(after.url,before.url);await exitDocument(p);const denied=await p.request.get(goalUrl(fixture.first),{maxRedirects:0});assert.ok([302,303,401,403].includes(denied.status()));return {revokedSessionId:revoked[0].id,after,documentExitUrl:p.url(),protectedGetStatus:denied.status(),fixture:'additional test-only beforeunload listener denies automatic departure'};}finally{await ctx.close();}
 });
 await check('Real CSRF 419 is not treated as revoked and cannot reopen the handed-off document','CSRF',async()=>{
  const {ctx,p}=await setup();try{const before=await capture(p),sessions=inspect();await p.route(base+'/logout',async r=>{const actual=await r.fetch({headers:{...r.request().headers(),'sec-fetch-site':'cross-site','x-xsrf-token':'invalid-proof-token'},maxRedirects:0});await r.fulfill({response:actual});});const response=p.waitForResponse(r=>r.url()===base+'/logout');await trigger(p);assert.equal((await response).status(),419);await wake(p);closed(await capture(p));unchangedKeys(before,await capture(p));assert.deepEqual(inspect(),sessions);assert.equal((await p.request.get(goalUrl(fixture.first),{maxRedirects:0})).status(),200);assert.match(await p.locator('#logout-status').innerText(),/لم نؤكد/);return {status:419,sessionUnrevoked:true,oldDocumentClosed:true,fixture:'real Laravel response proxied to Chromium after invalid token and cross-site metadata exercise the existing origin/token fallback'};}finally{await ctx.close();}
 });
 for(const target of ['/login','/'])await check('Foreign Inertia location '+target+' cannot own logout or change its exit','foreign outcome',async()=>{
  const {ctx,p}=await setup();let release;try{const before=await capture(p),sessions=inspect();const held=new Promise(r=>release=r);await p.route(base+'/logout',async r=>{await held;await r.abort('failed');});await blockDeparture(p);await trigger(p);closed(await capture(p));await p.route(base+'/profile',r=>r.fulfill({status:409,headers:{'X-Inertia-Location':base+target},body:''}));const response=p.waitForResponse(r=>r.url()===base+'/profile'&&r.status()===409);await p.evaluate(()=>document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit('/profile',{async:true}));await response;await p.waitForTimeout(300);const after=await capture(p);closed(after);unchangedKeys(before,after);assert.equal(after.exit,'/');assert.deepEqual(inspect(),sessions);release();await p.waitForTimeout(150);closed(await capture(p));return {foreignTarget:target,logoutExit:after.exit,keyMaterialUnchanged:true,sessionUnrevoked:true,fixture:'test-only 409 transport response; real official Inertia location handler; automatic document departure denied'};}finally{release?.();await ctx.close();}
 });
 await check('Successful owned logout is a new document; same-user new session and another user do not receive draft; real Back observed','isolation and history',async()=>{
  const {ctx,p}=await setup();try{const cacheMisses=[];const cdp=await ctx.newCDPSession(p);await cdp.send('Page.enable');cdp.on('Page.backForwardCacheNotUsed',event=>cacheMisses.push(event));const old=await capture(p),sessions=inspect();await trigger(p);await p.waitForURL(base+'/');await mounted(p);const revoked=await waitRevocation(p,sessions);assert.equal(await p.evaluate(()=>!window.__sameDocument),true);await login(p);await ready(p);await visit(p,goalUrl(fixture.first));await ready(p);const fresh=await capture(p);assert.notEqual(fresh.context,old.context);assert.equal(await control(p,'select-sales').isChecked(),false);await trigger(p);await p.waitForURL(base+'/');await mounted(p);await login(p,'b');await ready(p);await arm(p,true);const leaks=evidence.leaks.length;const denied=await p.request.get(goalUrl(fixture.first),{maxRedirects:0});assert.equal(denied.status(),403);const backBefore=evidence.pageshow.length;for(let i=0;i<5;i++)await historyStep(p,'back');cleanLeaks(leaks);const shows=evidence.pageshow.slice(backBefore),persisted=shows.some(s=>s.persisted&&s.url===goalUrl(fixture.first));return {revokedSessionId:revoked[0].id,sameUserFreshDraftEmpty:true,otherUserDenied:denied.status(),sampledLeaks:0,realBack:true,backSteps:5,pageshow:shows,cacheMisses,terminalGoalsBFCache:persisted?'OBSERVED':'UNPROVEN: no persisted=true terminal Goals restoration observed'};}finally{await ctx.close();}
 });
}finally{await writeFile(run+'/logout-release','1');await writeFile(run+'/logout-terminal-evidence.json',JSON.stringify(evidence,null,2));await browser.close();}
console.log(JSON.stringify({checks:evidence.checks,errors:evidence.errors}));process.exitCode=evidence.checks.some(c=>c.result!=='PASS')||evidence.errors.length?1:0;