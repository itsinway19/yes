import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require=createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const {chromium}=require('playwright');
assert.equal(process.env.APP_ENV,'testing'); assert.equal(process.env.DB_DATABASE,'builderad_test_disposable'); assert.ok(process.env.BUILDERAD_TEST_ISOLATION_TOKEN);
const run=process.env.CURRENT_INTENT_PROOF_RUN, fixture=JSON.parse(await readFile(run+'/ui-fixture.json','utf8'));
const base='http://127.0.0.1:8017', url=base+'/workspaces/'+fixture.workspace+'/business';
const artifacts='C:/Users/stoma/.codex/visualizations/2026/10/04/01a1083d-67dd-77d2-9c01-92495dd1999c';
const evidence={checks:[],errors:[],screenshots:[],posts:[]};
const browser=await chromium.launch({headless:true,executablePath:'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe'});
const control=(page,key)=>page.locator('[data-intent="'+key+'"]');
async function check(name,body){await body(); evidence.checks.push({name,result:'PASS'}); console.log('PASS '+name);}
async function screen(page,name){const path=artifacts+'/'+name+'.png'; await page.screenshot({path,fullPage:true}); evidence.screenshots.push(path);}
async function confirm(page){for(const key of ['scope','outcome','quantityConfirm','horizonConfirm','declaration']) {const c=control(page,key); if(await c.count()) await c.check();}}
async function outcome(page,meaning='Completed bookings'){await control(page,'edit').click(); await control(page,'direction').selectOption('preserve_value'); await control(page,'meaning').fill(meaning);}
async function save(page){const response=page.waitForResponse(r=>r.url()===url&&r.request().method()==='GET'&&r.headers()['x-inertia']==='true'); await control(page,'save').click(); const data=await(await response).json(); return data.props;}
async function goto(page){for(let i=0;i<30;i++){try{await page.goto(url);return;}catch(e){if(i===29)throw e; await new Promise(r=>setTimeout(r,100));}}}
try {
 const context=await browser.newContext({viewport:{width:320,height:740}}); await context.addCookies([{...fixture.ownerCookie,url:base,httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage(); page.on('pageerror',e=>evidence.errors.push(e.message));
 page.on('request',r=>{if(r.method()==='POST'&&r.url()===url+'/current-intent')evidence.posts.push(r.postDataJSON());});
 await goto(page); await page.locator('[data-current-intent]').waitFor();
 await check('optional absent Arabic RTL and 320px no overflow',async()=>{assert.equal(await page.locator('.business-shell[lang="ar"][dir="rtl"]').count(),1); assert.match(await control(page,'current').innerText(),/لم تُصرّح/); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);});
 await outcome(page); await check('no default scope, date or zone; native label and keyboard focus',async()=>{assert.equal(await control(page,'scope').isChecked(),false); await page.locator('[data-current-intent] details').nth(1).locator('summary').click(); assert.equal(await control(page,'timezone').inputValue(),''); await control(page,'scope').focus(); await page.keyboard.press('Space'); assert.equal(await control(page,'scope').isChecked(),true); assert.ok(await control(page,'meaning').evaluate(n=>n.labels.length>0));});
 await confirm(page); let canonical=await save(page);
 await check('simple outcome persists without quantity, horizon or forced qualifier',async()=>{assert.equal(canonical.business.currentIntent.value.quantity,null); assert.equal(canonical.business.currentIntent.value.horizon,null); assert.equal(canonical.business.currentIntent.value.outcome.clarification,null); assert.equal(evidence.posts[0].version,1); assert.equal(canonical.business.version,2); assert.equal(canonical.version,2);});
 await screen(page,'intent-ar-320');
 await control(page,'edit').click(); await control(page,'needsClarification').check(); await control(page,'clarification').fill('Buyer meets the requested service criteria'); await control(page,'meaning').fill('Qualified buyers');
 await page.locator('[data-current-intent] details').nth(0).locator('summary').click(); await control(page,'count_unit').fill('qualified buyers'); await control(page,'quantity').fill('9'.repeat(120));
 await page.locator('[data-current-intent] details').nth(1).locator('summary').click(); await control(page,'deadline_date').fill('2020-01-01'); await control(page,'timezone').selectOption('Europe/Istanbul'); await confirm(page); canonical=await save(page);
 await check('qualified outcome, exact 120 digits and concrete past local day persist',async()=>{assert.equal(canonical.business.currentIntent.value.quantity,'9'.repeat(120)); assert.equal(canonical.business.currentIntent.value.outcome.clarification,'Buyer meets the requested service criteria'); assert.deepEqual(canonical.business.currentIntent.value.horizon,{deadline_date:'2020-01-01',timezone:'Europe/Istanbul'}); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);});
 const readerContext=await browser.newContext({viewport:{width:320,height:740}}); await readerContext.addCookies([{...fixture.readerCookie,url:base,httpOnly:true,sameSite:'Lax'}]); const reader=await readerContext.newPage(); await goto(reader);
 await check('read-only real user sees whole canonical summary with no mutation controls',async()=>{assert.match(await control(reader,'current').innerText(),/Qualified buyers/); assert.equal(await reader.locator('[data-current-intent] input, [data-current-intent] button, [data-current-intent] form').count(),0);}); await readerContext.close();
 await control(page,'edit').click(); await control(page,'description').fill('ع😀'.repeat(1000)); await confirm(page); canonical=await save(page);
 await check('2000 Unicode code points preserved by real HTTP writer',async()=>assert.equal(canonical.business.currentIntent.value.description,'ع😀'.repeat(1000)));
 await control(page,'edit').click(); await control(page,'description').fill('ع😀'.repeat(1000)+'😀'); await confirm(page); const count=evidence.posts.length; await control(page,'save').click();
 await check('2001 code points blocked without truncation or a POST',async()=>{assert.equal(evidence.posts.length,count); assert.equal(await control(page,'description').inputValue(),'ع😀'.repeat(1000)+'😀');}); await control(page,'cancel').click();
 await control(page,'edit').click(); await page.locator('[data-current-intent] details').nth(1).locator('summary').click();
 await control(page,'deadline_date').fill('2011-12-30'); await control(page,'timezone').selectOption('Pacific/Apia'); await confirm(page); const civil=await save(page);
 await check('actual writer separately rejects skipped local civil day, retaining draft and canonical pair',async()=>{assert.ok(civil.errors.value); assert.equal(await control(page,'deadline_date').inputValue(),'2011-12-30'); assert.deepEqual(civil.business.currentIntent.value.horizon,{deadline_date:'2020-01-01',timezone:'Europe/Istanbul'});}); await control(page,'cancel').click();
 await control(page,'edit').click(); await control(page,'description').fill('Transport retained draft'); await confirm(page);
 await page.route(url+'/current-intent',async route=>{await route.continue({postData:route.request().postData()+' '.repeat(81921)});});
 const tooLarge=page.waitForResponse(r=>r.url()===url+'/current-intent'&&r.request().method()==='POST'); await control(page,'save').click(); assert.equal((await tooLarge).status(),413);
 await page.locator('[data-current-intent] [role="alert"]').filter({hasText:'حد الأمان التقني'}).waitFor();
 await check('real HTTP 413 handled in place without discarding the complete draft',async()=>{assert.equal(await control(page,'description').inputValue(),'Transport retained draft'); assert.equal(await page.locator('iframe').count(),0);}); await page.unroute(url+'/current-intent'); await control(page,'cancel').click();
 await control(page,'edit').click(); await control(page,'description').fill('Dirty intent'); const name=page.locator('input[autocomplete="organization"]'); await name.fill('Dirty business name');
 await page.locator('#market-search').fill('SA'); await page.locator('.target-markets input[value="SA"]').check();
 const competitor=await context.newPage(); await goto(competitor); await control(competitor,'edit').click(); await control(competitor,'direction').selectOption('increase_value'); await confirm(competitor); const winner=await save(competitor);
 const oldRevision=canonical.version;
 for(const locale of ['en','ar']){await page.getByRole('button',{name:locale==='en'?/English|الإنجليزية/:/Arabic|العربية/}).click(); await page.locator('.business-shell[lang="'+locale+'"]').waitFor(); assert.equal(await control(page,'description').inputValue(),'Dirty intent'); assert.equal(await name.inputValue(),'Dirty business name'); assert.equal(await page.locator('.target-markets input[value="SA"]').isChecked(),true);}
 await confirm(page); const stale=await save(page);
 await check('real competing write + locale navigation retain old revision and all mixed drafts; stale material write errors',async()=>{assert.ok(stale.errors.version); assert.equal(evidence.posts.at(-1).version,oldRevision); assert.equal(stale.business.version,winner.version); assert.equal(await control(page,'description').inputValue(),'Dirty intent'); assert.equal(await name.inputValue(),'Dirty business name'); assert.equal(await page.locator('.target-markets input[value="SA"]').isChecked(),true);});
 const reloaded=page.waitForResponse(r=>r.url()===url&&r.request().method()==='GET'&&r.headers()['x-inertia']==='true'); await control(page,'review').click(); await reloaded; await control(page,'adopt').waitFor(); await control(page,'adopt').click();
 await check('reload preserves draft; explicit adoption invalidates confirmations',async()=>{assert.equal(await control(page,'description').inputValue(),'Dirty intent'); assert.equal(await control(page,'declaration').isChecked(),false);}); await confirm(page); canonical=await save(page);
 await check('Intent success leaves sibling drafts and their original versions untouched',async()=>{assert.equal(await name.inputValue(),'Dirty business name'); assert.equal(await page.locator('.target-markets input[value="SA"]').isChecked(),true); assert.equal(evidence.posts.at(-1).version,winner.version);});
 // Execute a real POST, then withhold its redirect while another writer commits.
 await control(page,'edit').click(); await control(page,'description').fill('Submitted before later writer'); await confirm(page);
 let later,interceptError;
 await page.route(url+'/current-intent',async route=>{const response=await route.fetch({maxRedirects:0}); try{assert.equal(response.status(),302); await goto(competitor); await control(competitor,'edit').click(); await control(competitor,'description').fill('Later committed declaration'); await confirm(competitor); later=await save(competitor);}catch(e){interceptError=e;} await route.fulfill({response});});
 canonical=await save(page); await page.unroute(url+'/current-intent'); if(interceptError)throw interceptError;
 await check('late redirect reconciles complete later bundle with later revision',async()=>{assert.equal(canonical.version,later.version); await control(page,'edit').click(); assert.equal(await control(page,'description').inputValue(),'Later committed declaration');});
 await control(page,'description').fill('Submission with in-flight edit'); await confirm(page); let release,received;
 const held=new Promise(r=>{received=r;}); const barrier=new Promise(r=>{release=r;});
 await page.route(url+'/current-intent',async route=>{const response=await route.fetch({maxRedirects:0}); received(); await barrier; await route.fulfill({response});});
 const inFlight=page.waitForResponse(r=>r.url()===url&&r.request().method()==='GET'&&r.headers()['x-inertia']==='true'); await control(page,'save').click(); await held; await control(page,'description').fill('Edited while saving'); release(); const inFlightProps=(await(await inFlight).json()).props; await page.unroute(url+'/current-intent'); await control(page,'save').waitFor({state:'visible'});
 await check('in-flight edits retain complete draft and original shared revision',async()=>{assert.equal(await control(page,'description').inputValue(),'Edited while saving'); await confirm(page); const rejected=await save(page); assert.ok(rejected.errors.version); assert.equal(evidence.posts.at(-1).version,inFlightProps.version-1);});
 await control(page,'cancel').click(); await control(page,'clear').click(); await check('clear explains retained history and requires separate confirmation',async()=>{assert.equal(await control(page,'declaration').isChecked(),false); assert.match(await page.locator('[data-current-intent]').innerText(),/سجل/);}); await control(page,'declaration').check(); canonical=await save(page); assert.equal(canonical.business.currentIntent.value,null);
 await outcome(page,'Completed bookings again'); await confirm(page); canonical=await save(page); assert.equal(canonical.business.currentIntent.value.outcome.meaning,'Completed bookings again');
 await page.setViewportSize({width:1280,height:900}); await page.getByRole('button',{name:/English|الإنجليزية/}).click(); await page.locator('.business-shell[lang="en"]').waitFor();
 await check('English desktop and Arabic mobile readable, no overflow or runtime errors',async()=>{assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true); assert.match(await control(page,'current').innerText(),/Completed bookings again/); assert.deepEqual(evidence.errors,[]);}); await screen(page,'intent-en-desktop');
 await context.close(); console.log('CURRENT_INTENT_BROWSER=PASS');
} finally {await browser.close(); await writeFile(run+'/browser-evidence.json',JSON.stringify(evidence,null,2));}
