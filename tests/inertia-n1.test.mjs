import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import path from 'node:path';
import { buildArtifact } from '../compat/inertia-n1/build.mjs';

const root = path.resolve(new URL('..', import.meta.url).pathname.replace(/^\/(\w:)/, '$1'));
const output = path.join(root, 'storage/framework/inertia-n1/contract-test');
const deferred = () => { let resolve, reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject}; };
const until = async predicate => { const deadline=performance.now()+5000;while(performance.now()<deadline){if(predicate())return;await new Promise(setImmediate);}throw Error('Required race checkpoint was not reached'); };
const realTimer = globalThis.setTimeout;
let state;
function browser() {
    const storage=new Map();state={storage,writes:[],mounts:[],failures:[],cancelled:0,context:'A'};
    globalThis.window={navigator:{userAgent:'N1 CriOS timer fixture'},location:new URL('http://localhost/a'),crypto:{subtle:webcrypto.subtle,getRandomValues:webcrypto.getRandomValues.bind(webcrypto)},
        sessionStorage:{getItem:key=>storage.get(key)??null,setItem:(key,v)=>storage.set(key,v),removeItem:key=>storage.delete(key)},
        history:{state:null,pushState(data,_,url){this.state=data;window.location=new URL(url,window.location);state.writes.push({type:'push',url,at:performance.now()});},
            replaceState(data,_,url){this.state=data;if(url)window.location=new URL(url,window.location);state.writes.push({type:'replace',url,at:performance.now()});}},
        scrollTo(){},requestAnimationFrame:fn=>fn(),dispatchEvent:()=>true};
    globalThis.document={querySelectorAll:()=>[],dispatchEvent:()=>true,visibilityState:'visible'};
    globalThis.CustomEvent=class {constructor(type,{detail}={}){this.type=type;this.detail=detail;}};
}
// axios environment detection should match the server-side unit harness, not a partial DOM.
browser();delete globalThis.document;
const artifact=await buildArtifact({root,output,patched:true,testExports:true});
const core=await import(pathToFileURL(artifact.target));
const page=(url='/a',context='A')=>({component:url==='/a'?'Goals':'Profile',url,version:'fixture',props:{errors:{},identity:{receipt:'receipt-'+context,binding:{principal:context}}},flash:{},rememberedState:{},encryptHistory:true,clearHistory:false});
async function setup({swap,resolve}={}) {
    browser();core.navigation.entry={};
    core.configureNavigation({validate:p=>p.props.identity.binding.principal===state.context,failure:(error,restored)=>state.failures.push({error:String(error),restored})});
    core.page.init({initialPage:page(),resolveComponent:resolve??(async(name)=>({name})),swapComponent:swap??(async({page:p})=>state.mounts.push(p.url))});
    await core.page.set(page(),{replace:true});await core.history.processQueue();state.writes=[];state.mounts=[];state.failures=[];
    return {material:[...state.storage],native:window.history.state,core:core.page.get(),internal:core.history.getAllState(),entry:core.navigation.entry};
}
function request(id) {
    return core.Request.create({id,onCancel:()=>{state.cancelled++;state.cancelAt=performance.now();},onFinish:()=>{}},core.page.get());
}
function holdEncryption(target='/b') {
    const gate=deferred();let reached=false;
    const original=webcrypto.subtle.encrypt.bind(webcrypto.subtle);
    window.crypto.subtle={...webcrypto.subtle,
        generateKey:webcrypto.subtle.generateKey.bind(webcrypto.subtle),exportKey:webcrypto.subtle.exportKey.bind(webcrypto.subtle),
        importKey:webcrypto.subtle.importKey.bind(webcrypto.subtle),decrypt:webcrypto.subtle.decrypt.bind(webcrypto.subtle),
        encrypt:async(...args)=>{const payload=JSON.parse(new TextDecoder().decode(args[2]));if(payload.url===target){reached=true;await gate.promise;}return original(...args);}};
    return {gate,reached:()=>reached};
}
function unchanged(before) {
    assert.deepEqual([...state.storage],before.material);
    assert.equal(window.history.state,before.native);
    assert.equal(core.page.get(),before.core);
    assert.equal(core.history.getAllState(),before.internal);
    assert.equal(core.navigation.entry,before.entry);
    assert.equal(state.writes.length,0);assert.equal(state.mounts.length,0);
}

