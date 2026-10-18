import http from 'node:http';
import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import { runGoalsContractCases } from './goals-api-contract.mjs';
if(process.env.GOALS_SPA_PROOF!=='enabled'||process.env.DB_DATABASE!=='builderad_test_disposable'||process.env.REDIS_PORT!=='6397')throw Error('Dedicated proof environment required');
const require=createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const {chromium}=require('playwright');
const run=process.env.GOALS_SPA_RUN;
const fixture=JSON.parse(await readFile(path.join(run,'fixture.json')));
const hash=v=>createHash('sha256').update(v).digest('hex');
const wire=[],held=new Map(),before=new Map();let turn=0;
const proxy=http.createServer((req,res)=>{
 const row={path:req.url,method:req.method,start:performance.now(),hold:req.headers['x-proof-hold'],cookies:[]};wire.push(row);
 const forward=()=>{
  const outgoing=http.request({hostname:'localhost',port:turn++%2?8137:8136,path:req.url,method:req.method,headers:req.headers},source=>{
   const chunks=[];source.on('data',chunk=>chunks.push(chunk));source.on('end',()=>{
    const bytes=Buffer.concat(chunks);row.status=source.statusCode;row.location=source.headers.location;row.bytes=bytes.length;row.finished=performance.now();row.serverDate=source.headers.date;row.cacheControl=source.headers['cache-control'];row.cookies=(source.headers['set-cookie']??[]).map(cookie=>({name:cookie.split('=')[0],hash:hash(cookie),expires:cookie.match(/;\s*expires=([^;]+)/i)?.[1],maxAge:cookie.match(/;\s*max-age=([^;]+)/i)?.[1]}));
    row.bodyHash=hash(bytes);
    const deliver=()=>{if(!res.destroyed){row.delivered=performance.now();row.sentHash=hash(bytes);row.sentBytes=bytes.length;res.writeHead(source.statusCode,source.headers);res.end(bytes,()=>{row.flushed=performance.now();});}};
    if(row.hold)held.set(row.hold,{deliver,discard:()=>{row.discarded=performance.now();res.destroy();},row,bytes,headers:source.headers});else deliver();
   });
  });
  outgoing.on('error',error=>{row.error=error.message;if(!res.destroyed){res.writeHead(502);res.end();}});
  req.pipe(outgoing);
 };
 if(req.headers['x-proof-before'])before.set(req.headers['x-proof-before'],forward);else forward();
});
await new Promise(resolve=>proxy.listen(8138,'localhost',resolve));
const onlyRestoration=process.env.GOALS_SPA_ONLY==='restoration';
const onlyClosure=process.env.GOALS_SPA_ONLY==='closure';
const onlyRetention=process.env.GOALS_SPA_ONLY==='retention';
const onlyFoundation=process.env.GOALS_SPA_ONLY==='foundation';
const onlyWorkspaces=process.env.GOALS_SPA_ONLY==='workspaces';
const onlyContext=process.env.GOALS_SPA_ONLY==='context';
const onlyPurpose=process.env.GOALS_SPA_ONLY==='purpose';
const onlySources=process.env.GOALS_SPA_ONLY==='sources';
const closure=process.env.GOALS_SPA_CLOSURE==='enabled';
const result={head:spawnSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).stdout.trim(),selection:process.env.GOALS_SPA_ONLY??'all',kind:'Real Laravel + dedicated Redis + PostgreSQL runtime/RLS + Chromium; synthetic users only',cases:[],wire,errors:[],assets:[],findings:[],unverified:[]};
let browser;
const until=async(fn,message='checkpoint')=>{const end=Date.now()+15000;while(!fn()){if(Date.now()>end)throw Error(message);await new Promise(resolve=>setTimeout(resolve,15));}};
function command(mode,who='a'){
 const child=spawnSync('C:/tools/php85/php.exe',['tests/Support/GoalsSpaFixture.php',mode,who],{encoding:'utf8',env:{...process.env,APP_ENV:'testing'}});
 assert.equal(child.status,0,child.stderr||child.stdout);
 try{return JSON.parse(child.stdout.trim());}catch{throw Error('Fixture '+mode+' did not return JSON: '+child.stdout+' '+child.stderr);}
}
const workspace=fixture.a.workspaces[0].public_id,other=fixture.a.workspaces[1].public_id,foreign=fixture.b.workspaces[0].public_id;
const target=(mode,id=workspace,review=false)=>`${mode==='spa'?'/spa':''}/workspaces/${id}/goals${review?'/review':''}`;
const meta=page=>page.evaluate(()=>{const raw=(document.getElementById('application-bootstrap')??document.getElementById('goals-bootstrap'))?.textContent;return raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);});
async function post(page,url,body,extra={}){
 return page.evaluate(async({url,body,extra})=>{
  const raw=(document.getElementById('application-bootstrap')??document.getElementById('goals-bootstrap'))?.textContent;
  const identity=raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);
  const response=await fetch(url,{method:'POST',credentials:'same-origin',redirect:'manual',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':identity.csrf,'X-Builderad-Document':identity.receipt,'X-Builderad-Transition':identity.transition,...extra},body:body?JSON.stringify(body):undefined});
  return {status:response.status,body:await response.json()};
 },{url,body,extra});
}
async function get(page,url,extra={}){
 return page.evaluate(async({url,extra})=>{
  const raw=(document.getElementById('application-bootstrap')??document.getElementById('goals-bootstrap'))?.textContent;
  const identity=raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);
  const response=await fetch(url,{headers:{Accept:'application/json','X-Builderad-Document':identity.receipt,...extra},cache:'no-store'});
  const text=await response.text();let body;try{body=JSON.parse(text);}catch{body=text;}
  return {status:response.status,body};
 },{url,extra});
}
const ready=(page,mode)=>page.waitForFunction(mode=>{
 const workspace=location.pathname.match(/workspaces\/([^/]+)\/goals/)?.[1];
 const root=document.querySelector('[data-application-spa]');
 return root?.dataset.state==='ready'&&!!root.querySelector(`[data-goals-workspace="${workspace}"]`);
},mode);
async function login(page,mode,who='a'){
 await page.goto('http://localhost:8138/login');
 const response=await post(page,'/login',{email:`goals-${who}@example.test`,password:'DisposableGoalsProof42!'});assert.equal(response.status,200,JSON.stringify(response));
 await page.goto('http://localhost:8138'+target(mode,fixture[who].workspaces[0].public_id));await ready(page,mode);
}
async function visit(page,mode,url){
 await page.evaluate(url=>__goalsSpa.router.push(url),url);
 await page.waitForURL('http://localhost:8138'+url);await ready(page,mode);
}
const selected=(page,id)=>page.locator(`[data-goals="select-${id}"]`);
async function check(name,mode,fn){
 const row={name,mode,start:Date.now()};result.cases.push(row);
 try{row.details=await fn();row.result=row.details?.result??'PASS';console.log(row.result+' '+mode+' '+name);}
 catch(error){row.result='FAIL';row.error=error.stack;console.log('FAIL '+mode+' '+name);throw error;}
 finally{await writeFile(path.join(run,'browser-results.json'),JSON.stringify(result,null,2));}
}
try{
 browser=await chromium.launch({headless:true,ignoreDefaultArgs:['--disable-back-forward-cache'],executablePath:'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe'});
 result.browser=browser.version();
 if(onlyRestoration){
  const {runRestorationAcceptance}=await import('./goals-spa-restoration.mjs');
  await runRestorationAcceptance({browser,check,login,get,post,target,fixture,workspace,foreign,command,wire,before,until,result});
 }
 if(onlyFoundation){
  const {runFoundationCases}=await import('./spa-foundation-browser.mjs');
  await runFoundationCases({browser,check,login,ready,visit,target,until,result,workspace,other,command,run});
 }
 if(onlyWorkspaces){
  const {runWorkspaceCases}=await import('./workspace-spa-browser.mjs');
  await runWorkspaceCases({browser,check,login,meta,result,fixture,command,run});
 }
 if(onlyContext){const {runBusinessContextCases}=await import('./business-context-spa-browser.mjs');await runBusinessContextCases({browser,check,login,meta,get,post,command,fixture,result,run,until,held});}
 if(onlyPurpose){const {runObjectPurposeCases}=await import('./object-purpose-spa-browser.mjs');await runObjectPurposeCases({browser,check,login,meta,get,post,command,fixture,result,run,until,held});}
 if(onlySources){const {runSourceCases}=await import('./sources-spa-browser.mjs');await runSourceCases({browser,check,login,meta,get,post,command,fixture,result,run,until,held});}
 if(!onlyRestoration&&!onlyClosure&&!onlyRetention&&!onlyFoundation&&!onlyWorkspaces&&!onlyContext&&!onlyPurpose&&!onlySources)await runGoalsContractCases({browser,check,login,meta,fixture,result});
 for(const mode of onlyRestoration||onlyClosure||onlyRetention||onlyFoundation||onlyWorkspaces||onlyContext||onlyPurpose||onlySources?[]:['application']){
  const context=await browser.newContext({viewport:{width:1280,height:900}});let page=await context.newPage();page.setDefaultTimeout(15000);
  page.on('dialog',dialog=>dialog.accept());page.on('pageerror',error=>result.errors.push({mode,message:error.message}));
  context.on('response',response=>{if(response.url().includes('/build/')&&response.status()===200)result.assets.push({url:response.url(),status:response.status()});});
  await check('real password login and canonical Goals read',mode,async()=>{await login(page,mode);assert.equal((await meta(page)).binding.principal,fixture.a.user);const data=await get(page,`/workspaces/${workspace}/goals`);assert.equal(data.status,200);return {principal:fixture.a.user,version:data.body.version};});
  await page.screenshot({path:path.join(run,mode+'-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(run,mode+'-mobile-viewport.png'),fullPage:true});await page.setViewportSize({width:1280,height:900});
  await check('canonical Goals locale change preserves choices and translates the workflow',mode,async()=>{
   const choices=await selected(page,'sales').isChecked();
   await page.locator('[data-application="locale"]').click();await page.waitForFunction(()=>document.documentElement.lang==='en');
   assert.equal(await page.locator('.goals-screen h1').innerText(),'What is your business goal now?');
   assert.equal(await selected(page,'sales').isChecked(),choices);
   await page.locator('[data-application="locale"]').click();await page.waitForFunction(()=>document.documentElement.lang==='ar');
   return {locales:['ar','en'],sameDraft:true};
  });
  await check('RLS foreign workspace and CSRF denial preserve effects',mode,async()=>{
   const initial=command('inspect');assert.equal((await get(page,`/workspaces/${foreign}/goals`)).status,403);
   const originVerified=await post(page,`/workspaces/${workspace}/goals`,{value:{},version:0},{'X-CSRF-TOKEN':'wrong'});assert.equal(originVerified.status,422,'Laravel 13 accepts verified same-origin before token fallback; malformed Goals still fails validation');
   const identity=await meta(page);
   const invalid=await context.request.post(`http://localhost:8138/workspaces/${workspace}/goals`,{headers:{Accept:'application/json','X-CSRF-TOKEN':'wrong','X-Builderad-Document':identity.receipt},data:{value:{},version:0}});assert.equal(invalid.status(),419,'Absent fetch-metadata must enforce the token fallback');
   assert.equal(command('inspect').effects,initial.effects);return {foreignRead:403,verifiedOriginValidation:422,tokenFallback:419,rls:initial.rls};
  });
  await check('draft retained across Workspace traversal; reload deliberately resets to canonical values',mode,async()=>{
   await page.evaluate(()=>window.__proofDocument=crypto.randomUUID());const token=await page.evaluate(()=>__proofDocument);
   const desired=!(await selected(page,'sales').isChecked());await selected(page,'sales').setChecked(desired);
   await page.waitForTimeout(300);
   await visit(page,mode,target(mode,other));assert.equal(await page.evaluate(()=>__proofDocument),token);
   await page.goBack({waitUntil:'commit'});await ready(page,mode);assert.equal(await selected(page,'sales').isChecked(),desired);
   await page.reload();await ready(page,mode);const canonical=(await get(page,`/workspaces/${workspace}/goals`)).body;
   assert.equal(await selected(page,'sales').isChecked(),canonical.value.goals.includes('sales'),'Reload resets to canonical values in a new identity document');
   assert.equal(await page.evaluate(()=>JSON.stringify(history.state).includes('preferences')),false);
  });
  await check('canonical validation, stale version 409, and no repeated effect from retry',mode,async()=>{
   const data=(await get(page,`/workspaces/${workspace}/goals`)).body;
   const value={goals:['sales'],priority:'sales',results:{sales:data.value.results?.sales?.includes('actual_sales')?['sales_value']:['actual_sales']}};
   const beforeCount=command('inspect').effects;
   assert.equal((await post(page,`/workspaces/${workspace}/goals`,{value:{goals:['fake'],priority:'fake',results:{}},version:data.version})).status,422);
   const saved=await post(page,`/workspaces/${workspace}/goals`,{value,version:data.version});assert.equal(saved.status,200);
   const repeated=await post(page,`/workspaces/${workspace}/goals`,{value,version:data.version});assert.equal(repeated.status,409);
   const inspected=command('inspect');assert.equal(inspected.effects,beforeCount+1);assert.equal(inspected.runtimeUnscopedGoals,0,'Runtime without signed transaction context must not see canonical Goals');return {savedVersion:saved.body.version,retryStatus:409,runtimeUnscopedGoals:0};
  });
  await check('UI conflict review preserves draft and explicit adoption saves once',mode,async()=>{
   await selected(page,'sales').check();await selected(page,'customers').check();
   const sales=page.locator('[data-goals="priority-sales"]');if(await sales.count())await sales.click();
   await page.locator('[data-goals="result-actual_sales"]').check();
   const conflictResponse=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===`/workspaces/${workspace}/goals`);
   await page.locator('[data-goals="continue"]').click();assert.equal((await conflictResponse).status(),409);
   await page.locator('[data-goals="review"]').click();await page.locator('[data-goals="adopt"]').waitFor();assert.equal(await selected(page,'customers').isChecked(),true);
   await page.locator('[data-goals="adopt"]').click();const count=command('inspect').effects;
   const saved=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===`/workspaces/${workspace}/goals`);
   await page.locator('[data-goals="continue"]').click();assert.equal((await saved).status(),200);
   await page.waitForURL(url=>url.pathname.endsWith('/review'));
   assert.equal(command('inspect').effects,count+1);await visit(page,mode,target(mode));
  });
  await check('late read and rapid visits cannot publish the wrong Workspace',mode,async()=>{
   let once=true;
   const latePath=mode==='spa'?`/spa/api/workspaces/${other}/goals`:`/workspaces/${other}/goals/context`;
   await page.route('**/*',async route=>{const req=route.request();if(once&&new URL(req.url()).pathname===latePath&&req.headers()['x-builderad-visibility']!=='1'&&req.headers()['x-goals-witness']!=='1'){once=false;await route.continue({headers:{...req.headers(),'X-Proof-Hold':'late-'+mode}});}else await route.continue();});
   await page.evaluate(url=>{void __goalsSpa.router.push(url);},target(mode,other));
   await until(()=>held.has('late-'+mode));await visit(page,mode,target(mode));held.get('late-'+mode).deliver();held.delete('late-'+mode);await page.waitForTimeout(150);await ready(page,mode);
   assert.equal(new URL(page.url()).pathname,target(mode));await page.unroute('**/*');
   await page.evaluate(urls=>{for(const url of urls)void __goalsSpa.router.push(url);},[target(mode,other),target(mode),target(mode,other)]);
   await page.waitForURL('http://localhost:8138'+target(mode,other));await ready(page,mode);await visit(page,mode,target(mode));
  });
  await check('authorized finite SSE updates only the matching document and Workspace',mode,async()=>{
   const data=(await get(page,`/workspaces/${workspace}/goals`)).body;
   const changed={goals:['growth'],priority:'growth',results:{growth:[]}};
   const saved=await post(page,`/workspaces/${workspace}/goals`,{value:changed,version:data.version});assert.equal(saved.status,200);
   assert.equal(await page.evaluate(workspace=>__goalsSpa.eventProof(workspace),workspace),true);
   assert.equal(await page.evaluate(()=>__goalsSpa.current.value.preferences.version),saved.body.version);
   const id=await meta(page);
   assert.equal(await page.evaluate(({workspace,foreign,binding,version})=>__goalsSpa.applyEvent({workspace:foreign,binding,version},workspace),{workspace,foreign,binding:id.binding,version:saved.body.version+1}),false);
   assert.equal(await page.evaluate(({workspace,binding,version})=>__goalsSpa.applyEvent({workspace,binding:{...binding,principal:-1},version},workspace),{workspace,binding:id.binding,version:saved.body.version+1}),false);
   return {transport:'real HTTP text/event-stream',version:saved.body.version};
  });
  await check('bounded warm local Workspace navigation observations',mode,async()=>{
   const base=(await get(page,`/workspaces/${workspace}/goals`)).body;
   assert.equal((await post(page,`/workspaces/${workspace}/goals`,{value:{goals:['sales','customers','growth'],priority:'sales',results:{sales:['actual_sales'],customers:['serious_inquiries'],growth:[]}},version:base.version})).status,200);
   const samples=[];
   for(let index=0;index<8;index++){
    const start=performance.now(),mark=wire.length;await visit(page,mode,target(mode,index%2?workspace:other));
    const requests=wire.slice(mark).filter(row=>row.path.includes('/workspaces/')&&row.method==='GET');
    samples.push({milliseconds:performance.now()-start,requests:requests.length,bytes:requests.reduce((sum,row)=>sum+(row.bytes??0),0)});
   }
   return {samples,note:'Local serialized headless observations, warm component/cache; includes witness. No production latency or statistically established speedup.'};
  });
  await check('permission withdrawn before queued request cannot mutate',mode,async()=>{
   const data=(await get(page,`/workspaces/${workspace}/goals`)).body;
   const waiting=post(page,`/workspaces/${workspace}/goals`,{value:{goals:['customers'],priority:'customers',results:{customers:[]}},version:data.version},{'X-Proof-Before':'deny-'+mode});
   await until(()=>before.has('deny-'+mode));command('deny');const count=command('inspect').effects;
   before.get('deny-'+mode)();before.delete('deny-'+mode);assert.equal((await waiting).status,403);assert.equal(command('inspect').effects,count);command('restore');
  });
  await check('read-only canonical Goals preserves outcomes and exposes no writer controls',mode,async()=>{
   const count=command('inspect').effects;const canonical=(await get(page,`/workspaces/${workspace}/goals`)).body;
   command('deny');
   try{
    await page.reload();await ready(page,mode);
    assert.equal(await page.locator('[data-goals="continue"]').count(),0);
    assert.equal(await page.locator('[data-goals="form"] input[type="checkbox"]').count(),0);
    const selectedGoal=canonical.value.goals[0];
    if(selectedGoal)await page.locator(`[data-goals="view-${selectedGoal}"]`).click();
    assert.deepEqual((await get(page,`/workspaces/${workspace}/goals`)).body,canonical);assert.equal(command('inspect').effects,count);
    return {writerControls:0,canonicalUnchanged:true};
   }finally{command('restore');await page.reload();await ready(page,mode);}
  });
  await check('cookie-free renewal preserves credential and canonical deadlines',mode,async()=>{
   const old=command('inspect');const identity=await meta(page);const session=old.sessions.find(row=>row.session_key_hash===identity.binding.credential);
   const renewed=await post(page,'/identity/renew');assert.equal(renewed.status,200);
   const after=command('inspect').sessions.find(row=>row.session_key_hash===identity.binding.credential);
   assert.equal(after.idle_expires_at,session.idle_expires_at);assert.equal(after.absolute_expires_at,session.absolute_expires_at);
   const row=wire.filter(row=>row.path==='/identity/renew').at(-1);assert.equal(row.cookies.length,0);return {cookieNames:row.cookies.map(c=>c.name),renewalProtocol:renewed.body.identity.renewalProtocol};
  });
  await check('actual account switch and cross-workspace denial',mode,async()=>{
   await page.addInitScript(()=>{window.__pageshows=[];addEventListener('pageshow',event=>__pageshows.push({persisted:event.persisted,trusted:event.isTrusted}));});
   await page.reload();await ready(page,mode);
   // Switch with the existing signed evidence, then follow the required new-document boundary.
   const switched=await post(page,'/identity/switch',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'});assert.equal(switched.status,200);
   assert.equal((await get(page,`/workspaces/${workspace}/goals`)).status,409,'Old document receipt must be rejected under the new cookie');
   await page.close();page=await context.newPage();page.setDefaultTimeout(15000);page.on('dialog',d=>d.accept());
   await page.goto('http://localhost:8138'+target(mode,foreign));await ready(page,mode);
   const old=await get(page,`/workspaces/${workspace}/goals`);assert.equal(old.status,403);
   return {switchedPrincipal:switched.body.identity.binding.principal,note:'Native cross-account BFCache traversal remains a separate gate; this case proves switch and cross-workspace denial only'};
  });
  await check('logout terminal transition denies further effects',mode,async()=>{
   const count=command('inspect').effects;const oldIdentity=await meta(page);
   {
    const ended=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).pathname==='/logout');
    await page.locator('[data-spa="logout"]').click();assert.equal((await ended).status(),200);await page.waitForURL('http://localhost:8138/');
   }
   assert.equal((await get(page,`/workspaces/${foreign}/goals`,{'X-Builderad-Document':oldIdentity.receipt})).status,409);assert.equal(command('inspect').effects,count);
  });
  await context.close();
  for(const terminal of ['expire','revoke']){
   const terminalContext=await browser.newContext();const terminalPage=await terminalContext.newPage();terminalPage.on('dialog',d=>d.accept());await login(terminalPage,mode);
   await check(terminal+' canonical session blocks read and write',mode,async()=>{
    const count=command('inspect').effects;command(terminal);assert.equal((await get(terminalPage,`/workspaces/${workspace}/goals`)).status,409);
    assert.equal((await post(terminalPage,`/workspaces/${workspace}/goals`,{value:{},version:0})).status,409);assert.equal(command('inspect').effects,count);
    await terminalPage.reload();await terminalPage.waitForURL('http://localhost:8138/login');assert.equal(await terminalPage.locator('[data-goals="form"]').count(),0);
   });await terminalContext.close();
  }
 }
 // Exact HTTP ownership experiment, shared backend and actual cookie delivery.
 for(const mode of onlyRestoration||onlyRetention||onlyFoundation||onlyWorkspaces||onlyContext||onlyPurpose||onlySources?[]:['application']){
  const context=await browser.newContext();const page=await context.newPage();page.on('dialog',d=>d.accept());await login(page,mode);
  await check('reversed real renewal/login cookie delivery',mode,async()=>{
   const originalIdentity=await meta(page);
   const originalServer=command('inspect');
   const renewal=post(page,'/identity/renew',undefined,{'X-Proof-Hold':'renew-'+mode});await until(()=>held.has('renew-'+mode));
   const heldRenewal=held.get('renew-'+mode);assert.equal(heldRenewal.row.cookies.length,0);
   const switched=await post(page,'/identity/switch',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'});assert.equal(switched.status,200);
   const beforeContext=await get(page,'/identity/context');assert.equal(beforeContext.status,200);assert.equal(beforeContext.body.identity.binding.principal,fixture.b.user);
   const beforeCookies=await context.cookies();heldRenewal.deliver();held.delete('renew-'+mode);await renewal;
   const afterCookies=await context.cookies();
   const status=await get(page,'/identity/context');
   const sessionName=beforeCookies.find(c=>c.httpOnly&&c.name.endsWith('-session')).name;
   const regressed=beforeCookies.find(c=>c.name===sessionName)?.value!==afterCookies.find(c=>c.name===sessionName)?.value;
   const effects=command('inspect').effects;
   const denied=await post(page,`/workspaces/${workspace}/goals`,{value:{},version:0});assert.equal(denied.status,409);assert.equal(command('inspect').effects,effects);
   const snapshot=values=>values.map(({name,value,path,expires,httpOnly,secure,sameSite})=>({name,hash:hash(value),path,expires,httpOnly,secure,sameSite}));
   const finalServer=command('inspect');
   const observation={renewalCookieNames:heldRenewal.row.cookies.map(c=>c.name),contextBeforeLate:beforeContext.status,cookieReplacedAfterNewLogin:regressed,contextStatus:status.status,oldProtectedWrite:denied.status,canonicalNewPrincipal:switched.body.identity.binding.principal,
    cookieBefore:snapshot(beforeCookies),cookieAfter:snapshot(afterCookies),
    oldSessionBefore:originalServer.sessions.find(s=>s.session_key_hash===originalIdentity.binding.credential),oldSessionAfter:finalServer.sessions.find(s=>s.session_key_hash===originalIdentity.binding.credential),
    newSessionAfter:finalServer.sessions.find(s=>s.session_key_hash===switched.body.identity.binding.credential),transitionAfter:finalServer.transitions.find(t=>t.lineage_id===originalIdentity.binding.lineage)};
   // Record availability separately from authority. A stale credential must never regain old authority.
   assert.equal(status.status,200,'Latest login must remain usable after the older renewal is delivered');
   assert.equal(status.body.identity.binding.principal,fixture.b.user);
   assert.equal(regressed,false,'The late renewal must not replace the new cookie');
   assert.ok(observation.oldSessionAfter.revoked_at);assert.equal(observation.newSessionAfter.revoked_at,null);
   if(status.status!==200)result.findings.push({gate:'latest login continuity under reversed Set-Cookie',mode,status:'VERIFIED',observation,classification:'shared-backend continuity failure; preserving the latest credential requires separate contract decision; not fixed by router'});
   return {...observation,result:status.status===200?'PASS':'FAIL_CONTRACT'};
  });await context.close();
 }
 if(closure){
  const {runClosureCases}=await import('./goals-spa-closure.mjs');
  await runClosureCases({browser,check,login,get,post,meta,ready,visit,target,selected,command,until,held,wire,result,fixture,workspace,other,foreign,run,hash});
 }
 for(const mode of onlyClosure||onlyRetention||onlyFoundation||onlyWorkspaces||onlyContext||onlyPurpose||onlySources?[]:['application']){
  for(const fault of onlyRestoration?[]:['load','render']){
   const context=await browser.newContext();const page=await context.newPage();page.on('dialog',d=>d.accept());
   await page.goto('http://localhost:8138/login');assert.equal((await post(page,'/login',{email:'goals-a@example.test',password:'DisposableGoalsProof42!'})).status,200);
   await check('actual component '+fault+' failure closes private presentation',mode,async()=>{
    const pattern=/\/build\/goals-spa\/assets\/GoalsEditor-[^/]+\.js/;
    await page.route(pattern,route=>fault==='load'?route.abort('failed'):route.fulfill({status:200,contentType:'text/javascript',body:'export default {setup(){throw new Error("GOALS_PROOF_RENDER_FAILURE")}}'}));
    await page.goto('http://localhost:8138'+target(mode));
    await page.waitForFunction(()=>__goalsSpa.status.value==='unavailable');
    assert.equal(await page.locator('[data-goals="form"]').count(),0);return {fault,fixture:'browser-only injection into the component module response; library/source files unchanged'};
   });
   if(fault==='render')await check('terminal closure survives synthetic lifecycle notifications without requests',mode,async()=>{
    const mark=wire.length;const state=await page.evaluate(()=>{__goalsSpa.close();dispatchEvent(new PageTransitionEvent('pagehide'));dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));return __goalsSpa.status.value;});
    assert.equal(state,'closed');await page.waitForTimeout(100);assert.equal(wire.slice(mark).filter(row=>row.path.startsWith('/spa/api/')).length,0);
    return {classification:'synthetic lifecycle handler probe; not native BFCache evidence'};
   });await context.close();
  }
  for(const transition of ['expire','switch']){
   const context=await browser.newContext();const page=await context.newPage();page.on('dialog',d=>d.accept());const shows=[],notRestored=[];
   const cdp=await context.newCDPSession(page);await cdp.send('Page.enable');cdp.on('Page.backForwardCacheNotUsed',event=>notRestored.push(event.notRestoredExplanations));
   await page.addInitScript(()=>addEventListener('pageshow',event=>console.log('GOALS_NATIVE_SHOW '+JSON.stringify({persisted:event.persisted,trusted:event.isTrusted,path:location.pathname}))));
   page.on('console',message=>{if(message.text().startsWith('GOALS_NATIVE_SHOW '))shows.push(JSON.parse(message.text().slice(18)));});
   await login(page,mode);
   await check('native BFCache '+transition+' denies authority and hides private DOM after traversal',mode,async()=>{
    const mark=shows.length;await page.goto('http://localhost:8138/up');
    if(transition==='expire')command('expire');
    else{
     const switcher=await context.newPage();switcher.on('dialog',d=>d.accept());await switcher.goto('http://localhost:8138'+target(mode));await ready(switcher,mode);
     assert.equal((await post(switcher,'/identity/switch',{email:'goals-b@example.test',password:'DisposableGoalsProof42!'})).status,200);await switcher.close();
    }
    const wireMark=wire.length;await page.goBack({waitUntil:'commit'});
    await until(()=>wire.slice(wireMark).some(row=>row.status===409||row.status===401||row.status===403||(row.status===302&&row.path===target(mode)&&row.location?.endsWith('/login'))),'restoration must cause actual server denial or native login redirect');
    await page.waitForFunction(()=>{const form=document.querySelector('[data-goals="form"]');return !form||getComputedStyle(form).visibility==='hidden'||!form.getClientRects().length||!!form.closest('[data-application-spa]:not([data-state="ready"])');});
    const native=shows.slice(mark).some(event=>event.persisted&&event.trusted);
    if(!native)result.unverified.push({mode,gate:'native BFCache '+transition,reason:'No trusted persisted event; fresh-document path does not prove BFCache'});
    return {result:native?'PASS':'UNVERIFIED',observedPath:native?'native-bfcache':'fresh-document',freshDocumentSafety:native?'NOT_EXERCISED':'PASS',shows:shows.slice(mark),notRestored,note:'A fresh native redirect is safe but is not BFCache evidence. Checks visible DOM after server denial; pre-JavaScript cached-frame confidentiality is not proved'};
   });await context.close();
  }
 }
 assert.deepEqual(result.errors,[],'Ordinary application journeys must not emit unhandled page errors');
 result.database=command('inspect');
 const servedAssets=wire.filter(row=>row.path.startsWith('/build/')&&row.status===200&&row.sentHash);
 for(const asset of servedAssets){const local=path.join(process.cwd(),'public',new URL(asset.path,'http://localhost:8138').pathname.slice(1));const bytes=await readFile(local);assert.equal(hash(bytes),asset.sentHash,'Forwarded HTTP asset differs from this candidate: '+asset.path);assert.equal(bytes.length,asset.sentBytes);}
 for(const asset of result.assets)assert.ok(servedAssets.some(row=>row.path===new URL(asset.url).pathname),'Browser asset has no verified network origin: '+asset.url);
 result.assetVerification={boundary:'Exact real PHP response bytes passed unchanged to the browser-facing HTTP response; browser URL/status observed separately. Aborted navigations may evict CDP bodies and are not hashed as empty files.',forwardedResponses:servedAssets.length,browserResponses:result.assets.length};
 result.servedAssetsVerified=true;
 result.unverified.push('Real mobile background and pre-JavaScript cached frames','SSE reconnect/gaps/open-channel revocation','Production performance distributions and full Foundation on final dirty state');
 result.acceptance=result.findings.length||result.unverified.some(value=>typeof value==='object'&&value.gate?.startsWith('native BFCache'))?'BLOCKED':'REQUIRED_GATES_REVIEW';
 if(result.acceptance==='BLOCKED')process.exitCode=2;
}catch(error){result.failure=error.stack;process.exitCode=1;}
finally{
 await browser?.close();for(const value of held.values())value.deliver();held.clear();
 proxy.closeAllConnections();await new Promise(resolve=>proxy.close(resolve));
 result.cleanup='browser and proxy closed';await writeFile(path.join(run,'browser-results.json'),JSON.stringify(result,null,2));
 console.log(JSON.stringify({cases:result.cases.map(({name,mode,result})=>({name,mode,result})),findings:result.findings,acceptance:result.acceptance,unverified:result.unverified,failure:result.failure,evidence:run},null,2));
}
