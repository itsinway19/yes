import { createRequire } from 'node:module';
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

const require = createRequire(process.env.N1_PLAYWRIGHT_PACKAGE);
const { chromium, firefox } = require('playwright');
const output = process.env.N1_EVIDENCE_DIRECTORY;
const candidateDirectory=process.env.N1_BROWSER_APP_DIRECTORY??path.join(output,'app');
const origin = 'http://localhost:8147';
const rows = [], cases = [], assets = [], errors = [];
let contextHold = false, heldContext, browser, deferredHold=false, heldDeferred, prefetchHold=false, heldPrefetch;
let repeatedFaultStart;
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const proxy = http.createServer((req, res) => {
    const row = { path:req.url,method:req.method,tab:req.headers['x-n1-tab'],purpose:req.headers.purpose,partial:req.headers['x-inertia-partial-data'],probe:req.headers['x-builderad-visibility']==='1',
        document:req.headers['sec-fetch-dest']==='document',entry:Date.now() };
    rows.push(row);
    const upstream = http.request({ hostname:'localhost',port:8145,path:req.url,method:req.method,headers:req.headers }, response => {
        row.status = response.statusCode; row.completed = Date.now();
        row.cookies = (response.headers['set-cookie'] ?? []).map(c=>c.split('=')[0]);
        const chunks=[];response.on('data',c=>chunks.push(c));response.on('end',()=>{
            const body=Buffer.concat(chunks);row.bodyAt=Date.now();
            if(response.statusCode===500){const source=body.toString();row.serverError=source.match(/<title>([^<]+)/)?.[1]??source.slice(0,250);}
            if (req.url.startsWith('/build/')) assets.push({path:req.url,sha256:hash(body)});
            const deliver=()=>{if(!res.destroyed){row.delivered=Date.now();res.writeHead(response.statusCode,response.headers);res.end(body);}};
            if(repeatedFaultStart&&row.document&&row.path==='/__n1/render-fault'&&rows.filter(r=>r.document&&r.path===row.path&&r.entry>repeatedFaultStart).length>2)return;
            if(contextHold && row.tab==='A' && req.url==='/identity/context') heldContext={deliver,row};
            else if(deferredHold&&req.url==='/__n1/partials'&&row.partial==='deferredA') heldDeferred={deliver,row};
            else if(prefetchHold&&req.url==='/profile'&&row.purpose==='prefetch') heldPrefetch={deliver,row};
            else deliver();
        });
    });
    upstream.on('error',e=>{row.error=e.code;if(!res.destroyed){res.writeHead(502);res.end('relay');}});req.pipe(upstream);
});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
const until=async predicate=>{const end=Date.now()+20000;while(Date.now()<end){if(await predicate())return;await delay(20);}throw Error('Required browser interleaving not observed');};
const ready=page=>page.waitForFunction(()=>document.documentElement.dataset.visibility==='ready',null,{timeout:20000});
const router=page=>page.evaluate(()=>!!document.getElementById('app')?.__vue_app__?.config.globalProperties.$inertia);
const metadata=page=>page.evaluate(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.props.identity);
const summary=async page=>page.evaluate(async()=>{
    const app=document.getElementById('app').__vue_app__,vue=app.config.globalProperties.$page;
    const k=JSON.parse(sessionStorage.getItem('historyKey')),iv=JSON.parse(sessionStorage.getItem('historyIv'));
    let native=history.state?.page;
    if(native instanceof ArrayBuffer){const key=await crypto.subtle.importKey('raw',new Uint8Array(k),'AES-GCM',true,['decrypt']);
        native=JSON.parse(new TextDecoder().decode(await window.__n1.originalDecrypt({name:'AES-GCM',iv:new Uint8Array(iv)},key,native)));}
    const found=[];const walk=v=>{if(!v||typeof v!=='object')return;if(v.component){const i=v.component;found.push({name:i.type.__name??i.type.name,uid:i.uid,mounted:i.isMounted,receipt:(i.props.identity??i.attrs.identity)?.receipt});walk(i.subTree);}if(Array.isArray(v.children))v.children.forEach(walk);};
    const root=document.getElementById('app')._vnode;if(!root)throw Error('Mounted VNode checkpoint unavailable');walk(root);
    return {url:location.pathname+location.search,vue:vue.component,native:native?.component,nativeUrl:native?.url,
        principal:vue.props.identity.binding.principal,epoch:vue.props.identity.binding.epoch,
        receiptMatches:native?.props.identity?.receipt===vue.props.identity.receipt,
        rememberedKeys:Object.keys(native?.rememberedState??{}),
        testDraft:native?.rememberedState?.['n1-ir-draft'],scroll:history.state?.documentScrollPosition,
        mounted:found.filter(i=>i.name===vue.component.split('/').at(-1)).map(({receipt,...i})=>({...i,receiptMatches:receipt===vue.props.identity.receipt})),
        selected:Array.from(document.querySelectorAll('[data-goals]:checked')).map(x=>x.dataset.goals),
        visibility:document.documentElement.dataset.visibility,events:window.__n1.events};
});
const instrumentation=initialRestoration=>{
    const probe=window.__n1={events:[],gate:null,fail:null,cancelAfterNative:false,originalDecrypt:crypto.subtle.decrypt.bind(crypto.subtle)};
    const encrypt=crypto.subtle.encrypt.bind(crypto.subtle),decrypt=crypto.subtle.decrypt.bind(crypto.subtle);
    crypto.subtle.encrypt=async(...args)=>{const data=JSON.parse(new TextDecoder().decode(args[2]));
        const encryptGate=probe.encryptGate??probe.gate;
        if(encryptGate?.kind==='encrypt'&&data.url.endsWith(encryptGate.path)){
            const gate=encryptGate;gate.reached=true;probe.events.push({event:'crypto-held',url:data.url,time:performance.now()});
            await new Promise((resolve,reject)=>{gate.release=resolve;gate.reject=reject;});
        }const result=await encrypt(...args);probe.events.push({event:'encrypted',url:data.url,time:performance.now()});return result;};
    const timer=window.setTimeout.bind(window);
    window.setTimeout=(fn,ms,...args)=>{const stack=new Error().stack;if(probe.holdTimer&&ms===undefined&&probe.events.some(e=>e.event==='encrypted'&&e.url==='/profile')&&/\/dist-[^/]+\.js/.test(stack)){probe.holdTimer=false;probe.timer={release:fn,stack};probe.events.push({event:'timer-held',time:performance.now()});return 0;}return timer(fn,ms,...args);};
    crypto.subtle.decrypt=async(...args)=>{
        if(initialRestoration&&performance.getEntriesByType('navigation')[0]?.type==='back_forward'){
            probe.backDecryptCount=(probe.backDecryptCount??0)+1;
            if(probe.backDecryptCount===2){probe.autoCaptured=true;probe.navigationType='back_forward';probe.gate={kind:'decrypt',bytes:btoa(String.fromCharCode(...new Uint8Array(args[2])))};}
        }
        const decryptGate=probe.decryptGate??probe.gate;
        if(decryptGate?.kind==='decrypt'&&btoa(String.fromCharCode(...new Uint8Array(args[2])))===decryptGate.bytes){
            const gate=decryptGate;gate.reached=true;probe.events.push({event:'decrypt-held',time:performance.now()});await new Promise(r=>gate.release=r);
        }const result=await decrypt(...args);probe.events.push({event:'decrypted',time:performance.now()});return result;};
    for(const type of ['pushState','replaceState']){
        const native=history[type].bind(history);
        history[type]=(...args)=>{
            if(probe.fail===type&&(!probe.failPath||args[2]===probe.failPath)){probe.fail=null;probe.events.push({event:'native-failed',type,time:performance.now()});throw new DOMException('Injected native History failure',probe.failName??'SecurityError');}
            const result=native(...args);probe.events.push({event:'native',type,url:args[2],time:performance.now()});
            if(probe.cancelAfterNative&&type==='pushState'){probe.cancelAfterNative=false;queueMicrotask(()=>{
                const outcome=probe.token.cancel();probe.events.push({event:'post-native-cancel',outcome,time:performance.now()});});}
            return result;
        };
    }
    probe.navigationType=performance.getEntriesByType('navigation')[0]?.type;
    window.addEventListener('pageshow',event=>probe.events.push({event:'pageshow',persisted:event.persisted,time:performance.now()}));
    window.addEventListener('popstate',()=>probe.events.push({event:'native-popstate',url:location.pathname,time:performance.now()}));
    window.addEventListener('scroll',()=>probe.events.push({event:'scroll-event',top:window.scrollY,time:performance.now()}));
};
const visit=(page,url,options={})=>page.evaluate(({url,options})=>{
    const p=window.__n1,r=document.getElementById('app').__vue_app__.config.globalProperties.$inertia;
    const sequence=p.sequence=(p.sequence??0)+1;
    p.done=false;p.success=false;p.cancelled=false;
    r.visit(url,{...options,onCancelToken:t=>p.token=t,onBeforeUpdate:()=>p.events.push({event:'candidate-admitted',time:performance.now()}),
        onCancel:()=>{if(sequence===p.sequence)p.cancelled=true;p.events.push({event:'cancel-ack',sequence,time:performance.now()});},
        onSuccess:()=>{if(sequence===p.sequence)p.success=true;p.events.push({event:'success',sequence,time:performance.now()});},
        onFinish:v=>{if(sequence===p.sequence)p.done=true;p.events.push({event:'finish',sequence,completed:v.completed,time:performance.now()});}});
},{url,options});
const completed=page=>page.waitForFunction(()=>window.__n1.done);
const coherent=s=>{assert.equal(s.receiptMatches,true);assert.equal(s.vue,s.native);assert.equal(s.url,s.nativeUrl);assert.ok(s.mounted.some(m=>m.mounted&&m.receiptMatches));};
const settled=page=>until(async()=>{try{const s=await summary(page);return s.receiptMatches&&s.vue===s.native&&s.url===s.nativeUrl&&s.mounted.some(m=>m.mounted&&m.receiptMatches);}catch{return false;}});
const hold=async(page,path)=>{await page.evaluate(path=>window.__n1.gate={kind:'encrypt',path},path);await visit(page,path);await page.waitForFunction(()=>window.__n1.gate?.reached);};
const release=page=>page.evaluate(()=>{const gate=window.__n1.gate;window.__n1.gate=null;gate.release();});
const login=async(page,entry='/login',email='p2b-browser-a@example.test')=>{
    await page.goto(origin+entry);await ready(page);await page.locator('#email').fill(email);await page.locator('#password').fill('password');
    const loaded=page.waitForEvent('domcontentloaded',{timeout:12000});const transition=page.waitForResponse(r=>r.request().method()==='POST'&&['/login','/identity/switch'].includes(new URL(r.url()).pathname));
    await page.locator('form button').click();assert.equal((await transition).status(),200);
    try{await loaded;}catch(error){throw new Error(`Transition document did not load: ${await page.locator('#email-error').textContent()}`,{cause:error});}await ready(page);
};

