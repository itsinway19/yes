import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.GOALS_SPA_ONLY !== 'businesses') throw Error('Guarded Business browser entry required');
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN, origin = 'http://localhost:8136';
const result = { scope: 'Independent Business reference flow; legacy cases may use synthetic qualification roots; internal-team enrollment uses the operating limited issuer; no live migration or external authority proof', cases: [], errors: [], assets: [] };
let activePage;
result.http = []; result.failedRequests = [];
function fixture(mode) {
    const p = spawnSync('C:/tools/php85/php.exe', ['tests/Support/BusinessSurfaceFixture.php', mode], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
    assert.equal(p.status, 0, p.stderr); let receipt; try { receipt = JSON.parse(p.stdout); } catch { throw Error('Fixture failed: '+p.stdout.slice(0, 900)); } assert.equal(receipt.status, 'completed');
}
async function check(name, fn) {
    // A bounded rerun retains the real login/reference setup. Other checks are
    // omitted explicitly, never reported as passed or added to the case count.
    if (process.env.GOALS_SPA_CASE_FILTER && !new RegExp(process.env.GOALS_SPA_CASE_FILTER).test(name)
        && name !== 'actual login, independent creation, list and opening without a Workspace identity') return;
    const row = { name }; result.cases.push(row);
    try { row.evidence = await fn(); row.result = 'PASS'; console.log('PASS '+name); }
    catch (e) {
        row.result = 'FAIL'; row.error = e.stack;
        if (activePage && !activePage.isClosed()) {
            row.presentation = await activePage.evaluate(() => ({ path: location.pathname, href: location.href, hash: location.hash, state: document.querySelector('[data-application-spa]')?.getAttribute('data-state'),
                text: document.body.innerText.slice(0, 2500), resources: performance.getEntriesByType('resource').slice(-16).map(r => ({ name: new URL(r.name).pathname, duration: r.duration })) })).catch(error => ({ captureError: error.message }));
            await activePage.screenshot({ path: path.join(run, 'business-failure.png'), fullPage: true }).catch(() => {});
        }
        throw e;
    }
    finally { await writeFile(path.join(run, 'browser-results.json'), JSON.stringify(result, null, 2)); }
}
fixture('setup');
const delegated = JSON.parse(await readFile(path.join(run, 'business-surface-fixture.json'), 'utf8'));
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
let release;
try {
    result.browser = browser.version();
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ar-JO' });
    const page = await context.newPage(); page.setDefaultTimeout(20000);
    activePage = page;
    page.on('pageerror', e => result.errors.push(e.message));
    page.on('requestfailed', request => { result.failedRequests.push({ path: new URL(request.url()).pathname, failure: request.failure()?.errorText }); });
    page.on('response', response => {
        if (!response.url().includes('/build/')) {
            result.http.push({ path: new URL(response.url()).pathname, status: response.status(), timing: response.request().timing() });
            if (result.http.length > 200) result.http.shift();
        }
    });
    page.on('response', async response => { if (response.url().includes('/build/') && response.status() === 200) {
        const bytes = await response.body().catch(() => null); if (bytes) result.assets.push({ url: response.url(), sha256: createHash('sha256').update(bytes).digest('hex') });
    } });
    const ready = async (selector = '[data-business-surface]') => {
        await page.waitForFunction(selector => document.querySelector('[data-application-spa]')?.dataset.state === 'ready' && !!document.querySelector(selector), selector);
    };
    const index = async () => { await ensureWorkspaceNavigation(); await page.locator('[data-advertiser-shell] a[href="/businesses"]').first().click(); await page.waitForURL(origin+'/businesses'); await ready(); };
    const open = async (name,section='settings') => { await page.locator('[data-business-list] li').filter({ has: page.getByRole('heading', { name, exact: true }) }).getByRole('link', { name: 'فتح النشاط', exact: true }).click(); await ready('[data-business-public]'); await workspaceSection(section); };
    // Full-run scenarios deliberately rename this Business. Its display label is
    // not its identity; later scenarios must open that same immutable resource.
    const openCreated = async (section) => { await page.locator(`[data-business-list] a[href^="/businesses/${result.createdBusiness}?"]`).click(); await ready('[data-business-public]'); assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),result.createdBusiness); if(section)await workspaceSection(section); };
    const sendFrom = async (tab, url, method = 'GET', body) => tab.evaluate(async ({ url, method, body }) => {
        const identity = JSON.parse(document.getElementById('application-bootstrap').textContent).identity;
        const response = await fetch(url, { method, credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': identity.csrf, 'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition }, body: body ? JSON.stringify(body) : undefined });
        return { status: response.status, body: await response.json() };
    }, { url, method, body });
    const send = (url, method = 'GET', body) => sendFrom(page, url, method, body);
    await check('actual login, independent creation, list and opening without a Workspace identity', async () => {
        await page.goto(origin+'/login');
        const logged = await send('/login', 'POST', { email: 'goals-b@example.test', password: 'DisposableGoalsProof42!' }); assert.equal(logged.status, 200);
        assert.equal(logged.body.redirect, '/dashboard'); await page.goto(origin+logged.body.redirect); await ready('[data-business-public]');
        assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),delegated.business,'A single qualified existing business opens after login without creating another');
        assert.equal((await send('/api/businesses')).body.data.items.length, 1);
        await index();
        await page.locator('main').getByRole('link', { name: 'إضافة نشاط', exact: true }).click(); await ready('#bf-create-name');
        await page.getByLabel('اسم النشاط', { exact: true }).fill('نشاط مستقل للمتصفح');
        await page.locator('#bf-create-type').selectOption({ index: 1 });
        const selectedType = await page.locator('#bf-create-type').inputValue();
        await page.locator('#bf-create-market-search').fill('SA');
        await page.locator('.setup-countries input[value="SA"]').check();
        await page.locator('#bf-create-market-search').fill('no-country-matches-this-query');
        await page.locator('[data-create-market-search-empty]').waitFor();
        await page.locator('[data-create-market-search-clear]').click();
        assert.equal(await page.locator('#bf-create-market-search').evaluate(input=>document.activeElement===input),true);
        assert.equal(await page.locator('.setup-countries input[value="SA"]').isChecked(),true,'Clearing search must not clear the selected target market');
        await page.getByRole('button', { name: 'إنشاء النشاط والمتابعة', exact: true }).click();
        await ready('[data-business-public]');
        assert.equal(await page.locator('[data-business-name]').textContent(), 'نشاط مستقل للمتصفح');
        await ensureWorkspaceNavigation();
        await page.locator('[data-workspace-section="settings"]').click();
        assert.equal(await page.locator('#bf-type').inputValue(), selectedType);
        result.createdBusiness = await page.locator('[data-business-public]').getAttribute('data-business-public');
        assert.deepEqual((await send(`/api/businesses/${result.createdBusiness}/surface?access=${new URL(page.url()).searchParams.get('access')}`)).body.data.business.declared_markets,['SA']);
        assert.notEqual(result.createdBusiness, delegated.business);
        return { createdBusiness: result.createdBusiness, sharedWorkspaceInference: false };
    });
    await check('accepted Vue shell switches only qualified Businesses in sidebar and mobile drawer without a document reload', async () => {
        const before=(await send('/api/businesses')).body.data;
        const desktop=page.locator('.advertiser-sidebar [data-business-switch]');
        await desktop.waitFor();
        await page.waitForFunction(count=>document.querySelector('.advertiser-sidebar [data-business-switch]')?.options.length>=count,before.items.length);
        const candidates=await desktop.locator('option').evaluateAll(options=>options.map(option=>({value:option.value,label:option.textContent})));
        for(const item of before.items)assert.ok(candidates.some(option=>JSON.parse(option.value)[0]===item.business_public_id&&JSON.parse(option.value)[1]===item.read_grant_id), 'Each switch option names the exact qualified resource and access path');
        const created=before.items.find(item=>item.business_public_id===result.createdBusiness);
        const other=before.items.find(item=>item.business_public_id===delegated.business);
        assert.ok(created&&other);
        const choice=item=>candidates.find(option=>JSON.parse(option.value)[0]===item.business_public_id&&JSON.parse(option.value)[1]===item.read_grant_id).value;
        const documentOrigin=await page.evaluate(()=>performance.timeOrigin);
        await desktop.selectOption(choice(other));await ready('[data-business-public]');
        await page.waitForFunction(id=>document.querySelector('[data-business-public]')?.getAttribute('data-business-public')===id,other.business_public_id);
        assert.equal(await page.evaluate(()=>performance.timeOrigin),documentOrigin);
        assert.ok(!(await page.locator('[data-business-workspace]').innerText()).includes('نشاط مستقل للمتصفح'),'The former Business private content is not reused');
        await page.goBack();await ready('[data-business-public]');
        await page.waitForFunction(id=>document.querySelector('[data-business-public]')?.getAttribute('data-business-public')===id,result.createdBusiness);
        await page.setViewportSize({width:390,height:850});
        const toggle=page.locator('[data-advertiser-menu-toggle]');await toggle.click();
        const drawer=page.locator('[data-advertiser-drawer]');await drawer.waitFor({state:'visible'});
        const mobile=drawer.locator('[data-business-switch]');await mobile.selectOption(choice(other));
        await ready('[data-business-public]');await page.waitForFunction(id=>document.querySelector('[data-business-public]')?.getAttribute('data-business-public')===id,other.business_public_id);
        assert.equal(await drawer.evaluate(element=>element.open),false);
        assert.equal(await page.locator('.advertiser-sidebar').isVisible(),false);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
        await page.screenshot({path:path.join(run,'stage1-shell-mobile.png'),fullPage:true});
        await toggle.click();await drawer.locator('[data-business-switch]').selectOption(choice(created));await ready('[data-business-public]');
        await page.waitForFunction(id=>document.querySelector('[data-business-public]')?.getAttribute('data-business-public')===id,result.createdBusiness);
        await toggle.click();await page.setViewportSize({width:1280,height:900});
        await page.waitForFunction(()=>!document.querySelector('[data-advertiser-drawer]')?.open);
        await page.screenshot({path:path.join(run,'stage1-shell-desktop.png'),fullPage:true});
        return{qualifiedOptions:before.items.length,realAPI:true,mobileDrawer:true,sameDocument:documentOrigin===await page.evaluate(()=>performance.timeOrigin),noWorkspaceCreation:true};
    });
    await check('qualified draft archive recovers a real stale-revision conflict and reconciles a lost acknowledgement once', async () => {
        const created = await send('/api/businesses', 'POST', {command_id:crypto.randomUUID(),name:'مسودة اختبار الأرشفة'});
        assert.equal(created.status,201);
        const draft=created.body.data, business=draft.business_public_id;
        const access=draft.grant_refs['business.context.read'];
        const surface=(await send(`/api/businesses/${business}/surface?access=${access}`)).body.data;
        assert.deepEqual(surface.lifecycle.archive_options,[{grant_id:String(draft.grant_refs['business.draft.archive'])}]);
        const pageRoute=url=>url.pathname===`/businesses/${business}`;
        const staleSurface=async route=>{
            const headers=route.request().headers();
            if(headers.accept!=='application/json' || headers['x-builderad-witness']==='1') return route.continue();
            const response=await route.fetch();assert.equal(response.status(),200);
            const snapshot=await response.json();
            assert.equal(snapshot.data.lifecycle.revision,surface.lifecycle.revision);
            // Simulate a stale tab without changing SQL or weakening the real writer.
            snapshot.data.lifecycle.revision++;
            await route.fulfill({response,json:snapshot});
        };
        await page.route(pageRoute,staleSurface);
        try {
            await page.goto(`${origin}/businesses/${business}?access=${access}&section=settings&settings=lifecycle`);
            await ready('[data-business-lifecycle]');
        } finally { await page.unroute(pageRoute,staleSurface); }
        await page.locator('.advertiser-sidebar [data-business-choices-loading]').waitFor({state:'hidden'});
        await page.screenshot({path:path.join(run,'stage2-lifecycle-desktop.png'),fullPage:true});
        const sent=[];
        const endpoint=`**/api/businesses/${business}/lifecycle`;
        const intercept=async route=>{
            const command=route.request().postDataJSON();sent.push(command);
            const response=await route.fetch();
            assert.equal(response.status(),sent.length===1?409:200);
            if(sent.length===2)await route.fulfill({status:503,contentType:'application/json',body:'{"error":"synthetic_ack_lost_after_commit"}'});
            else await route.fulfill({response});
        };
        const archiveResponse=()=>page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${business}/lifecycle`&&response.request().method()==='POST');
        await page.route(endpoint,intercept);
        try {
            await page.locator('[data-business-archive]').click();
            await page.locator('[data-business-archive-cancel]').waitFor();
            assert.equal(await page.locator('[data-business-archive-cancel]').evaluate(element=>document.activeElement===element),true);
            await page.screenshot({path:path.join(run,'stage2-lifecycle-confirm-desktop.png'),fullPage:true,animations:'disabled'});
            await page.locator('[data-business-archive-cancel]').click();
            await page.locator('[data-business-archive-confirm]').waitFor({state:'hidden'});
            assert.equal(sent.length,0,'Cancel does not send an archive command');
            assert.equal(await page.locator('[data-business-archive]').evaluate(element=>document.activeElement===element),true);
            await page.locator('[data-business-archive]').click();
            const rejected=archiveResponse();
            await page.locator('[data-business-archive-confirm]').click();
            const conflict=await rejected;
            assert.equal(conflict.status(),409);assert.deepEqual(await conflict.json(),{error:'business_conflict'});
            await page.locator('[data-business-lifecycle-conflict]').waitFor();
            assert.equal(await page.locator('[data-business-archive]').isDisabled(),true);
            assert.equal(sent.length,1);assert.equal(sent[0].expected_revision,surface.lifecycle.revision+1);
            await page.locator('[data-business-lifecycle-adopt]').click();
            await page.locator('[data-business-archive]').click();
            const lost=archiveResponse();
            await page.locator('[data-business-archive-confirm]').click();
            assert.equal((await lost).status(),503);
            await page.locator('[data-business-archive-unknown]').waitFor();
            await page.locator('[data-business-archive-confirm]').waitFor({state:'hidden'});
            assert.equal(await page.locator('[data-business-archive]').count(),0,'An unknown original cannot be replaced');
            assert.equal(sent.length,2);assert.notEqual(sent[0].command_id,sent[1].command_id);
            assert.equal(sent[1].expected_revision,surface.lifecycle.revision);
            assert.deepEqual(sent[1].authority_selection.requirements,[{requirement_kind:'business',leaf_grant_id:draft.grant_refs['business.draft.archive']}]);
            await page.setViewportSize({width:390,height:850});
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
            const activeTab=await page.locator('.workspace-local-navigation [aria-current="page"]').boundingBox();
            assert.ok(activeTab && activeTab.x>=0 && activeTab.x+activeTab.width<=390 && activeTab.height>=44,'The active settings tab must fit within the mobile viewport and remain a 44px target');
            await page.screenshot({path:path.join(run,'stage2-lifecycle-mobile-unknown.png'),fullPage:true});
            await page.locator('[data-business-archive-reconcile]').click();
            await page.waitForURL(origin+'/businesses');await ready();
            assert.equal(sent.length,3);assert.deepEqual(sent[2],sent[1]);
            assert.equal((await send(`/api/businesses/${business}/surface?access=${access}`)).status,403);
            const choices=(await send('/api/businesses')).body.data.items;
            assert.ok(!choices.some(item=>item.business_public_id===business));
            assert.ok(choices.some(item=>item.business_public_id===result.createdBusiness));
        } finally {await page.unroute(endpoint,intercept);await page.setViewportSize({width:1280,height:900});}
        await openCreated('settings');
        return{realArchive:true,realConflict:true,explicitRevisionAdoption:true,lostAckAfterCommit:true,exactCommandReplay:true,matchingReceipt:true,originalBusinessPreserved:true};
    });
    async function sharedSourceReturnCheck() {
    await check('shared-source return survives actual OAuth success and cancel, fresh documents and revoked Business authority', async () => {
        fixture('source-setup'); fixture('shared-return-setup');
        const target = JSON.parse(await readFile(path.join(run, 'shared-return-fixture.json'), 'utf8'));
        const businessPath = `/businesses/${target.business}?access=${target.access}&section=sources`;
        const organizationPath = `/workspaces/${target.workspace}`;
        const returnLink = page.locator('[data-business-return]');
        const assertReturn = async () => {
            await ready('[data-sources-workspace]');
            await returnLink.waitFor();
            assert.equal(await returnLink.getAttribute('href'), businessPath);
            const hints = await page.evaluate(() => Object.entries(sessionStorage).filter(([key]) => key.startsWith('builderad.account-return.v1:')).map(([, value]) => JSON.parse(value)));
            assert.equal(hints.length, 1);
            assert.deepEqual(Object.keys(hints[0]).sort(), ['access', 'business', 'workspace']);
            assert.equal(hints[0].business, target.business);
        };
        const enter = async (label) => {
            await page.goto(origin + businessPath); await ready('[data-business-source-inventory]');
            await page.locator('[data-business-source-inventory-details] > summary').click();
            await page.getByRole('link', { name: label, exact: true }).first().click();
            await page.waitForURL(origin + organizationPath); await assertReturn();
        };
        await enter('إدارة المصدر');
        await page.reload(); await assertReturn();
        await page.goto(origin + `/workspaces/${target.other_workspace}`); await ready('[data-sources-workspace]');
        assert.equal(await returnLink.count(), 0, 'A different organization cannot consume this return hint');
        await page.goto(origin + organizationPath); await assertReturn();
        assert.equal((await send(`/workspaces/${target.other_identity_workspace}/providers/google`)).status, 403,
            'A Business return hint cannot grant source authority in another organization');
        const external = url => url.hostname === 'accounts.google.com';
        await page.route(external, async route => {
            const state = new URL(route.request().url()).searchParams.get('state'); assert.ok(state);
            const callback = origin + '/providers/google/callback?state=' + encodeURIComponent(state);
            await route.fulfill({ contentType: 'text/html', body: `<main data-synthetic-oauth><a href="${callback}&error=access_denied">Cancel synthetic OAuth</a><a href="${callback}&code=synthetic-code">Authorize synthetic OAuth</a></main>` });
        });
        const outcomes = [];
        try {
            for (const outcome of ['Cancel', 'Authorize']) {
                const snapshot = await send(organizationPath + '/providers/google');
                assert.equal(snapshot.status, 200);
                assert.equal(snapshot.body.availability, 'CONNECT_AVAILABLE');
                await page.locator('[data-provider="google"] button').click();
                await page.locator('[data-source-connect="google"]:visible').click();
                await page.locator('[data-synthetic-oauth]').waitFor();
                await page.getByRole('link', { name: outcome + ' synthetic OAuth', exact: true }).click();
                await page.waitForURL(url => url.origin === origin && url.pathname === organizationPath);
                await assertReturn();
                await page.reload(); await assertReturn();
                const dialog = page.locator('dialog[open]');
                if (await dialog.count()) await dialog.getByRole('button', { name: /^إغلاق/ }).click();
                await returnLink.click(); await ready('[data-business-public]');
                assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'), target.business);
                await page.locator('[data-business-source-inventory]').waitFor();
                assert.equal((await send(`/api/businesses/${result.createdBusiness}/surface?access=${target.access}`)).status, 403,
                    'The original grant cannot admit a different Business');
                outcomes.push(outcome);
                await enter('فتح الجهة');
            }
        } finally { await page.unroute(external); }
        fixture('shared-return-revoke');
        await page.reload(); await assertReturn();
        const denied = page.waitForResponse(response => new URL(response.url()).pathname === `/businesses/${target.business}`);
        await returnLink.click(); assert.equal((await denied).status(), 403);
        await page.waitForFunction(() => document.querySelector('[data-application-spa]')?.dataset.state === 'ready');
        assert.equal(await page.locator('[data-business-public]').count(), 0, 'The hint cannot restore revoked Business access');
        assert.equal(new URL(page.url()).pathname, organizationPath, 'A denied destination keeps the qualified source page');
        await page.goto(origin + organizationPath); await assertReturn();
        assert.equal((await send('/logout', 'POST')).status, 200);
        await page.goto(origin + '/login');
        assert.equal((await send('/login', 'POST', { email: 'goals-a@example.test', password: 'DisposableGoalsProof42!' })).status, 200);
        await page.goto(origin + `/workspaces/${target.other_identity_workspace}`); await ready('[data-sources-workspace]');
        assert.equal(await returnLink.count(), 0);
        assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter(key => key.startsWith('builderad.account-return.v1:')).length), 0);
        fixture('shared-return-finish');
        return { outcomes, newDocuments: true, wrongOrganizationRejected: true, wrongBusinessGrantRejected: true,
            revokedBusinessDenied: true, changedIdentityCleared: true, authority: 'real server; synthetic provider responses only' };
    });
    }
    await check('Business advertising accounts open directly in the activity with five brands and preserve a simulated cancellation return', async()=>{
        await workspaceSection('sources');
        assert.equal((await page.locator('[data-workspace-heading]').textContent()).trim(),'المصادر');
        const accounts=page.locator('[data-business-source-inventory] [data-advertising-accounts]');await accounts.waitFor();
        const platformIds=await accounts.locator('[data-provider]').evaluateAll(nodes=>nodes.map(node=>node.dataset.provider));assert.deepEqual(platformIds,['google','meta','snapchat','tiktok','x']);
        await page.waitForFunction(()=>[...document.querySelectorAll('[data-business-source-inventory] .provider-brand img')].every(image=>image.complete&&image.naturalWidth>0));
        await page.waitForFunction(()=>document.querySelectorAll('[data-business-source-inventory] [data-state="loading"]').length===0);
        assert.equal(await accounts.locator('[data-state="unselected"]').count(),0,'The Business is already the source context');
        assert.equal(await page.locator('#business-account-administration,[data-account-administration-heading]').count(),0);
        const businessUrl=page.url();
        for(const provider of platformIds){
            const snapshot=await send('/businesses/'+result.createdBusiness+'/providers/'+provider);
            assert.equal(snapshot.status,200);assert.deepEqual(snapshot.body.connections,[],'Brand visibility cannot invent an account or data permission');
        }
        await page.setViewportSize({width:390,height:844});
        const xTrigger=accounts.locator('[data-provider="x"] button');await xTrigger.click();
        const dialog=accounts.locator('dialog');await dialog.waitFor({state:'visible'});assert.equal(await dialog.getAttribute('aria-label'),'X Ads');
        assert.equal(await page.locator('#business-account-administration').count(),0);
        await page.screenshot({path:path.join(run,'source-x-dialog-ar-390.png'),fullPage:true});
        await dialog.locator('.advertising-dialog-heading button').focus();await page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});
        assert.equal(await xTrigger.evaluate(button=>document.activeElement===button),true);
        // The PHP acceptance exercises actual callback ingress. This browser case
        // verifies only rendering a session-bound cancellation return fragment.
        const returned=new URL(businessUrl);returned.searchParams.set('section','sources');returned.hash='google-result=AUTHORIZATION_NOT_COMPLETED';
        // OAuth leaves this document. A hash-only navigation inside the mounted
        // activity does not simulate the callback's new-document return.
        await page.goto('about:blank');assert.ok(await page.goto(returned.href));await ready('[data-business-public]');
        const callbackDialog=page.getByRole('dialog',{name:'Google Ads',exact:true});await callbackDialog.waitFor({state:'visible'});
        await callbackDialog.getByText('لم يكتمل تفويض Google. يمكنك بدء الربط من جديد.',{exact:true}).waitFor();
        await callbackDialog.getByRole('button',{name:'إغلاق',exact:true}).click();await callbackDialog.waitFor({state:'hidden'});
        await page.waitForFunction(()=>!location.hash.includes('google-result='));
        assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),result.createdBusiness);
        assert.equal(await page.locator('[data-workspace-panel="sources"]').isVisible(),true);
        await page.reload();await ready('[data-business-public]');assert.equal(await page.locator('[data-workspace-panel="sources"]').isVisible(),true);
        await index();assert.equal(await page.locator('[data-business-public]').count(),0);
        await page.goBack();await ready('[data-business-public]');assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),result.createdBusiness);
        await page.goForward();await ready('[data-business-list]');assert.equal(await page.locator('[data-business-public]').count(),0);
        await page.goBack();await ready('[data-business-public]');
        await page.waitForFunction(()=>document.querySelectorAll('[data-business-source-inventory] [data-state="loading"]').length===0);
        assert.equal(await accounts.locator('[data-state="unselected"]').count(),0,'Reload/history preserve the qualified Business owner without another setup step');
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await page.screenshot({path:path.join(run,'business-advertising-accounts-ar-390.png'),fullPage:true});
        await page.setViewportSize({width:1280,height:900});await page.screenshot({path:path.join(run,'business-sources-ar-1280.png'),fullPage:true});
        await page.setViewportSize({width:768,height:1024});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await page.screenshot({path:path.join(run,'business-sources-ar-768.png'),fullPage:true});
        if(await page.locator('html').getAttribute('data-theme')!=='dark')await page.locator('[data-theme-toggle]').first().click();await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
        await page.screenshot({path:path.join(run,'business-sources-dark-ar-768.png'),fullPage:true});
        await page.locator('[data-theme-toggle]').first().click();await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');await page.setViewportSize({width:1280,height:900});
        const accountMenu=page.locator('.advertiser-account-menu');
        await accountMenu.locator('summary').click();
        await page.locator('[data-application="locale"]').click();
        await page.waitForFunction(()=>document.documentElement.lang==='en'&&document.documentElement.dir==='ltr');
        await accountMenu.locator('summary').click();
        await page.getByRole('heading',{name:'Business advertising accounts',exact:true}).waitFor();
        await page.screenshot({path:path.join(run,'business-sources-en-1280.png'),fullPage:true});
        await accountMenu.locator('summary').click();
        await page.locator('[data-application="locale"]').click();
        await page.waitForFunction(()=>document.documentElement.lang==='ar'&&document.documentElement.dir==='rtl');
        await accountMenu.locator('summary').click();
        const dataLink=page.locator('[data-workspace-panel="sources"] a[href="/data-rights"]');
        await dataLink.click();await page.waitForURL(origin+'/data-rights');await ready('[data-boundary-surface]');
        await page.goBack();await ready('[data-business-public]');
        assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),result.createdBusiness);
        assert.equal(await page.locator('[data-workspace-panel="sources"]').isVisible(),true);
        return{platformIds,brandedEntries:5,directBusinessCustody:true,mandatoryWorkspaceChooser:false,realSnapshots:true,simulatedCancelledFragment:true,sameBusinessReturned:true,reloadAndBackForward:true,returnFocus:true,tabletAndDark:true,mobileOverflow:false,locales:['ar','en'],dataRightsDiscoverable:true};
    });
    await check('Business-only delegate can see provider brands but cannot read or connect private accounts', async () => {
        const path=`/businesses/${delegated.business}?access=${delegated.read.leaf}&section=sources`;
        await page.goto(origin+path);await ready('[data-business-source-inventory]');
        const accounts=page.locator('[data-business-source-inventory] [data-advertising-accounts]');
        await page.waitForFunction(()=>document.querySelectorAll('[data-business-source-inventory] [data-state="loading"]').length===0);
        const platforms=await accounts.locator('[data-provider]').evaluateAll(nodes=>nodes.map(node=>node.dataset.provider));
        assert.equal(platforms.length,5);
        for(const provider of platforms){
            const snapshot=await send(`/businesses/${delegated.business}/providers/${provider}`);
            assert.equal(snapshot.status,403);
        }
        assert.equal(await accounts.getByRole('button',{name:/ربط حساب/}).count(),0);
        assert.equal(await accounts.locator('[data-state="connected"],[data-state="ready"]').count(),0);
        await index();await openCreated('sources');
        return{brandsVisible:platforms.length,privateSnapshotsDenied:platforms.length,connectActionVisible:false};
    });
    await check('brand-new verified advertiser identity reaches first Business setup without a Workspace or advertising prerequisite',async()=>{
        fixture('new-advertiser-identity');const identity=JSON.parse(await readFile(path.join(run,'new-advertiser-identity.json'),'utf8'));
        const freshContext=await browser.newContext({viewport:{width:390,height:844},locale:'ar-JO'}),tab=await freshContext.newPage();tab.setDefaultTimeout(20000);activePage=tab;
        const freshReady=async selector=>tab.waitForFunction(selector=>document.querySelector('[data-application-spa]')?.dataset.state==='ready'&&!!document.querySelector(selector),selector);
        try{
            await tab.goto(origin+'/login');assert.equal((await sendFrom(tab,'/login','POST',{email:identity.email,password:'DisposableGoalsProof42!'})).status,200);
            await tab.goto(origin+'/dashboard');await freshReady('#bf-create-name');assert.equal(new URL(tab.url()).pathname,'/businesses/new');
            assert.equal((await sendFrom(tab,'/api/businesses')).body.data.items.length,0);const organizations=await sendFrom(tab,'/workspaces');assert.equal(organizations.status,200);assert.deepEqual(organizations.body.data.workspaces,[]);
            await tab.locator('#bf-create-name').fill('نشاط المعلن الأول');await tab.locator('#bf-create-type').selectOption({index:1});await tab.locator('#bf-create-market-search').fill('SA');await tab.locator('.setup-countries input[value="SA"]').check();
            await tab.getByRole('button',{name:'إنشاء النشاط والمتابعة',exact:true}).click();await freshReady('[data-business-public]');const business=await tab.locator('[data-business-public]').getAttribute('data-business-public');
            assert.equal((await sendFrom(tab,'/api/businesses')).body.data.items.length,1);assert.deepEqual((await sendFrom(tab,'/workspaces')).body.data.workspaces,[]);
            await ensureWorkspaceNavigation(tab);await tab.locator('[data-workspace-section="sources"]').filter({visible:true}).click();
            assert.equal(await tab.locator('[data-business-source-inventory] .advertising-platforms > [data-provider] .provider-brand').count(),5);assert.equal(await tab.locator('[data-advertising-accounts] dialog').isVisible(),false);assert.equal(await tab.locator('[data-state="unselected"]').count(),0);await tab.locator('[data-provider="meta"] button').click();await tab.getByRole('dialog',{name:'Meta Ads',exact:true}).waitFor({state:'visible'});assert.equal(await tab.locator('#business-account-administration').count(),0);assert.deepEqual((await sendFrom(tab,'/workspaces')).body.data.workspaces,[]);assert.equal(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            return{identityFixture:'verified before browser; registration email transport not exercised',firstEntrySetup:true,business,workspacesBeforeAndAfter:0,brandedPlatforms:5,mobileOverflow:false};
        }finally{await freshContext.close();activePage=page;}
    });
    await check('direct Business team recovers initial422 and409 and reconciles a committed unknown result without a replacement command',async()=>{
        const evidence=[];
        for(const scenario of [422,409,'unknown']){
            const created=await send('/api/businesses','POST',{command_id:crypto.randomUUID(),name:'Browser team recovery '+scenario});assert.equal(created.status,201);const business=created.body.data;
            await page.goto(`${origin}/businesses/${business.business_public_id}?access=${business.grant_refs['business.context.read']}`);await ready('[data-business-public]');await workspaceSection('access');
            const team=page.locator('[data-business-direct-team]'),target=`**/api/businesses/${business.business_public_id}/direct-team`,commands=[];let first=true,committedReceipt;
            await page.route(target,async route=>{
                if(route.request().method()!=='POST')return route.continue();commands.push(route.request().postDataJSON());
                if(first){first=false;if(scenario==='unknown'){const response=await route.fetch();assert.equal(response.status(),200);committedReceipt=(await response.json()).data;return route.abort('failed');}return route.fulfill({status:scenario,contentType:'application/json',body:JSON.stringify({error:scenario===422?'invalid_business_command':'business_conflict'})});}
                return route.continue();
            });
            try{
                const rejected=scenario==='unknown'?undefined:page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${business.business_public_id}/direct-team`&&response.request().method()==='POST');
                await team.locator('[data-direct-team-confirm]').check();await team.locator('[data-direct-team-submit]').click();
                if(scenario==='unknown')await team.getByText('لم نتأكد من النتيجة. تحقّق بإرسال الطلب الأصلي نفسه.',{exact:true}).waitFor();
                else {
                    assert.equal((await rejected).status(),scenario);
                    await team.getByText(scenario===422?'رُفض التفعيل دون تنفيذ. راجع الاختيار وأكّده قبل إرسال طلب جديد.':'تم تحديث الحالة. راجع تفعيل الفريق وأكّده من جديد.',{exact:true}).waitFor();
                    await team.locator('[data-direct-team-confirm]:not([disabled])').waitFor();
                }
                assert.equal(commands.length,1,'No automatic alternative command');const original=structuredClone(commands[0]);
                if(scenario!=='unknown'){await team.locator('[data-direct-team-confirm]').check();}
                const acknowledged=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${business.business_public_id}/direct-team`&&response.request().method()==='POST');
                await team.locator('[data-direct-team-submit]').click();const receipt=await acknowledged;assert.equal(receipt.status(),200);if(committedReceipt)assert.deepEqual((await receipt.json()).data,committedReceipt,'Exact replay returns the original operating enrollment, not newly issued roots');
                await team.getByText('تم تفعيل إدارة فريق النشاط. اختر الصلاحية والموظف أدناه.',{exact:true}).waitFor();
                assert.equal(commands.length,2);if(scenario==='unknown')assert.deepEqual(commands[1],original,'Committed unknown result reuses the exact original command');else assert.notEqual(commands[1].command_id,original.command_id,'Confirmed rejection requires a fresh reviewed command');
                const surface=await send(`/api/businesses/${business.business_public_id}/surface?access=${business.grant_refs['business.context.read']}`);assert.equal(surface.status,200);assert.equal(surface.body.data.direct_team_enrollment_available,false);
                evidence.push({scenario,transportInjection:scenario==='unknown'?'real200effect then droppedACK':'rejected before server effect',requests:commands.length,command:scenario==='unknown'?'same':'new',operatingEnrollment:true});
            }finally{await page.unroute(target);}
        }
        await page.goto(`${origin}/businesses/${result.createdBusiness}?access=${(await send('/api/businesses')).body.data.items.find(item=>item.business_public_id===result.createdBusiness).read_grant_id}`);await ready('[data-business-public]');
        return{cases:evidence,noDuplicateEffects:true};
    });
    await check('an acknowledged-lost Business edit survives a later validation rejection and exact explicit reconciliation changes one version only',async()=>{
        const created=await send('/api/businesses','POST',{command_id:crypto.randomUUID(),name:'Business validation reconciliation'});assert.equal(created.status,201);
        const business=created.body.data,businessId=business.business_public_id;
        await page.goto(`${origin}/businesses/${businessId}?access=${business.grant_refs['business.context.read']}`);await ready('[data-business-public]');await workspaceSection('settings');
        const target=`**/api/businesses/${businessId}/context`,commands=[];let committedReceipt;
        await page.route(target,async route=>{
            if(route.request().method()!=='PUT')return route.continue();commands.push(route.request().postDataJSON());
            if(commands.length===1){const response=await route.fetch();assert.equal(response.status(),200);committedReceipt=(await response.json()).data;return route.abort('failed');}
            if(commands.length===2)return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({error:'simulated_validation_rejection'})});
            return route.continue();
        });
        try{
            await page.locator('#bf-name').fill('Business effect committed once');await page.getByRole('button',{name:'حفظ المعلومات',exact:true}).click();await page.locator('[data-business-unknown]').waitFor();
            assert.equal(commands.length,1);const original=structuredClone(commands[0]);
            await page.locator('[data-workspace-settings="lifecycle"]').click();
            await page.locator('[data-business-archive-pending-work]').waitFor();
            assert.equal(await page.locator('[data-business-archive]').isEnabled(),false,'A new archive cannot strand the existing exact context command');
            assert.equal(await page.locator('[data-business-archive-confirm]').count(),0);
            await page.locator('[data-workspace-settings="details"]').click();
            const denied=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${businessId}/context`&&response.request().method()==='PUT');
            await page.getByRole('button',{name:'تحقق بالطلب الأصلي',exact:true}).click();assert.equal((await denied).status(),422);await page.waitForFunction(()=>!document.querySelector('[data-business-public]')?.matches('[aria-busy="true"]'));
            await page.locator('[data-business-unknown]').waitFor();assert.equal(await page.locator('#bf-name').isDisabled(),true);assert.equal(commands.length,2);assert.deepEqual(commands[1],original);
            const settled=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${businessId}/context`&&response.request().method()==='PUT');
            await page.getByRole('button',{name:'تحقق بالطلب الأصلي',exact:true}).click();const response=await settled;assert.equal(response.status(),200);assert.deepEqual((await response.json()).data,committedReceipt);
            await page.locator('[data-business-saved]').waitFor();assert.equal(commands.length,3);assert.deepEqual(commands[2],original);
            const current=await send(`/api/businesses/${businessId}/surface?access=${business.grant_refs['business.context.read']}`);assert.equal(current.status,200);assert.equal(current.body.data.business.versions.context,business.versions.context+1);
            await page.locator('[data-workspace-settings="lifecycle"]').click();
            await page.locator('[data-business-archive]:enabled').waitFor();
            assert.equal(await page.locator('[data-business-archive-pending-work]').count(),0);
            await page.locator('[data-workspace-settings="details"]').click();
            return{realCommitThenLostAcknowledgement:true,simulatedLater422:true,deliveries:3,versionDelta:1,retainedExactOriginal:true,archiveBlockedUntilReconciled:true};
        }finally{await page.unroute(target);}
    });
    async function ensureWorkspaceNavigation(targetPage=page){const sidebar=targetPage.locator('[data-advertiser-shell] > .advertiser-sidebar');if(!await sidebar.isVisible()){const drawer=targetPage.locator('[data-advertiser-drawer]');if(!await drawer.evaluate(element=>element.open))await targetPage.locator('[data-advertiser-menu-toggle]').click();}}
    async function workspaceSection(section,targetPage=page){await ensureWorkspaceNavigation(targetPage);if(["inquiries","access","settings"].includes(section)){await targetPage.locator('[data-workspace-section="settings"]').click();await targetPage.locator(`[data-workspace-settings="${section==="inquiries"?"data":section==="access"?"access":"details"}"]`).click();}else await targetPage.locator(`[data-workspace-section="${section}"]`).click();await targetPage.locator(`[data-workspace-panel="${section}"]`).waitFor({state:'visible'});}
    const openInquiryDetail=async()=>{await inquiryPanel().locator('[data-inquiry-open]').first().click();await inquiryPanel().locator('[data-inquiry-detail]').waitFor();};
    await check('operating independent team and organization registration, membership and departure', async () => {
        fixture('team-identities');
        const identities=JSON.parse(await readFile(path.join(run,'team-identities.json'),'utf8'));
        const created=await send('/api/businesses','POST',{command_id:crypto.randomUUID(),name:'نشاط مستقل لفريق الموظفين'});
        assert.equal(created.status,201);const independent=created.body.data,teamBusiness=independent.business_public_id;
        await page.goto(`${origin}/businesses/${teamBusiness}?access=${independent.grant_refs['business.context.read']}`);
        await ready('[data-business-public]');await workspaceSection('access');
        const direct=page.locator('[data-business-direct-team]');
        await direct.locator('input[type=checkbox]').check();
        const [enrollment]=await Promise.all([
            page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${teamBusiness}/direct-team`&&response.request().method()==='POST'),
            direct.getByRole('button',{name:'تفعيل إدارة الموظفين',exact:true}).click(),
        ]);
        assert.equal(enrollment.status(),200);const roots=(await enrollment.json()).data.grant_refs;
        const rights={};
        for(const action of ['business.context.read','business.context.manage']){
            await page.locator('#bf-delegation-parent').selectOption(roots[action]);
            await page.locator('#bf-delegation-recipient').fill(identities.staff);
            assert.equal(await page.locator('#bf-delegation-agency').count(),0);
            const [response]=await Promise.all([
                page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${teamBusiness}/grants`&&response.request().method()==='POST'),
                page.locator('[data-delegation-form]').getByRole('button',{name:'منح الحق المحدد',exact:true}).click(),
            ]);
            assert.equal(response.status(),200);rights[action]=(await response.json()).data.grant_id;
            await page.locator('[data-delegation-confirmed]').waitFor();
        }
        await page.goto(origin+'/workspaces');await ready('[data-workspaces="index"]');
        await page.locator('[data-workspaces-add]').click();await ready('[data-workspaces="create"]');
        assert.equal(await page.locator('[data-workspaces="type"]').count(),0);
        await page.locator('[data-workspaces="name"]').fill('وكالة الفريق المتصفحي');
        await page.locator('[data-workspaces="save"]').click();await ready('[data-sources-workspace]');
        const organization=new URL(page.url()).pathname.split('/')[2],team=page.locator('[data-organization-team]');
        result.operatingOrganization=organization;result.organizationStaff=identities.staff;
        await page.getByRole('button',{name:'الفريق',exact:true}).click();
        await team.locator('[data-organization-recipient]').fill(identities.staff);
        await team.locator('[data-organization-confirm]').check();
        const [added]=await Promise.all([
            page.waitForResponse(response=>new URL(response.url()).pathname===`/api/organizations/${organization}/team`&&response.request().method()==='POST'),
            team.getByRole('button',{name:'إضافة الموظف',exact:true}).click(),
        ]);
        assert.equal(added.status(),200);
        const newMember=team.locator('[data-organization-members] li').filter({hasText:identities.staff});
        await newMember.waitFor();
        assert.equal(await newMember.locator('code').isVisible(),false);
        await newMember.locator('summary').click();
        assert.equal(await newMember.locator('code').textContent(),identities.staff);
        await newMember.locator('summary').click();
        await page.screenshot({path:path.join(run,'organization-team-ar-1280.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await page.screenshot({path:path.join(run,'organization-team-ar-390.png'),fullPage:true});
        const language=async target=>{const menu=page.locator('.advertiser-account-menu');if(await menu.getAttribute('open')===null)await menu.locator(':scope > summary').click();await page.locator('[data-application="locale"]').click();await page.waitForFunction(lang=>document.documentElement.lang===lang,target);await ready('[data-sources-workspace]');await team.locator('[data-organization-members]').waitFor();if(await menu.getAttribute('open')!==null)await menu.locator(':scope > summary').click();};
        await language('en');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await team.getByText('Team membership updated. Business rights remain explicit per file.',{exact:true}).waitFor();
        assert.equal(await team.getByText('تم تحديث عضوية الفريق. صلاحيات الأنشطة تُحدد لكل ملف بصورة مستقلة.',{exact:true}).count(),0);
        await page.screenshot({path:path.join(run,'organization-team-en-390.png'),fullPage:true});
        await page.getByRole('button',{name:'Accounts and sources',exact:true}).click();
        await page.locator('.sources-page > div > details.biz-divider > summary').click();
        const registryHeading=page.locator('#sources-heading');await registryHeading.waitFor();assert.equal(await registryHeading.textContent(),'Sources and current permissions');
        await page.getByText('No sources are visible under the current source permission. Workspace view permission alone is insufficient.',{exact:true}).waitFor();
        await page.screenshot({path:path.join(run,'organization-sources-en-390-advanced.png'),fullPage:true});
        await page.getByRole('button',{name:'Team',exact:true}).click();await team.locator('[data-organization-members]').waitFor();await language('ar');
        await page.setViewportSize({width:1280,height:900});
        const employee=await browser.newContext({viewport:{width:390,height:844},locale:'ar-JO'});
        try{
            const tab=await employee.newPage();tab.on('pageerror',e=>result.errors.push(e.message));
            await tab.goto(origin+'/login');
            assert.equal((await sendFrom(tab,'/login','POST',{email:'goals-a@example.test',password:'DisposableGoalsProof42!'})).status,200);
            await tab.goto(`${origin}/businesses/${teamBusiness}?access=${rights['business.context.read']}`);
            await tab.waitForSelector('[data-business-public]');await workspaceSection('settings',tab);
            assert.equal(await tab.locator('[data-business-history], [data-business-legacy-history]').count(),0);
            await tab.locator('#bf-name').fill('نشاط عدّله الموظف المباشر');
            await tab.getByRole('button',{name:'حفظ المعلومات',exact:true}).click();await tab.locator('[data-business-saved]').waitFor();
            await tab.goto(`${origin}/workspaces/${organization}`);
            await tab.getByRole('button',{name:'الفريق',exact:true}).click();
            const ownTeam=tab.locator('[data-organization-team]');await ownTeam.getByRole('button',{name:'مغادرة الجهة',exact:true}).waitFor();
            assert.equal(await ownTeam.locator('form').count(),0);
            await ownTeam.locator('[data-organization-confirm]').check();await ownTeam.getByRole('button',{name:'مغادرة الجهة',exact:true}).click();
            await tab.waitForURL(origin+'/workspaces');
            assert.equal((await sendFrom(tab,`/api/organizations/${organization}/team`)).status,403);
            assert.equal((await sendFrom(tab,`/api/businesses/${teamBusiness}/surface?access=${rights['business.context.read']}`)).status,200,'Independent Business rights are not organizational membership');
        }finally{await employee.close();}
        return {independentBusiness:teamBusiness,locales:['ar','en'],operatingIssuers:true,workspaceInferredForIndependentBusiness:false,organizationRegisteredWithoutCommercialType:true,employeeMembershipAndSelfDeparture:true,directBusinessPreservedAfterOrganizationalDeparture:true,width:390,horizontalOverflow:0};
    });
    await check('organization membership keeps a committed unknown command through later409 and422 and reconciles one effect', async()=>{
        const organization=result.operatingOrganization,recipient=result.organizationStaff;
        assert.ok(organization&&recipient,'The operating organization journey is a required prerequisite');
        await page.goto(`${origin}/workspaces/${organization}`);await ready('[data-sources-workspace]');
        await page.getByRole('button',{name:'الفريق',exact:true}).click();
        const team=page.locator('[data-organization-team]'),target=`**/api/organizations/${organization}/team`,evidence=[];
        for(const [rejection,effect] of [[409,'add'],[422,'remove']]){
            const before=await send(`/api/organizations/${organization}/team`);assert.equal(before.status,200);
            const commands=[];let committedReceipt;
            await page.route(target,async route=>{
                if(route.request().method()!=='POST')return route.continue();
                commands.push(route.request().postDataJSON());
                if(commands.length===1){const response=await route.fetch();assert.equal(response.status(),200);committedReceipt=(await response.json()).data;return route.abort('failed');}
                if(commands.length===2)return route.fulfill({status:rejection,contentType:'application/json',body:JSON.stringify({error:'simulated_reconciliation_rejection'})});
                return route.continue();
            });
            try{
                if(effect==='add')await team.locator('[data-organization-recipient]').fill(recipient);
                await team.locator('[data-organization-confirm]').check();
                if(effect==='add')await team.getByRole('button',{name:'إضافة الموظف',exact:true}).click();
                else await team.locator('[data-organization-members] li').filter({hasText:recipient}).getByRole('button',{name:'سحب العضوية',exact:true}).click();
                const unknown=team.getByText('لم نتأكد من النتيجة. تحقّق بالطلب الأصلي نفسه؛ لن يُرسل تلقائيًا.',{exact:true});await unknown.waitFor();
                assert.equal(commands.length,1);const original=structuredClone(commands[0]);assert.equal(original.expected_revision,before.body.data.revision);
                const rejected=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/organizations/${organization}/team`&&response.request().method()==='POST');
                await team.locator('[data-organization-retry]').click();assert.equal((await rejected).status(),rejection);await unknown.waitFor();
                assert.equal(await team.locator('fieldset').evaluate(fieldset=>fieldset.disabled),true);
                assert.equal(await team.locator('[data-organization-recipient]').isDisabled(),true,'The retained unknown command keeps editable membership input disabled');
                assert.equal(commands.length,2);assert.deepEqual(commands[1],original);
                const settled=page.waitForResponse(response=>new URL(response.url()).pathname===`/api/organizations/${organization}/team`&&response.request().method()==='POST');
                await team.locator('[data-organization-retry]').click();const receipt=await settled;assert.equal(receipt.status(),200);assert.deepEqual((await receipt.json()).data,committedReceipt);
                await team.locator('[data-organization-retry]').waitFor({state:'hidden'});assert.equal(commands.length,3);assert.deepEqual(commands[2],original);
                const after=await send(`/api/organizations/${organization}/team`);assert.equal(after.status,200);assert.equal(after.body.data.revision,before.body.data.revision+1,'Three deliveries must produce only one membership change');
                evidence.push({rejection,effect,requests:3,revisionDelta:1,exactOriginal:true,transport:'real committed effect then dropped ACK; simulated later rejection'});
            }finally{await page.unroute(target);}
        }
        return{cases:evidence};
    });
    await check('internal client file team opt-in, real staff assignment and staff edit preserve private boundaries', async () => {
        fixture('internal-team-setup');
        const f=JSON.parse(await readFile(path.join(run,'internal-team-browser-fixture.json'),'utf8'));
        const business=f.business.business_public_id;
        await page.goto(`${origin}/businesses/${business}?access=${f.business.grant_refs['business.context.read']}`);
        await ready('[data-business-public]');await workspaceSection('access');
        const panel=page.locator('[data-internal-client-team]');
        await panel.locator('select').selectOption(f.team.public_id);
        await panel.locator('input[type=checkbox]').check();
        await panel.getByRole('button',{name:'تفعيل إدارة الفريق لهذا الملف',exact:true}).click();
        const openTeam=panel.locator('[data-open-internal-team]');await openTeam.waitFor();
        await openTeam.click();await ready('[data-business-public]');await workspaceSection('access');
        assert.equal(await page.locator('[data-business-delegation] input[type=checkbox]').count(),0);
        const readAccess = new URL(page.url()).searchParams.get('access');
        const qualified=await send(`/api/businesses/${business}/surface?access=${readAccess}`);assert.equal(qualified.status,200);
        assert.equal(qualified.body.data.authority_selection.realm_kind,'internal_team');
        const rights={};
        for (const action of ['business.context.read','business.context.manage']) {
            const option=qualified.body.data.delegation.options.find(item=>item.action===action&&item.can_issue);assert.ok(option);
            await page.locator('#bf-delegation-parent').selectOption(option.grant_id);
            await page.locator('#bf-delegation-recipient').fill(f.staff);
            const [response]=await Promise.all([
                page.waitForResponse(response=>new URL(response.url()).pathname===`/api/businesses/${business}/grants`&&response.request().method()==='POST'),
                page.locator('[data-delegation-form]').getByRole('button',{name:'منح الحق المحدد',exact:true}).click(),
            ]);
            const receipt=await response.json();assert.equal(receipt.data.status,'active');rights[action]=receipt.data.grant_id;
            await page.locator('[data-delegation-confirmed]').waitFor();
        }
        await page.setViewportSize({width:390,height:844});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await page.screenshot({path:path.join(run,'internal-team-ar-390.png'),fullPage:true});await page.setViewportSize({width:1280,height:900});
        const staffContext=await browser.newContext({viewport:{width:1280,height:900},locale:'ar-JO'});
        try {
            const staffPage=await staffContext.newPage();
            staffPage.on('pageerror',error=>result.errors.push(error.message));
            await staffPage.goto(origin+'/login');
            const login=await sendFrom(staffPage,'/login','POST',{email:'goals-a@example.test',password:'DisposableGoalsProof42!'});assert.equal(login.status,200);
            await staffPage.goto(`${origin}/businesses/${business}?access=${rights['business.context.read']}`);
            await staffPage.waitForSelector('[data-business-public]');await workspaceSection('settings',staffPage);
            await staffPage.locator('#bf-name').fill('ملف عدّله موظف مؤهل');
            await staffPage.getByRole('button',{name:'حفظ المعلومات',exact:true}).click();
            await staffPage.locator('[data-business-saved]').waitFor();
            await staffPage.waitForFunction(()=>document.querySelector('[data-business-name]')?.textContent==='ملف عدّله موظف مؤهل');
            assert.equal(await staffPage.locator('[data-business-name]').textContent(),'ملف عدّله موظف مؤهل');
            await workspaceSection('inquiries',staffPage);assert.equal(await staffPage.locator('[data-inquiry-panel]').count(),0);
            const history=await sendFrom(staffPage,`/api/businesses/${business}/history?access=${rights['business.context.read']}&kind=context`);assert.equal(history.status,403);
            await workspaceSection('access',staffPage);
            const membership=staffPage.locator('[data-organization-team]');await membership.getByRole('button',{name:'مغادرة الجهة',exact:true}).waitFor();
            assert.equal(await membership.locator('form').count(),0);
            assert.equal((await sendFrom(staffPage,`/workspaces/${f.team.public_id}`)).status,403,'Native Workspace view is not required for self-surrender');
            await membership.locator('[data-organization-confirm]').check();await membership.getByRole('button',{name:'مغادرة الجهة',exact:true}).click();
            await staffPage.waitForURL(origin+'/workspaces');
            assert.equal((await sendFrom(staffPage,`/api/businesses/${business}/surface?access=${rights['business.context.read']}`)).status,403);
            const retained=await send(`/api/businesses/${business}/surface?access=${f.business.grant_refs['business.context.read']}`);
            assert.equal(retained.status,200);assert.equal(retained.body.data.business.name,'ملف عدّله موظف مؤهل');
        } finally {await staffContext.close();}
        return {operatingCreatorEnrollment:true,staffEdit:true,selfDepartureWithoutWorkspaceView:true,departedStaffDenied:true,qualifiedFilePreserved:true,privateHistoryDenied:true,privateInquiryNotMounted:true,mobileOverflow:false};
    });
    const inquiryPanel=()=>page.locator('[data-inquiry-panel]');
    const inquiryRecord=()=>inquiryPanel().locator('[data-inquiry-record-action]');
    const inquirySend=async(method='GET',suffix='',body)=>send(`/api/businesses/${result.createdBusiness}/inquiry-evidence${suffix}`,method,body);
    const inquirySelection=grant=>({realm_kind:'direct',realm_workspace_public_id:null,requirements:[{requirement_kind:'business',leaf_grant_id:String(grant)}]});
    let inquiryAccess,inquiryGrants,inquiryPayload;
    const fillInquiry=async()=>{
        const panel=inquiryPanel();
        await panel.locator('[data-inquiry-start]').fill('2020-01-01T00:00:00Z');
        await panel.locator('[data-inquiry-end]').fill('2020-01-02T00:00:00Z');
        await panel.locator('[data-inquiry-asof]').fill('2020-01-03T00:00:00Z');
        await panel.locator('[data-inquiry-cost]').fill('1000.00');
        await panel.locator('[data-inquiry-count]').fill('100');
        await panel.locator('[data-inquiry-acceptance-toggle]').check();
        await panel.locator('[data-inquiry-accepted]').fill('10');
        await panel.locator('textarea[data-inquiry-criterion-definition]').fill('الاستفسار يطلب خدمة متاحة ضمن نطاق النشاط');
        await panel.locator('[data-inquiry-cutoff]').fill('2020-01-03T00:00:00Z');
    };
    await check('BF03 explicit creator opt-in, invalid inputs editable, assertion readback and historical meaning',async()=>{
        // The preceding operating internal-team journey deliberately leaves an
        // internal client file open. Private declarations belong to the actual
        // independently created Business, never to that staff-selected path.
        await index();await openCreated();
        assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'),result.createdBusiness);
        await workspaceSection('inquiries');const panel=inquiryPanel();await panel.locator('[data-inquiry-optin]').waitFor();
        inquiryAccess=new URL(page.url()).searchParams.get('access');assert.ok(inquiryAccess);
        const before=await inquirySend('GET',`/eligibility?access=${inquiryAccess}`);assert.equal(before.status,200);assert.deepEqual(before.body.data.grants,{});
        await panel.locator('[data-inquiry-optin] input[type=checkbox]').check();
        await panel.getByRole('button',{name:'تفعيل السجل الخاص',exact:true}).click();await inquiryRecord().waitFor();
        inquiryGrants=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data.grants;assert.equal(Object.keys(inquiryGrants).length,3);
        await inquiryRecord().click();await fillInquiry();await panel.locator('[data-inquiry-asof]').fill('2099-01-03T00:00:00Z');
        await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();await panel.getByText('لم تُحفظ البيانات. صحّح النموذج ثم احفظه من جديد.',{exact:true}).waitFor();
        assert.equal(await panel.locator('[data-inquiry-count]').isDisabled(),false);assert.equal(await panel.locator('[data-inquiry-unknown]').count(),0);
        await panel.locator('[data-inquiry-asof]').fill('2020-01-03T00:00:00Z');
        await page.screenshot({path:path.join(run,'workspace-ar-light-1280-form.png'),fullPage:true});await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);const timezoneInput=panel.getByLabel('المنطقة الزمنية',{exact:true});assert.equal(await timezoneInput.isVisible(),true);assert.equal(await timezoneInput.inputValue(),'Asia/Riyadh');await timezoneInput.scrollIntoViewIfNeeded();await page.screenshot({path:path.join(run,'workspace-ar-light-390-timezone.png')});await page.screenshot({path:path.join(run,'workspace-ar-light-390-form.png'),fullPage:true});await page.setViewportSize({width:1280,height:900});
        page.on('request',request=>{if(request.method()==='POST'&&new URL(request.url()).pathname===`/api/businesses/${result.createdBusiness}/inquiry-evidence`)inquiryPayload=request.postDataJSON();});
        await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();await panel.locator('.inquiry-item').waitFor();
        assert.ok((await panel.innerText()).includes('غير متحقق منه'));
        await openInquiryDetail();assert.ok((await panel.locator('[data-inquiry-detail]').innerText()).includes('نضج القبول'));
        await panel.getByRole('button',{name:'قراءة التاريخ',exact:true}).first().click();await panel.locator('[data-inquiry-history] article details summary').first().click();
        assert.ok((await panel.locator('[data-inquiry-history]').innerText()).includes('معيار القبول'));assert.ok((await panel.locator('[data-inquiry-history]').innerText()).includes('إصدار معيار القبول'));
        const data=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data;
        assert.equal(data.independently_verified,false);assert.equal(data.items[0].value.acceptance.count,10);
        return {explicitOptIn:true,invalid422Editable:true,sourceAssurance:'SOURCE_ASSERTED',completeCurrentAndHistoryMeaning:true};
    });
    await check('BF03 stale correction rejected without overwriting newer revision and RTL mobile remains usable',async()=>{
        const panel=inquiryPanel();if(await panel.locator('[data-inquiry-open]').count())await openInquiryDetail();await panel.getByRole('button',{name:'فتح التصحيح',exact:true}).first().click();
        const current=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];
        const changed=structuredClone(current.value);changed.acceptance.count=11;
        const updated=await inquirySend('POST',`/${current.declaration_id}/revisions`,{command_id:crypto.randomUUID(),authority_selection:inquirySelection(inquiryGrants['business.inquiry_evidence.correct']),expected_revision:current.revision,effect:'correct',value:changed});assert.equal(updated.status,200);
        await panel.locator('[data-inquiry-accepted]').fill('15');await panel.getByRole('button',{name:'حفظ التصحيح',exact:true}).click();
        await panel.getByText('رُفض التصحيح لأن الإصدار تغير. ألغِ التعديل وافتح النسخة الحالية.',{exact:true}).waitFor();assert.equal(await panel.locator('[data-inquiry-unknown]').count(),0);
        await panel.getByRole('button',{name:'إلغاء التعديل',exact:true}).click();await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        const latest=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];assert.equal(latest.revision,2);assert.equal(latest.value.acceptance.count,11);
        await page.screenshot({path:path.join(run,'inquiry-desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});
        assert.equal(await panel.getAttribute('dir'),'rtl');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await page.screenshot({path:path.join(run,'inquiry-mobile.png'),fullPage:true});await page.setViewportSize({width:1280,height:900});
        return {typedPreEffectConflict:true,newerRevisionRetained:true,mobileRTL:true};
    });
    await check('BF03 history denial clears detail and stored collection',async()=>{
        const panel=inquiryPanel();if(await panel.locator('[data-inquiry-open]').count())await openInquiryDetail();
        const revoked=await inquirySend('POST','/rights',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.read',authority_selection:inquirySelection(inquiryGrants['business.inquiry_evidence.read'])});assert.equal(revoked.status,200);
        await panel.getByRole('button',{name:'قراءة التاريخ',exact:true}).first().click();await panel.getByText('تعذر تأهيل قراءة التاريخ.',{exact:true}).waitFor();
        assert.equal(await panel.locator('[data-inquiry-editor]').count(),0);assert.equal(await panel.locator('[data-inquiry-list]').count(),0);
        const eligible=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data;
        assert.equal(eligible.grants['business.inquiry_evidence.read'],undefined);assert.ok(eligible.grants['business.inquiry_evidence.record']);
        const renewed=await inquirySend('POST','/enrollment',{command_id:crypto.randomUUID(),basis_revision:eligible.basis_revision,confirm:true,authority_selection:inquirySelection(inquiryAccess)});assert.equal(renewed.status,200);
        await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();await panel.locator('.inquiry-item').waitFor();
        inquiryGrants=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data.grants;
        return {knownDenialConcealsHistoryAndCollection:true,recordPermissionDidNotImplyRead:true};
    });
    await check('BF03 positive UI correction and withdrawal preserve semantic versions and full history',async()=>{
        const panel=inquiryPanel();
        await inquiryRecord().click();await fillInquiry();
        const recorded=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===`/api/businesses/${result.createdBusiness}/inquiry-evidence`);
        await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();assert.equal((await recorded).status(),200);
        await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        await openInquiryDetail();const item=panel.locator('[data-inquiry-detail]');
        const created=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];assert.equal(created.revision,1);
        await item.getByRole('button',{name:'فتح التصحيح',exact:true}).click();
        await panel.locator('[data-inquiry-accepted]').fill('12');
        await panel.getByLabel('نوع التكلفة').selectOption('advertising_spend');
        await panel.locator('[data-inquiry-definition-details] summary').click();await panel.getByLabel('إصدار معيار القبول',{exact:true}).fill('2');
        const corrected=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.endsWith(`/${created.declaration_id}/revisions`));
        await panel.getByRole('button',{name:'حفظ التصحيح',exact:true}).click();assert.equal((await corrected).status(),200);
        await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        await item.locator('.inquiry-detail-heading').filter({hasText:'إصدار 2'}).waitFor();
        let latest=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];
        assert.equal(latest.declaration_id,created.declaration_id);assert.equal(latest.value.acceptance.count,12);assert.equal(latest.value.acceptance.criterion_version,2);assert.equal(latest.value.cost.cost_basis,'advertising_spend');
        await item.getByRole('button',{name:'قراءة التاريخ',exact:true}).click();await panel.locator('[data-inquiry-history] article').nth(1).waitFor();
        assert.ok((await panel.locator('[data-inquiry-history] article').nth(0).innerText()).includes('المقبول المصرح به: 10'));
        assert.ok((await panel.locator('[data-inquiry-history] article').nth(1).innerText()).includes('المقبول المصرح به: 12'));
        const withdrawn=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.endsWith(`/${created.declaration_id}/revisions`));
        await item.getByRole('button',{name:'سحب التصريح مع حفظ التاريخ',exact:true}).click();assert.equal((await withdrawn).status(),200);
        await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        await item.getByText('مسحوب؛ التاريخ محفوظ',{exact:true}).waitFor();
        latest=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];assert.equal(latest.revision,3);assert.equal(latest.state,'withdrawn');assert.equal(latest.value,null);
        await item.getByRole('button',{name:'قراءة التاريخ',exact:true}).click();await panel.locator('[data-inquiry-history] article').nth(2).waitFor();
        const historyRows=panel.locator('[data-inquiry-history] article');assert.equal(await historyRows.count(),3);
        assert.ok((await historyRows.nth(0).innerText()).includes('المقبول المصرح به: 10'));assert.ok((await historyRows.nth(1).innerText()).includes('المقبول المصرح به: 12'));assert.ok((await historyRows.nth(2).innerText()).includes('سحب'));
        await panel.locator('[data-inquiry-back]').click();return {uiCorrectionCommitted:true,changedCountAndMeaning:true,uiWithdrawalCommitted:true,appendOnlyHistoryRevisions:3};
    });

    await check('workspace task navigation preserves unsent inquiry fields through list and Business remount',async()=>{
        await workspaceSection('overview');
        assert.equal(await page.locator('[data-workspace-panel="overview"]').isVisible(),true);
        assert.equal(await inquiryPanel().isVisible(),false);
        assert.ok((await page.locator('[data-business-name]').innerText()).includes('نشاط مستقل'));
        await workspaceSection('inquiries');await inquiryRecord().click();await fillInquiry();
        await inquiryPanel().locator('[data-inquiry-count]').fill('73');
        await workspaceSection('direction');await page.locator('[data-workspace-panel=\"direction\"]').waitFor();
        await workspaceSection('settings');await page.locator('#bf-name').waitFor();
        await workspaceSection('access');assert.ok((await page.locator('[data-workspace-panel="access"]').innerText()).includes('الصلاحيات')||(await page.locator('[data-workspace-panel="access"]').innerText()).includes('التفويض'));
        await workspaceSection('inquiries');assert.equal(await inquiryPanel().locator('[data-inquiry-count]').inputValue(),'73');
        await index();await open('نشاط العميل المفوض');await workspaceSection('overview');
        await index();await openCreated();await inquiryPanel().locator('[data-inquiry-editor]').waitFor();
        assert.equal(await inquiryPanel().locator('[data-inquiry-count]').inputValue(),'73');
        await inquiryPanel().getByRole('button',{name:'إلغاء التعديل',exact:true}).click();
        return {localTasksPreserveInput:true,listAndOtherBusinessRemountPreserveOwnDraft:true,crossBusinessDisclosure:false};
    });
    await check('workspace correction draft returns unchanged and revoked read prevents its restoration',async()=>{
        const panel=inquiryPanel();await panel.locator('.inquiry-item:has(.inquiry-record-preview) [data-inquiry-open]').first().click();
        await panel.getByRole('button',{name:'فتح التصحيح',exact:true}).click();await panel.locator('[data-inquiry-accepted]').fill('19');
        await index();await openCreated();await workspaceSection('inquiries');await panel.locator('[data-inquiry-editor]').waitFor();assert.equal(await panel.locator('[data-inquiry-accepted]').inputValue(),'19');
        const revoked=await inquirySend('POST','/rights',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.read',authority_selection:inquirySelection(inquiryGrants['business.inquiry_evidence.read'])});assert.equal(revoked.status,200);
        await index();await openCreated();await workspaceSection('inquiries');await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        assert.equal(await panel.locator('[data-inquiry-editor]').count(),0);assert.equal(await panel.locator('[data-inquiry-list]').count(),0);
        const eligible=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data;
        const renewed=await inquirySend('POST','/enrollment',{command_id:crypto.randomUUID(),basis_revision:eligible.basis_revision,confirm:true,authority_selection:inquirySelection(inquiryAccess)});assert.equal(renewed.status,200);
        await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();await panel.locator('[data-inquiry-open]').first().waitFor();assert.equal(await panel.locator('[data-inquiry-editor]').count(),0);
        inquiryGrants=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data.grants;
        return {unsentCorrectionRestoredWithOriginalBaseline:true,readRevocationBlocksRestoration:true,reEnrollmentDoesNotReviveDiscardedProtectedDraft:true};
    });
    await check('workspace long activity identity and explicit zero remain distinct from missing evidence',async()=>{
        const name=('نشاط إقليمي للاستفسارات وخدمات الأعمال — '+ 'RegionalBusinessReference'.repeat(3)).slice(0,120);
        await workspaceSection('settings');await page.locator('#bf-name').fill(name);
        const saved=page.waitForResponse(r=>r.request().method()==='PUT'&&new URL(r.url()).pathname===`/api/businesses/${result.createdBusiness}/context`);
        await page.getByRole('button',{name:'حفظ المعلومات',exact:true}).click();assert.equal((await saved).status(),200);
        await page.waitForFunction(wanted=>document.querySelector('[data-business-name]')?.textContent===wanted,name);
        await workspaceSection('inquiries');const panel=inquiryPanel();await inquiryRecord().click();await fillInquiry();await panel.locator('[data-inquiry-count]').fill('0');await panel.locator('[data-inquiry-cost]').fill('');await panel.locator('[data-inquiry-acceptance-toggle]').uncheck();
        const recorded=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===`/api/businesses/${result.createdBusiness}/inquiry-evidence`);
        await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();assert.equal((await recorded).status(),200);await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        const latest=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items[0];assert.equal(latest.value.inquiries.count,0);assert.equal(latest.value.cost,null);assert.equal(latest.value.acceptance,null);
        await openInquiryDetail();const detail=panel.locator('[data-inquiry-detail]');assert.ok((await detail.innerText()).includes('غير معلوم'));assert.ok((await detail.innerText()).includes('0'));
        await detail.locator('.inquiry-metadata summary').click();assert.ok((await detail.innerText()).includes(latest.declaration_id));await panel.locator('[data-inquiry-back]').click();
        return {longBilingualBusinessName:true,nativeIdentifiersAccessible:true,knownZero:0,unknownCost:null,unknownAcceptance:null};
    });
    await check('workspace compact collection complete details return focus and responsive bilingual themed matrix',async()=>{
        const panel=inquiryPanel();await panel.locator('.inquiry-item:has(.inquiry-record-preview) [data-inquiry-open]').first().waitFor();
        assert.equal(await panel.locator('[data-inquiry-detail]').count(),0);
        assert.equal(await panel.locator('[data-inquiry-list] [data-inquiry-semantics]').count(),0);
        const matrix=[];
        for(const language of ['ar','en']){
            if(await page.locator('html').getAttribute('lang')!==language){
                const accountMenu=page.locator('.advertiser-account-menu');if(await accountMenu.getAttribute('open')===null)await accountMenu.locator(':scope > summary').click();
                await page.locator('[data-application="locale"]').click();await page.waitForFunction(lang=>document.documentElement.lang===lang,language);
                if(await accountMenu.getAttribute('open')!==null)await accountMenu.locator(':scope > summary').click();
            }
            const savedMessage=panel.locator('[data-inquiry-message]');if(await savedMessage.count())assert.ok((await savedMessage.innerText()).includes(language==='ar'?'تم الحفظ':'Saved'));
            for(const theme of ['light','dark']){
                if(await page.locator('html').getAttribute('data-theme')!==theme){await page.locator('[data-theme-toggle]').first().click();await page.waitForFunction(wanted=>document.documentElement.dataset.theme===wanted,theme);}
                for(const width of [360,390,430,768,1024,1366,1920]){
                    await page.setViewportSize({width,height:900});
                    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`${language}/${theme}/${width} overflow`);
                    const nav=page.locator('[data-workspace-section="inquiries"]');assert.equal(await nav.getAttribute('aria-current'),'page');
                    const first=panel.locator('.inquiry-item:has(.inquiry-record-preview) [data-inquiry-open]').first();assert.ok((await first.boundingBox()).height>=44);
                    if(width===390||width===1366)await page.screenshot({path:path.join(run,`workspace-${language}-${theme}-${width}-collection.png`),fullPage:true});
                    matrix.push({language,theme,width,overflow:false});
                }
                await panel.locator('.inquiry-item:has(.inquiry-record-preview) [data-inquiry-open]').first().click();await panel.locator('[data-inquiry-detail]').waitFor();
                const detail=await panel.locator('[data-inquiry-detail]').innerText();
                assert.ok(detail.includes(language==='ar'?'غير متحقق':'not independently verified')||detail.includes(language==='ar'?'ليست نتائج':'not results'));
                for(const width of [390,1366]){await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.screenshot({path:path.join(run,`workspace-${language}-${theme}-${width}-detail.png`),fullPage:true});}
                await panel.locator('[data-inquiry-back]').click();await panel.locator('.inquiry-item:has(.inquiry-record-preview) [data-inquiry-open]').first().waitFor();assert.equal(await page.locator(':focus').getAttribute('data-inquiry-open')!==null,true);
            }
        }
        if(await page.locator('html').getAttribute('lang')!=='ar'){
            const accountMenu=page.locator('.advertiser-account-menu');if(await accountMenu.getAttribute('open')===null)await accountMenu.locator(':scope > summary').click();
            await page.locator('[data-application="locale"]').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');
            if(await accountMenu.getAttribute('open')!==null)await accountMenu.locator(':scope > summary').click();
        }
        await page.setViewportSize({width:1280,height:900});
        return {matrix,compactCollection:true,completeDetails:true,backRestoresFocus:true,formalUsabilityStudy:false};
    });

    await check('BF03 committed lost ACK survives remount and exact retry without duplicate declaration',async()=>{
        const panel=inquiryPanel(),commands=[];const beforeCount=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items.length;let first=true,ackDropped;const dropped=new Promise(resolve=>{ackDropped=resolve;});
        await page.route(`**/api/businesses/${result.createdBusiness}/inquiry-evidence`,async route=>{
            if(route.request().method()!=='POST')return route.continue();commands.push(route.request().postDataJSON());
            const response=await route.fetch();assert.equal(response.status(),200);if(first){first=false;await route.abort('failed');ackDropped();return;}return route.fulfill({response});
        });
        await inquiryRecord().click();await fillInquiry();await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();await dropped;await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');await panel.locator('[data-inquiry-unknown]').waitFor();
        await index();await openCreated();await workspaceSection('inquiries');await inquiryPanel().locator('[data-inquiry-unknown]').waitFor();
        await inquiryPanel().getByRole('button',{name:'إعادة طلب نتيجة الأمر نفسه',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('[data-inquiry-unknown]'));
        await page.unroute(`**/api/businesses/${result.createdBusiness}/inquiry-evidence`);
        assert.equal(commands.length,2);assert.deepEqual(commands[0],commands[1]);
        const data=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data;assert.equal(data.items.length,beforeCount+1);
        return {serverCommittedBeforeLostAck:true,remountRetainsOriginalCommand:true,exactReplay:true,duplicateEffects:0};
    });
    await check('BF03 later denial cannot erase an earlier uncertain commit or authorize replacement',async()=>{
        const panel=inquiryPanel();const beforeCount=(await inquirySend('GET',`?access=${inquiryGrants['business.inquiry_evidence.read']}`)).body.data.items.length;let intercepted=false,ackDropped;const dropped=new Promise(resolve=>{ackDropped=resolve;});
        await page.route(`**/api/businesses/${result.createdBusiness}/inquiry-evidence`,async route=>{
            if(route.request().method()!=='POST'||intercepted)return route.continue();intercepted=true;const response=await route.fetch();assert.equal(response.status(),200);await route.abort('failed');ackDropped();
        });
        await inquiryRecord().click();await fillInquiry();await panel.getByRole('button',{name:'حفظ التصريح',exact:true}).click();await dropped;await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');await panel.locator('[data-inquiry-unknown]').waitFor();
        await page.unroute(`**/api/businesses/${result.createdBusiness}/inquiry-evidence`);
        const revoked=await inquirySend('POST','/rights',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.record',authority_selection:inquirySelection(inquiryGrants['business.inquiry_evidence.record'])});assert.equal(revoked.status,200);
        await panel.getByRole('button',{name:'إعادة طلب نتيجة الأمر نفسه',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        assert.equal(await panel.locator('[data-inquiry-unknown]').count(),1);
        const eligible=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data;
        const renewed=await inquirySend('POST','/enrollment',{command_id:crypto.randomUUID(),basis_revision:eligible.basis_revision,confirm:true,authority_selection:inquirySelection(inquiryAccess)});assert.equal(renewed.status,200);
        await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();assert.ok(await inquiryRecord().count()===0||await inquiryRecord().isDisabled());assert.equal(await panel.locator('[data-inquiry-unknown]').count(),1);
        const rights=(await inquirySend('GET',`/eligibility?access=${inquiryAccess}`)).body.data.grants;
        assert.equal((await inquirySend('GET',`?access=${rights['business.inquiry_evidence.read']}`)).body.data.items.length,beforeCount+1);
        return {priorUncertaintySurvives403:true,reEnrollmentDoesNotSilentlyReplace:true,duplicateEffects:0};
    });
    await check('BF03 retained reentry qualifies before protected DOM and record-only revocation retains reading',async()=>{
        const created=await send('/api/businesses','POST',{command_id:crypto.randomUUID(),name:'نشاط اختبار إعادة تأهيل العرض',business_type_id:null});assert.equal(created.status,201);
        const b=created.body.data,business=b.business_public_id,basis=b.grant_refs['business.context.read'];
        const base=`/api/businesses/${business}/inquiry-evidence`;
        const e=await send(`${base}/eligibility?access=${basis}`);assert.equal(e.status,200);
        const enrolled=await send(`${base}/enrollment`,'POST',{command_id:crypto.randomUUID(),authority_selection:inquirySelection(basis),basis_revision:e.body.data.basis_revision,confirm:true});assert.equal(enrolled.status,200);
        const grants=enrolled.body.data.grants;
        const value={cohort:{channel_kind:'form',definition_version:1,country_code:null,population:'recorded_inquiries',counting_policy:'distinct_inquiries'},observation:{period_start:'2020-01-01T00:00:00Z',period_end:'2020-01-02T00:00:00Z',as_of:'2020-01-03T00:00:00Z',timezone:'Asia/Riyadh'},cost:null,inquiries:{count:37,coverage:'complete'},acceptance:null};
        assert.equal((await send(base,'POST',{command_id:crypto.randomUUID(),authority_selection:inquirySelection(grants['business.inquiry_evidence.record']),value})).status,200);
        await index();await open('نشاط اختبار إعادة تأهيل العرض','inquiries');const panel=inquiryPanel();await panel.locator('[data-inquiry-open]').waitFor();await openInquiryDetail();
        await workspaceSection('overview');assert.equal(await panel.locator('[data-inquiry-detail],[data-inquiry-history],.inquiry-record').count(),0);
        let reached,releaseRead;const waiting=new Promise(resolve=>{reached=resolve;});const held=new Promise(resolve=>{releaseRead=resolve;});
        await page.route(`**${base}?*`,async route=>{const response=await route.fetch();assert.equal(response.status(),200);reached();await held;await route.fulfill({response});});
        await workspaceSection('inquiries');await waiting;assert.equal(await panel.locator('[data-inquiry-detail],[data-inquiry-history],.inquiry-record,[data-inquiry-count]').count(),0);
        assert.equal(await panel.locator('[data-inquiry-message]').count(),0);releaseRead();await panel.locator('[data-inquiry-open]').waitFor();await page.unroute(`**${base}?*`);
        await inquiryRecord().click();await panel.locator('[data-inquiry-count]').fill('73');
        assert.equal((await send(`${base}/rights`,'POST',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.record',authority_selection:inquirySelection(grants['business.inquiry_evidence.record'])})).status,200);
        await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        assert.equal(await panel.locator('form').count(),0);assert.ok((await panel.locator('[data-inquiry-message]').innerText()).includes('لم تعد صلاحية'));
        assert.equal((await send(`${base}?access=${grants['business.inquiry_evidence.read']}`)).status,200);
        await openInquiryDetail();assert.equal(await panel.getByRole('button',{name:'فتح التصحيح',exact:true}).isEnabled(),true);
        assert.equal((await send(`${base}/rights`,'POST',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.read',authority_selection:inquirySelection(grants['business.inquiry_evidence.read'])})).status,200);
        assert.equal((await send(`${base}?access=${grants['business.inquiry_evidence.read']}`)).status,403);
        await workspaceSection('overview');await workspaceSection('inquiries');await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');
        assert.equal(await panel.locator('[data-inquiry-detail],[data-inquiry-history],.inquiry-record,[data-inquiry-count]').count(),0);
        return {authorizedReentryDelayed:true,noProtectedDOMBeforeQualification:true,recordOnlyRevocation:true,readStill200:true,directRevokedRead403:true,noRetainedDisclosure:true};
    });
    let aUrl;
    await check('A to B to A keeps each human draft and its originating version separate', async () => {
        await index(); await open('نشاط العميل المفوض'); aUrl = page.url();
        await page.getByLabel('اسم النشاط', { exact: true }).fill('مسودة العميل الأول');
        await index(); await open('نشاط مستقل للمتصفح');
        assert.equal(await page.locator('#bf-name').inputValue(), 'نشاط مستقل للمتصفح');
        await page.locator('#bf-name').fill('مسودة النشاط الثاني');
        await index(); await open('نشاط العميل المفوض'); assert.equal(await page.locator('#bf-name').inputValue(), 'مسودة العميل الأول');
        return { independentDrafts: 2 };
    });
    await check('late committed acknowledgement cannot alter B; explicit reconciliation uses the original command', async () => {
        const commands = []; let committed;
        const reached = new Promise(resolve => { committed = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route(`**/api/businesses/${delegated.business}/context`, async route => {
            commands.push(route.request().postDataJSON());
            const response = await route.fetch();
            if (commands.length === 1) { assert.equal(response.status(), 200); committed(); await held; }
            await route.fulfill({ response });
        });
        await page.getByRole('button', { name: 'حفظ المعلومات', exact: true }).click(); await reached;
        await index(); await open('نشاط مستقل للمتصفح');
        release();
        assert.equal(await page.locator('#bf-name').inputValue(), 'مسودة النشاط الثاني');
        assert.equal(await page.locator('[data-business-name]').textContent(), 'نشاط مستقل للمتصفح');
        await index(); await open('مسودة العميل الأول');
        await page.locator('[data-business-unknown]').waitFor();
        await page.getByRole('button', { name: 'تحقق بالطلب الأصلي', exact: true }).click();
        await page.locator('[data-business-saved]').waitFor();
        assert.equal(commands.length, 2); assert.deepEqual(commands[0], commands[1]);
        await page.unroute(`**/api/businesses/${delegated.business}/context`);
        return { committedBeforeLateReply: true, automaticRetries: 0, explicitExactCommandReconciliation: true };
    });
    await check('Arabic RTL desktop and small viewport, semantic labels and keyboard focus', async () => {
        assert.equal(await page.locator('[data-business-surface]').evaluate(e => getComputedStyle(e).direction), 'rtl');
        await page.waitForFunction(() => { const field = document.querySelector('#bf-name'); return field && !field.matches(':disabled'); });
        await page.getByLabel('اسم النشاط', { exact: true }).focus(); await page.keyboard.press('Tab');
        assert.equal(await page.locator('#bf-type').evaluate(e => document.activeElement === e), true);
        await page.screenshot({ path: path.join(run, 'business-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'business-small-viewport.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        return { rtl: true, widths: [1280,390], keyboardLabelledFields: true, nativeMobileApp: 'not implemented or tested' };
    });
    await check('withdrawing editing does not revoke independently granted reading', async () => {
        fixture('revoke-edit'); await index(); await open('مسودة العميل الأول');
        await page.locator('[data-business-readonly]').waitFor(); assert.equal(await page.locator('#bf-name').isDisabled(), true);
        assert.equal(await page.getByRole('button', { name: 'حفظ المعلومات', exact: true }).count(), 0);
        const read = await send(`/api/businesses/${delegated.business}/surface?access=${delegated.read.leaf}`);
        assert.equal(read.status, 200); assert.deepEqual(read.body.data.context_write_options, []);
        return { read: 200, editingChoices: 0, noWorkspaceAuthorityFallback: true };
    });
    await check('withdrawing reading blocks the exact URL and excludes A while leaving B available', async () => {
        const before=(await send('/api/businesses')).body.data.items.map(item=>item.business_public_id).sort();assert.ok(before.includes(delegated.business));
        fixture('revoke-read');
        const denied = await send(`/api/businesses/${delegated.business}/surface?access=${delegated.read.leaf}`); assert.equal(denied.status, 403);
        const exactPage = await send(aUrl.replace(origin,'')); assert.equal(exactPage.status, 403);
        // Chromium headless keeps both tabs visible; exercise the actual registered restoration listener.
        // This hint carries no authority; the real server must requalify and deny the withdrawn path.
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => ['unavailable','closed'].includes(document.querySelector('[data-application-spa]')?.dataset.state));
        assert.equal(await page.locator('[data-business-workspace]').isVisible(), false);
        await index();
        const remaining=await page.locator('[data-business-list] a[href^="/businesses/"]').evaluateAll(links=>links.map(link=>new URL(link.href).pathname.split('/')[2]).sort());
        assert.deepEqual(remaining,before.filter(id=>id!==delegated.business),'Only the withdrawn Business leaves qualified choices; every independent Business remains');
        await open('نشاط مستقل للمتصفح'); assert.equal(await page.locator('#bf-name').inputValue(), 'مسودة النشاط الثاني');
        return { deniedExactPath: 403, remainingIndependentBusinesses: remaining.length, restoration: 'actual listener exercised by dispatched visibilitychange', nativeTabHide: 'UNPROVEN in headless Chromium 145' };
    });
    await check('an independently updated context never acquires a dirty draft until the user explicitly adopts it', async () => {
        const url = `/api/businesses/${result.createdBusiness}`;
        const fresh = (await send(url+`/surface?access=${new URL(page.url()).searchParams.get('access')}`)).body.data;
        const selection = { ...fresh.authority_selection, requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(fresh.context_write_options[0].grant_id) }] };
        const update = await send(url+'/context', 'PUT', { command_id: crypto.randomUUID(), name: 'معلومات أحدث للنشاط الثاني', business_type_id: fresh.business.business_type_id, expected_context: fresh.business.versions.context, authority_selection: selection });
        assert.equal(update.status, 200); await index(); await open('معلومات أحدث للنشاط الثاني');
        await page.locator('[data-business-conflict]').waitFor();
        assert.equal(await page.locator('#bf-name').inputValue(), 'مسودة النشاط الثاني');
        assert.equal(await page.getByRole('button', { name: 'حفظ المعلومات', exact: true }).isDisabled(), true);
        await page.screenshot({ path: path.join(run, 'business-conflict.png'), fullPage: true });
        await page.getByRole('button', { name: 'استبدال المسودة بالمعلومات الحالية', exact: true }).click();
        assert.equal(await page.locator('#bf-name').inputValue(), 'معلومات أحدث للنشاط الثاني');
        await page.locator('#bf-name').fill('التعديل بعد مراجعة النسخة الأحدث');
        await page.getByRole('button', { name: 'حفظ المعلومات', exact: true }).click(); await page.locator('[data-business-saved]').waitFor();
        return { staleDraftRetained: true, automaticRebase: false, explicitAdoptionAndSave: true };
    });
    const surface = async () => {
        const response = await send(`/api/businesses/${new URL(page.url()).pathname.split('/').at(-1)}/surface?access=${new URL(page.url()).searchParams.get('access')}`);
        assert.equal(response.status, 200); return response.body.data;
    };
    const factForm = kind => page.locator(`[data-business-fact-form="${kind}"]`);
    const chooseFactMarkets = async codes => {await factForm('markets').locator('#bf-market-search').fill('');for(const option of await factForm('markets').locator('[data-fact-country]:checked').all()){if(!codes.includes(await option.getAttribute('data-fact-country')))await option.uncheck();}for(const code of codes)await factForm('markets').locator(`[data-fact-country="${code}"]`).check();};
    const selectedFactMarkets = () => factForm('markets').locator('[data-fact-country]:checked').evaluateAll(options => options.map(option=>option.value).sort());
    await check('independent markets, explicit whole-business intent and advertiser preferences are discoverable and persist through the shared API', async () => {
        await workspaceSection('direction'); await ready('[data-business-facts]');
        await chooseFactMarkets(['SA', 'JO']);
        await factForm('markets').getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
        await factForm('markets').locator('[data-fact-saved]').waitFor();
        await page.waitForFunction(() => document.querySelector('[data-business-fact-current]')?.textContent.includes('الأردن'));
        assert.deepEqual((await surface()).business.declared_markets, ['JO', 'SA']);
        await page.getByRole('button', { name: 'النية التجارية الحالية', exact: true }).click();
        const intent = factForm('intent');
        await intent.locator('#bf-intent-direction').selectOption('increase_value');
        await intent.locator('#bf-intent-meaning').fill('زيادة مشتريات العملاء المؤهلين');
        for (const checkbox of await intent.locator('input[type=checkbox]:not([data-fact-intent-clear])').all()) {
            if (!(await checkbox.isChecked()) && !await checkbox.evaluate(el => el.closest('label').textContent.includes('إزالة النية'))) await checkbox.check();
        }
        await intent.locator('#bf-intent-meaning').fill('زيادة المشتريات المؤهلة في السوقين');
        assert.equal(await intent.locator('[data-fact-confirm="outcome"]').isChecked(), false);
        for (const checkbox of await intent.locator('input[type=checkbox]:not([data-fact-intent-clear])').all()) {
            if (!await checkbox.evaluate(el => el.closest('label').textContent.includes('إزالة النية'))) await checkbox.check();
        }
        await intent.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
        await intent.locator('[data-fact-saved]').waitFor();
        assert.equal((await surface()).business.current_intent.outcome.meaning, 'زيادة المشتريات المؤهلة في السوقين');
        await page.getByRole('button', { name: 'أولويات المعلن', exact: true }).click();
        const preferences = factForm('preferences');
        await preferences.getByLabel('زيادة المبيعات والعملاء الفعليين', { exact: true }).check();
        await preferences.getByLabel('زيادة عدد المبيعات الفعلية', { exact: true }).check();
        await preferences.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
        await preferences.locator('[data-fact-saved]').waitFor();
        await page.waitForFunction(() => !document.querySelector('[data-business-rechecking]'));
        assert.equal(await preferences.getByLabel('زيادة المبيعات والعملاء الفعليين', { exact: true }).isChecked(), true);
        assert.equal(await preferences.getByLabel('زيادة المبيعات والعملاء الفعليين', { exact: true }).evaluate(el => getComputedStyle(el).appearance), 'auto');
        assert.equal(await preferences.locator('[role=alert]').count(), 0);
        assert.equal(await page.locator('[data-business-fact-current]').textContent().then(text => text.includes('زيادة المبيعات والعملاء الفعليين')), true);
        const current = await surface(); assert.equal(current.business.preferences.priority, 'sales');
        assert.deepEqual(current.business.preferences.results.sales, ['actual_sales']);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'business-facts-small-viewport.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        return { markets: ['JO','SA'], explicitIntent: true, confirmationsResetOnEdit: true, preferences: 'sales', responsiveWidth: 390 };
    });
    let third;
    await check('fact drafts and missing declarations stay separate when switching between independent businesses', async () => {
        third = (await send('/api/businesses', 'POST', { command_id: crypto.randomUUID(), name: 'النشاط الثالث لاختبار الفصل', business_type_id: null })).body.data;
        await index(); await open('النشاط الثالث لاختبار الفصل'); await workspaceSection('direction');
        assert.deepEqual((await surface()).business.declared_markets, []);
        assert.equal((await surface()).business.current_intent, null);
        await chooseFactMarkets(['AE']);
        await index(); await open('التعديل بعد مراجعة النسخة الأحدث'); await workspaceSection('direction');
        assert.deepEqual(await selectedFactMarkets(), ['JO','SA']);
        await index(); await open('النشاط الثالث لاختبار الفصل'); await workspaceSection('direction');
        assert.deepEqual(await selectedFactMarkets(), ['AE']);
        return { independentDrafts: true, absentIntentIsNull: true };
    });
    await check('a late fact acknowledgement cannot change another business and exact explicit retry causes only one effect', async () => {
        const commands = []; let committed;
        const reached = new Promise(resolve => { committed = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route(`**/api/businesses/${third.business_public_id}/markets`, async route => {
            commands.push(route.request().postDataJSON()); const response = await route.fetch();
            if (commands.length === 1) { assert.equal(response.status(), 200); committed(); await held; }
            await route.fulfill({ response });
        });
        await factForm('markets').getByRole('button', { name: 'حفظ التغييرات', exact: true }).click(); await reached;
        await index(); await open('التعديل بعد مراجعة النسخة الأحدث'); await workspaceSection('direction'); release();
        assert.deepEqual((await surface()).business.declared_markets, ['JO','SA']);
        await index(); await open('النشاط الثالث لاختبار الفصل'); await workspaceSection('direction');
        await factForm('markets').locator('[data-fact-unknown]').waitFor();
        await factForm('markets').getByRole('button', { name: 'تحقق بالطلب الأصلي', exact: true }).click();
        await factForm('markets').locator('[data-fact-saved]').waitFor();
        assert.equal(commands.length, 2); assert.deepEqual(commands[0],commands[1]);
        assert.equal((await surface()).business.versions.context, 1);
        await page.unroute(`**/api/businesses/${third.business_public_id}/markets`);
        return { commands: 2, effects: 1, changedBusinessUntouched: true };
    });
    await check('withdrawn market declaration disappears while independent reading and other fact actions remain available', async () => {
        const current = await surface();
        const session = JSON.parse(await readFile(path.join(run, 'business-surface-fixture.json'),'utf8'));
        const self = await send(`/api/businesses/${third.business_public_id}/grants`, 'POST', { command_id: crypto.randomUUID(), effect: 'revoke',
            grantee_public_id: session.actor_public_id, action: 'business.markets.declare', parent_grant_id: null, grant_id: third.grant_refs['business.markets.declare'],
            expected_grant: 1, expires_at: null, delegable: false, realm_workspace_public_id: null, authority_selection: { ...current.authority_selection,
                requirements: [{ requirement_kind: 'business', leaf_grant_id: third.grant_refs['business.markets.declare'] }] } });
        assert.equal(self.status, 200);
        await index(); await open('النشاط الثالث لاختبار الفصل'); await workspaceSection('direction');
        assert.equal(await factForm('markets').count(), 0);
        assert.equal((await surface()).fact_write_options.intent.length, 1);
        await page.getByRole('button', { name: 'النية التجارية الحالية', exact: true }).click();
        assert.equal(await factForm('intent').count(), 1);
        return { read: 200, marketActions: 0, intentActions: 1 };
    });
    const collaboration = page.locator('[data-business-collaboration]');
    const settled = () => page.waitForFunction(() => !document.querySelector('[data-business-rechecking]'));
    const linkState = async (business = third.business_public_id, access = third.grant_refs['business.context.read']) => {
        const response = await send(`/api/businesses/${business}/surface?access=${access}`);
        assert.equal(response.status, 200); return response.body.data;
    };
    let workspace, firstLink;
    await check('team collaboration is discoverable, draft authority cannot become agency authority and ending preserves history', async () => {
        await workspaceSection('access'); await settled(); const current = await linkState();
        assert.equal(current.collaboration.options.length, 1);
        assert.equal(current.collaboration.options[0].allows_agency, false);
        assert.equal(current.collaboration.workspaces.length, 2);
        workspace = current.collaboration.workspaces.find(w => w.slug === 'spa-b-1'); assert.ok(workspace);
        assert.equal('name' in workspace, false);
        await collaboration.locator('#bf-link-workspace').selectOption(workspace.public_id);
        assert.equal(await collaboration.locator('#bf-link-kind option[value=agency]').count(), 0);
        await collaboration.getByRole('button', { name: 'ربط المساحة بالنشاط', exact: true }).click();
        await collaboration.locator('[data-collaboration-confirmed]').waitFor(); await settled();
        firstLink = (await linkState()).collaboration.relationships[0];
        assert.equal(firstLink.revision, 1); assert.equal(firstLink.state, 'active');
        await collaboration.getByRole('button', { name: 'إنهاء العلاقة', exact: true }).click(); await settled();
        await page.waitForFunction(() => !!document.querySelector('[data-relationship-state=ended]'));
        const ended = (await linkState()).collaboration.relationships[0];
        assert.equal(ended.revision, 2); assert.equal(ended.link_ref, firstLink.link_ref);
        return { add: 1, end: 2, draftAgencyOption: false, metadata: 'independently qualified slug only' };
    });
    await check('stale relationship revision is rejected before effect and requires explicit review without automatic rebase', async () => {
        await settled(); await collaboration.locator('#bf-link-workspace').selectOption(workspace.public_id);
        const current = await linkState();
        const make = (effect, revision) => ({ command_id: crypto.randomUUID(), workspace_public_id: workspace.public_id, kind: 'collaboration', effect,
            expected_revision: revision, authority_selection: { ...current.authority_selection,
                requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(current.collaboration.options[0].grant_id) }] } });
        const add = await send(`/api/businesses/${third.business_public_id}/workspace-links`, 'POST', make('add', 2)); assert.equal(add.status, 200);
        assert.notEqual((await linkState()).collaboration.relationships[0].link_ref, firstLink.link_ref);
        const end = await send(`/api/businesses/${third.business_public_id}/workspace-links`, 'POST', make('end', 3)); assert.equal(end.status, 200);
        const commands = [];
        await page.route(`**/api/businesses/${third.business_public_id}/workspace-links`, async route => {
            commands.push(route.request().postDataJSON()); await route.continue();
        });
        await collaboration.getByRole('button', { name: 'ربط المساحة بالنشاط', exact: true }).click();
        await collaboration.locator('[data-collaboration-conflict]').waitFor(); await settled();
        assert.equal(commands.length, 1); assert.equal(commands[0].expected_revision, 2);
        assert.equal(await collaboration.locator('[data-collaboration-unknown]').count(), 0);
        await collaboration.getByRole('button', { name: 'اعتماد الحالة المعروضة لطلب جديد', exact: true }).click();
        await collaboration.getByRole('button', { name: 'ربط المساحة بالنشاط', exact: true }).click();
        await collaboration.locator('[data-collaboration-confirmed]').waitFor(); await settled();
        assert.equal(commands.length, 2); assert.equal(commands[1].expected_revision, 4);
        assert.notEqual(commands[0].command_id, commands[1].command_id);
        assert.equal((await linkState()).collaboration.relationships[0].revision, 5);
        await page.unroute(`**/api/businesses/${third.business_public_id}/workspace-links`);
        return { rejectedOriginalRevision: 2, explicitNewRevision: 4, finalRevision: 5, automaticRetries: 0 };
    });
    await check('late relationship acknowledgement stays with its business and exact reconciliation does not repeat the effect', async () => {
        const commands = []; let committed;
        const reached = new Promise(resolve => { committed = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route(`**/api/businesses/${third.business_public_id}/workspace-links`, async route => {
            commands.push(route.request().postDataJSON()); const response = await route.fetch();
            if (commands.length === 1) { assert.equal(response.status(), 200); committed(); await held; }
            await route.fulfill({ response });
        });
        await collaboration.getByRole('button', { name: 'إنهاء العلاقة', exact: true }).click(); await reached;
        await index(); await open('التعديل بعد مراجعة النسخة الأحدث','access'); release();
        assert.deepEqual((await surface()).collaboration.relationships, []);
        await index(); await open('النشاط الثالث لاختبار الفصل','access');
        await collaboration.locator('[data-collaboration-unknown]').waitFor();
        await collaboration.getByRole('button', { name: 'تحقق بالطلب الأصلي', exact: true }).click();
        await collaboration.locator('[data-collaboration-confirmed]').waitFor(); await settled();
        assert.equal(commands.length, 2); assert.deepEqual(commands[0], commands[1]);
        assert.equal((await linkState()).collaboration.relationships[0].revision, 6);
        await page.unroute(`**/api/businesses/${third.business_public_id}/workspace-links`);
        return { explicitOriginalCommandReplay: true, effects: 1, independentBusinessUntouched: true };
    });
    await check('qualified agency relationship is managed through the same resource without granting access to workspace members', async () => {
        fixture('agency-setup');
        const agency = JSON.parse(await readFile(path.join(run, 'business-agency-fixture.json'), 'utf8'));
        await index(); await open('نشاط إدارة الوكالة','access'); await settled();
        const current = await linkState(agency.business, agency.read.leaf);
        assert.equal(current.collaboration.options[0].allows_agency, true);
        const agencyWorkspace=current.collaboration.workspaces.find(item=>item.slug==='spa-b-1');
        assert.ok(agencyWorkspace);
        await collaboration.locator('#bf-link-workspace').selectOption(agencyWorkspace.public_id);
        await collaboration.locator('#bf-link-kind').selectOption('agency');
        await collaboration.getByRole('button', { name: 'ربط المساحة بالنشاط', exact: true }).click();
        await collaboration.locator('[data-collaboration-confirmed]').waitFor(); await settled();
        assert.equal((await linkState(agency.business, agency.read.leaf)).collaboration.relationships[0].kind, 'agency');
        await collaboration.getByRole('button', { name: 'إنهاء العلاقة', exact: true }).click();
        await page.waitForFunction(() => !!document.querySelector('[data-relationship-state=ended][data-relationship-kind=agency]')); await settled();
        await collaboration.locator('#bf-link-workspace').selectOption(agencyWorkspace.public_id);
        await collaboration.locator('#bf-link-kind').selectOption('agency');
        await collaboration.getByRole('button', { name: 'ربط المساحة بالنشاط', exact: true }).click();
        await collaboration.locator('[data-collaboration-confirmed]').waitFor(); await settled();
        const latest = (await linkState(agency.business, agency.read.leaf)).collaboration.relationships[0];
        assert.equal(latest.state, 'active'); assert.equal(latest.revision, 3);
        fixture('agency-audit');
        await collaboration.locator('#bf-link-workspace').focus(); await page.keyboard.press('Tab');
        assert.equal(await collaboration.locator('#bf-link-kind').evaluate(e => document.activeElement === e), true);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'business-collaboration-small-viewport.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        return { qualification: 'SYNTHETIC TEST ROOT; NOT AN OPERATING ISSUER', agencyRevision: 3, keyboard: true, width: 390 };
    });
    await check('withdrawing independent workspace authority removes its metadata and rejects forged management without withdrawing business reading', async () => {
        fixture('withdraw-workspace');
        await index(); await open('النشاط الثالث لاختبار الفصل','access'); await settled();
        const current = await linkState();
        assert.equal(current.collaboration.workspaces.some(w => w.public_id === workspace.public_id), false);
        assert.deepEqual(current.collaboration.relationships, []);
        assert.equal(current.collaboration.workspaces.length, 1);
        assert.equal(await collaboration.locator(`#bf-link-workspace option[value="${workspace.public_id}"]`).count(), 0);
        const denied = await send(`/api/businesses/${third.business_public_id}/workspace-links`, 'POST', {
            command_id: crypto.randomUUID(), workspace_public_id: workspace.public_id, kind: 'collaboration', effect: 'add', expected_revision: 6,
            authority_selection: { ...current.authority_selection, requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(current.collaboration.options[0].grant_id) }] }
        });
        assert.equal(denied.status, 403); assert.equal((await linkState()).business.name, 'النشاط الثالث لاختبار الفصل');
        return { independentBusinessRead: 200, forgedRelationshipWrite: 403, withdrawnMetadata: 0, remainingWorkspaceChoices: 1 };
    });
    let delegationFixture, firstGrant, secondGrant, otherClientGrant, recipientPage, recipientContext;
    const delegation = page.locator('[data-business-delegation]');
    const openDelegation = async key => {
        await index();
        const activity = delegationFixture[key];
        await page.locator(`[data-business-list] a[href="/businesses/${activity.business}?access=${activity.read}"]`).click();
        await ready('[data-business-public]'); await workspaceSection('access'); await ready('[data-business-delegation]'); await settled();
    };
    const delegationState = async key => {
        const activity = delegationFixture[key];
        const response = await send(`/api/businesses/${activity.business}/surface?access=${activity.read}`);
        assert.equal(response.status, 200); return response.body.data;
    };
    await check('a qualified issuer can discover a specific delegation, issue it and the recipient opens the actual customer business', async () => {
        fixture('delegation-setup'); delegationFixture = JSON.parse(await readFile(path.join(run, 'business-delegation-fixture.json'), 'utf8'));
        await openDelegation('a');
        assert.equal((await delegationState('a')).delegation.options[0].grant_id, String(delegationFixture.a.parent));
        recipientContext = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ar-JO' });
        recipientPage = await recipientContext.newPage();
        recipientPage.on('pageerror', e => result.errors.push(e.message));
        await recipientPage.goto(origin+'/login');
        const logged = await sendFrom(recipientPage, '/login', 'POST', { email: delegationFixture.recipient_email, password: 'DisposableGoalsProof42!' });
        assert.equal(logged.status, 200);
        await recipientPage.goto(origin+'/businesses');
        await recipientPage.locator('[data-business-empty]').waitFor();
        assert.equal((await sendFrom(recipientPage, '/api/businesses')).body.data.items.length, 0);
        await recipientPage.locator('[data-business-account-reference] summary').click();
        await recipientPage.locator('[data-business-account-id]').waitFor({ state: 'visible' });
        const recipientId = (await recipientPage.locator('[data-business-account-id]').textContent()).trim();
        assert.equal(recipientId, delegationFixture.recipient_public_id);
        await recipientPage.setViewportSize({ width: 390, height: 844 });
        assert.equal(await recipientPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await recipientPage.screenshot({ path: path.join(run, 'business-recipient-empty-account.png'), fullPage: true });
        await recipientPage.setViewportSize({ width: 1280, height: 900 });
        await delegation.locator('#bf-delegation-recipient').fill(recipientId);
        await delegation.getByRole('button', { name: 'منح الحق المحدد', exact: true }).click();
        await delegation.locator('[data-delegation-confirmed]').waitFor(); await settled();
        firstGrant = (await delegationState('a')).delegation.issued[0].grant_id;
        assert.equal(await delegation.locator(`[data-issued-grant="${firstGrant}"]`).getAttribute('data-issued-status'), 'active');
        await recipientPage.reload();
        await recipientPage.locator(`[data-business-list] a[href="/businesses/${delegationFixture.a.business}?access=${firstGrant}"]`).click();
        await recipientPage.locator(`[data-business-public="${delegationFixture.a.business}"]`).waitFor();
        const desktopPicker=recipientPage.locator('.advertiser-sidebar [data-business-switch]');
        await desktopPicker.waitFor();
        const selected=await desktopPicker.evaluate(select=>({label:select.selectedOptions[0]?.textContent,value:select.value}));
        assert.match(selected.label,/عميل التفويض الأول/);
        assert.deepEqual(JSON.parse(selected.value).slice(0,2),[delegationFixture.a.business,String(firstGrant)]);
        await workspaceSection('access',recipientPage);
        assert.equal(await recipientPage.locator('[data-delegation-readonly]').count(), 1);
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.a.business}/surface?access=${firstGrant}`)).status, 200);
        return { issued: firstGrant, recipientRealRead: 200, recipientDelegationOptions: 0, recipientAccountWithoutBusiness: true, identifierDiscoveredInUI: true,
            operatingIssuerPolicy: 'NOT_PROVEN; synthetic qualified parent' };
    });
    await check('an unknown delegation command stays frozen through A to B to A and explicit replay issues one right only', async () => {
        const commands = []; let committed;
        const reached = new Promise(resolve => { committed = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route(`**/api/businesses/${delegationFixture.a.business}/grants`, async route => {
            commands.push(route.request().postDataJSON()); const response = await route.fetch();
            if (commands.length === 1) { assert.equal(response.status(), 200); committed(); await held; }
            await route.fulfill({ response });
        });
        await delegation.getByRole('button', { name: 'منح الحق المحدد', exact: true }).click(); await reached;
        await openDelegation('b'); release();
        assert.deepEqual((await delegationState('b')).delegation.issued, []);
        assert.equal(await delegation.locator('#bf-delegation-recipient').inputValue(), '');
        await openDelegation('a'); await delegation.locator('[data-delegation-unknown]').waitFor();
        await delegation.getByRole('button', { name: 'تحقق بالطلب الأصلي', exact: true }).click();
        await delegation.locator('[data-delegation-confirmed]').waitFor(); await settled();
        assert.equal(commands.length, 2); assert.deepEqual(commands[0], commands[1]);
        const issued = (await delegationState('a')).delegation.issued; assert.equal(issued.length, 2);
        secondGrant = issued.find(row => row.grant_id !== firstGrant).grant_id;
        await page.unroute(`**/api/businesses/${delegationFixture.a.business}/grants`);
        return { attempts: 2, materialEffects: 1, exactReplay: true, otherCustomerUnchanged: true };
    });
    await check('withdrawing an exact issued right preserves a second independent path and another customer', async () => {
        await openDelegation('b');
        await delegation.locator('#bf-delegation-recipient').fill(delegationFixture.recipient_public_id);
        await delegation.getByRole('button', { name: 'منح الحق المحدد', exact: true }).click();
        await delegation.locator('[data-delegation-confirmed]').waitFor(); await settled();
        otherClientGrant = (await delegationState('b')).delegation.issued[0].grant_id;
        await openDelegation('a');
        await delegation.locator(`[data-issued-grant="${firstGrant}"]`).getByRole('button', { name: 'سحب هذا الحق', exact: true }).click();
        await delegation.locator('[data-delegation-confirmed]').waitFor(); await settled();
        await page.waitForFunction(id => document.querySelector(`[data-issued-grant="${id}"]`)?.dataset.issuedStatus === 'revoked', firstGrant);
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.a.business}/surface?access=${firstGrant}`)).status, 403);
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.a.business}/surface?access=${secondGrant}`)).status, 200);
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.b.business}/surface?access=${otherClientGrant}`)).status, 200);
        await delegation.locator('#bf-delegation-parent').focus(); await page.keyboard.press('Tab');
        assert.equal(await delegation.locator('#bf-delegation-recipient').evaluate(e => document.activeElement === e), true);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'business-delegation-small-viewport.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        return { withdrawnRead: 403, independentRead: 200, otherCustomerRead: 200, width: 390, keyboard: true };
    });
    await check('withdrawing the parent invalidates its descendant without rewriting the recorded child or the other customer', async () => {
        fixture('delegation-revoke-parent');
        await openDelegation('a'); await delegation.locator('[data-delegation-readonly]').waitFor();
        const current = await delegationState('a');
        assert.deepEqual(current.delegation.options, []); assert.deepEqual(current.delegation.issued, []);
        assert.equal(current.business.name, 'عميل التفويض الأول');
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.a.business}/surface?access=${secondGrant}`)).status, 403);
        assert.equal((await sendFrom(recipientPage, `/api/businesses/${delegationFixture.b.business}/surface?access=${otherClientGrant}`)).status, 200);
        await recipientContext.close();
        return { childDeniedAfterParentWithdrawal: 403, independentIssuerReadPreserved: 200, otherCustomerRead: 200 };
    });
    let boundaryFixture, firstMembership, recreatedMembership;
    const boundaryPanel = page.locator('[data-boundary-editor]');
    const boundaryIndex = async () => {
        const currentPath=new URL(page.url()).pathname;
        if(currentPath==='/data-rights')return;
        if(currentPath.startsWith('/data-rights/'))await page.locator('[data-boundary-surface] a[href="/data-rights"]').click();
        else {
            if(currentPath!=='/businesses')await index();
            await openCreated('sources');
            await page.locator('[data-workspace-panel="sources"] a[href="/data-rights"]').click();
        }
        await page.waitForURL(origin+'/data-rights'); await ready('[data-boundary-surface]');
    };
    const openBoundary = async key => {
        await boundaryIndex(); const b = boundaryFixture[key].boundary;
        await page.locator(`[data-boundary-list] a[href="/data-rights/${b.boundary_id}?access=${b.grant_id}"]`).click();
        await ready('[data-boundary-editor]');
    };
    const boundaryState = async key => {
        const b = boundaryFixture[key].boundary;
        const response = await send(`/api/data-rights/boundaries/${b.boundary_id}?access=${b.grant_id}`);
        assert.equal(response.status, 200); return response.body.data;
    };
    const boundarySettled = () => page.waitForFunction(() => !document.querySelector('[data-boundary-rechecking]'));
    const attachBoundary = async key => {
        await boundaryPanel.locator('#bf-boundary-business').selectOption(String(boundaryFixture[key].read.leaf));
        await boundaryPanel.getByRole('button', { name: 'ربط النطاق بالنشاط', exact: true }).click();
        await boundaryPanel.locator('[data-boundary-confirmed]').waitFor(); await boundarySettled();
    };
    await check('client boundary management is discoverable and requires both independently selected authorities without exposing another custodian', async () => {
        await index(); await boundaryIndex(); await page.locator('[data-boundary-empty]').waitFor();
        fixture('boundary-setup'); boundaryFixture = JSON.parse(await readFile(path.join(run, 'business-boundary-fixture.json'), 'utf8'));
        // Clicking the current route is a duplicate navigation, not a readback of an
        // independently issued right. Exercise the actual current-document restore path.
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => document.querySelectorAll('[data-boundary-list] li').length === 2);
        assert.equal(await page.locator(`[data-boundary-list] a[href*="${boundaryFixture.foreign.boundary_id}"]`).count(), 0);
        await openBoundary('a'); await attachBoundary('a');
        const current = await boundaryState('a'); firstMembership = current.memberships[0];
        assert.equal(firstMembership.business_public_id, boundaryFixture.a.business); assert.equal(firstMembership.revision, 1);
        assert.equal(JSON.stringify(current).includes(boundaryFixture.a.name), false);
        assert.deepEqual((await boundaryState('b')).memberships, []);
        const denied = await send('/api/data-rights/commands', 'POST', { command_id: crypto.randomUUID(), target_kind: 'boundary_subject',
            target_ref: boundaryFixture.b.boundary.boundary_id, effect: 'attach_subject', payload: { business_public_id: boundaryFixture.b.business, branch_id: null },
            expected_revision: 0, authority_selection: current.authority_selection });
        assert.equal(denied.status, 403);
        await openBoundary('b'); await attachBoundary('b');
        assert.equal((await boundaryState('b')).memberships[0].revision, 1);
        return { visibleOwnBoundaries: 2, foreignBoundaryHidden: true, bothAuthoritiesRequired: true, independentBusinessNameRead: true };
    });
    await check('a late coverage withdrawal acknowledgement retains its exact command across business navigation and does not repeat the effect', async () => {
        await openBoundary('a'); const commands = []; let committed;
        const reached = new Promise(resolve => { committed = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route('**/api/data-rights/commands', async route => {
            commands.push(route.request().postDataJSON()); const response = await route.fetch();
            if (commands.length === 1) { assert.equal(response.status(), 200); committed(); await held; }
            await route.fulfill({ response });
        });
        await boundaryPanel.getByRole('button', { name: 'إنهاء هذه التغطية', exact: true }).click(); await reached;
        await index(); await open('التعديل بعد مراجعة النسخة الأحدث'); release();
        assert.equal(await page.locator('[data-business-name]').textContent(), 'التعديل بعد مراجعة النسخة الأحدث');
        await openBoundary('a'); await boundaryPanel.locator('[data-boundary-unknown]').waitFor();
        await boundaryPanel.getByRole('button', { name: 'تحقق بالطلب الأصلي', exact: true }).click();
        await boundaryPanel.locator('[data-boundary-confirmed]').waitFor(); await boundarySettled();
        assert.equal(commands.length, 2); assert.deepEqual(commands[0], commands[1]);
        assert.equal((await boundaryState('a')).memberships[0].revision, 2);
        assert.equal((await boundaryState('b')).memberships[0].state, 'active');
        await page.unroute('**/api/data-rights/commands');
        return { exactReplay: true, effects: 1, otherBoundaryUnaffected: true, automaticRetries: 0 };
    });
    await check('a custodian ends recreated coverage after business authority is withdrawn and an old membership cannot target its replacement', async () => {
        await attachBoundary('a'); recreatedMembership = (await boundaryState('a')).memberships[0];
        assert.notEqual(recreatedMembership.membership_id, firstMembership.membership_id); assert.equal(recreatedMembership.revision, 3);
        let heldRead, deliveredRead, heldOnce = false;
        const reached = new Promise(resolve => { heldRead = resolve; });
        const delivered = new Promise(resolve => { deliveredRead = resolve; });
        const held = new Promise(resolve => { release = resolve; });
        await page.route('**/api/businesses', async route => {
            if (route.request().method() !== 'GET' || heldOnce) { await route.continue(); return; }
            heldOnce = true;
            const response = await route.fetch(); assert.equal(response.status(), 200);
            const old = await response.json(); assert.equal(old.data.items.some(item => item.name === boundaryFixture.a.name), true);
            heldRead(); await held; await route.fulfill({ response }); deliveredRead();
        });
        // A fresh restoration starts an auxiliary read. Delay its genuinely qualified
        // old response across a second restoration and real Business grant withdrawal.
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await reached;
        fixture('boundary-drop-business-authority');
        assert.equal((await send(`/api/businesses/${boundaryFixture.a.business}/surface?access=${boundaryFixture.a.read.leaf}`)).status, 403);
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(name => document.querySelector('[data-application-spa]')?.getAttribute('data-state') === 'ready'
            && !!document.querySelector('#bf-boundary-business') && !Array.from(document.querySelector('#bf-boundary-business').options).some(option => option.textContent.includes(name)), boundaryFixture.a.name);
        await page.waitForFunction(() => !document.querySelector('#bf-boundary-business')?.matches(':disabled'));
        release(); await delivered;
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await boundaryPanel.locator('#bf-boundary-business option').allTextContents().then(names => names.some(name => name.includes(boundaryFixture.a.name))), false);
        await page.unroute('**/api/businesses');
        assert.equal((await send(`/api/businesses/${boundaryFixture.a.business}/surface?access=${boundaryFixture.a.read.leaf}`)).status, 403);
        const current = await boundaryState('a');
        const stale = await send('/api/data-rights/commands', 'POST', { command_id: crypto.randomUUID(), target_kind: 'boundary_subject',
            target_ref: firstMembership.membership_id, effect: 'detach_subject', payload: {}, expected_revision: 3, authority_selection: current.authority_selection });
        assert.equal(stale.status, 409); assert.equal(stale.body.error, 'business_relation_revision_rejected');
        await boundaryPanel.getByRole('button', { name: 'إنهاء هذه التغطية', exact: true }).click();
        await boundaryPanel.locator('[data-boundary-confirmed]').waitFor(); await boundarySettled();
        assert.equal((await boundaryState('a')).memberships[0].revision, 4);
        assert.equal((await boundaryState('a')).memberships[0].state, 'ended');
        assert.equal((await boundaryState('b')).memberships[0].state, 'active');
        await page.waitForFunction(() => !document.querySelector('#bf-boundary-business')?.matches(':disabled'));
        await boundaryPanel.locator('#bf-boundary-business').focus(); assert.equal(await boundaryPanel.locator('#bf-boundary-business').evaluate(e => document.activeElement === e), true);
        await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'client-boundary-small-viewport.png'), fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        return { businessRead: 403, custodianWithdrawal: 200, revision: 4, oldIdentityRejected: 409, otherBoundaryPreserved: true,
            oldQualifiedBusinessReplyDiscarded: true, width: 390 };
    });
    await check('withdrawing boundary management conceals its exact URL and current presentation without revoking another boundary or business', async () => {
        fixture('boundary-withdraw-manager'); const b = boundaryFixture.a.boundary;
        assert.equal((await send(`/api/data-rights/boundaries/${b.boundary_id}?access=${b.grant_id}`)).status, 403);
        assert.equal((await send(`/data-rights/${b.boundary_id}?access=${b.grant_id}`)).status, 403);
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => ['unavailable','closed'].includes(document.querySelector('[data-application-spa]')?.dataset.state));
        assert.equal(await page.locator('[data-boundary-editor]').isVisible(), false);
        await index(); await boundaryIndex(); assert.equal(await page.locator('[data-boundary-list] li').count(), 1);
        assert.equal((await boundaryState('b')).memberships[0].state, 'active');
        assert.equal((await send(`/api/businesses/${boundaryFixture.b.business}/surface?access=${boundaryFixture.b.read.leaf}`)).status, 200);
        return { exactApiAndPageDenied: 403, currentPresentationConcealed: true, otherBusinessRead: 200, remainingBoundaryChoices: 1 };
    });
    let sourceFixture;
    const sourceIndex = async () => {
        await index();await openCreated('sources');
        await page.locator('[data-workspace-panel="sources"] a[href="/source-rights"]').click();await page.waitForURL(origin+'/source-rights');await ready('[data-source-rights-surface]');
    };
    const openSource = async key => { await page.locator('[data-source-rights-list] li').filter({ has: page.getByRole('heading', { name: sourceFixture[key].name, exact: true }) }).getByRole('link', { name: 'فتح إدارة المصدر', exact: true }).click(); await ready('[data-source-rights-editor]'); };
    const sourceState = async key => { const s = sourceFixture[key]; const response = await send(`/api/source-rights/${s.source}?access=${s.grants['source.manage']}`); assert.equal(response.status, 200); return response.body.data; };
    await check('qualified source discovery and adoption expose no foreign source or inferred business/client-data right', async () => {
        await index(); await sourceIndex(); assert.equal(await page.locator('[data-source-rights-empty]').count(), 1);
        fixture('source-setup'); sourceFixture = JSON.parse(await readFile(path.join(run, 'business-source-fixture.json'), 'utf8'));
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await ready('[data-source-rights-list]');
        assert.equal(await page.locator('[data-source-rights-list] li').count(), 2); assert.equal((await page.textContent('body')).includes(sourceFixture.foreign.name), false);
        await openSource('a'); assert.equal((await sourceState('a')).source.adopted, false);
        await page.locator('[data-source-confirm]').check(); await page.locator('[data-source-adopt]').click();
        await page.locator('[data-source-rights-confirmed]').waitFor(); await page.waitForFunction(() => !document.querySelector('[data-source-adopt]'));
        assert.equal((await sourceState('a')).source.adopted, true);
        assert.equal((await sourceState('b')).source.adopted, false);
        return { sources: 2, foreignHidden: true, adopted: true, otherUnchanged: true, providerVerified: false };
    });
    await check('lost protected source rename ACK is reconciled exactly across source and Business navigation', async () => {
        let lost;
        const done = new Promise(resolve => { lost = resolve; });
        await page.route('**/api/data-rights/commands', async route => {
            const body = route.request().postDataJSON();
            if (body.target_kind === 'source' && body.effect === 'rename') {
                const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); lost(body); return;
            }
            await route.continue();
        });
        await page.locator('#bf-source-label').fill('اسم مصدر محفوظ جديد'); await page.getByRole('button', { name: 'حفظ اسم المصدر', exact: true }).click();
        const original = await done; await page.locator('[data-source-reconcile]').waitFor(); await page.unroute('**/api/data-rights/commands');
        assert.equal((await sourceState('a')).source.revision, 2);
        sourceFixture.a.name = (await sourceState('a')).source.label;
        await index(); await sourceIndex(); await openSource('b'); assert.equal(await page.locator('[data-source-reconcile]').count(), 0);
        await sourceIndex(); await openSource('a');
        const replay = page.waitForRequest(request => request.url().endsWith('/api/data-rights/commands') && request.method() === 'POST');
        await page.locator('[data-source-reconcile]').click(); assert.deepEqual((await replay).postDataJSON(), original);
        await page.locator('[data-source-rights-confirmed]').waitFor();
        const after = await sourceState('a'); assert.equal(after.source.revision, 2); assert.equal(after.source.label, 'اسم مصدر محفوظ جديد');
        sourceFixture.a.name = after.source.label;
        return { exactReplay: true, sourceRevision: 2, otherSourcePending: false, nativeHistoryPreserved: true };
    });
    await check('an adopted source declares an independently qualified new technical scope without a legacy writer or invented provider permission', async () => {
        fixture('source-add-scope'); sourceFixture = JSON.parse(await readFile(path.join(run, 'business-source-fixture.json'), 'utf8'));
        sourceFixture.a.name = 'اسم مصدر محفوظ جديد';
        await sourceIndex();
        await page.locator(`a[href="/source-rights/${sourceFixture.a.source}?access=${sourceFixture.a.scope_manager}"]`).click();
        await ready('[data-source-rights-editor]'); await page.locator('[data-source-declare-scope]').waitFor();
        assert.equal(await page.locator('#bf-source-label').count(), 0);
        await page.locator('[data-source-confirm]').check(); assert.equal(await page.locator('[data-source-confirm]').evaluate(e => getComputedStyle(e).appearance), 'auto');
        await page.locator('[data-source-declare-scope]').click();
        await page.waitForFunction(() => !document.querySelector('[data-source-declare-scope]') && !!document.querySelector('#bf-source-label'));
        const s = await send(`/api/source-rights/${sourceFixture.a.source}?access=${sourceFixture.a.scope_manager}`);
        assert.equal(s.status, 200); assert.equal(s.body.data.source.scope_declared, true); assert.equal(s.body.data.source.operation, 'discovery.read');
        assert.equal(s.body.data.source.revision, 3); assert.equal(s.body.data.source.status, 'UNVERIFIED');
        await sourceIndex(); await page.locator(`a[href="/source-rights/${sourceFixture.a.source}?access=${sourceFixture.a.grants['source.manage']}"]`).click(); await ready('[data-source-rights-editor]');
        return { exactPrequalifiedScope: 'discovery.read', sourceRevision: 3, providerVerification: 'UNVERIFIED' };
    });
    await check('exact source right withdrawal and independent reissue retain revoked history, then retirement preserves technical scope', async () => {
        const oldRead = String(sourceFixture.a.grants['source.read']);
        await page.locator('[data-source-confirm]').check(); await page.locator(`[data-source-grant="${oldRead}"]`).getByRole('button').click();
        await page.waitForFunction(id => document.querySelector(`[data-source-grant="${id}"]`)?.getAttribute('data-source-grant-state') === 'revoked', oldRead);
        await page.getByRole('button', { name: 'إصدار حق مستقل', exact: true }).click();
        await page.waitForFunction(id => [...document.querySelectorAll('[data-source-grant]')].some(row => row.textContent.includes('source.read') && row.dataset.sourceGrant !== id && row.dataset.sourceGrantState === 'active'), oldRead);
        const before = await sourceState('a'); const reads = before.rights.filter(row => row.action === 'source.read');
        assert.equal(reads.length, 2); assert.equal(reads.find(row => row.grant_id === oldRead).recorded_status, 'revoked');
        assert.equal(reads.filter(row => row.recorded_status === 'active').length, 1);
        await page.locator('[data-source-confirm]').check(); await page.locator('[data-source-retire]').click();
        await page.waitForFunction(() => !document.querySelector('[data-source-retire]') && !document.querySelector('#bf-source-label'));
        assert.equal((await sourceState('a')).source.status, 'REVOKED'); assert.equal((await sourceState('a')).source.revision, 4);
        assert.equal((await sourceState('b')).source.revision, 1);
        await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'source-rights-small-viewport.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
        return { oldRight: 'revoked', newIndependentRead: true, retiredRevision: 4, otherSourceRevision: 1, width: 390 };
    });
    await check('a qualified delayed source-list reply cannot restore source names after responsible ownership is withdrawn', async () => {
        await sourceIndex();
        let reached, releaseRead, delivered;
        const qualified = new Promise(resolve => { reached = resolve; }), held = new Promise(resolve => { releaseRead = resolve; }), finished = new Promise(resolve => { delivered = resolve; });
        let intercepted = false;
        await page.route('**/source-rights', async route => {
            if (intercepted || route.request().headers()['x-builderad-witness']) { await route.continue(); return; }
            intercepted = true; const response = await route.fetch(); assert.equal(response.status(), 200);
            assert.equal((await response.json()).data.sources.some(row => row.label === sourceFixture.a.name), true); reached(); await held;
            await route.fulfill({ response }).catch(() => {}); delivered();
        });
        release = releaseRead;
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await qualified;
        fixture('source-withdraw-owner');
        releaseRead(); await finished; release = undefined; await page.unroute('**/source-rights');
        // This is the primary page read, not an auxiliary read. Its fresh witness
        // must reject the stale payload, keeping it concealed until a new admission.
        await page.waitForFunction(() => ['unavailable','closed'].includes(document.querySelector('[data-application-spa]')?.dataset.state));
        assert.equal(await page.locator('[data-source-rights-list]').isVisible(), false);
        await index(); await sourceIndex(); await ready('[data-source-rights-list]');
        assert.equal((await page.locator('[data-source-rights-list]').textContent()).includes(sourceFixture.a.name), false);
        assert.equal(await page.locator('[data-source-rights-list] li').count(), 1);
        assert.equal((await send(`/api/source-rights/${sourceFixture.a.source}?access=${sourceFixture.a.grants['source.manage']}`)).status, 403);
        assert.equal((await sourceState('b')).source.status, 'UNVERIFIED');
        return { oldQualifiedReplyDiscarded: true, withdrawnNameHidden: true, exactApiDenied: 403, remainingSources: 1 };
    });
    let dataFixture, firstDataBinding, recreatedDataBinding;
    const dataPanel = page.locator('[data-business-data-use]');
    const dataState = async key => {
        const b = dataFixture[key].business; const response = await send(`/api/businesses/${b.business_public_id}/surface?access=${b.grant_refs['business.context.read']}`);
        assert.equal(response.status, 200); return response.body.data;
    };
    const openData = async key => { await index(); await open(dataFixture[key].name,'sources');const advanced=page.locator('[data-business-source-advanced]');if(await advanced.getAttribute('open')===null)await advanced.locator(':scope > summary').click();await dataPanel.waitFor(); await page.waitForFunction(() => !document.querySelector('[data-business-rechecking]') && (!!document.querySelector('[data-data-use-reconcile]') || !document.querySelector('#bf-data-scope')?.matches(':disabled'))); };
    const selectData = async key => { await dataPanel.locator('#bf-data-scope').selectOption(String(dataFixture[key].data.grant_id)); await dataPanel.locator('[data-data-use-details]').waitFor(); };
    const bindData = async key => {
        await selectData(key); await dataPanel.getByRole('button', { name: 'طلب ربط النطاق بالنشاط', exact: true }).click();
        await dataPanel.locator('[data-data-use-confirmed]').waitFor(); await page.waitForFunction(() => !document.querySelector('[data-business-rechecking]'));
    };
    await check('data-use: qualified scopes are discoverable and bindings keep two customers independent', async () => {
        fixture('data-use-setup'); dataFixture = JSON.parse(await readFile(path.join(run, 'business-data-use-fixture.json'), 'utf8'));
        await openData('a');
        const scopes = await send('/api/data-rights/use-scopes'); assert.equal(scopes.status, 200); assert.equal(scopes.body.data.scopes.length, 2);
        const serialized = JSON.stringify(scopes.body); for (const value of ['private-customer-key','Private source display name','private-source-identifier','test.private']) assert.equal(serialized.includes(value), false);
        assert.equal(scopes.body.data.scopes.some(row => row.scope_id === dataFixture.foreign.scope_id), false);
        assert.equal(dataFixture.a.data.source, dataFixture.b.data.source);
        const forbidden = await send(`/api/businesses/${dataFixture.a.business.business_public_id}/data-use-bindings`, 'POST', {
            command_id: crypto.randomUUID(), data_use_scope_id: dataFixture.b.data.scope_id, effect: 'add', expected_relation: 0,
            authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: dataFixture.a.business.grant_refs['business.link.manage'] }, { requirement_kind: 'data_use', leaf_grant_id: dataFixture.b.data.grant_id }] } });
        assert.equal(forbidden.status, 403);
        await bindData('a'); firstDataBinding = (await dataState('a')).data_use.bindings[0]; assert.equal(firstDataBinding.revision, 1);
        await openData('b'); await bindData('b'); assert.equal((await dataState('b')).data_use.bindings[0].revision, 1);
        // Earlier source-management journeys can leave unrelated, independently
        // authorized sources. Data-use authority must not expose this shared
        // source; an unrelated legitimate source is not an authority leak.
        const sourceChoices = await send('/api/source-rights'); assert.equal(sourceChoices.status, 200);
        assert.equal(sourceChoices.body.data.sources.some(row => row.source_id === dataFixture.a.data.source || row.source_id === dataFixture.foreign.source), false);
        return { ownScopes: 2, foreignHidden: true, wrongCustomerDenied: 403, nativeKeysHidden: true, technicalAuthorityInferred: false };
    });
    await check('data-use: an unknown end command stays with its business and exact replay never repeats the effect', async () => {
        await openData('a'); await dataPanel.locator('[data-data-use-confirm]').check();
        let lost; const committed = new Promise(resolve => { lost = resolve; });
        await page.route('**/api/businesses/*/data-use-bindings', async route => {
            const body = route.request().postDataJSON();
            if (body.effect === 'end') { const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); lost(body); return; }
            await route.continue();
        });
        await dataPanel.getByRole('button', { name: 'إنهاء ربط الاستعمال', exact: true }).click(); const original = await committed;
        await dataPanel.locator('[data-data-use-reconcile]').waitFor(); await page.unroute('**/api/businesses/*/data-use-bindings');
        await openData('b'); assert.equal(await dataPanel.locator('[data-data-use-reconcile]').count(), 0);
        await openData('a'); const replay = page.waitForRequest(request => request.url().endsWith('/data-use-bindings') && request.method() === 'POST');
        await dataPanel.locator('[data-data-use-reconcile]').click(); assert.deepEqual((await replay).postDataJSON(), original);
        await dataPanel.locator('[data-data-use-confirmed]').waitFor(); assert.equal((await dataState('a')).data_use.bindings[0].revision, 2);
        await bindData('a'); recreatedDataBinding = (await dataState('a')).data_use.bindings[0];
        assert.equal(recreatedDataBinding.revision, 3); assert.notEqual(recreatedDataBinding.binding_id, firstDataBinding.binding_id);
        assert.equal((await dataState('b')).data_use.bindings[0].revision, 1);
        return { originalCommandReplayed: true, endedRevision: 2, recreatedRevision: 3, independentCustomerUnchanged: true };
    });
    await check('data-use: withdrawn permission and delayed auxiliary reply cannot restore cached scope metadata', async () => {
        let reached, delivered, intercepted = false;
        const qualified = new Promise(resolve => { reached = resolve; }), finished = new Promise(resolve => { delivered = resolve; }), held = new Promise(resolve => { release = resolve; });
        await page.route('**/api/data-rights/use-scopes', async route => {
            if (intercepted) { await route.continue(); return; }
            intercepted = true; const response = await route.fetch(); assert.equal(response.status(), 200);
            assert.equal((await response.json()).data.scopes.some(row => row.scope_id === dataFixture.a.data.scope_id), true);
            reached(); await held; await route.fulfill({ response }); delivered();
        });
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await qualified;
        fixture('data-use-withdraw-a');
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(id => document.querySelector('[data-application-spa]')?.dataset.state === 'ready' && !!document.querySelector('#bf-data-scope')
            && !document.querySelector('#bf-data-scope').matches(':disabled') && ![...document.querySelector('#bf-data-scope').options].some(row => row.value === id), String(dataFixture.a.data.grant_id));
        release(); await finished; release = undefined; await page.unroute('**/api/data-rights/use-scopes');
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.equal(await dataPanel.locator('#bf-data-scope option').evaluateAll((options, id) => options.some(row => row.value === id), String(dataFixture.a.data.grant_id)), false);
        assert.equal(await dataPanel.locator('[data-data-use-details]').count(), 0);
        assert.equal((await dataState('a')).data_use.bindings[0].state, 'active');
        await dataPanel.locator('[data-data-use-confirm]').check(); await dataPanel.getByRole('button', { name: 'إنهاء ربط الاستعمال', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('[data-binding-state=ended]') !== null);
        assert.equal((await dataState('a')).data_use.bindings[0].revision, 4); assert.equal((await dataState('b')).data_use.bindings[0].state, 'active');
        return { oldQualifiedReplyDiscarded: true, withdrawnScopeHidden: true, independentBusinessEnd: true, revision: 4, otherCustomerPreserved: true };
    });
    await check('data-use: independent requalification does not revive an old binding and surrender preserves other rights', async () => {
        const old = dataFixture.a.data.grant_id; fixture('data-use-requalify-a');
        dataFixture = JSON.parse(await readFile(path.join(run, 'business-data-use-fixture.json'), 'utf8'));
        assert.notEqual(dataFixture.a.data.grant_id, old);
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(id => document.querySelector('[data-application-spa]')?.dataset.state === 'ready'
            && !![...document.querySelector('#bf-data-scope').options].find(row => row.value === id), String(dataFixture.a.data.grant_id));
        assert.equal((await dataState('a')).data_use.bindings[0].state, 'ended'); await bindData('a');
        assert.equal((await dataState('a')).data_use.bindings[0].revision, 5);
        const stale = await send(`/api/businesses/${dataFixture.a.business.business_public_id}/data-use-bindings`, 'POST', {
            command_id: crypto.randomUUID(), data_use_scope_id: dataFixture.a.data.scope_id, effect: 'end', expected_relation: recreatedDataBinding.revision,
            authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: dataFixture.a.business.grant_refs['business.link.manage'] }] } });
        assert.equal(stale.status, 409); assert.equal(stale.body.error, 'business_relation_revision_rejected');
        await selectData('a'); await dataPanel.locator('[data-data-use-confirm]').check(); await dataPanel.locator('[data-data-use-surrender]').click();
        await page.waitForFunction(id => !document.querySelector('[data-business-rechecking]') && ![...document.querySelector('#bf-data-scope').options].some(row => row.value === id), String(dataFixture.a.data.grant_id));
        assert.equal((await send('/api/data-rights/use-scopes')).body.data.scopes.length, 1);
        assert.equal((await dataState('a')).data_use.bindings[0].revision, 5);
        await dataPanel.locator('[data-data-use-confirm]').check(); await dataPanel.getByRole('button', { name: 'إنهاء ربط الاستعمال', exact: true }).click();
        await page.waitForFunction(() => !!document.querySelector('[data-binding-state=ended]'));
        assert.equal((await dataState('a')).data_use.bindings[0].revision, 6);
        await dataPanel.locator('#bf-data-scope').focus(); assert.equal(await dataPanel.locator('#bf-data-scope').evaluate(row => document.activeElement === row), true);
        await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'data-use-small-viewport.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
        return { syntheticNewQualificationOnly: true, noAutomaticRebinding: true, staleEndDenied: 409, surrenderedOnlySelectedRight: true, finalRevision: 6, width: 390 };
    });
    let historyA, historyB;
    const historyPanel = page.locator('[data-business-history]');
    const openHistory = async name => { await index(); await open(name); await historyPanel.locator('summary').click(); await page.waitForFunction(() => !!document.querySelector('[data-history-entries], [data-history-empty]')); };
    await check('history: native owners keep separate versions and readable Arabic before/after values', async () => {
        const a = await send('/api/businesses', 'POST', { command_id: crypto.randomUUID(), name: 'تاريخ النشاط الأول' }); assert.equal(a.status, 201); historyA = a.body.data;
        const b = await send('/api/businesses', 'POST', { command_id: crypto.randomUUID(), name: 'تاريخ النشاط الثاني' }); assert.equal(b.status, 201); historyB = b.body.data;
        const selection = action => ({ realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: historyA.grant_refs[action] }] });
        const base = `/api/businesses/${historyA.business_public_id}`;
        for (const [path, body] of [
            ['context', { name: 'التاريخ الأول المصحح', business_type_id: null, expected_context: 0, authority_selection: selection('business.context.manage') }],
            ['markets', { codes: ['SA','JO'], expected_context: 1, authority_selection: selection('business.markets.declare') }],
            ['intent', { value: { description: null, direction: 'increase_value', scope: 'business_wide', outcome: { meaning: 'زيادة طلبات مؤهلة', clarification: null, count_unit: null }, quantity: null, horizon: null }, confirmations: { declaration: true, business_wide: true, outcome: true, acceptance_clear: true, clarification_required: false, quantity: true, horizon: true }, expected_context: 2, expected_intent: 0, authority_selection: selection('business.intent.declare') }],
            ['preferences', { value: { goals: ['sales'], priority: 'sales', results: { sales: ['actual_sales'] } }, expected_preference: 0, authority_selection: selection('business.preferences.manage') }],
        ]) { const saved = await send(`${base}/${path}`, 'PUT', { command_id: crypto.randomUUID(), ...body }); assert.equal(saved.status, 200, JSON.stringify(saved.body)); }
        await writeFile(path.join(run, 'business-history-fixture.json'), JSON.stringify({ a: historyA, b: historyB }));
        await openHistory('التاريخ الأول المصحح');
        assert.equal(await historyPanel.locator('[data-history-before]').textContent(), 'تاريخ النشاط الأول — دون تصنيف');
        assert.equal(await historyPanel.locator('[data-history-after]').textContent(), 'التاريخ الأول المصحح — دون تصنيف');
        for (const [kind, text] of [['markets', 'الأردن'], ['intent', 'زيادة طلبات مؤهلة'], ['preferences', 'المبيعات']]) {
            await historyPanel.locator('#bf-history-kind').selectOption(kind);
            await page.waitForFunction(text => document.querySelector('[data-history-after]')?.textContent.includes(text), text);
        }
        await historyPanel.locator('#bf-history-kind').focus(); assert.equal(await historyPanel.locator('#bf-history-kind').evaluate(e => document.activeElement === e), true);
        await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'history-small-viewport.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
        return { nativeKinds: 4, originalVersionsPreserved: true, labelsAndFocus: true, width: 390 };
    });
    await check('history: late A history cannot populate B or survive current read withdrawal', async () => {
        // Buffer an actual completed response before delaying delivery to the
        // transport. Network cancellation alone must not be our stale-data proof.
        const delay = async () => {
            await page.evaluate(path => {
                const original = window.fetch;
                let claimed = false, resume;
                const held = new Promise(resolve => { resume = resolve; });
                window.__historyDelay = { started: false, delivered: false, release: resume, restore: () => { window.fetch = original; } };
                window.fetch = async function(input, init) {
                    const response = await original.call(this, input, init);
                    const url = new URL(typeof input === 'string' ? input : input.url, location.origin);
                    if (!claimed && url.pathname === path && response.status === 200) {
                        claimed = true;
                        const buffered = new Response(await response.arrayBuffer(), { status: response.status, headers: response.headers });
                        window.__historyDelay.started = true; await held;
                        window.__historyDelay.delivered = true; return buffered;
                    }
                    return response;
                };
            }, `/api/businesses/${historyA.business_public_id}/history`);
            release = () => { void page.evaluate(() => window.__historyDelay?.release()).catch(() => {}); };
            await historyPanel.getByRole('button', { name: 'قراءة أحدث سجل', exact: true }).click();
            await page.waitForFunction(() => window.__historyDelay?.started);
        };
        const deliver = async () => {
            await page.evaluate(() => window.__historyDelay.release());
            await page.waitForFunction(() => window.__historyDelay.delivered);
            await page.evaluate(() => { window.__historyDelay.restore(); });
            release = undefined;
        };
        await openHistory('التاريخ الأول المصحح');
        await delay(); await openHistory('تاريخ النشاط الثاني'); await deliver();
        assert.equal(await historyPanel.locator('[data-history-empty]').count(), 1);
        assert.equal(await historyPanel.textContent().then(text => text.includes('تاريخ النشاط الأول')), false);
        await openHistory('التاريخ الأول المصحح');
        await delay();
        fixture('history-withdraw');
        assert.equal((await send(`/api/businesses/${historyA.business_public_id}/history?kind=context&access=${historyA.grant_refs['business.context.read']}`)).status, 403);
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => ['unavailable','closed'].includes(document.querySelector('[data-application-spa]')?.dataset.state));
        await deliver();
        assert.equal(await page.locator('[data-history-entries]').count(), 0);
        await page.goto(origin+'/businesses'); await ready('[data-business-list]');
        assert.equal(await page.locator('[data-business-list]').textContent().then(text => text.includes('التاريخ الأول المصحح')), false);
        await open('تاريخ النشاط الثاني'); await historyPanel.locator('summary').click(); await historyPanel.locator('[data-history-empty]').waitFor();
        assert.equal(await historyPanel.locator('[data-history-empty]').count(), 1);
        return { actualCompletedResponseDelayed: true, withheldLateResponseAcrossSubjects: true, freshWithdrawalDenied: 403, oldQualifiedReplyCannotRestoreHistory: true };
    });
    let legacyFixture;
    const legacyPanel = page.locator('[data-business-legacy-history]');
    await check('legacy-history: qualified originals retain Purpose corrections and native identity after real synthetic cutover', async () => {
        fixture('legacy-history-setup'); legacyFixture = JSON.parse(await readFile(path.join(run, 'business-legacy-history-fixture.json'), 'utf8'));
        await index(); await open('نشاط a 1');
        await legacyPanel.locator('summary').click(); await legacyPanel.locator('[data-legacy-purpose]').first().waitFor();
        assert.equal(await legacyPanel.locator('[data-legacy-purpose]').count(), 2);
        assert.equal(await legacyPanel.locator('[data-legacy-meaning]').allTextContents().then(values => values.includes('غرض إعلاني أصلي بعد التصحيح')), true);
        const original = await send(`/api/businesses/${legacyFixture.business}/legacy-history?access=${legacyFixture.read}&kind=object_purpose`);
        assert.equal(original.status, 200); assert.equal(original.body.data.entries[0].revision_id, legacyFixture.first.revision_id);
        assert.equal(original.body.data.entries[1].supersedes_revision_id, legacyFixture.first.revision_id);
        assert.equal(original.body.data.entries[1].applicability, 'NOT_EVALUATED_HISTORICAL_DECLARATION');
        assert.equal(original.body.data.completeness, 'QUALIFIED_SUBSET_ONLY');
        await legacyPanel.locator('[data-legacy-purpose]').last().locator('details summary').click();
        assert.equal((await legacyPanel.textContent()).includes(legacyFixture.second.revision_id), true);
        await legacyPanel.locator('#bf-legacy-kind').selectOption('context'); await legacyPanel.locator('[data-legacy-after]').first().waitFor();
        assert.equal((await legacyPanel.textContent()).includes('الإصدار الأصلي'), true);
        await legacyPanel.locator('#bf-legacy-kind').selectOption('object_purpose'); await legacyPanel.locator('[data-legacy-purpose]').last().waitFor();
        await legacyPanel.locator('#bf-legacy-kind').focus(); assert.equal(await legacyPanel.locator('#bf-legacy-kind').evaluate(e => document.activeElement === e), true);
        await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await legacyPanel.screenshot({ path: path.join(run, 'legacy-history-small-viewport.png') }); await page.setViewportSize({ width: 1280, height: 900 });
        return { actualSyntheticImport: true, nativeRevisions: [legacyFixture.first.revision_id, legacyFixture.second.revision_id], noCurrentApplicabilityClaim: true, width: 390 };
    });
    await check('legacy-history: withdrawn original qualifications invalidate delayed completed replies without erasing native history', async () => {
        await page.evaluate(target => {
            const original = window.fetch; let claimed = false, resume;
            const held = new Promise(resolve => { resume = resolve; });
            window.__legacyDelay = { started: false, delivered: false, release: resume, restore: () => { window.fetch = original; } };
            window.fetch = async function(input, init) {
                const response = await original.call(this, input, init);
                const url = new URL(typeof input === 'string' ? input : input.url, location.origin);
                if (!claimed && url.pathname === target && response.status === 200) {
                    claimed = true; const buffered = new Response(await response.arrayBuffer(), { status: response.status, headers: response.headers });
                    window.__legacyDelay.started = true; await held; window.__legacyDelay.delivered = true; return buffered;
                }
                return response;
            };
        }, `/api/businesses/${legacyFixture.business}/legacy-history`);
        release = () => { void page.evaluate(() => window.__legacyDelay?.release()).catch(() => {}); };
        await legacyPanel.getByRole('button', { name: 'قراءة السجل المؤهل', exact: true }).click(); await page.waitForFunction(() => window.__legacyDelay.started);
        fixture('legacy-history-withdraw');
        const fresh = await send(`/api/businesses/${legacyFixture.business}/legacy-history?access=${legacyFixture.read}&kind=object_purpose`);
        assert.equal(fresh.status, 200); assert.deepEqual(fresh.body.data.entries, []);
        // Review another owner and then explicitly requalify the original owner;
        // the completed stale reply must not replace this newly read empty subset.
        await legacyPanel.locator('#bf-legacy-kind').selectOption('context'); await legacyPanel.locator('[data-legacy-after]').first().waitFor();
        await legacyPanel.locator('#bf-legacy-kind').selectOption('object_purpose'); await legacyPanel.locator('[data-legacy-empty]').waitFor();
        await page.evaluate(() => window.__legacyDelay.release()); await page.waitForFunction(() => window.__legacyDelay.delivered);
        await page.evaluate(() => window.__legacyDelay.restore()); release = undefined;
        assert.equal(await legacyPanel.locator('[data-legacy-purpose]').count(), 0);
        assert.equal(await legacyPanel.locator('[data-legacy-empty]').count(), 1);
        await index(); await openCreated('settings'); await legacyPanel.locator('summary').click(); await legacyPanel.locator('[data-legacy-empty]').waitFor();
        return { qualificationWithdrawn: true, oldCompletedResponseDiscarded: true, unrelatedBusinessEmptyQualifiedSubset: true, nativeHistoryRetainedByBackendProof: true };
    });
    await check('operating own Business Purpose opt-in declaration correction and withdrawal establish no external authority', async () => {
        fixture('purpose-setup');
        const f = JSON.parse(await readFile(path.join(run, 'business-purpose-fixture.json'), 'utf8'));
        await page.goto(`${origin}/businesses/${f.business}?access=${f.read.leaf}`); await ready('[data-business-public]'); await workspaceSection('settings');
        await page.locator('[data-workspace-settings="purposes"]').click();
        const panel = page.locator('[data-business-purposes]');
        await panel.locator('summary').first().click(); await panel.locator('[data-purpose-empty]').waitFor();
        await panel.locator('[data-purpose-enrollment-confirm]').check();
        await Promise.all([
            page.waitForResponse(response => new URL(response.url()).pathname === `/businesses/${f.business}` && response.request().headers()['x-builderad-witness'] === '1'),
            panel.locator('[data-purpose-enrollment-submit]').click(),
        ]);
        await page.waitForFunction(() => document.querySelector('[data-business-public]')?.getAttribute('aria-busy') === 'false');
        await panel.locator('summary').first().click(); await panel.locator('[data-purpose-create]').waitFor();
        assert.match(await panel.locator('[data-purpose-enrollment]').innerText(), /لا يثبت ملكية حملة أو عنصر إعلاني/);
        await panel.locator('[data-purpose-create]').click();
        const form = panel.locator('[data-purpose="save"]').locator('xpath=ancestor::form');
        for (const [field,value] of Object.entries({meaning:'تعريف المنتج قبل الإطلاق',provider:'opaque-provider',namespace:'account',kind:'campaign', 'account-0':'Account-A','object-0':'Object-1',source:'تصريح منشئ النشاط',identity_basis:'هوية العنصر المصرح بها؛ ليست إثبات ملكية خارجية',authority_basis:'حق تصريح النشاط فقط',effective_basis:'من وقت الحفظ'})) await form.locator(`[data-purpose="${field}"]`).fill(value);
        for (const flag of ['meaning','scope','authority','identity','standing']) await form.locator(`[data-purpose="confirm-${flag}"]`).check();
        await form.locator('[data-purpose="save"]').click(); await panel.locator('[data-purpose-item]').waitFor();
        assert.equal(await panel.locator('[data-purpose-item] h3').innerText(),'تعريف المنتج قبل الإطلاق');
        await panel.locator('[data-purpose-edit]').click();
        await form.locator('[data-purpose="operation"]').selectOption('correct');
        await form.locator('[data-purpose="meaning"]').fill('غرض مصحح مؤهل'); await form.locator('[data-purpose="reason"]').fill('تصحيح تصريح المالك');
        for (const flag of ['meaning','scope','authority','identity','standing','transition']) await form.locator(`[data-purpose="confirm-${flag}"]`).check();
        await form.locator('[data-purpose="save"]').click(); await panel.getByRole('heading',{name:'غرض مصحح مؤهل',exact:true}).waitFor();
        await panel.locator('[data-purpose-item] summary').click();
        assert.equal(await panel.locator('[data-purpose-item] li').count(),2);
        await panel.locator('[data-purpose-edit]').click();
        await form.locator('[data-purpose="operation"]').selectOption('withdraw');
        await form.locator('[data-purpose="reason"]').fill('إنهاء انطباق التصريح');
        for (const flag of ['meaning','scope','authority','identity','standing','transition']) await form.locator(`[data-purpose="confirm-${flag}"]`).check();
        const deliveries=[];
        await page.route(`**/api/businesses/${f.business}/purposes`,async route=>{
            if(route.request().method()!=='POST'){await route.continue();return;}
            deliveries.push(route.request().postDataJSON());const response=await route.fetch();assert.equal(response.status(),201);
            if(deliveries.length===1)await route.abort('failed');else await route.fulfill({response});
        });
        await form.locator('[data-purpose="save"]').click();await form.locator('[data-purpose="retry-original"]').waitFor();
        await form.locator('[data-purpose="retry-original"]').click();await panel.getByRole('heading',{name:'لا تصريح منطبق في الوقت الحالي',exact:true}).waitFor();
        await page.unroute(`**/api/businesses/${f.business}/purposes`);
        assert.equal(deliveries.length,2);assert.deepEqual(deliveries[0],deliveries[1]);
        await panel.locator('[data-purpose-item] summary').click();assert.equal(await panel.locator('[data-purpose-item] li').count(),3);
        await page.setViewportSize({width:390,height:844}); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await page.screenshot({path:path.join(run,'business-purpose-mobile.png'),fullPage:true}); await page.setViewportSize({width:1280,height:900});
        const contract = await send(`/api/businesses/${f.business}/purposes?access=${f.read.leaf}`);
        assert.equal(contract.status,200); assert.equal(contract.body.data.establishes_external_authority,false);
        return {operatingOriginalCreatorOptIn:true,externalAuthorityEstablished:false,providerNeutral:true,currentDeclarationCorrectionWithdrawal:true,historyVersions:3,unknownAckSameFrozenCommand:true,mobile:true};
    });
    await check('native Business Purpose write revocation retains independent read and stale response cannot cross Business', async () => {
        const f = JSON.parse(await readFile(path.join(run,'business-purpose-fixture.json'),'utf8'));
        await workspaceSection('access');
        await page.locator('[data-purpose-surrender-confirm]').check();
        const [surrender] = await Promise.all([
            page.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.business}/grants` && response.request().method() === 'POST'),
            page.locator('[data-purpose-surrender]').click(),
        ]);
        assert.equal(surrender.status(),200);
        await page.waitForFunction(() => !document.querySelector('[data-own-purpose-right]'));
        await page.waitForFunction(() => document.querySelector('[data-business-public]')?.getAttribute('aria-busy') === 'false');
        await workspaceSection('settings'); await page.locator('[data-workspace-settings="purposes"]').click();
        const panel=page.locator('[data-business-purposes]'); await panel.locator('summary').first().click(); await panel.locator('[data-purpose-item]').waitFor();
        assert.equal(await panel.locator('[data-purpose-create], [data-purpose-edit]').count(),0);
        await panel.locator('[data-purpose-enrollment-confirm]').check();
        await Promise.all([
            page.waitForResponse(response => new URL(response.url()).pathname === `/businesses/${f.business}` && response.request().headers()['x-builderad-witness'] === '1'),
            panel.locator('[data-purpose-enrollment-submit]').click(),
        ]);
        await page.waitForFunction(() => document.querySelector('[data-business-public]')?.getAttribute('aria-busy') === 'false');
        await panel.locator('summary').first().click(); await panel.locator('[data-purpose-edit]').waitFor();
        await page.evaluate(target=>{
            const original=window.fetch;let resolve;const held=new Promise(r=>resolve=r);
            window.__purposeDelay={started:false,delivered:false,release:resolve,restore:()=>{window.fetch=original;}};
            window.fetch=async function(input,init){const response=await original.call(this,input,init);const url=new URL(typeof input==='string'?input:input.url,location.origin);
                if(url.pathname===target&&response.status===200&&!window.__purposeDelay.started){const buffered=new Response(await response.arrayBuffer(),{status:response.status,headers:response.headers});window.__purposeDelay.started=true;await held;window.__purposeDelay.delivered=true;return buffered;}return response;};
        },`/api/businesses/${f.business}/purposes`);
        release=()=>{void page.evaluate(()=>window.__purposeDelay?.release()).catch(()=>{});};
        await panel.getByRole('button',{name:'تحديث الأغراض',exact:true}).click(); await page.waitForFunction(()=>window.__purposeDelay.started);
        fixture('purpose-revoke-read');
        await index(); await openCreated();
        await page.evaluate(()=>window.__purposeDelay.release());await page.waitForFunction(()=>window.__purposeDelay.delivered);await page.evaluate(()=>window.__purposeDelay.restore());release=undefined;
        assert.equal(await page.getByText('غرض مصحح مؤهل',{exact:true}).count(),0);
        return {operatingUISurrender:true,explicitRequalification:true,writeRevokedReadRetained:true,lateCompletedReplyDiscarded:true,revokedBusinessNotDisplayed:true};
    });
    await sharedSourceReturnCheck();
    assert.deepEqual(result.errors, []); result.result = 'PASS'; await context.close();
} catch (error) { result.result = 'FAIL'; result.failure = error.stack; console.error(error); process.exitCode = 1; }
finally { release?.(); await browser.close(); await writeFile(path.join(run, 'browser-results.json'), JSON.stringify(result, null, 2)); }
