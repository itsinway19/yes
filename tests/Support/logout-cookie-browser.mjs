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
const base = 'http://localhost:8030';
const goalUrl = id => base + '/workspaces/' + id + '/goals';
const aName = 'نشاط اختبار أهداف المعلن';
const evidence = { environment: { base, database: process.env.DB_DATABASE, port: process.env.DB_PORT }, mechanism: 'Official Inertia plus Client Visibility Boundary', checks: [], errors: [], leaks: [], responses: [], pageshow: [] };
import http from 'node:http';
import { createHash } from 'node:crypto';
const cookieProofMode=process.env.COOKIE_PROOF_MODE || 'baseline';
const held=new Map(), outgoing=[];
const cookieHash=v=>createHash('sha256').update(v).digest('hex');
const cookieSummary=cs=>cs.map(({value,...c})=>({...c,valueSHA256:cookieHash(value)}));
const headerSummary=headers=>({...headers,'set-cookie':(headers['set-cookie']||[]).map(v=>{const split=v.indexOf(';'), first=split<0?v:v.slice(0,split), eq=first.indexOf('=');return {name:first.slice(0,eq),valueSHA256:cookieHash(first.slice(eq+1)),attributes:split<0?'':v.slice(split+1)};})});
const proxy=http.createServer((req,res)=>{
 const upstream=http.request({host:'localhost',port:8027,path:req.url,method:req.method,headers:req.headers},r=>{
  const chunks=[];r.on('data',c=>chunks.push(c));r.on('end',()=>{
   const record={url:req.url,method:req.method,secFetchSite:req.headers['sec-fetch-site'],status:r.statusCode,headers:headerSummary(r.headers)};
   if(new URL(req.url,'http://localhost').pathname==='/logout')outgoing.push(record);
   const deliver=()=>{res.writeHead(r.statusCode,r.headers);res.end(Buffer.concat(chunks));};
   const id=req.headers['x-cookie-proof-hold']||new URL(req.url,'http://localhost').searchParams.get('cookie-proof-hold');
   if(id) held.set(id,{deliver,record});else deliver();
  });
 });upstream.on('error',e=>{res.writeHead(502);res.end('Isolated upstream unavailable');});req.pipe(upstream);
});
await new Promise((resolve,reject)=>{proxy.once('error',reject);proxy.listen(8030,'localhost',resolve);});
async function awaitHeld(id){for(let i=0;i<500;i++){if(held.has(id))return held.get(id);await new Promise(r=>setTimeout(r,20));}throw new Error('Response hold not reached');}
const sessionCookie=async ctx=>(await ctx.cookies()).find(c=>c.httpOnly&&c.name.endsWith('-session')&&!c.name.includes('-internal-'));
// Separate same-site origin creates a genuine Chromium CSRF-rejected POST.
const crossSite=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html');res.end('<form method="POST" action="http://localhost:8030/logout?cookie-proof-hold=reject-old"><input name="_token" value="invalid-proof-token"><button>Rejected POST</button></form>');});
await new Promise((resolve,reject)=>{crossSite.once('error',reject);crossSite.listen(8031,'localhost',resolve);});
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
 for(const failure of [false,true]) await check(failure?'Old 419 cannot replace a newer authenticated cookie':'Old successful logout revokes A without replacing a newer cookie','cookie isolation',async()=>{
  const rowIdsBefore=new Set(inspect().map(s=>s.id)); const {ctx,p}=await setup();
  try {
   await blockDeparture(p);
   await p.evaluate(()=>{const original=window.fetch;window.fetch=async(...args)=>{try{const r=await original(...args);if(new URL(String(args[0]),location.href).pathname==='/logout')window.__cookieProofOutcome={type:r.type,status:r.status};return r;}catch(e){if(new URL(String(args[0]),location.href).pathname==='/logout')window.__cookieProofOutcome={error:e.name};throw e;}};});
   assert.equal((await ctx.request.get(base+'/internal/login',{maxRedirects:0})).status(),200);
   const internalBefore=(await ctx.cookies()).find(c=>c.name.includes('-internal-session'));assert.ok(internalBefore);
   const originalCookies=await ctx.cookies(), rowsA=inspect().filter(s=>s.user_id===fixture.a&&!s.revoked_at&&!rowIdsBefore.has(s.id));
   const id=failure?'reject-old':'success-old';
   await p.route(base+'/logout',r=>r.continue({headers:{...r.request().headers(),'x-cookie-proof-hold':id,...(failure?{'sec-fetch-site':'cross-site','x-xsrf-token':'invalid-proof-token'}:{})}}));
   if(failure){const rejectPage=await ctx.newPage();await rejectPage.goto('http://localhost:8031');await rejectPage.locator('button').click({noWaitAfter:true});}
else await trigger(p);
const old=await awaitHeld(id); if(!failure)closed(await capture(p));
   assert.equal(old.record.status,failure?419:302);
   assert.equal(old.record.secFetchSite,failure?'same-site':'same-origin');
   if(failure){const token=(await ctx.cookies()).find(c=>c.name==='XSRF-TOKEN'&&c.path==='/');const ended=await ctx.request.post(base+'/logout',{headers:{'X-XSRF-TOKEN':decodeURIComponent(token.value),Accept:'application/json'},maxRedirects:0});assert.equal(ended.status(),302);}
   assert.ok(rowsA.every(a=>inspect().some(s=>s.id===a.id&&s.revoked_at)),'A really revoked before new login');
   const oldDraft=failure?await capture(p):undefined;if(failure)await p.close();
   const p2=await ctx.newPage(); await login(p2,'b'); await ready(p2);
   const protectedBefore=await ctx.request.get(base+'/profile',{maxRedirects:0});assert.equal(protectedBefore.status(),200);
   const beforeCookie=await sessionCookie(ctx), bRows=inspect().filter(s=>s.user_id===fixture.b&&!s.revoked_at);
   const before=failure?oldDraft:await capture(p);
   old.deliver(); held.delete(id); if(!failure)await p.waitForFunction(()=>window.__cookieProofOutcome); await p2.waitForTimeout(150);
   const internalAfter=(await ctx.cookies()).find(c=>c.name===internalBefore.name);assert.equal(internalAfter.value,internalBefore.value);
   const afterCookie=await sessionCookie(ctx), preserved=beforeCookie.value===afterCookie.value;
   const protectedAfter=await ctx.request.get(base+'/profile',{maxRedirects:0});
   assert.ok(bRows.every(b=>inspect().some(s=>s.id===b.id&&!s.revoked_at)));
   if(!failure){closed(await capture(p));unchangedKeys(before,await capture(p));}
   const sentSessionCookie=old.record.headers['set-cookie'].some(c=>c.name===beforeCookie.name);
   assert.equal(preserved,cookieProofMode!=='baseline');assert.equal(protectedAfter.status(),cookieProofMode==='baseline'?302:200);assert.equal(sentSessionCookie,cookieProofMode==='baseline');
   return {mode:cookieProofMode,originalCookies:cookieSummary(originalCookies),newCookiesBefore:cookieSummary([beforeCookie]),newCookiesAfter:cookieSummary([afterCookie]),heldResponse:old.record,protectedBefore:protectedBefore.status(),protectedAfter:protectedAfter.status(),newCookiePreserved:preserved,internalCookieUnchanged:true,authorityRows:inspect(),oldDocumentTerminal:!failure,keyMaterialUnchanged:true};
  } finally {await ctx.close();}
 });
 await check('Ordinary logout denies the original session and a guest 419 does not authenticate','ordinary and rejected logout',async()=>{
  const rowIdsBefore=new Set(inspect().map(s=>s.id)); const {ctx,p}=await setup();
  try {
   const original=await ctx.cookies(), rows=inspect().filter(s=>s.user_id===fixture.a&&!s.revoked_at&&!rowIdsBefore.has(s.id));
   await logout(p);
   assert.ok(rows.every(a=>inspect().some(s=>s.id===a.id&&s.revoked_at)));
   const replay=await browser.newContext();await replay.addCookies(original);const denied=await replay.request.get(base+'/profile',{maxRedirects:0});assert.equal(denied.status(),302);await replay.close();
   const guest=await browser.newContext();const rejected=await guest.request.post(base+'/logout',{headers:{Accept:'application/json','X-XSRF-TOKEN':'invalid-proof-token','Sec-Fetch-Site':'cross-site'},maxRedirects:0});assert.equal(rejected.status(),419);
   const noAuthority=await guest.request.get(base+'/profile',{maxRedirects:0});assert.equal(noAuthority.status(),302);await guest.close();
   const own=outgoing.slice(-2);if(cookieProofMode!=='baseline')for(const r of own)assert.ok(!r.headers['set-cookie'].some(c=>c.name.endsWith('-session')&&!c.name.includes('-internal-')));
   return {oldAuthorityRevoked:true,oldCookieProtectedStatus:denied.status(),guest419:rejected.status(),guestProtected:noAuthority.status(),finalLogoutResponses:own};
  } finally{await ctx.close();}
 });
} finally {
 for(const pending of held.values())pending.deliver();held.clear();
 await browser.close();await new Promise(resolve=>proxy.close(resolve));await new Promise(resolve=>crossSite.close(resolve));
 await writeFile(run+'/logout-cookie-evidence.json',JSON.stringify({...evidence,mode:cookieProofMode,outgoing},null,2));
}
if(evidence.checks.some(c=>c.result==='FAIL')||evidence.errors.length)process.exitCode=1;