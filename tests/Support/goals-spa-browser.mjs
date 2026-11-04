import http from 'node:http';
import {readFile,writeFile} from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
if(process.env.GOALS_SPA_PROOF!=='enabled'||process.env.DB_DATABASE!=='builderad_test_disposable'||process.env.REDIS_PORT!=='6397')throw Error('Dedicated proof environment required');
const require=createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const {chromium}=require('playwright');
const run=process.env.GOALS_SPA_RUN;
const fixture=JSON.parse(await readFile(path.join(run,'fixture.json')));
const hash=v=>createHash('sha256').update(v).digest('hex');
const wire=[],held=new Map(),before=new Map();let turn=0;
const proxy=http.createServer((req,res)=>{
 const correlation=req.headers['x-proof-correlation']??crypto.randomUUID();
 req.headers['x-proof-correlation']=correlation;
 const row={correlation,arrivalUnix:Date.now(),path:req.url,method:req.method,start:performance.now(),hold:req.headers['x-proof-hold'],cookies:[]};wire.push(row);
 const forward=()=>{
  row.dispatch=performance.now();
  const outgoing=http.request({hostname:'localhost',port:turn++%2?8137:8136,path:req.url,method:req.method,headers:req.headers},source=>{
   row.headers=performance.now();
   const chunks=[];source.on('data',chunk=>chunks.push(chunk));source.on('end',()=>{
    const bytes=Buffer.concat(chunks);row.status=source.statusCode;row.location=source.headers.location;row.bytes=bytes.length;row.finished=performance.now();row.serverDate=source.headers.date;row.cacheControl=source.headers['cache-control'];row.cookies=(source.headers['set-cookie']??[]).map(cookie=>({name:cookie.split('=')[0],hash:hash(cookie),expires:cookie.match(/;\s*expires=([^;]+)/i)?.[1],maxAge:cookie.match(/;\s*max-age=([^;]+)/i)?.[1]}));
    row.bodyHash=hash(bytes);
    const deliver=()=>{if(!res.destroyed){row.delivered=performance.now();row.sentHash=hash(bytes);row.sentBytes=bytes.length;res.writeHead(source.statusCode,source.headers);res.end(bytes,()=>{row.flushed=performance.now();});}};
    if(row.hold)held.set(row.hold,{deliver,discard:()=>{row.discarded=performance.now();res.destroy();},row,bytes,headers:source.headers});else deliver();
   });
  });
  outgoing.on('socket',socket=>{row.socket=performance.now();socket.once('connect',()=>row.connected=performance.now());});
  outgoing.on('error',error=>{row.error=error.message;if(!res.destroyed){res.writeHead(502);res.end();}});
  req.pipe(outgoing);
 };
 if(req.headers['x-proof-before'])before.set(req.headers['x-proof-before'],forward);else forward();
});
await new Promise(resolve=>proxy.listen(8138,'localhost',resolve));
const onlyRestoration=process.env.GOALS_SPA_ONLY==='restoration';
const onlyClosure=process.env.GOALS_SPA_ONLY==='closure';
const onlyRetention=process.env.GOALS_SPA_ONLY==='retention';
const onlyWorkspaces=process.env.GOALS_SPA_ONLY==='workspaces';
const onlyContext=process.env.GOALS_SPA_ONLY==='context';
const onlyPurpose=process.env.GOALS_SPA_ONLY==='purpose';
const onlySources=process.env.GOALS_SPA_ONLY==='sources';
const onlyAccount=process.env.GOALS_SPA_ONLY==='account';
const onlyAuth=process.env.GOALS_SPA_ONLY==='auth';
const result={head:spawnSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).stdout.trim(),selection:process.env.GOALS_SPA_ONLY??'all',kind:'Real Laravel + dedicated Redis + PostgreSQL runtime/RLS + Chromium; synthetic users only',cases:[],wire,errors:[],assets:[],findings:[],unverified:[]};
let browser;
const until=async(fn,message='checkpoint')=>{const end=Date.now()+15000;while(!fn()){if(Date.now()>end)throw Error(message);await new Promise(resolve=>setTimeout(resolve,15));}};
function command(mode,who='a'){
 const child=spawnSync('C:/tools/php85/php.exe',['tests/Support/GoalsSpaFixture.php',mode,who],{encoding:'utf8',env:{...process.env,APP_ENV:'testing'}});
 assert.equal(child.status,0,child.stderr||child.stdout);
 try{return JSON.parse(child.stdout.trim());}catch{throw Error('Fixture '+mode+' did not return JSON: '+child.stdout+' '+child.stderr);}
}
const workspace=fixture.a.workspaces[0].public_id,other=fixture.a.workspaces[1].public_id,foreign=fixture.b.workspaces[0].public_id;
const target=(mode,id=workspace,review=false)=>`/workspaces/${id}/commercial-history`;
const meta=page=>page.evaluate(()=>{const raw=document.getElementById('application-bootstrap')?.textContent;return raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);});
async function post(page,url,body,extra={}){
 return page.evaluate(async({url,body,extra})=>{
  const raw=document.getElementById('application-bootstrap')?.textContent;
  const identity=raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);
  const response=await fetch(url,{method:'POST',credentials:'same-origin',redirect:'manual',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':identity.csrf,'X-Builderad-Document':identity.receipt,'X-Builderad-Transition':identity.transition,...extra},body:body?JSON.stringify(body):undefined});
  return {status:response.status,body:await response.json()};
 },{url,body,extra});
}
async function get(page,url,extra={}){
 return page.evaluate(async({url,extra})=>{
  const raw=document.getElementById('application-bootstrap')?.textContent;
  const identity=raw?JSON.parse(raw).identity:JSON.parse(document.querySelector('meta[name="identity-context"]').content);
  const response=await fetch(url,{headers:{Accept:'application/json','X-Builderad-Document':identity.receipt,...extra},cache:'no-store'});
  const text=await response.text();let body;try{body=JSON.parse(text);}catch{body=text;}
  return {status:response.status,body};
 },{url,extra});
}
const ready=(page,mode)=>page.waitForFunction(()=>document.querySelector('[data-application-spa]')?.dataset.state==='ready'&&!!document.querySelector('[data-commercial-history]'));
async function login(page,mode,who='a'){
 await page.goto('http://localhost:8138/login');
 const response=await post(page,'/login',{email:`goals-${who}@example.test`,password:'DisposableGoalsProof42!'});assert.equal(response.status,200,JSON.stringify(response));
 await page.goto('http://localhost:8138'+target(mode,fixture[who].workspaces[0].public_id));await ready(page,mode);
}
async function visit(page,mode,url){
 await page.evaluate(url=>__goalsSpa.router.push(url),url);
 await page.waitForURL('http://localhost:8138'+url);await ready(page,mode);
}
async function check(name,mode,fn){
 const row={name,mode,start:Date.now()};result.cases.push(row);
 try{row.details=await fn();row.result=row.details?.result??'PASS';console.log(row.result+' '+mode+' '+name);}
 catch(error){row.result='FAIL';row.error=error.stack;console.log('FAIL '+mode+' '+name);throw error;}
 finally{await writeFile(path.join(run,'browser-results.json'),JSON.stringify(result,null,2));}
}
try{
 browser=await chromium.launch({headless:true,ignoreDefaultArgs:['--disable-back-forward-cache'],executablePath:'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe'});
 result.browser=browser.version();
 const newContext=browser.newContext.bind(browser);
 browser.newContext=async options=>{
  const context=await newContext(options);
  await context.addInitScript(()=>{
   const original=window.fetch;
   window.fetch=async(input,init)=>{
    const request=new Request(input,init),correlation=crypto.randomUUID(),started=performance.now(),createdUnix=Date.now();
    request.headers.set('X-Proof-Correlation',correlation);
    try {const response=await original(request);console.debug('PROOF_HTTP '+JSON.stringify({correlation,createdUnix,path:new URL(request.url).pathname,phase:'headers',ms:performance.now()-started,status:response.status}));return response;}
    catch(error){console.debug('PROOF_HTTP '+JSON.stringify({correlation,createdUnix,path:new URL(request.url).pathname,phase:'rejected',ms:performance.now()-started,error:error.name}));throw error;}
   };
  });
  context.on('page',page=>page.on('console',message=>{if(message.text().startsWith('PROOF_HTTP '))(result.clientTiming??=[]).push(JSON.parse(message.text().slice(11)));}));
  return context;
 };
 const harness={browser,check,login,ready,visit,target,until,result,workspace,other,foreign,command,run,meta,get,post,fixture,held,before,wire,hash};
 if(onlyWorkspaces){const {runWorkspaceCases}=await import('./workspace-spa-browser.mjs');await runWorkspaceCases(harness);}
 else if(onlySources){const {runSourceCases}=await import('./sources-spa-browser.mjs');await runSourceCases(harness);}
 else if(onlyAuth){const {runAuthEntryCases}=await import(process.env.RUNTIME_RETIREMENT_SLICE7==='enabled'?'./runtime-retirement-browser.mjs':process.env.INTERNAL_OPERATIONS_SLICE5==='enabled'?'./internal-operations-browser.mjs':process.env.INTERNAL_IDENTITY_SLICE4==='enabled'?'./internal-identity-browser.mjs':'./auth-entry-browser.mjs');await runAuthEntryCases(harness);}
 else if(onlyAccount){const {runAccountCases}=await import('./account-spa-browser.mjs');await runAccountCases(harness);}
 else if(onlyClosure||onlyRetention){const {runClosureCases}=await import('./goals-spa-closure.mjs');await runClosureCases(harness);}
 else if(onlyRestoration){const {runRestorationAcceptance}=await import('./goals-spa-restoration.mjs');await runRestorationAcceptance(harness);}
 else {
  const {runWorkspaceRetirementCases}=await import('./workspace-retirement-browser.mjs');await runWorkspaceRetirementCases(harness);
  // Context/Purpose selections are explicit refusal selections; continuing
  // Business editing is exercised by the businesses browser selection.
  if(!onlyContext&&!onlyPurpose){const {runFocusedAcceptance}=await import('./focused-spa-acceptance.mjs');await runFocusedAcceptance(harness);}
 }
 assert.deepEqual(result.errors,[],'Ordinary application journeys must not emit unhandled page errors');
 result.database=command('inspect');
 const servedAssets=wire.filter(row=>row.path.startsWith('/build/')&&row.status===200&&row.sentHash);
 for(const asset of servedAssets){const local=path.join(process.cwd(),'public',new URL(asset.path,'http://localhost:8138').pathname.slice(1));const bytes=await readFile(local);assert.equal(hash(bytes),asset.sentHash,'Forwarded HTTP asset differs from this candidate: '+asset.path);assert.equal(bytes.length,asset.sentBytes);}
 for(const asset of result.assets)assert.ok(servedAssets.some(row=>row.path===new URL(asset.url).pathname),'Browser asset has no verified network origin: '+asset.url);
 result.assetVerification={boundary:'Exact real PHP response bytes passed unchanged to the browser-facing HTTP response; browser URL/status observed separately. Aborted navigations may evict CDP bodies and are not hashed as empty files.',forwardedResponses:servedAssets.length,browserResponses:result.assets.length};
 result.servedAssetsVerified=true;
 result.unverified.push('Real mobile background and pre-JavaScript cached frames','Production performance distributions; Foundation acceptance is recorded separately');
 result.acceptance=result.findings.length||result.unverified.some(value=>typeof value==='object'&&value.gate?.startsWith('native BFCache'))?'BLOCKED':'REQUIRED_GATES_REVIEW';
 if(result.acceptance==='BLOCKED')process.exitCode=2;
}catch(error){result.failure=error.stack;process.exitCode=1;}
finally{
 await browser?.close();for(const value of held.values())value.deliver();held.clear();
 proxy.closeAllConnections();await new Promise(resolve=>proxy.close(resolve));
 result.cleanup='browser and proxy closed';await writeFile(path.join(run,'browser-results.json'),JSON.stringify(result,null,2));
 console.log(JSON.stringify({cases:result.cases.map(({name,mode,result})=>({name,mode,result})),findings:result.findings,acceptance:result.acceptance,unverified:result.unverified,failure:result.failure,evidence:run},null,2));
}
