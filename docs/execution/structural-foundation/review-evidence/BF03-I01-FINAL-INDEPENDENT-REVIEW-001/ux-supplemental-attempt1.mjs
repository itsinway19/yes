// Independent reviewer probe. Uses only the official runner's synthetic HTTP environment.
import { createRequire } from 'node:module';
import { access, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';
const run = process.argv[2];
assert.ok(run?.includes('storage') && run.includes('goals-spa-'));
const output = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const result = { run, syntheticOnly: true, probes: [], errors: [] };
let browser;
try {
  // The official runner creates this only after isolation guards and synthetic fixtures.
  let ready = false;
  for (let n=0;n<180;n++) {
    try { await access(path.join(run,'business-surface-fixture.json')); ready=true; break; } catch {}
    await new Promise(resolve=>setTimeout(resolve,1000));
  }
  assert.ok(ready, 'Official guarded browser fixture did not become ready');
  assert.ok((await readFile(path.join(run,'proof-token.txt'),'utf8')).trim());
  browser=await chromium.launch({ headless:true, executablePath:'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
  const context=await browser.newContext({viewport:{width:1366,height:900},locale:'ar-JO'});
  const page=await context.newPage();page.setDefaultTimeout(20000);
  page.on('pageerror',error=>result.errors.push(error.message));
  const send=(url,method='GET',body)=>page.evaluate(async({url,method,body})=>{
    const identity=JSON.parse(document.getElementById('application-bootstrap').textContent).identity;
    const response=await fetch(url,{method,credentials:'same-origin',redirect:'manual',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':identity.csrf,'X-Builderad-Document':identity.receipt,'X-Builderad-Transition':identity.transition},body:body?JSON.stringify(body):undefined});
    return {status:response.status,body:await response.json()};
  },{url,method,body});
  const readyPage=()=>page.waitForFunction(()=>document.querySelector('[data-application-spa]')?.dataset.state==='ready');
  await page.goto('http://localhost:8136/login');
  const login=await send('/login','POST',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'});assert.equal(login.status,200);
  await page.goto('http://localhost:8136'+login.body.redirect);await readyPage();
  const created=await send('/api/businesses','POST',{command_id:crypto.randomUUID(),name:'نشاط المراجعة المستقلة',business_type_id:null});assert.equal(created.status,200);
  const business=created.body.data.business_public_id;
  const basis=String(created.body.data.grant_refs['business.context.read']);
  result.business=business;
  await page.goto(`http://localhost:8136/businesses/${business}?access=${basis}`);await readyPage();
  const section=async id=>{await page.locator(`[data-workspace-section="${id}"]`).click();await page.locator(`[data-workspace-panel="${id}"]`).waitFor({state:'visible'});};
  await page.screenshot({path:path.join(output,'independent-overview-ar-desktop.png'),fullPage:true});
  for(const id of ['direction','settings','access']) {
    await section(id);
    result.probes.push({name:`existing destination ${id}`,visibleText:(await page.locator(`[data-workspace-panel="${id}"]`).innerText()).slice(0,2500)});
  }
  await section('inquiries');
  const panel=page.locator('[data-inquiry-panel]');
  await panel.locator('[data-inquiry-optin]').waitFor();
  await panel.locator('[data-inquiry-optin] input[type=checkbox]').check();
  await panel.getByRole('button',{name:'تفعيل السجل الخاص',exact:true}).click();
  await panel.getByRole('button',{name:'تسجيل تصريح جديد',exact:true}).waitFor();
  const endpoint=`/api/businesses/${business}/inquiry-evidence`;
  const select=grant=>({realm_kind:'direct',realm_workspace_public_id:null,requirements:[{requirement_kind:'business',leaf_grant_id:String(grant)}]});
  const eligible=await send(`${endpoint}/eligibility?access=${basis}`);assert.equal(eligible.status,200);
  const grants=eligible.body.data.grants;
  const value={cohort:{channel_kind:'form',definition_version:1,country_code:null,population:'recorded_inquiries',counting_policy:'distinct_inquiries'},observation:{period_start:'2020-01-01T00:00:00Z',period_end:'2020-01-02T00:00:00Z',as_of:'2020-01-03T00:00:00Z',timezone:'Asia/Riyadh'},cost:null,inquiries:{count:37,coverage:'complete'},acceptance:null};
  const recorded=await send(endpoint,'POST',{command_id:crypto.randomUUID(),authority_selection:select(grants['business.inquiry_evidence.record']),value});assert.equal(recorded.status,200);
  const refresh=async()=>{await panel.getByRole('button',{name:'التحقق من الحالة الحالية',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-inquiry-panel]')?.getAttribute('aria-busy')==='false');};
  await refresh();await panel.locator('[data-inquiry-open]').first().click();
  await panel.getByRole('button',{name:'فتح التصحيح',exact:true}).click();
  const revokeCorrect=await send(`${endpoint}/rights`,'POST',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.correct',authority_selection:select(grants['business.inquiry_evidence.correct'])});assert.equal(revokeCorrect.status,200);
  await refresh();
  const after=await send(`${endpoint}/eligibility?access=${basis}`);assert.equal(after.status,200);
  const save=panel.getByRole('button',{name:'حفظ التصحيح',exact:true});
  let mutationRequests=0;
  page.on('request',request=>{if(request.method()==='POST'&&new URL(request.url()).pathname.includes('/revisions'))mutationRequests++;});
  const visible=await save.isVisible(),enabled=await save.isEnabled();
  if(visible&&enabled)await save.click();
  await page.waitForTimeout(250);
  result.probes.push({name:'known correction revocation after explicit refresh',correctRightPresent:!!after.body.data.grants['business.inquiry_evidence.correct'],saveVisible:visible,saveEnabled:enabled,revisionPostRequests:mutationRequests,notice:await panel.locator('[data-inquiry-message]').innerText()});
  await page.screenshot({path:path.join(output,'correction-right-revoked-refresh.png'),fullPage:true});
  await panel.getByRole('button',{name:'إلغاء التعديل',exact:true}).click();
  await section('overview');
  const revokeRead=await send(`${endpoint}/rights`,'POST',{command_id:crypto.randomUUID(),action:'business.inquiry_evidence.read',authority_selection:select(grants['business.inquiry_evidence.read'])});assert.equal(revokeRead.status,200);
  const denied=await send(`${endpoint}?access=${grants['business.inquiry_evidence.read']}`);assert.equal(denied.status,403);
  let reentryRequests=0;
  page.on('request',request=>{if(new URL(request.url()).pathname.startsWith(endpoint))reentryRequests++;});
  await section('inquiries');
  result.probes.push({name:'hidden-panel return following out-of-panel read revocation',backendReadStatus:denied.status,inquiryGetRequestsOnReturn:reentryRequests,protectedDetailVisible:await panel.locator('[data-inquiry-detail]').isVisible(),protectedCount:await panel.locator('[data-inquiry-detail-count]').innerText()});
  await page.screenshot({path:path.join(output,'retained-panel-after-read-revocation.png'),fullPage:true});
  await refresh();
  result.probes.push({name:'explicit refresh after revoked read',protectedDetailCount:await panel.locator('[data-inquiry-detail]').count(),collectionCount:await panel.locator('[data-inquiry-list]').count(),editorCount:await panel.locator('[data-inquiry-editor]').count()});
  result.completed=true;
} catch(error) {result.failure=error.stack;process.exitCode=1;}
finally {if(browser)await browser.close();await writeFile(path.join(output,'ux-supplemental-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result,null,2));}