await new Promise(r=>proxy.listen(8147,'localhost',r));
const evidence={scope:process.env.N1_PRODUCTION==='1'?'Exact production source/build through public/index.php in isolated Laravel, PostgreSQL and Redis':'Actual candidate core/app in isolated Laravel, PostgreSQL, Redis and built browser; test-only render fixture',cases,rows,assets,errors};
try{
    const engine=process.env.N1_BROWSER==='firefox'?firefox:chromium;
    const headed=process.env.N1_HEADFUL==='1';
    browser=await engine.launch({headless:!headed,executablePath:process.env.N1_BROWSER_EXECUTABLE,
        args:headed&&engine===chromium?['--window-position=-10000,-10000','--window-size=1280,900']:[]});evidence.browser=browser.version();evidence.headed=headed;
    const fixtures=JSON.parse(await fs.readFile(path.join(output,'goals-browser-fixtures.json'),'utf8'));
    const names=(process.env.N1_CASES??'Smoke').split(',');
    for(const name of names){
        const c={name,started:Date.now()};cases.push(c);const context=await browser.newContext(name==='N04'?{userAgent:'N1 CriOS deterministic timer branch fixture'}:{});await context.addInitScript(instrumentation,name==='N08Initial');
        await context.route('**/*',r=>new URL(r.request().url()).hostname==='localhost'?r.continue():r.abort());
        const page=await context.newPage();await page.setExtraHTTPHeaders({'X-N1-Tab':'A'});page.on('dialog',d=>d.accept());page.on('pageerror',e=>errors.push({case:name,error:String(e)}));
        await login(page);await page.goto(`${origin}/workspaces/${fixtures.a.workspace}/goals`);await ready(page);
        assert.equal(await router(page),true);await page.waitForFunction(()=>!!history.state?.page);c.initial=await summary(page);
        assert.equal(c.initial.receiptMatches,true);assert.equal(c.initial.vue,'Business/Goals');
        await page.locator('[data-goals="select-sales"]').check();await page.locator('[data-goals="result-actual_sales"]').check();
        if(name==='IR01'){
            await settled(page);await delay(150);
            const marker=await page.evaluate(()=>{const p=window.__n1;p.encryptGate={kind:'encrypt',path:location.pathname};
                const marker=p.events.length;document.getElementById('app').__vue_app__.config.globalProperties.$inertia.remember({text:'legitimate-sales-draft'},'n1-ir-draft');return marker;});
            await page.waitForFunction(()=>window.__n1.encryptGate.reached);
            await page.evaluate(()=>{document.body.style.minHeight='3000px';window.scrollTo(0,125);});
            await page.waitForFunction(n=>window.__n1.events.slice(n).some(e=>e.event==='scroll-event'&&e.top===125),marker);await delay(150);
            c.held=await summary(page);assert.equal(c.held.events.slice(marker).filter(e=>e.event==='native').length,0);
            await page.evaluate(()=>{const p=window.__n1,g=p.encryptGate;p.encryptGate=null;g.release();});
            await page.waitForFunction(n=>window.__n1.events.slice(n).filter(e=>e.event==='native').length>=2,marker);
            c.current=await summary(page);coherent(c.current);assert.deepEqual(c.current.testDraft,{text:'legitimate-sales-draft'});assert.equal(c.current.scroll.top,125);
            c.order='Actual accepted Goals remember WebCrypto held; real window scroll observed; release; remember then scroll native writes; newest encrypted draft retained';
            await visit(page,'/profile');await completed(page);coherent(await summary(page));
        }else if(name==='IR02'||name==='IR02SameURL'){
            await visit(page,'/profile');await completed(page);await settled(page);
            let bytes=await page.evaluate(()=>btoa(String.fromCharCode(...new Uint8Array(history.state.page))));
            await visit(page,c.initial.url);await completed(page);await settled(page);
            await page.locator('[data-goals="select-sales"]').check();await page.locator('[data-goals="result-actual_sales"]').check();await delay(150);
            c.outgoing=await summary(page);assert.ok(c.outgoing.selected.includes('result-actual_sales'));assert.ok(c.outgoing.rememberedKeys.some(k=>k.startsWith('advertiser-goals:')));
            const targetUrl=name==='IR02SameURL'?c.initial.url:'/profile',targetComponent=name==='IR02SameURL'?'Business/Goals':'Profile/Edit';
            if(name==='IR02SameURL'){
                bytes=await page.evaluate(()=>{const bytes=btoa(String.fromCharCode(...new Uint8Array(history.state.page)));history.pushState(history.state,'',location.href);return bytes;});
                c.entryFixture='Test-only native push creates a distinct same-URL entry with the accepted encrypted Goals state; no product History interception or repair';
            }
            const marker=await page.evaluate(bytes=>{const p=window.__n1;p.encryptGate={kind:'encrypt',path:location.pathname};p.decryptGate={kind:'decrypt',bytes};
                const marker=p.events.length;document.getElementById('app').__vue_app__.config.globalProperties.$inertia.remember({text:'outgoing-draft'},'n1-ir-draft');return marker;},bytes);
            await page.waitForFunction(()=>window.__n1.encryptGate.reached);
            await page.goBack();await page.waitForFunction(()=>window.__n1.decryptGate.reached);
            c.traversal=await page.evaluate(()=>({url:location.pathname,events:window.__n1.events}));assert.equal(c.traversal.url,targetUrl);
            assert.ok(c.traversal.events.slice(marker).some(e=>e.event==='native-popstate'&&e.url===targetUrl));
            await page.evaluate(()=>{const p=window.__n1,g=p.encryptGate;p.encryptGate=null;g.release();});
            await page.waitForFunction(n=>window.__n1.events.slice(n).some(e=>e.event==='encrypted'&&e.url.endsWith('/goals')),marker);
            await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));
            c.beforeRestore=await summary(page);assert.equal(c.beforeRestore.url,targetUrl);assert.equal(c.beforeRestore.native,targetComponent);
            assert.equal(c.beforeRestore.events.slice(marker).filter(e=>e.event==='native').length,0);
            await page.evaluate(()=>{const p=window.__n1,g=p.decryptGate;p.decryptGate=null;g.release();});await ready(page);await settled(page);
            c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,targetUrl);assert.equal(c.current.testDraft,undefined);
            await page.goForward();await ready(page);await settled(page);c.forward=await summary(page);coherent(c.forward);assert.equal(c.forward.url,c.initial.url);
            assert.ok(c.forward.selected.includes('result-actual_sales'));assert.equal(c.forward.testDraft,undefined);
            c.order=`Real native Back to ${targetComponent}; target decrypt held; outgoing Goals remember completes first without native write; owned restore then real Forward preserves original Goals draft`;
        }else if(name==='Smoke'||name==='N11'){
            await visit(page,'/profile');await page.waitForURL(u=>u.pathname==='/profile');await ready(page);c.current=await summary(page);
            assert.equal(c.current.vue,'Profile/Edit');assert.equal(c.current.receiptMatches,true);assert.ok(c.current.mounted.some(m=>m.mounted&&m.receiptMatches));
            await completed(page);coherent(await summary(page));
            if(name==='N11'){const before=rows.length;await page.evaluate(()=>window.dispatchEvent(new Event('focus')));await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))));c.focus=await summary(page);coherent(c.focus);assert.equal(c.focus.visibility,'ready');assert.equal(rows.slice(before).filter(r=>r.path==='/identity/context'||r.document).length,0);c.focusEvidence='Browser focus event and two frames; genuine hidden/visible tab transition is separately unverified';}
        }else if(name==='N11Tab'){
            await page.evaluate(()=>{window.__n1.visibility=[];document.addEventListener('visibilitychange',()=>window.__n1.visibility.push(document.visibilityState));});
            const before=rows.length,other=await context.newPage();await other.goto('about:blank');await other.bringToFront();
            await page.waitForFunction(()=>document.visibilityState==='hidden',null,{timeout:3000}).catch(()=>{});
            c.hidden=await page.evaluate(()=>({state:document.visibilityState,events:window.__n1.visibility}));await page.bringToFront();await page.waitForFunction(()=>document.visibilityState==='visible',null,{timeout:3000}).catch(()=>{});
            c.returned=await page.evaluate(()=>({state:document.visibilityState,events:window.__n1.visibility}));await other.close();c.current=await summary(page);coherent(c.current);assert.equal(c.current.visibility,'ready');assert.equal(rows.slice(before).filter(r=>r.path==='/identity/context'||r.document).length,0);
            c.observedNativeTransition=c.hidden.state==='hidden'&&c.returned.state==='visible'&&c.returned.events.includes('hidden')&&c.returned.events.includes('visible');
            if(!c.observedNativeTransition)c.verification='UNVERIFIED: browser did not produce native hidden/visible events';
        }else if(name==='N07Initial'){
            repeatedFaultStart=Date.now();await page.goto(origin+'/__n1/render-fault',{waitUntil:'domcontentloaded'});
            await until(async()=>{const count=rows.filter(r=>r.document&&r.path==='/__n1/render-fault'&&r.entry>repeatedFaultStart).length;
                if(count>2)throw Error('Repeated initial render fault triggered more than one automatic fresh document recovery');
                try{return await page.evaluate(()=>document.documentElement.dataset.visibility==='unavailable'&&document.getElementById('app')?.hasAttribute('inert'));}catch{return false;}});
            c.documents=rows.filter(r=>r.document&&r.path==='/__n1/render-fault'&&r.entry>repeatedFaultStart).map(r=>({status:r.status,entry:r.entry}));assert.ok(c.documents.length<=2);repeatedFaultStart=undefined;
            c.recovery='Repeated initial render failure remains unavailable/inert with explicit user action; no automatic reload loop';
        }else if(name==='N12Success'||name==='N12Compensation'){
            await visit(page,name==='N12Success'?'/profile':'/__n1/render-fault');await completed(page);c.beforeReload=await summary(page);coherent(c.beforeReload);
            await page.reload();await ready(page);await settled(page);c.current=await summary(page);coherent(c.current);
            assert.equal(c.current.url,c.beforeReload.url);assert.equal(c.current.vue,c.beforeReload.vue);
        }else if(name==='N07Recovery'){
            await page.evaluate(()=>{const walk=v=>{if(v?.component){const i=v.component;if(i.type.__name==='Goals'){i.type.setup=()=>{throw Error('Injected compensation mount failure');};return true;}if(walk(i.subTree))return true;}return Array.isArray(v?.children)&&v.children.some(walk);};if(!walk(document.getElementById('app')._vnode))throw Error('Goals instance not found');});
            const loaded=page.waitForEvent('domcontentloaded');await visit(page,'/__n1/render-fault');await loaded;await ready(page);await settled(page);c.current=await summary(page);coherent(c.current);
            assert.equal(rows.filter(r=>r.document&&r.tab==='A'&&r.path===c.initial.url&&r.entry>c.started).length,2);c.recovery='One initial Goals GET plus exactly one fresh recovery GET; no replay';
        }else if(name==='NDeferred'){
            deferredHold=true;await visit(page,'/__n1/partials');await completed(page);await until(()=>!!heldDeferred);
            await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.props.deferredB==='B');c.beforeA=await summary(page);coherent(c.beforeA);
            c.held={partial:heldDeferred.row.partial,status:heldDeferred.row.status,bodyAt:heldDeferred.row.bodyAt};deferredHold=false;heldDeferred.deliver();heldDeferred=undefined;
            await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.props.deferredA==='A');
            c.props=await page.evaluate(()=>{const p=document.getElementById('app').__vue_app__.config.globalProperties.$page.props;return {a:p.deferredA,b:p.deferredB};});assert.deepEqual(c.props,{a:'A',b:'B'});await settled(page);c.current=await summary(page);coherent(c.current);
        }else if(name==='NPrefetchPending'){
            prefetchHold=true;await page.evaluate(()=>document.getElementById('app').__vue_app__.config.globalProperties.$inertia.prefetch('/profile',{}, {cacheFor:60000}));await until(()=>!!heldPrefetch);
            await visit(page,'/profile');await page.waitForFunction(()=>!!window.__n1.token);const newer=`/workspaces/${fixtures.a.workspace}/business`;await visit(page,newer);await completed(page);
            c.beforeRelease=await summary(page);coherent(c.beforeRelease);assert.equal(c.beforeRelease.url,newer);assert.ok(c.beforeRelease.events.some(e=>e.event==='cancel-ack'&&e.sequence===1));
            c.held={bodyAt:heldPrefetch.row.bodyAt,purpose:heldPrefetch.row.purpose};prefetchHold=false;heldPrefetch.deliver();heldPrefetch=undefined;
            c.current=await summary(page);coherent(c.current);assert.equal(c.current.events.some(e=>e.event==='native'&&e.type==='pushState'&&e.url==='/profile'),false);
        }else if(name==='NPrefetch'){
            await page.evaluate(()=>document.getElementById('app').__vue_app__.config.globalProperties.$inertia.prefetch('/profile',{}, {cacheFor:60000}));
            await page.waitForFunction(()=>!!document.getElementById('app').__vue_app__.config.globalProperties.$inertia.getCached('/profile'));
            await hold(page,'/profile');const newer=`/workspaces/${fixtures.a.workspace}/business`;await visit(page,newer);await completed(page);await release(page);
            c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,newer);assert.equal(c.current.events.some(e=>e.event==='native'&&e.type==='pushState'&&e.url==='/profile'),false);
        }else if(name==='N04'){
            await hold(page,'/profile');await page.evaluate(()=>window.__n1.holdTimer=true);await release(page);await page.waitForFunction(()=>!!window.__n1.timer);
            c.held=await summary(page);coherent(c.held);assert.equal(c.held.url,c.initial.url);c.stack=await page.evaluate(()=>window.__n1.timer.stack);
            assert.equal(await page.evaluate(()=>window.__n1.token.cancel()),'cancelled');await page.evaluate(()=>window.__n1.timer.release());await completed(page);
            c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,c.initial.url);assert.equal(await page.evaluate(()=>window.__n1.success),false);
            c.platform='Desktop engine executing exact CriOS source branch; not Safari/iOS evidence';
        }else if(['N01','N05','N12'].includes(name)){
            c.keys=await page.evaluate(()=>({key:sessionStorage.getItem('historyKey'),iv:sessionStorage.getItem('historyIv')}));
            await hold(page,'/profile');c.held=await summary(page);coherent(c.held);assert.equal(c.held.url,c.initial.url);
            if(name==='N05') await page.evaluate(()=>{const g=window.__n1.gate;window.__n1.gate=null;g.reject(new Error('Injected encrypt rejection'));});
            else {c.cancel=await page.evaluate(()=>window.__n1.token.cancel());assert.equal(c.cancel,'cancelled');await release(page);}
            await completed(page);c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,c.initial.url);
            assert.equal(await page.evaluate(()=>window.__n1.success),false);
            assert.deepEqual(await page.evaluate(()=>({key:sessionStorage.getItem('historyKey'),iv:sessionStorage.getItem('historyIv')})),c.keys);
            assert.ok(c.current.selected.includes('select-sales'));
            if(name==='N12'){await page.reload();await ready(page);await settled(page);c.reloaded=await summary(page);coherent(c.reloaded);assert.equal(c.reloaded.vue,'Business/Goals');}
            else {await visit(page,'/profile');await completed(page);coherent(await summary(page));}
        }else if(name==='N02'){
            await page.evaluate(()=>window.__n1.cancelAfterNative=true);await visit(page,'/profile');await completed(page);c.current=await summary(page);coherent(c.current);
            assert.equal(c.current.events.find(e=>e.event==='post-native-cancel')?.outcome,'already-committed');
            assert.equal(await page.evaluate(()=>window.__n1.cancelled),false);assert.equal(await page.evaluate(()=>window.__n1.success),true);
        }else if(name==='N03'){
            await hold(page,'/profile');c.held=await summary(page);const newer=`/workspaces/${fixtures.a.workspace}/business`;await visit(page,newer);await completed(page);c.newer=await summary(page);coherent(c.newer);
            await release(page);await page.waitForFunction(()=>window.__n1.events.filter(e=>e.event==='finish').length===2);c.current=await summary(page);coherent(c.current);
            assert.equal(c.current.url,newer);assert.equal(c.current.events.filter(e=>e.event==='native'&&e.type==='pushState'&&e.url==='/profile').length,0);
        }else if(name.startsWith('N06')){
            await page.evaluate(name=>{window.__n1.fail=name.includes('Replace')?'replaceState':'pushState';window.__n1.failPath='/profile';window.__n1.failName=name.includes('Quota')?'QuotaExceededError':'SecurityError';},name);await visit(page,'/profile',{replace:name.includes('Replace')});await completed(page);c.current=await summary(page);coherent(c.current);
            assert.equal(c.current.url,c.initial.url);assert.equal(await page.evaluate(()=>window.__n1.success),false);assert.ok(c.current.events.some(e=>e.event==='native-failed'));
            await visit(page,'/profile');await completed(page);coherent(await summary(page));
        }else if(name==='N07'){
            await visit(page,'/__n1/render-fault');await completed(page);c.current=await summary(page);coherent(c.current);
            assert.equal(c.current.url,c.initial.url);assert.equal(await page.evaluate(()=>window.__n1.success),false);assert.ok(c.current.selected.includes('select-sales'));
            assert.equal(c.current.events.filter(e=>e.event==='native'&&e.url==='/__n1/render-fault').length,1);
        }else if(name==='N08Initial'){
            c.stage='fresh-profile';await page.goto(origin+'/profile');await ready(page);c.profile=await summary(page);await settled(page);
            c.stage='native-back';await page.goBack({waitUntil:'domcontentloaded'});c.stage='back-document';
            c.restorationCheckpoint=await page.evaluate(()=>({actualType:performance.getEntriesByType('navigation')[0]?.type,probeType:window.__n1.navigationType,events:window.__n1.events,gate:window.__n1.gate?.kind,stateType:history.state?.page?.constructor?.name,keyPresent:!!sessionStorage.getItem('historyKey'),ivPresent:!!sessionStorage.getItem('historyIv'),visibility:document.documentElement.dataset.visibility}));
            await page.waitForFunction(()=>window.__n1.gate?.reached&&!!document.getElementById('app')?.__vue_app__?.config.globalProperties.$inertia);
            c.initialRestoration=await page.evaluate(()=>({type:window.__n1.navigationType,events:window.__n1.events}));assert.equal(c.initialRestoration.type,'back_forward');assert.ok(c.initialRestoration.events.some(e=>e.event==='pageshow'&&!e.persisted));
            await visit(page,'/profile');await completed(page);c.newer=await summary(page);coherent(c.newer);const marker=await page.evaluate(()=>window.__n1.events.filter(e=>e.event==='decrypted').length);await release(page);
            await page.waitForFunction(n=>window.__n1.events.filter(e=>e.event==='decrypted').length>n,marker);c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,'/profile');assert.equal(c.current.events.some(e=>e.event==='native'&&e.url?.includes('/goals')),false);
        }else if(name==='N08'){
            const bytes=await page.evaluate(()=>btoa(String.fromCharCode(...new Uint8Array(history.state.page))));
            await visit(page,'/profile');await completed(page);coherent(await summary(page));await page.evaluate(bytes=>window.__n1.gate={kind:'decrypt',bytes},bytes);
            await page.goBack();await page.waitForFunction(()=>window.__n1.gate?.reached);c.heldUrl=await page.url();await page.goForward();await ready(page);
            await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.component==='Profile/Edit');await release(page);
            c.current=await summary(page);coherent(c.current);assert.equal(c.current.url,'/profile');assert.ok(c.current.events.some(e=>e.event==='decrypt-held'));
        }else if(name==='N10'){
            await hold(page,'/profile');contextHold=true;const other=await context.newPage();await other.setExtraHTTPHeaders({'X-N1-Tab':'B'});
            await login(other,'/identity/switch','p2b-browser-b@example.test');const b=await metadata(other);c.b={principal:b.binding.principal,epoch:b.binding.epoch};
            await until(()=>!!heldContext);await page.waitForFunction(()=>document.documentElement.dataset.visibility==='checking');await release(page);
            c.blocked=await summary(page);assert.equal(c.blocked.url,c.initial.url);assert.equal(await page.evaluate(()=>window.__n1.success),false);
            assert.equal(c.blocked.events.filter(e=>e.event==='native'&&e.type==='pushState'&&e.url==='/profile').length,0);
            const old=await metadata(page);c.protectedReject=await other.evaluate(async headers=>{const r=await fetch('/locale',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json',...headers},body:JSON.stringify({locale:'en'})});const body=await r.json();return {status:r.status,identityConflict:body.identityConflict};},{'X-CSRF-TOKEN':b.csrf,'X-Builderad-Document':old.receipt});
            assert.equal(c.protectedReject.status,409);assert.equal(c.protectedReject.identityConflict,true);
            c.context={status:heldContext.row.status,requested:heldContext.row.entry,held:heldContext.row.bodyAt};contextHold=false;heldContext.deliver();heldContext=undefined;
            await page.waitForEvent('domcontentloaded');await until(()=>rows.some(r=>r.document&&r.path===new URL(c.initial.url,origin).pathname&&r.entry>c.context.held));
            assert.equal(rows.filter(r=>r.tab==='A'&&r.path==='/identity/context'&&r.entry>c.started).length,1);c.recovery='Fresh document requested; old private candidate denied without write replay';
            await other.goto(`${origin}/workspaces/${fixtures.b.workspace}/goals`);await ready(other);await other.waitForFunction(()=>!!history.state?.page);c.current=await summary(other);coherent(c.current);
            assert.equal(c.current.principal,c.b.principal);assert.equal(c.current.selected.length,0);
        }else if(name==='N09Pending'){
            await hold(page,'/profile');const marker=await page.evaluate(()=>window.__n1.events.length);
            await page.locator('[data-goals="result-actual_sales"]').uncheck();await page.locator('[data-goals="result-actual_sales"]').check();
            await page.waitForFunction(n=>window.__n1.events.slice(n).some(e=>e.event==='native'&&e.type==='replaceState'&&e.url?.endsWith('/goals')),marker);
            c.savedWhileHeld=await summary(page);assert.ok(c.savedWhileHeld.rememberedKeys.some(k=>k.startsWith('advertiser-goals:')));await release(page);await completed(page);
            c.destination=await summary(page);coherent(c.destination);assert.equal(c.destination.rememberedKeys.some(k=>k.startsWith('advertiser-goals:')),false);
            await page.goBack();await ready(page);await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.component==='Business/Goals');c.restored=await summary(page);coherent(c.restored);assert.ok(c.restored.selected.includes('result-actual_sales'));
        }else if(name==='N09'){
            await visit(page,'/profile');await completed(page);coherent(await summary(page));await page.goBack();await ready(page);
            await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.component==='Business/Goals');c.restored=await summary(page);coherent(c.restored);
            assert.ok(c.restored.selected.includes('select-sales'));assert.ok(c.restored.selected.includes('result-actual_sales'));
            await page.goForward();await ready(page);await page.waitForFunction(()=>document.getElementById('app').__vue_app__.config.globalProperties.$page.component==='Profile/Edit');coherent(await summary(page));
        }else throw Error('Case not implemented: '+name);
        c.result=c.verification?'UNVERIFIED':'PASS';await context.close();await fs.writeFile(path.join(output,`browser-${process.env.N1_BROWSER??'chromium'}-evidence.json`),JSON.stringify(evidence,null,2));
    }
    for(const asset of assets){const file=path.join(candidateDirectory,'public',asset.path.split('?')[0]);assert.equal(hash(await fs.readFile(file)),asset.sha256);}
    evidence.result='PASS selected browser cases; all served asset bytes matched';
}catch(error){evidence.result='FAIL';evidence.error=String(error);process.exitCode=1;
    for(const context of browser?.contexts()??[]){const page=context.pages()[0];if(page)try{evidence.failureBoundary=await page.evaluate(()=>({url:location.pathname,visibility:document.documentElement.dataset.visibility,inert:document.getElementById('app')?.hasAttribute('inert'),component:document.getElementById('app')?.__vue_app__?.config.globalProperties.$page?.component,events:window.__n1?.events}));}catch{}}
}
finally{await browser?.close();await new Promise(r=>proxy.close(r));await fs.writeFile(path.join(output,`browser-${process.env.N1_BROWSER??'chromium'}-evidence.json`),JSON.stringify(evidence,null,2));console.log(evidence.result);if(evidence.error)console.log(evidence.error);}
