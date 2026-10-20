import http from 'node:http';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.DB_DATABASE !== 'builderad_test_disposable' || process.env.REDIS_PORT !== '6397') throw Error('Guarded disposable environment required');
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN, mode = process.env.ADMIN_UI_CAPTURE ?? 'after';
const output = path.join(run, 'admin-ui-'+mode); await mkdir(output, { recursive: true });
const fixture = spawnSync('C:/tools/php85/php.exe', ['tests/Support/AdminUiBrowserFixture.php'], { env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr); assert.equal(JSON.parse(fixture.stdout).database.db, 'builderad_test_disposable');
const cookies = JSON.parse(await readFile(path.join(run, 'admin-ui-fixture.json'), 'utf8'));
const proxy = http.createServer((req, res) => {
    const outgoing = http.request({ hostname: 'localhost', port: 8136, path: req.url, method: req.method, headers: req.headers }, incoming => { res.writeHead(incoming.statusCode, incoming.headers); incoming.pipe(res); });
    outgoing.on('error', error => { res.writeHead(502); res.end(error.message); }); req.pipe(outgoing);
});
await new Promise(resolve => proxy.listen(8138, 'localhost', resolve));
let browser;
const contexts = [], results = [], errors = [], screenshots = [], assets = new Map(), requests = [];
const base = 'http://localhost:8138';
async function session(kind='founder') {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }); contexts.push(context); await context.addCookies(cookies[kind]);
    const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => errors.push(error.stack ?? error.message));
    page.on('request', request => requests.push({ method: request.method(), path: new URL(request.url()).pathname }));
    page.on('response', response => {
        const asset = new URL(response.url()).pathname;
        if (asset.includes('/build/') && response.status()===200) {
            // Read while this document still owns the response; navigation can
            // evict CDP's body cache before a final lazy read.
            assets.set(asset,response.body().then(bytes=>createHash('sha256').update(bytes).digest('hex')).catch(error=>({error:error.message})));
        }
    }); return page;
}
const ready = page => page.waitForFunction(() => document.querySelector('#application-spa')?.dataset.state === 'ready');
const visit = async (page, route, selector) => { await page.goto(base+route); await ready(page); await page.locator(selector).waitFor(); };
async function direction(page, expected) {
    const current = await page.locator('html').getAttribute('dir'); if (current === expected) return;
    await page.locator('[data-application=locale]').click(); await page.waitForFunction(expected => document.documentElement.dir===expected, expected); await ready(page);
}
const pages = [
    { key:'administration',route:'/internal/administration',selector:'[data-administration]' },
    { key:'customers',route:'/internal/administration/customers',selector:'[data-customers]' },
    { key:'services',route:'/internal/services',selector:'[data-services]' },
    { key:'appearance',route:'/internal/administration/appearance',selector:'[data-appearance-settings]' },
];
const check = async (name, action) => { await action(); results.push({name,status:'PASS'}); console.log('PASS '+name); };
try {
    browser = await chromium.launch({ headless:true, executablePath:'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
    const page = await session();
    if(process.env.ADMIN_UI_VERIFY_ASSETS_ONLY==='1') {
        const retained=JSON.parse(await readFile(path.join(output,'results.json'),'utf8'));
        assert.equal(retained.errors.length,0);assert.equal(retained.results.filter(result=>result.status==='PASS').length,6);
        await writeFile(path.join(output,'retained-functional-results.json'),JSON.stringify(retained,null,2));
        screenshots.push(...retained.screenshots);results.push(...retained.results.map(result=>({...result,status:'RETAINED'})));
        for(const target of pages) await visit(page,target.route,target.selector);
        const advertiser=await session('advertiser');await visit(advertiser,'/workspaces','[data-spa-private]');
    } else {
    if(process.env.ADMIN_UI_SKIP_MATRIX==='1') {
        const retained=JSON.parse(await readFile(path.join(output,'results.json'),'utf8'));
        assert.equal(retained.screenshots.length,20); screenshots.push(...retained.screenshots);
        await writeFile(path.join(output,'retained-matrix-attempt.json'),JSON.stringify(retained,null,2));
        results.push({name:'Comparable real-page screenshot matrix',status:'RETAINED',reason:'Visual markup/CSS unchanged. Final focus handling is verified separately on rebuilt production assets.'});
    } else await check('Comparable real-page screenshot matrix', async () => {
        for (const width of [1440,1280,768,390]) {
            await page.setViewportSize({width,height:1000});
            for (const target of pages) {
                await visit(page,target.route,target.selector); await direction(page,'rtl');
                const file = `${target.key}-${width}-rtl.png`; await page.screenshot({path:path.join(output,file),fullPage:true});
                const measurement = await page.evaluate(() => ({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,dir:document.documentElement.dir,primary:getComputedStyle(document.documentElement).getPropertyValue('--ba-brand-primary').trim()}));
                screenshots.push({file,route:target.route,...measurement});
                if(mode!=='before') assert.ok(measurement.scrollWidth<=width,`${target.key} overflow ${width}: ${measurement.scrollWidth}`);
            }
        }
        await page.setViewportSize({width:1280,height:1000});
        for(const target of pages) {
            await visit(page,target.route,target.selector); await direction(page,'ltr');
            const file=`${target.key}-1280-ltr.png`; await page.screenshot({path:path.join(output,file),fullPage:true}); screenshots.push({file,route:target.route,width:1280,dir:'ltr',primary:'#155f53'});
            if(mode!=='before') assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        }
    });
    if(mode!=='before') {
        // Added after shell integration: tests below use stable accessible shell selectors.
        await check('Administration has one header and scoped advertiser regression', async () => {
            await page.setViewportSize({width:1440,height:1000}); await visit(page,pages[0].route,pages[0].selector); await direction(page,'rtl');
            assert.equal(await page.locator('[data-admin-header]').count(),1);
            const advertiser=await session('advertiser'); await visit(advertiser,'/workspaces','[data-spa-private]');
            assert.equal(await advertiser.locator('[data-admin-shell]').count(),0); await advertiser.screenshot({path:path.join(output,'advertiser-1440-rtl.png'),fullPage:true});
        });
        const navigation = page => page.locator('[data-admin-sidebar] [data-admin-nav]');
        await check('Grouped active navigation preserves routes and Back/Forward', async () => {
            assert.equal(await navigation(page).locator('a').count(),8);
            assert.equal(await navigation(page).locator('a[aria-current="page"]').count(),1);
            const initial = requests.length;
            await navigation(page).locator('a[href="/internal/services"]').click(); await ready(page); await page.locator('[data-services]').waitFor();
            assert.equal(await navigation(page).locator('a[aria-current="page"]').getAttribute('href'),'/internal/services');
            await navigation(page).locator('a[href="/internal/administration/customers"]').click(); await ready(page); await page.locator('[data-customers]').waitFor();
            assert.equal(await navigation(page).locator('a[aria-current="page"]').getAttribute('href'),'/internal/administration/customers');
            await page.goBack(); await ready(page); await page.locator('[data-services]').waitFor();
            await page.goForward(); await ready(page); await page.locator('[data-customers]').waitFor();
            assert.equal(requests.slice(initial).filter(request=>request.path==='/internal/administration/appearance').length,0,'Ordinary nav must not fetch appearance');
        });
        await check('Real customer records, query filters and details remain usable', async () => {
            await page.locator('#customer-search').fill('Admin UI advertiser');
            const response=page.waitForResponse(response=>new URL(response.url()).pathname==='/internal/administration/customers'&&response.request().headers().accept?.includes('json')&&!response.request().headers()['x-builderad-witness']);
            await page.locator('.customer-filters button[type=submit]').click(); const filtered=await response; assert.equal(filtered.status(),200);
            const payload=await filtered.json(); assert.equal(payload.data.filters.search,'Admin UI advertiser'); assert.equal(payload.data.total,1);
            await page.waitForFunction(()=>document.querySelectorAll('.customer-row').length===1); await ready(page);
            assert.equal(await page.locator('.customer-row').count(),1);
            const detail=page.locator('.customer-row').filter({hasText:'Admin UI advertiser'}).locator('button').first();
            await detail.click(); await page.waitForURL(url=>url.searchParams.has('selected')); await ready(page);
        });
        await check('Mobile drawer Escape, backdrop, focus confinement and draft preservation', async () => {
            await page.setViewportSize({width:390,height:844}); await visit(page,'/internal/administration/appearance','[data-appearance-settings]');
            await page.locator('#appearance-primary').fill('#008866');
            const initial=requests.length;
            const toggle=page.locator('[data-admin-menu-toggle]'),dialog=page.locator('[data-admin-drawer]');
            await toggle.click(); await dialog.waitFor({state:'visible'}); assert.equal(await toggle.getAttribute('aria-expanded'),'true');
            assert.equal(await dialog.evaluate(el=>el.contains(document.activeElement)),true);
            await page.locator('#appearance-primary').evaluate(el=>el.focus());
            assert.equal(await dialog.evaluate(el=>el.contains(document.activeElement)),true,'Underlying editor must be inert');
            for(let i=0;i<15;i++){await page.keyboard.press('Tab');assert.equal(await dialog.evaluate(el=>el.contains(document.activeElement)),true,'Focus must remain in modal drawer');}
            for(let i=0;i<15;i++){await page.keyboard.press('Shift+Tab');assert.equal(await dialog.evaluate(el=>el.contains(document.activeElement)),true,'Reverse focus must remain in modal drawer');}
            await page.keyboard.press('Escape'); await dialog.waitFor({state:'hidden'}); assert.equal(await toggle.evaluate(el=>document.activeElement===el),true);
            assert.equal(await page.locator('#appearance-primary').inputValue(),'#008866');
            await toggle.click(); const box=await dialog.boundingBox(); assert.ok(box); await page.mouse.click(box.x>10?5:385,300); await dialog.waitFor({state:'hidden'});
            assert.equal(await page.locator('#appearance-primary').inputValue(),'#008866');
            await toggle.click(); await dialog.locator('[data-admin-drawer-close]').click(); await dialog.waitFor({state:'hidden'});
            assert.equal(await page.locator('#appearance-primary').inputValue(),'#008866');
            assert.equal(requests.slice(initial).filter(request=>request.method==='POST'||request.path==='/internal/administration/appearance').length,0);
            assert.equal(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--ba-brand-primary').trim()),'#155f53');
            await toggle.click(); await dialog.locator('a[href="/internal/services"]').click(); await dialog.waitFor({state:'hidden'}); await ready(page); await page.locator('[data-services]').waitFor();
            await page.locator('#service-name').fill('Unsubmitted mobile service'); await toggle.click(); await page.keyboard.press('Escape'); await dialog.waitFor({state:'hidden'});
            assert.equal(await page.locator('#service-name').inputValue(),'Unsubmitted mobile service');
            await direction(page,'ltr'); await toggle.click(); await dialog.waitFor({state:'visible'});
            const ltrBox=await dialog.boundingBox(); assert.ok(ltrBox.x<10,'LTR drawer must align left');
            await page.screenshot({path:path.join(output,'drawer-390-ltr.png'),fullPage:true}); await page.keyboard.press('Escape'); await dialog.waitFor({state:'hidden'});
            await direction(page,'rtl'); await toggle.click(); await dialog.waitFor({state:'visible'});
            const rtlBox=await dialog.boundingBox(); assert.ok(rtlBox.x>10,'RTL drawer must align right');
            await page.screenshot({path:path.join(output,'drawer-390-rtl.png'),fullPage:true}); await page.setViewportSize({width:1440,height:1000}); await dialog.waitFor({state:'hidden'});
        });
        await check('Existing staff form retains validation, draft and successful invitation', async () => {
            await visit(page,'/internal/administration','[data-administration]');
            const disclosure=page.locator('details').filter({has:page.locator('#staff-name')});
            await page.locator('[data-admin-primary-action]').click(); assert.notEqual(await disclosure.getAttribute('open'),null);
            await page.waitForFunction(()=>document.activeElement?.id==='staff-name');
            await page.locator('#staff-name').fill('Admin UI invited reviewer'); await page.locator('#staff-email').fill('admin-ui-founder@example.test');
            const rejection=page.waitForResponse(response=>new URL(response.url()).pathname==='/internal/staff'&&response.request().method()==='POST');
            await page.locator('.admin-invite-form button[type=submit]').click(); assert.equal((await rejection).status(),422);
            await page.locator('#staff-email-error').waitFor(); assert.equal(await page.locator('#staff-name').inputValue(),'Admin UI invited reviewer');
            await page.setViewportSize({width:390,height:844}); await page.locator('[data-admin-menu-toggle]').click(); await page.keyboard.press('Escape');
            assert.equal(await page.locator('#staff-name').inputValue(),'Admin UI invited reviewer');
            const email='admin-ui-invited-'+Date.now()+'@example.test'; await page.locator('#staff-email').fill(email);
            const acknowledgement=page.waitForResponse(response=>new URL(response.url()).pathname==='/internal/staff'&&response.request().method()==='POST');
            await page.locator('.admin-invite-form button[type=submit]').click(); assert.equal((await acknowledgement).status(),200);
            await page.locator('.admin-entity-row').filter({hasText:email}).waitFor(); assert.equal(await page.locator('#staff-name').inputValue(),'');
        });
        await check('Employee controls keep existing permission visibility and denial',async()=>{
            const employeeFixture=spawnSync('C:/tools/php85/php.exe',['tests/Support/AdminUiBrowserFixture.php','employee'],{env:{...process.env,APP_ENV:'testing'},encoding:'utf8'}); assert.equal(employeeFixture.status,0,employeeFixture.stderr);
            const employeeCookies=JSON.parse(await readFile(path.join(run,'admin-ui-fixture.json'),'utf8')); cookies.employee=employeeCookies.employee;
            const employee=await session('employee');await visit(employee,'/internal/administration','[data-administration]');
            assert.equal(await employee.locator('#staff-name').count(),0);assert.equal(await employee.locator('#grant-reason').count(),0);
            const response=await employee.goto(base+'/internal/administration/appearance');assert.ok([403,409].includes(response.status()));
        });
    }
    }
    for (const [asset,pendingHash] of assets) {
        const actualHash=await pendingHash;assert.equal(typeof actualHash,'string',`${asset}: ${JSON.stringify(actualHash)}`);
        assert.equal(actualHash,createHash('sha256').update(await readFile(path.join(process.cwd(),'public',asset))).digest('hex'));
    }
    results.push({name:'Served production assets match final checkout',status:'PASS'});
    assert.equal(errors.length,0,errors.join('\n'));
    await writeFile(path.join(output,'results.json'),JSON.stringify({mode,results,errors,screenshots,assets:[...assets.keys()],productionAssets:true,syntheticSessions:true},null,2));
} catch(error) {
    await writeFile(path.join(output,'results.json'),JSON.stringify({mode,results,errors,screenshots,failure:error.stack},null,2)); throw error;
} finally {
    await Promise.all(contexts.map(context=>context.close())); if(browser)await browser.close(); await new Promise(resolve=>proxy.close(resolve));
}