test('N1-IR-01 — accepted-entry scroll must not overwrite newer remember',async()=>{
    await setup();const hold=holdEncryption('/a');const native=window.history.state.page;
    core.history.remember({text:'legitimate-sales-draft'},'draft');await until(hold.reached);
    core.history.saveDocumentScrollPosition({top:125,left:0});
    assert.equal(window.history.state.page,native);hold.gate.resolve();await core.history.processQueue();
    const decoded=await core.history.decrypt();
    assert.deepEqual(decoded.rememberedState.draft,{text:'legitimate-sales-draft'});
    assert.deepEqual(decoded.rememberedState,core.page.get().rememberedState);
    assert.deepEqual(decoded.rememberedState,core.history.getAllState().rememberedState);
    assert.deepEqual(window.history.state.documentScrollPosition,{top:125,left:0});
    await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

test('N1-IR-02 — pending remember must not overwrite native traversal target',async()=>{
    await setup();await core.page.set(page('/b'));const target=window.history.state;
    await core.page.set(page('/a'));const hold=holdEncryption('/a');
    core.history.remember({text:'outgoing-draft'},'draft');await until(hold.reached);
    const decryptGate=deferred();let decryptReached=false;
    const decrypt=webcrypto.subtle.decrypt.bind(webcrypto.subtle);
    window.crypto.subtle.decrypt=async(...args)=>{if(args[2]===target.page){decryptReached=true;await decryptGate.promise;}return decrypt(...args);};
    state.writes=[];window.history.state=target;window.location=new URL('http://localhost/b');
    core.eventHandler.handlePopstateEvent({state:target});await until(()=>decryptReached);
    hold.gate.resolve();await core.history.processQueue();
    const beforeRestore={url:window.location.pathname,writes:state.writes.map(x=>x.url),native:window.history.state};
    decryptGate.resolve();
    assert.equal(beforeRestore.url,'/b');assert.deepEqual(beforeRestore.writes,[]);assert.equal(beforeRestore.native,target);
    await until(()=>core.page.get().url==='/b'||core.page.isCleared());await core.history.processQueue();
    assert.equal(core.page.get().url,'/b');assert.equal((await core.history.decrypt()).url,'/b');
    assert.equal(core.page.get().rememberedState.draft,undefined);
    await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

for(const order of ['scroll-first','multiple-scrolls','crypto-failure'])test(`N1-IR-01 ${order} preserves latest accepted bytes and queue progress`,async()=>{
    await setup();const native=window.history.state.page;
    if(order==='scroll-first'){core.history.saveDocumentScrollPosition({top:15,left:0});await core.history.processQueue();}
    const hold=holdEncryption('/a');core.history.remember({text:'draft'},'draft');await until(hold.reached);
    core.history.saveDocumentScrollPosition({top:125,left:0});
    if(order==='multiple-scrolls')core.history.saveDocumentScrollPosition({top:250,left:0});
    if(order==='crypto-failure')hold.gate.reject(Error('remember crypto fault'));else hold.gate.resolve();
    await core.history.processQueue();const decoded=await core.history.decrypt();
    if(order==='crypto-failure'){assert.equal(window.history.state.page,native);assert.equal(decoded.rememberedState.draft,undefined);assert.equal(state.failures.length,1);}
    else assert.deepEqual(decoded.rememberedState.draft,{text:'draft'});
    assert.deepEqual(decoded.rememberedState,core.history.getAllState().rememberedState);
    assert.equal(window.history.state.documentScrollPosition.top,order==='multiple-scrolls'?250:125);
    window.crypto.subtle=webcrypto.subtle;await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

function decryptHold(target){
    const gate=deferred();let reached=false;const original=webcrypto.subtle.decrypt.bind(webcrypto.subtle);
    window.crypto.subtle.decrypt=async(...args)=>{if(args[2]===target.page){reached=true;await gate.promise;}return original(...args);};
    return {gate,reached:()=>reached};
}
function traverse(target,url){window.history.state=target;window.location=new URL(url,'http://localhost');core.eventHandler.handlePopstateEvent({state:target});}

for(const mode of ['same-url','back-forward','decrypt-failure','cancelled'])test(`N1-IR-02 ${mode} revokes outgoing writers at traversal start`,async()=>{
    await setup();await core.page.set({...page(mode==='same-url'?'/a':'/b'),props:{...page().props,entryMarker:'target'}});const target=window.history.state;
    await core.page.set(page('/a'));const outgoing=window.history.state,entry=core.navigation.entry,revision=core.navigation.nativeRevision;
    const encrypt=holdEncryption('/a');core.history.remember({text:'pending'},'outgoing');await until(encrypt.reached);
    const held=decryptHold(target);state.writes=[];traverse(target,mode==='same-url'?'/a':'/b');await until(held.reached);
    assert.equal(core.navigation.entry,entry);assert.equal(core.navigation.ownsNative(entry,revision),false);
    core.history.remember({text:'during-restore'},'during');core.history.saveDocumentScrollPosition({top:99,left:0});
    encrypt.gate.resolve();await core.history.processQueue();assert.deepEqual(state.writes,[]);assert.equal(window.history.state,target);
    assert.deepEqual(core.page.get().rememberedState.during,{text:'during-restore'});
    if(mode==='back-forward'){
        traverse(outgoing,'/a');await until(()=>core.navigation.entry!==entry);held.gate.resolve();
        await core.history.processQueue();assert.equal(core.page.get().props.entryMarker,undefined);assert.equal(window.history.state,outgoing);
        assert.equal(core.navigation.ownsNative(entry,revision),false);
    }else if(mode==='decrypt-failure'||mode==='cancelled'){
        if(mode==='decrypt-failure')held.gate.reject(Error('restore crypto fault'));
        else {core.navigation.invalidatePreparing();held.gate.resolve();}
        await until(()=>mode==='cancelled'?state.failures.length===1:core.page.isCleared());await core.history.processQueue();
        assert.equal(core.navigation.isRestoring(),true);assert.equal(window.history.state,target);assert.deepEqual(state.writes,[]);
        core.history.saveDocumentScrollPosition({top:100,left:0});await core.history.processQueue();assert.deepEqual(state.writes,[]);
        if(mode==='cancelled')assert.equal(state.failures[0].restored,false);
    }else {
        held.gate.resolve();await until(()=>core.navigation.entry!==entry);await core.history.processQueue();
        assert.equal(core.page.get().props.entryMarker,'target');assert.equal(core.page.get().rememberedState.during,undefined);
        assert.equal(core.page.get().rememberedState.outgoing,undefined);assert.equal(core.navigation.ownsNative(entry,revision),false);
    }
    window.crypto.subtle=webcrypto.subtle;await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

test('N1-IR-01 scroll queued behind navigation cannot modify its destination entry',async()=>{
    const gate=deferred();let held=false;await setup({swap:async({page:p})=>{if(p.url==='/b'){held=true;await gate.promise;}}});
    const visit=core.page.set(page('/b'));await until(()=>held);
    core.history.saveDocumentScrollPosition({top:999,left:0});
    // A traversal starts while this accepted candidate is settling. No old
    // scroll or same-URL compensation may reacquire native write permission.
    core.navigation.beginTraversal();gate.resolve();await assert.rejects(visit,/Native entry moved/);await core.history.processQueue();
    assert.notEqual(window.history.state.documentScrollPosition?.top,999);assert.equal(state.failures.at(-1).restored,false);
    await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

test('N1-IR-02 a traversal during restoration settlement cannot announce the older entry settled',async()=>{
    const gate=deferred();let held=false;
    await setup({swap:async({page:p})=>{if(p.props.restoreHeld){held=true;await gate.promise;}}});
    const target={...page(),props:{...page().props,restoreHeld:true}};
    const restore=core.page.setQuietly(target);const outcome=restore.catch(error=>error);await until(()=>held);
    core.navigation.beginTraversal();gate.resolve();
    const error=await outcome;assert.ok(error instanceof Error);assert.equal(state.failures.length,1);assert.equal(state.failures[0].restored,false);
    assert.equal(core.navigation.isRestoring(),true);assert.notEqual(core.navigationPhase(core.page.get()),'settled');
    await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

test('N1-IR-02 failed restoration mount does not grant queued writers native permission',async()=>{
    await setup({swap:async({page:p})=>{if(p.props.restoreFault){core.history.remember({text:'unsettled'},'unsettled');throw Error('restore mount fault');}}});
    await assert.rejects(core.page.setQuietly({...page(),props:{...page().props,restoreFault:true}}),/restore mount fault/);
    await core.history.processQueue();assert.equal(core.navigation.isRestoring(),true);assert.equal(state.writes.length,0);
    assert.equal((await core.history.decrypt()).rememberedState.unsettled,undefined);assert.equal(state.failures.at(-1).restored,false);
    await core.page.set(page('/c'));assert.equal(core.page.get().url,'/c');
});

test('N01 cancellation while encryption is held publishes no native/internal/core/key/entry effects',async()=>{
    const before=await setup();const hold=holdEncryption();const req=request('N01');
    const result=core.page.set({...page('/b'),clearHistory:true},{visitId:'N01'});const rejected=assert.rejects(result,core.HttpCancelledError);
    await until(hold.reached);assert.deepEqual([...state.storage],before.material);
    assert.equal(req.cancel({cancelled:true}),'cancelled');assert.equal(state.cancelled,1);hold.gate.resolve();await rejected;unchanged(before);
});
test('N02 cancel after native success is already-committed and cannot prevent settlement',async()=>{
    const gate=deferred();let reached=false;await setup({swap:async({page:p})=>{if(p.url==='/b'){reached=true;await gate.promise;}state.mounts.push(p.url);}});
    const req=request('N02');const work=core.page.set(page('/b'),{visitId:'N02'});await until(()=>reached);
    assert.equal(window.location.pathname,'/b');assert.equal(core.page.get().url,'/b');
    assert.equal(req.cancel({cancelled:true}),'already-committed');assert.equal(state.cancelled,0);
    gate.resolve();await work;assert.equal(core.navigationPhase(core.page.get()),'settled');assert.deepEqual(state.mounts,['/b']);
});
test('N03 newer visit commits while older encryption is held; old completion cannot overwrite it',async()=>{
    await setup();const hold=holdEncryption();const old=core.page.set(page('/b'),{visitId:'N03-old'});const rejected=assert.rejects(old,core.HttpCancelledError);
    await until(hold.reached);await core.page.set({...page('/c'),component:'Latest'},{visitId:'N03-new'});
    hold.gate.resolve();await rejected;await core.history.processQueue();
    assert.deepEqual(state.writes.filter(v=>v.type==='push').map(v=>v.url),['/c']);
    assert.equal(state.writes.some(v=>v.url==='/b'),false);assert.equal((await core.history.decrypt()).url,'/c');
    assert.deepEqual(state.mounts,['/c']);assert.equal(core.page.get().component,'Latest');
});
test('N03 Response queue admits the newer response before cancelled encryption completes',async()=>{
    await setup();const hold=holdEncryption();
    const response=(url,id)=>{
        const params={...core.router.getPendingVisit(url,{}),...core.router.getVisitEvents({}),id};
        const req=core.Request.create(params,core.page.get());core.navigation.start(id,true);
        return core.Response.create(req.requestParams,{data:page(url),headers:{'x-inertia':'true'},status:200},core.page.get());
    };
    const old=response('/b','queue-old').handle();const rejected=assert.rejects(old,core.HttpCancelledError);
    await until(hold.reached);await response('/c','queue-new').handle();await rejected;
    assert.equal(core.page.get().url,'/c');assert.equal((await core.history.decrypt()).url,'/c');
    hold.gate.resolve();await new Promise(setImmediate);await core.history.processQueue();
    assert.equal(state.writes.some(v=>v.url==='/b'),false);
});
test('initial History settlement preserves the admitted identity metadata object without publishing early',async()=>{
    const before=await setup();const identity=core.page.get().props.identity;const hold=holdEncryption('/a');
    const initial=core.page.set(core.page.get(),{replace:true,initialRender:true});await until(hold.reached);
    unchanged(before);hold.gate.resolve();await initial;
    assert.equal(core.page.get().props.identity,identity);assert.equal((await core.history.decrypt()).url,'/a');
});
test('explicit History clear invalidates a candidate still awaiting component resolution',async()=>{
    const gate=deferred();let held=false;const before=await setup({resolve:async name=>{if(name==='Profile'){held=true;await gate.promise;}return {name};}});
    const pending=core.page.set(page('/b'),{visitId:'clear-pending'});
    await until(()=>held);core.history.clear();gate.resolve();
    await assert.rejects(pending,core.HttpCancelledError);
    assert.equal(core.page.get(),before.core);assert.equal(window.history.state,before.native);assert.equal(state.writes.length,0);
    assert.equal(state.storage.has('historyKey'),false);assert.equal(state.storage.has('historyIv'),false);
});
test('conditional InitialVisit cannot restart stale restoration after a newer navigation settles',async()=>{
    await setup();const gate=deferred(),done=deferred();const old=page();const decrypt=core.history.decrypt.bind(core.history),set=core.page.set.bind(core.page);
    let late,first=true;const type=core.navigationType.type;core.navigationType.type='back_forward';
    core.history.decrypt=(...args)=>{if(!first)return decrypt(...args);first=false;const p=gate.promise,then=p.then.bind(p);p.then=(fn,...rest)=>then(value=>{try{const result=fn(value);done.resolve(result);return result;}catch(error){done.reject(error);throw error;}},...rest);return p;};
    core.page.set=(p,options)=>{const result=set(p,options);if(p===old)late=result;return result;};
    const outcome=done.promise.then(()=>undefined,error=>error);
    try{assert.equal(core.InitialVisit.handleBackForward(),true);await set(page('/b'));gate.resolve(old);const error=await outcome;if(late)await late.catch(()=>{});
        assert.equal(core.page.get().url,'/b');assert.ok(error instanceof core.HttpCancelledError);
    }finally{core.history.decrypt=decrypt;core.page.set=set;core.navigationType.type=type;}
});
test('conditional prefetch consumption owns the visit before the delayed response is available',async()=>{
    await setup();const gate=deferred();const params={...core.router.getPendingVisit('/a',{}),...core.router.getVisitEvents({onCancel(){state.cancelled++;}})};
    const req=core.Request.create(params,core.page.get());const old=core.Response.create(req.requestParams,{data:page(),headers:{'x-inertia':'true'},status:200},core.page.get());
    let completed=false;const use=core.prefetchedRequests.use({response:gate.promise},params);const outcome=use.then(()=>{completed=true;},error=>{completed=true;return error;});
    await core.page.set(page('/b'));try{await until(()=>completed);}finally{gate.resolve(old);}const error=await outcome;
    assert.equal(core.page.get().url,'/b');assert.equal(error,undefined);assert.equal(state.cancelled,1);
});
test('independent same-page deferred GET responses retain both partial payloads',async()=>{
    await setup();const gates={deferredA:deferred(),deferredB:deferred()},client=core.http.getClient();let sent=0;
    core.http.setClient({request:config=>{sent++;return gates[config.headers['X-Inertia-Partial-Data']].promise;}});
    const create=(field,id)=>{
        const params={...core.router.getPendingVisit('/a',{async:true,only:[field]}),...core.router.getVisitEvents({}),deferredProps:true,id};
        return core.Request.create(params,core.page.get()).send();
    };
    try{const a=create('deferredA','partial-A'),b=create('deferredB','partial-B');await until(()=>sent===2);
        gates.deferredA.resolve({data:{...page(),props:{...page().props,deferredA:'A'}},headers:{'x-inertia':'true'},status:200});await a;
        gates.deferredB.resolve({data:{...page(),props:{...page().props,deferredB:'B'}},headers:{'x-inertia':'true'},status:200});await b;
        assert.equal(core.page.get().props.deferredA,'A');assert.equal(core.page.get().props.deferredB,'B');
    }finally{core.http.setClient(client);}
});
test('a genuine navigation cancels a pending deferred response before its success callbacks',async()=>{
    await setup();const gate=deferred(),client=core.http.getClient();let sent=false,success=0;
    core.http.setClient({request:()=>{sent=true;return gate.promise;}});
    const params={...core.router.getPendingVisit('/a',{async:true,only:['late']}),...core.router.getVisitEvents({onSuccess(){success++;},onCancel(){state.cancelled++;}}),deferredProps:true};
    try{const request=core.Request.create(params,core.page.get()).send();await until(()=>sent);await core.page.set(page('/b'));
        gate.resolve({data:{...page(),props:{...page().props,late:'private-A'}},headers:{'x-inertia':'true'},status:200});await request;
        assert.equal(core.page.get().url,'/b');assert.equal(core.page.get().props.late,undefined);assert.equal(success,0);assert.equal(state.cancelled,1);
    }finally{core.http.setClient(client);}
});
test('prefetch consumption reports already-committed cancellation and completes only after swap',async()=>{
    const gate=deferred();let mounted=false,cancel,finished;
    await setup({swap:async({page:p})=>{if(p.url==='/b'){mounted=true;await gate.promise;}state.mounts.push(p.url);}});
    const params={...core.router.getPendingVisit('/b',{}),...core.router.getVisitEvents({onCancelToken:t=>cancel=t.cancel,onCancel(){state.cancelled++;},onFinish:v=>finished=v.completed})};
    const req=core.Request.create(params,core.page.get());const response=core.Response.create(req.requestParams,{data:page('/b'),headers:{'x-inertia':'true'},status:200},core.page.get());
    const use=core.prefetchedRequests.use({response:Promise.resolve(response)},params);await until(()=>mounted);
    assert.equal(cancel(),'already-committed');assert.equal(state.cancelled,0);assert.equal(finished,undefined);gate.resolve();await use;assert.equal(finished,true);
});
test('N04 cancellation after reaching the actual CriOS scheduling boundary prevents the final callback write',async()=>{
    const before=await setup();const gate=deferred();let callback;
    globalThis.setTimeout=fn=>{callback=fn;gate.resolve();return 1;};
    try{const work=core.page.set(page('/b'),{visitId:'N04'});const rejected=assert.rejects(work,core.HttpCancelledError);await gate.promise;
        assert.equal(request('N04').cancel({cancelled:true}),'cancelled');callback();await rejected;unchanged(before);
    }finally{globalThis.setTimeout=realTimer;}
});
test('N05 crypto rejection leaves accepted effects intact; the following operation remains runnable',async()=>{
    const before=await setup();const hold=holdEncryption();const work=core.page.set({...page('/b'),clearHistory:true});const rejected=assert.rejects(work,/crypto fault/);
    await until(hold.reached);hold.gate.reject(Error('crypto fault'));await rejected;unchanged(before);
    window.crypto.subtle=webcrypto.subtle;await core.page.set(page('/c'));assert.equal(window.location.pathname,'/c');
    const queue=new core.Queue();const failed=queue.add(()=>{throw Error('queue fault');});const next=queue.add(()=>7);
    await assert.rejects(failed,/queue fault/);assert.equal(await next,7);await queue.process();
});
for(const name of ['SecurityError','QuotaExceededError'])test(`N06 ${name} propagates and restores staged material without accepting the candidate`,async()=>{
    const before=await setup();window.history.pushState=()=>{throw new DOMException('native fault',name);};
    await assert.rejects(core.page.set({...page('/b'),clearHistory:true}),e=>e.name===name);unchanged(before);
});
test('N07 swap rejection after commit performs one same-identity compensation and reports failure',async()=>{
    await setup({swap:async({page:p})=>{if(p.url==='/b')throw Error('swap fault');state.mounts.push(p.url);}});
    await assert.rejects(core.page.set({...page('/b'),clearHistory:true}),/swap fault/);
    assert.deepEqual(state.writes.map(v=>[v.type,v.url]),[['push','/b'],['replace','/a']]);
    assert.equal(core.page.get().url,'/a');assert.equal(window.location.pathname,'/a');assert.deepEqual(state.mounts,['/a']);
    assert.equal(state.failures.length,1);assert.equal(state.failures[0].restored,true);
});
test('N09 cancellation does not invalidate a legitimate outgoing remembered draft write',async()=>{
    await setup();const hold=holdEncryption();const work=core.page.set(page('/b'),{visitId:'N09'});const rejected=assert.rejects(work,core.HttpCancelledError);
    await until(hold.reached);core.history.remember({draft:'sales'},'principal:context:workspace');await core.history.processQueue();
    assert.equal(request('N09').cancel({cancelled:true}),'cancelled');hold.gate.resolve();await rejected;
    assert.deepEqual(core.history.restore('principal:context:workspace'),{draft:'sales'});assert.equal(window.location.pathname,'/a');
    assert.equal(state.writes.length,1);assert.equal(state.writes[0].type,'replace');
});
test('N10 known identity replacement invalidates old preparation without restoring old key material',async()=>{
    await setup();const hold=holdEncryption();const old=core.page.set({...page('/b'),clearHistory:true},{visitId:'N10-A'});const rejected=assert.rejects(old,core.HttpCancelledError);
    await until(hold.reached);state.context='B';await core.page.set({...page('/c','B'),clearHistory:true},{visitId:'N10-B'});
    const bMaterial=[...state.storage];hold.gate.resolve();await rejected;
    assert.deepEqual([...state.storage],bMaterial);assert.equal(core.page.get().props.identity.binding.principal,'B');assert.deepEqual(state.mounts,['/c']);
});
test('N11 settled core/native History/callback tuple agrees for successful navigation',async()=>{
    await setup();await core.page.set(page('/b'));
    const native=await core.history.decrypt();assert.equal(window.location.pathname,'/b');assert.equal(native.url,core.page.get().url);
    assert.equal(native.component,core.page.get().component);assert.equal(native.props.identity.receipt,core.page.get().props.identity.receipt);
    assert.equal(core.navigationPhase(core.page.get()),'settled');assert.deepEqual(state.mounts,['/b']);
});

test('N08 an older decrypt cannot accept a later same-document traversal or mutate accepted History early',async()=>{
    await setup();const aState=window.history.state;await core.page.set(page('/b'));await core.history.processQueue();const bState=window.history.state;
    const gate=deferred();let reached=false;
    const original=webcrypto.subtle.decrypt.bind(webcrypto.subtle);
    window.crypto.subtle={generateKey:webcrypto.subtle.generateKey.bind(webcrypto.subtle),exportKey:webcrypto.subtle.exportKey.bind(webcrypto.subtle),
        importKey:webcrypto.subtle.importKey.bind(webcrypto.subtle),encrypt:webcrypto.subtle.encrypt.bind(webcrypto.subtle),
        decrypt:async(...args)=>{if(args[2]===aState.page){reached=true;await gate.promise;}return original(...args);}};
    const accepted=core.history.getAllState();state.mounts=[];
    window.history.state=aState;window.location=new URL('http://localhost/a');core.eventHandler.handlePopstateEvent({state:aState});
    await until(()=>reached);assert.equal(core.history.getAllState(),accepted);
    window.history.state=bState;window.location=new URL('http://localhost/b');core.eventHandler.handlePopstateEvent({state:bState});
    await until(()=>core.navigationPhase(core.page.get())==='settled'&&state.mounts.includes('/b'));
    gate.resolve();await new Promise(setImmediate);await new Promise(setImmediate);
    assert.equal(core.page.get().url,'/b');assert.equal(core.history.getAllState().url,'/b');assert.deepEqual(state.mounts,['/b']);
});

test('N07 failed compensation ends in one explicit recovery outcome without repeated attempts',async()=>{
    let fail=false;await setup({swap:async({page:p})=>{if(fail)throw Error('render/mount fault');state.mounts.push(p.url);}});fail=true;
    await assert.rejects(core.page.set(page('/b')),/render\/mount fault/);
    assert.equal(state.failures.length,1);assert.equal(state.failures[0].restored,false);
    assert.equal(state.writes.filter(v=>v.type==='replace').length,1);
});
test('N12 a failed candidate leaves the persisted original entry and draft reloadable',async()=>{
    await setup();core.history.remember({draft:'sales'},'principal:context:workspace');await core.history.processQueue();
    const hold=holdEncryption();const work=core.page.set({...page('/b'),clearHistory:true});const rejected=assert.rejects(work,/crypto fault/);
    await until(hold.reached);hold.gate.reject(Error('crypto fault'));await rejected;
    const persisted=await core.history.decrypt();assert.equal(window.location.pathname,'/a');assert.equal(persisted.url,'/a');
    assert.deepEqual(persisted.rememberedState['principal:context:workspace'],{draft:'sales'});
});
test('N10 failed write after identity change discards candidate keys and never restores A keys',async()=>{
    await setup();state.context='B';window.history.pushState=()=>{throw Error('write fault');};
    await assert.rejects(core.page.set({...page('/b','B'),clearHistory:true}),/write fault/);
    assert.equal(state.storage.size,0);assert.equal(state.failures.length,1);assert.equal(state.failures[0].restored,false);
});
test('conditional optimistic swap cannot outlive its accepted entry after another navigation settles',async()=>{
    const gate=deferred();let pause=false,reached=false;
    await setup({resolve:async(name)=>{if(pause&&name==='Goals'){reached=true;await gate.promise;}return {name};},
        swap:async({component,page:p})=>state.mounts.push({name:component.name,url:p.url})});
    pause=true;const stale=core.page.setPropsQuietly({...core.page.get().props,optimistic:'old'});const rejected=assert.rejects(stale,core.HttpCancelledError);
    await until(()=>reached);await core.page.set(page('/b'));gate.resolve();await rejected;
    assert.deepEqual(state.mounts,[{name:'Profile',url:'/b'}]);
});

test('a response rejected before commit cannot change the accepted optimistic baseline',async()=>{
    await setup();core.page.merge({props:{...core.page.get().props,value:'old'}});core.page.setBaseline('value','old');
    const options={...core.router.getPendingVisit('/a',{}),...core.router.getVisitEvents({onBeforeUpdate(){throw Error('precommit response fault');}})};
    const req=core.Request.create(options,core.page.get());
    const optimistic=core.Request.create({...options,id:'optimistic-fixture'},core.page.get(),{optimistic:true});
    core.router.asyncRequestStream.requests.push(optimistic);
    try{
        assert.equal(core.router.hasPendingOptimistic(),true);
        const candidate={...page(),props:{...page().props,value:'candidate'}};
        const response=core.Response.create(req.requestParams,{data:candidate,headers:{},status:200},core.page.get());
        await assert.rejects(response.setPage(),/precommit response fault/);
        assert.equal(core.page.replayOptimistics().value,'old');assert.equal(core.page.get().props.value,'old');
    }finally{core.router.asyncRequestStream.requests.pop();core.page.clearOptimisticState();}
});

test('conditional optimistic rollback from A cannot restore A data into the same component under B',async()=>{
    await setup();core.page.merge({props:{...core.page.get().props,value:'A-private'}});
    const events=core.router.getVisitEvents({});core.router.applyOptimisticUpdate(()=>({value:'A-command'}),events);
    await new Promise(setImmediate);state.context='B';
    await core.page.set({...page('/a','B'),props:{...page('/a','B').props,value:'B-value'},clearHistory:true});
    events.onFinish({id:'old-A'});await new Promise(setImmediate);
    assert.equal(core.page.get().props.identity.binding.principal,'B');assert.equal(core.page.get().props.value,'B-value');
});

test('a failed preserveUrl response cannot leak its URL policy into a later local navigation',async()=>{
    await setup();
    const params={...core.router.getPendingVisit('/a',{preserveUrl:true}),...core.router.getVisitEvents({onBeforeUpdate(){throw Error('response fault');}})};
    const req=core.Request.create(params,core.page.get());
    const response=core.Response.create(req.requestParams,{data:page(),headers:{'x-inertia':'true'},status:200},core.page.get());
    await assert.rejects(response.process(),/response fault/);
    await core.page.set(page('/b'));assert.equal(window.location.pathname,'/b');assert.equal(state.failures.length,0);
});
