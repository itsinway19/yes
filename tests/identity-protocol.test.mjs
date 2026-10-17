import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

// Contract-level client probes. Browser/store races are recorded separately.
const dataUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const core = dataUrl(`export class HttpCancelledError extends Error {};
export const http={setClient(v){globalThis.__identity.options=v},onRequest(fn){globalThis.__identity.request=fn},
onResponse(fn){globalThis.__identity.response=fn},onError(fn){globalThis.__identity.error=fn}};`);
const vue = dataUrl(`export const router={cancelAll(){globalThis.__identity.cancelled++},flushAll(){globalThis.__identity.flushed++},
clearHistory(){globalThis.__identity.cleared++}};`);
const axios = dataUrl(`export default {defaults:{},interceptors:{request:{use(fn){globalThis.__identity.axiosRequest=fn}},
response:{use(fn,error){globalThis.__identity.axiosResponse=fn;globalThis.__identity.axiosError=error}}}};`);
const passkeys = dataUrl(`export const Passkeys={configure(v){globalThis.__identity.passkeys=v}};`);
const leaseModule=dataUrl(ts.transpileModule(await readFile(new URL('../resources/js/sessionLease.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText);
const compiled = ts.transpileModule(await readFile(new URL('../resources/js/identityProtocol.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    .replace("from '@inertiajs/core'", `from ${JSON.stringify(core)}`)
    .replace("from '@inertiajs/vue3'", `from ${JSON.stringify(vue)}`)
    .replace("from 'axios'", `from ${JSON.stringify(axios)}`)
    .replace("from '@laravel/passkeys'", `from ${JSON.stringify(passkeys)}`)
    .replace("from './sessionLease'", `from ${JSON.stringify(leaseModule)}`);
let sequence = 0;
function metadata(overrides = {}) {
    const binding = {lineage:'11111111-1111-4111-8111-111111111111',epoch:1,boundary:'advertiser',credential:'a'.repeat(64),
        principal:1,lifecycle:1,context:'b'.repeat(64),generation:'c'.repeat(64), ...overrides};
    const transition = Buffer.from(JSON.stringify({binding:{...binding, nonce:'d'.repeat(64)}})).toString('base64url') + '.signature';
    return {binding,receipt:'signed-document',transition,csrf:'document-token',leaseMilliseconds:30000};
}
async function setup(t, initial = metadata()) {
    const saved = {setTimeout:globalThis.setTimeout,clearTimeout:globalThis.clearTimeout,document:globalThis.document,window:globalThis.window,fetch:globalThis.fetch,BroadcastChannel:globalThis.BroadcastChannel};
    const savedNow=Date.now;t.after(() => {Object.assign(globalThis,saved);Date.now=savedNow;});
    const state = globalThis.__identity = {cancelled:0,flushed:0,cleared:0,requests:[],replaced:[],timers:[],events:{},inert:false};
    globalThis.BroadcastChannel=undefined;
    state.now=0;Date.now=()=>state.now;
    globalThis.setTimeout = (fn,delay) => {state.timers.push({fn,delay,due:state.now+delay});return state.timers.length;};
    globalThis.clearTimeout = id => {if (state.timers[id-1]) state.timers[id-1].cleared=true;};
    state.activeTimers=()=>state.timers.filter(timer=>!timer.cleared&&!timer.consumed);
    state.fire=async timer=>{state.now=Math.max(state.now,timer.due);timer.consumed=true;await timer.fn();await flush();};
    globalThis.document = {documentElement:{dataset:{visibility:'ready'}},querySelector: selector => ({getAttribute:()=>JSON.stringify(initial),setAttribute:(name,value)=>state.meta=value}),
        getElementById:()=>({setAttribute(){state.inert=true}})};
    globalThis.window = {location:{origin:'http://localhost',href:'http://localhost/profile',replace: url => state.replaced.push(url)},
        addEventListener:(name,fn)=>state.events[name]=fn};
    globalThis.fetch = (url,options) => new Promise((resolve,reject)=>state.requests.push({url,options,resolve,reject}));
    state.protocol = await import(dataUrl(compiled + `\n// isolated instance ${++sequence}`));
    state.protocol.installIdentityProtocol();
    state.page = {props:{identity:initial}};
    state.protocol.attachIdentityPage(state.page);
    state.protocol.onIdentityRecovered(()=>{document.documentElement.dataset.visibility='ready';});
    state.reply = (index,status,body) => state.requests[index].resolve({status,ok:status>=200&&status<300,json:async()=>body});
    return state;
}
const flush = async () => {for(let i=0;i<20;i++) await Promise.resolve();};

test('R01 consumed renewal timer resumes once after same-identity recovery using the current lease',async t=>{
    const s=await setup(t);s.protocol.onIdentityRecovered(()=>{document.documentElement.dataset.visibility='ready';});
    const timer=s.activeTimers()[0],waiting=s.protocol.recoverIdentity();
    await s.fire(timer);assert.equal(timer.consumed,true);assert.equal(s.activeTimers().length,0);
    assert.deepEqual(s.requests.map(request=>request.url),['/identity/context']);
    const fresh=metadata({generation:'e'.repeat(64)});fresh.leaseMilliseconds=4000;
    s.reply(0,200,{identity:fresh});await waiting;await flush();
    assert.equal(s.protocol.identityBlocked(),false);assert.equal(s.activeTimers().length,1);
    assert.equal(s.activeTimers()[0].delay,5000,'Apply the existing minimum to the fresh remaining lease');
    await s.fire(s.activeTimers()[0]);assert.equal(s.requests[1].url,'/identity/renew');
    const renewed={...fresh,leaseMilliseconds:60000};s.reply(1,200,{identity:renewed});await flush();
    assert.equal(s.activeTimers().length,1);assert.equal(s.activeTimers()[0].delay,42000);
});

test('R01 repeated recovery and reveal retain one timer; old queued callbacks cannot renew',async t=>{
    const s=await setup(t),old=s.activeTimers()[0];
    for(let index=0;index<3;index++){
        const waiting=s.protocol.recoverIdentity();s.reply(index,200,{identity:metadata({generation:String(index+4).repeat(64)})});await waiting;await flush();
        s.protocol.resumeIdentityRenewal();s.protocol.resumeIdentityRenewal();assert.equal(s.activeTimers().length,1);
    }
    await s.fire(old);assert.equal(s.requests.length,3);assert.equal(s.activeTimers().length,1);
    await s.fire(s.activeTimers()[0]);assert.equal(s.requests[3].url,'/identity/renew');
    s.reply(3,200,{identity:s.protocol.captureIdentity()});await flush();assert.equal(s.activeTimers().length,1);
});

for(const outcome of ['terminal','failed','changed','lost','anonymous'])test(`R01 consumed timer does not resume after ${outcome} recovery`,async t=>{
    const s=await setup(t),timer=s.activeTimers()[0],waiting=s.protocol.recoverIdentity();await s.fire(timer);
    if(outcome==='terminal')s.protocol.closeIdentityDocument();
    if(outcome==='failed'){s.requests[0].reject(new Error('offline'));await assert.rejects(waiting,/offline/)}
    else{s.reply(0,outcome==='lost'?401:200,{identity:metadata(outcome==='changed'?{principal:2,epoch:2}:outcome==='anonymous'?{principal:0}:{generation:'e'.repeat(64)})});await waiting}
    await flush();s.protocol.resumeIdentityRenewal();s.events.pageshow({persisted:true});await s.fire(timer);
    assert.equal(s.activeTimers().length,0);assert.equal(s.requests.length,1);
});

test('R01 explicit successful retry after failed recovery restores eligibility without polling',async t=>{
    const s=await setup(t),waiting=s.protocol.recoverIdentity();await s.fire(s.activeTimers()[0]);
    s.requests[0].reject(new Error('offline'));await assert.rejects(waiting);await flush();assert.equal(s.activeTimers().length,0);
    const retry=s.protocol.retryIdentityRecovery();s.reply(1,200,{identity:metadata({generation:'e'.repeat(64)})});await retry;await flush();
    assert.equal(s.activeTimers().length,1);assert.equal(s.requests.length,2);
});

test('R01 recovery waits for the one existing renewal; obsolete response cannot update fresh generation',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);assert.equal(s.requests[0].url,'/identity/renew');
    const waiting=s.protocol.recoverIdentity(),fresh=metadata({generation:'e'.repeat(64)});fresh.csrf='recovered-token';
    s.reply(1,200,{identity:fresh});await waiting;await flush();
    assert.equal(s.activeTimers().length,0,'Do not arm while an earlier renewal is still running');
    s.protocol.resumeIdentityRenewal();s.protocol.resumeIdentityRenewal();assert.equal(s.requests.length,2);
    s.reply(0,200,{identity:{...metadata(),csrf:'obsolete-token'}});await flush();
    assert.strictEqual(s.protocol.captureIdentity(),fresh);assert.equal(s.activeTimers().length,1);
    await s.fire(s.activeTimers()[0]);assert.equal(s.requests[2].url,'/identity/renew');
    s.reply(2,200,{identity:fresh});await flush();assert.equal(s.activeTimers().length,1);
});

test('R01 obsolete renewal transport failure after recovery creates no extra context probe',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);const waiting=s.protocol.recoverIdentity();
    s.reply(1,200,{identity:metadata({generation:'e'.repeat(64)})});await waiting;await flush();
    s.requests[0].reject(new Error('late offline'));await flush();assert.equal(s.requests.length,2);assert.equal(s.activeTimers().length,1);
});

test('R01 renewal failure owns one recovery and arms once after that recovery finishes',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);s.reply(0,409,{});await flush();
    assert.equal(s.requests[1].url,'/identity/context');assert.equal(s.activeTimers().length,0);
    s.reply(1,200,{identity:metadata({generation:'e'.repeat(64)})});await flush();assert.equal(s.activeTimers().length,1);
});

test('R01 terminal closure cancels a current recovery schedule deferred behind an obsolete renewal',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);const waiting=s.protocol.recoverIdentity();
    s.reply(1,200,{identity:metadata({generation:'e'.repeat(64)})});await waiting;await flush();
    assert.equal(s.activeTimers().length,0);s.protocol.closeIdentityDocument();
    s.reply(0,200,{identity:metadata()});await flush();assert.equal(s.activeTimers().length,0);assert.equal(s.requests.length,2);
});

test('R01 obsolete asynchronous JSON cannot mutate or arm after the current document generation changes',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);let release;
    s.requests[0].resolve({status:200,ok:true,json:()=>new Promise(resolve=>{release=resolve})});await flush();
    const waiting=s.protocol.recoverIdentity(),fresh=metadata({generation:'e'.repeat(64)});
    s.reply(1,200,{identity:fresh});await waiting;await flush();release({identity:metadata()});await flush();
    assert.strictEqual(s.protocol.captureIdentity(),fresh);assert.equal(s.activeTimers().length,1);assert.equal(s.requests.length,2);
});

test('R01 remembered same-generation metadata does not extend an observed lease schedule',async t=>{
    const s=await setup(t);s.now=20000;s.protocol.attachIdentityPage({props:{identity:metadata()}});s.protocol.resumeIdentityRenewal();
    assert.equal(s.activeTimers().length,1);assert.equal(s.activeTimers()[0].delay,5000);
});

test('R01 pagehide prevents late renewal rearming; restored document must regain trusted visibility',async t=>{
    const s=await setup(t);await s.fire(s.activeTimers()[0]);s.events.pagehide();document.documentElement.dataset.visibility='checking';
    s.reply(0,200,{identity:{...metadata(),csrf:'late-hidden-token'}});await flush();
    assert.equal(s.activeTimers().length,0);assert.equal(s.protocol.captureIdentity().csrf,'document-token');
    s.events.pageshow({persisted:true});s.protocol.resumeIdentityRenewal();assert.equal(s.activeTimers().length,0);
    document.documentElement.dataset.visibility='ready';s.protocol.resumeIdentityRenewal();assert.equal(s.activeTimers().length,1);
    await s.fire(s.activeTimers()[0]);assert.equal(s.requests[1].url,'/identity/renew');s.reply(1,200,{identity:metadata()});await flush();
    assert.equal(s.activeTimers().length,1);
});

test('R01 hidden visibility alone adds no probe or overlapping renewal and uses the elapsed current lease',async t=>{
    const s=await setup(t);document.visibilityState='hidden';s.now=20000;s.protocol.resumeIdentityRenewal();
    assert.equal(s.activeTimers().length,1);assert.equal(s.activeTimers()[0].delay,5000);
    await s.fire(s.activeTimers()[0]);s.protocol.resumeIdentityRenewal();s.events.pageshow({persisted:true});
    assert.equal(s.activeTimers().length,0);assert.equal(s.requests.length,1);
    s.reply(0,200,{identity:metadata()});await flush();assert.equal(s.activeTimers().length,1);
    assert.ok(!s.requests.some(request=>request.url==='/identity/context'));
});

test('renewal finishing after terminal closure cannot replace metadata or schedule another lease', async t => {
    const s=await setup(t);
    const original=s.page.props.identity;
    const waiting=s.timers[0].fn();await flush();
    assert.equal(s.requests[0].url,'/identity/renew');
    s.protocol.closeIdentityDocument();
    s.reply(0,200,{identity:{...metadata(),csrf:'late-renewal-token'}});
    await waiting;await flush();
    assert.strictEqual(s.page.props.identity,original);
    assert.equal(s.timers.length,1);
    s.events.pageshow({persisted:true});assert.equal(s.timers.length,1);
    assert.throws(()=>s.protocol.identityHeaders(),/closed/);
});

test('document CSRF owns Inertia, axios and passkey headers; no automatic XSRF cookie override', async t => {
    const s = await setup(t);
    assert.deepEqual(s.options,{xsrfCookieName:'',xsrfHeaderName:''});
    const config=s.request({url:'/locale',headers:{'X-CSRF-TOKEN':'obsolete-cookie-token'}});
    assert.equal(config.headers['X-CSRF-TOKEN'],'document-token');
    assert.equal(config.headers['X-Builderad-Document'],'signed-document');
    const headers={set(name,value){this[name]=value}};
    s.axiosRequest({url:'/locale',headers});
    assert.equal(headers['X-CSRF-TOKEN'],'document-token');
    assert.equal(s.passkeys.fetch.headers['X-CSRF-TOKEN'],'document-token');
    assert.throws(()=>s.request({url:'https://foreign.example',headers:{}}),/application origin/);
});
test('signed-looking cached candidate cannot cross principal, epoch, SID, session context or generation', async t => {
    const s=await setup(t);
    for(const overrides of [{principal:2},{epoch:2},{credential:'e'.repeat(64)},{context:'e'.repeat(64)},{generation:'e'.repeat(64)},{boundary:'internal'}]) {
        assert.throws(()=>s.protocol.acceptIdentityPage({props:{identity:metadata(overrides)}}),/another identity/);
    }
    assert.equal(s.page.props.identity.binding.principal,1);
});
test('actual conflict starts exactly one context check; same identity resumes without replay', async t => {
    const s=await setup(t);let resumed=0;s.protocol.onIdentityRecovered(()=>resumed++);
    const first=s.protocol.recoverIdentity(),second=s.protocol.recoverIdentity();
    assert.strictEqual(first,second);assert.equal(s.requests.length,1);
    assert.strictEqual(s.protocol.retryIdentityRecovery(),first);assert.equal(s.requests.length,1);
    assert.equal(s.requests[0].options.cache,'no-store');assert.equal(s.requests[0].options.method,undefined);
    assert.throws(()=>s.protocol.identityHeaders(),/closed/);
    const fresh=metadata({generation:'e'.repeat(64)});fresh.csrf='fresh-document-token';
    s.reply(0,200,{identity:fresh});await first;
    assert.equal(resumed,1);assert.equal(s.protocol.identityHeaders()['X-CSRF-TOKEN'],'fresh-document-token');
    assert.equal(s.requests.length,1);assert.equal(s.replaced.length,0);
    assert.strictEqual(s.page.props.identity,fresh);
});
test('different current identity discards old state and requires a fresh native document', async t => {
    const s=await setup(t);const waiting=s.protocol.recoverIdentity();
    s.reply(0,200,{identity:metadata({principal:2,epoch:2,credential:'e'.repeat(64)})});await waiting;
    assert.deepEqual(s.replaced,['http://localhost/profile']);assert.equal(s.flushed,1);assert.equal(s.cleared,1);
    assert.equal(s.requests.length,1);assert.throws(()=>s.protocol.identityHeaders(),/closed/);
});
test('credential loss chooses the matching internal reauthentication destination without replay', async t => {
    const s=await setup(t,metadata({boundary:'internal'}));const waiting=s.protocol.recoverIdentity();
    assert.equal(s.requests[0].url,'/internal/identity/context');s.reply(0,401,{});await waiting;
    assert.deepEqual(s.replaced,['/internal/login']);assert.equal(s.requests.length,1);
});
test('transport failure is finite and a new context attempt needs an explicit retry', async t => {
    const s=await setup(t);const waiting=s.protocol.recoverIdentity();s.requests[0].reject(new Error('offline'));
    await assert.rejects(waiting,/offline/);await flush();
    assert.equal(document.documentElement.dataset.visibility,'unavailable');assert.equal(s.requests.length,1);
    assert.strictEqual(s.protocol.recoverIdentity(),waiting);
    const retry=s.protocol.retryIdentityRecovery();assert.equal(s.requests.length,2);
    s.reply(1,401,{});await retry;
});
test('late response is rejected before client consumption and starts only one recovery check', async t => {
    const s=await setup(t);const raw=JSON.stringify(metadata({epoch:2,principal:2}));
    assert.throws(()=>s.response({headers:{'x-builderad-identity':raw}}),/discarded/);
    assert.equal(s.requests.length,1);s.reply(0,401,{});await flush();
    assert.throws(()=>s.protocol.acceptIdentityPage(s.page),/another identity/);
});
test('unknown authentication commit resolves its nonce once and never replays credentials', async t => {
    const s=await setup(t);const waiting=s.protocol.authenticateIdentity('/login',{email:'synthetic@example.test',password:'synthetic'});
    s.reply(0,503,{identityUnknown:true});await flush();
    assert.equal(s.requests[1].url,'/identity/transition');s.reply(1,200,{transition:null});await flush();
    assert.equal(s.requests[2].url,'/identity/context');s.reply(2,401,{});await assert.rejects(waiting);
    assert.equal(s.requests.filter(r=>r.options.method==='POST').length,1);assert.equal(s.requests.length,3);
});
test('only the captured nonce and next epoch may hand off successful authentication', async t => {
    const s=await setup(t);const captured=s.protocol.captureIdentity();
    const result={redirect:'/profile',transition:{lineage:captured.binding.lineage,boundary:'advertiser',epoch:2,nonce:'d'.repeat(64)}};
    assert.equal(s.protocol.identityDestination(result,captured),'http://localhost/profile');
    assert.throws(()=>s.protocol.identityDestination({...result,transition:{...result.transition,nonce:'e'.repeat(64)}},captured),/unowned/);
    assert.throws(()=>s.protocol.identityDestination({...result,redirect:'https://foreign.example'},captured),/destination/);
});
test('remembered draft trust augments existing scope and cannot outlive a revoked document', async t => {
    const s=await setup(t);const original=metadata().binding;
    assert.equal(s.protocol.trustIdentityState(original),true);
    assert.equal(s.protocol.trustIdentityState({...original,generation:'e'.repeat(64)}),true);
    for(const overrides of [{principal:2},{epoch:2},{credential:'e'.repeat(64)},{context:'e'.repeat(64)},{lifecycle:2},{boundary:'internal'}]) {
        assert.equal(s.protocol.trustIdentityState({...original,...overrides}),false);
    }
    assert.equal(s.protocol.trustIdentityState(undefined),false);
    s.protocol.closeIdentityDocument();assert.equal(s.protocol.trustIdentityState(original),false);
});
test('terminal logout document cannot be reopened by late responses or an in-flight context check',async t=>{
    const s=await setup(t);const waiting=s.protocol.recoverIdentity();
    s.protocol.closeIdentityDocument();s.reply(0,200,{identity:metadata({generation:'e'.repeat(64)})});await waiting;
    assert.equal(s.protocol.identityBlocked(),true);assert.throws(()=>s.protocol.identityHeaders(),/closed/);
    await s.protocol.recoverIdentity();await s.protocol.retryIdentityRecovery();
    assert.throws(()=>s.response({headers:{'x-builderad-identity':JSON.stringify(metadata())}}),/discarded/);
    assert.equal(s.requests.length,1);assert.equal(s.protocol.trustIdentityState(metadata().binding),false);
});
test('epoch hints never grant trust and wrong boundaries or malformed messages do nothing',async t=>{
    const s=await setup(t);
    for(const value of [null,'invalidate',{boundary:'internal',epoch:2},{boundary:'advertiser',epoch:-1},{boundary:'advertiser',epoch:1}]) s.protocol.receiveIdentityHint(value);
    assert.equal(s.requests.length,0);
    s.protocol.receiveIdentityHint({boundary:'advertiser',epoch:999});assert.equal(s.requests.length,1);
    assert.throws(()=>s.protocol.identityHeaders(),/closed/);
    s.reply(0,200,{identity:metadata()});await flush();
    assert.equal(s.protocol.captureIdentity().binding.epoch,1);assert.equal(s.protocol.identityBlocked(),false);
});


for (const appearance of ['during-recovery','after-failure']) test(`F01 ${appearance} pageshow records presence without granting renewal`, async t=>{
 const s=await setup(t);s.events.pagehide();let waiting=s.protocol.recoverIdentity();
 if(appearance==='after-failure'){
  s.requests[0].reject(new Error('offline'));await assert.rejects(waiting);await flush();
 }
 s.events.pageshow({persisted:true});s.protocol.resumeIdentityRenewal();
 assert.equal(s.activeTimers().length,0);assert.equal(s.requests.length,1);
 if(appearance==='after-failure')waiting=s.protocol.retryIdentityRecovery();
 const fresh=metadata({generation:'e'.repeat(64)});
 s.reply(s.requests.length-1,200,{identity:fresh});await waiting;await flush();
 assert.equal(s.activeTimers().length,1);await s.fire(s.activeTimers()[0]);
 assert.equal(s.requests.at(-1).url,'/identity/renew');
 s.reply(s.requests.length-1,200,{identity:fresh});await flush();assert.equal(s.activeTimers().length,1);
});

for(const outcome of ['successful','failed','changed','lost','terminal','hidden'])test(`F02 obsolete pending JSON ownership after ${outcome}`,async t=>{
 const s=await setup(t);await s.fire(s.activeTimers()[0]);let release;
 s.requests[0].resolve({status:200,ok:true,json:()=>new Promise(resolve=>{release=resolve})});await flush();
 if(outcome==='hidden')s.events.pagehide();
 const waiting=s.protocol.recoverIdentity();const fresh=metadata({generation:'e'.repeat(64)});fresh.leaseMilliseconds=10000;
 if(outcome==='terminal')s.protocol.closeIdentityDocument();
 if(outcome==='failed'){s.requests[1].reject(new Error('offline'));await assert.rejects(waiting)}
 else {s.reply(1,outcome==='lost'?401:200,{identity:outcome==='changed'?metadata({principal:2,epoch:2}):fresh});await waiting}
 await flush();assert.equal(s.requests[0].options.signal.aborted,true,'Only obsolete body consumption is aborted');
 s.now+=20000;s.protocol.resumeIdentityRenewal();s.protocol.resumeIdentityRenewal();
 const eligible=outcome==='successful';assert.equal(s.activeTimers().length,eligible?1:0);
 let newest;
 if(eligible){await s.fire(s.activeTimers()[0]);newest=s.requests[2];assert.equal(newest.url,'/identity/renew');
  s.protocol.resumeIdentityRenewal();assert.equal(s.requests.length,3);assert.equal(s.activeTimers().length,0)}
 release({identity:{...metadata(),csrf:'obsolete-token'}});await flush();
 assert.notEqual(s.protocol.captureIdentity().csrf,'obsolete-token');
 assert.equal(s.activeTimers().length,0,'Old body completion cannot arm a timer or release the new request');
 if(eligible){s.protocol.resumeIdentityRenewal();assert.equal(s.activeTimers().length,0);
  s.reply(2,200,{identity:fresh});await flush();assert.equal(s.activeTimers().length,1)}
 if(outcome==='hidden'){
  s.events.pageshow({persisted:true});assert.equal(s.activeTimers().length,1);
 }
});

test('F02 unknown pre-header renewal remains serialized; invalidation does not imply server cancellation',async t=>{
 const s=await setup(t);await s.fire(s.activeTimers()[0]);const old=s.requests[0];
 const waiting=s.protocol.recoverIdentity();s.reply(1,200,{identity:metadata({generation:'e'.repeat(64)})});await waiting;await flush();
 s.now+=60000;s.protocol.resumeIdentityRenewal();assert.equal(s.activeTimers().length,0);
 assert.equal(old.options.signal.aborted,false);assert.equal(s.requests.length,2);
 s.reply(0,200,{identity:{...metadata(),csrf:'obsolete-token'}});await flush();
 assert.equal(s.activeTimers().length,1);assert.notEqual(s.protocol.captureIdentity().csrf,'obsolete-token');
});


const correctedMetadata = (overrides = {}, leaseMilliseconds = 30000) =>
    ({...metadata(overrides),renewalProtocol:1,leaseMilliseconds});
const leaseTimer = s => s.activeTimers().find(timer=>![15000,10000].includes(timer.delay));
const deadlineTimer = (s,ms) => s.activeTimers().find(timer=>timer.delay===ms);

test('cookie-free renewal counts delayed delivery against its observed availability horizon',async t=>{
 const s=await setup(t,correctedMetadata());await s.fire(leaseTimer(s));
 s.now+=2000;s.reply(0,200,{identity:correctedMetadata({},30000)});await flush();
 assert.equal(s.protocol.captureIdentity().leaseMilliseconds,28000);assert.equal(leaseTimer(s).delay,19600);
});

test('an expired availability reply cannot restart a renewal lease',async t=>{
 const s=await setup(t,correctedMetadata());await s.fire(leaseTimer(s));
 s.now+=2000;s.reply(0,200,{identity:correctedMetadata({},1000)});await flush();
 assert.equal(s.protocol.identityBlocked(),true);assert.equal(s.requests[1].url,'/identity/context');
});

async function timedOutRenewal(t) {
 const s=await setup(t,correctedMetadata());await s.fire(leaseTimer(s));
 assert.equal(s.requests[0].url,'/identity/renew');await s.fire(deadlineTimer(s,15000));
 assert.equal(s.requests[0].options.signal.aborted,true);
 assert.equal(s.requests[1].url,'/identity/context');assert.equal(s.activeTimers().length,1);
 return s;
}

test('F02 compatible pre-header timeout owns one context and replacement; old completion cannot release the new attempt',async t=>{
 const s=await timedOutRenewal(t);const fresh=correctedMetadata({generation:'e'.repeat(64)},1000);
 s.reply(1,200,{identity:fresh});await flush();assert.equal(s.protocol.identityBlocked(),false);
 assert.equal(s.activeTimers().length,1);assert.equal(leaseTimer(s).delay,700);
 await s.fire(leaseTimer(s));assert.equal(s.requests[2].url,'/identity/renew');
 s.reply(0,200,{identity:{...correctedMetadata(),csrf:'obsolete-token'}});await flush();
 assert.strictEqual(s.protocol.captureIdentity(),fresh);s.protocol.resumeIdentityRenewal();s.protocol.resumeIdentityRenewal();
 assert.equal(s.requests.length,3);assert.equal(s.activeTimers().length,1,'Only the new transport deadline remains');
 const renewed={...fresh,leaseMilliseconds:60000};s.reply(2,200,{identity:renewed});await flush();
 assert.strictEqual(s.protocol.captureIdentity(),renewed);assert.equal(s.activeTimers().length,1);assert.equal(leaseTimer(s).delay,42000);
});

test('F02 a hung replacement stops without a second context or automatic retry; explicit retry is finite',async t=>{
 const s=await timedOutRenewal(t);s.reply(1,200,{identity:correctedMetadata({generation:'e'.repeat(64)},1000)});await flush();
 await s.fire(leaseTimer(s));await s.fire(deadlineTimer(s,15000));
 assert.equal(s.requests.length,3);assert.equal(s.activeTimers().length,0);assert.equal(s.protocol.identityBlocked(),true);
 assert.equal(document.documentElement.dataset.visibility,'unavailable');
 for(let index=0;index<3;index++){s.protocol.receiveIdentityHint({boundary:'advertiser',epoch:999});s.protocol.resumeIdentityRenewal();await flush()}
 assert.equal(s.requests.length,3);const retry=s.protocol.retryIdentityRecovery();assert.equal(s.requests[3].url,'/identity/context');
 s.reply(3,200,{identity:correctedMetadata({generation:'f'.repeat(64)},1000)});await retry;await flush();
 assert.equal(s.activeTimers().length,1);assert.equal(s.protocol.identityBlocked(),false);
});

for(const phase of ['headers','json'])test(`F02 hung context ${phase} is bounded and its late result cannot reopen the document`,async t=>{
 const s=await timedOutRenewal(t),original=s.protocol.captureIdentity();let release;
 if(phase==='json'){s.requests[1].resolve({status:200,ok:true,json:()=>new Promise(resolve=>{release=resolve})});await flush()}
 await s.fire(deadlineTimer(s,10000));assert.equal(s.requests[1].options.signal.aborted,true);
 assert.equal(s.requests.length,2);assert.equal(s.activeTimers().length,0);assert.equal(document.documentElement.dataset.visibility,'unavailable');
 const late={identity:correctedMetadata({generation:'e'.repeat(64)})};if(release)release(late);else s.reply(1,200,late);
 await flush();assert.strictEqual(s.protocol.captureIdentity(),original);assert.equal(s.protocol.identityBlocked(),true);assert.equal(s.activeTimers().length,0);
});

for(const outcome of ['failed','different','lost','incompatible'])test(`F02 timeout recovery ${outcome} cannot start an unauthorized replacement`,async t=>{
 const s=await timedOutRenewal(t);
 if(outcome==='failed')s.requests[1].reject(new Error('offline'));
 else s.reply(1,outcome==='lost'?401:200,{identity:outcome==='different'?correctedMetadata({principal:2,epoch:2}):outcome==='incompatible'?metadata({generation:'e'.repeat(64)}):correctedMetadata()});
 await flush();assert.equal(s.protocol.identityBlocked(),true);assert.equal(s.requests.length,2);assert.equal(s.activeTimers().length,0);
});

test('F02 pagehide cancels a compatible wait; pageshow during recovery restores presence without early renewal',async t=>{
 const s=await setup(t,correctedMetadata());await s.fire(leaseTimer(s));s.events.pagehide();
 await flush();assert.equal(s.requests[0].options.signal.aborted,true);assert.equal(s.activeTimers().length,0);assert.equal(s.requests.length,1);
 const waiting=s.protocol.recoverIdentity();s.events.pageshow({persisted:true});assert.equal(s.requests.length,2);
 s.reply(1,200,{identity:correctedMetadata({generation:'e'.repeat(64)},1000)});await waiting;await flush();
 assert.equal(s.activeTimers().length,1);await s.fire(leaseTimer(s));assert.equal(s.requests[2].url,'/identity/renew');
});

test('F02 terminal closure suppresses pending context timeout and late renewal/document results',async t=>{
 const s=await timedOutRenewal(t);s.protocol.closeIdentityDocument();document.documentElement.dataset.visibility='departed';
 await s.fire(deadlineTimer(s,10000));s.reply(1,200,{identity:correctedMetadata({generation:'e'.repeat(64)})});s.reply(0,200,{identity:correctedMetadata()});await flush();
 assert.equal(document.documentElement.dataset.visibility,'departed');assert.equal(s.activeTimers().length,0);
 s.events.pageshow({persisted:true});await s.protocol.retryIdentityRecovery();assert.equal(s.requests.length,2);assert.equal(s.protocol.identityBlocked(),true);
});

test('F02 corrected backend with conservative bootstrap horizon renews once then resumes ordinary cadence',async t=>{
 const s=await setup(t,correctedMetadata({},1000));assert.equal(leaseTimer(s).delay,700);await s.fire(leaseTimer(s));
 const fresh=correctedMetadata({},60000);s.reply(0,200,{identity:fresh});await flush();
 assert.equal(s.activeTimers().length,1);assert.equal(leaseTimer(s).delay,42000);assert.equal(s.requests.length,1);
 s.protocol.attachIdentityPage({props:{identity:correctedMetadata({},1000)}});s.protocol.resumeIdentityRenewal();
 assert.equal(s.activeTimers().length,1);assert.equal(leaseTimer(s).delay,42000,'Remembered conservative metadata cannot restart or shorten the observed lease');
});


test('F02 a current mismatched renewal result is discarded and owns one bounded recovery',async t=>{
 const s=await setup(t,correctedMetadata()),original=s.protocol.captureIdentity();await s.fire(leaseTimer(s));
 s.reply(0,200,{identity:correctedMetadata({principal:2,epoch:2})});await flush();
 assert.strictEqual(s.protocol.captureIdentity(),original);assert.equal(s.requests[1].url,'/identity/context');
 assert.equal(s.protocol.identityBlocked(),true);s.reply(1,200,{identity:correctedMetadata({principal:2,epoch:2})});await flush();
 assert.deepEqual(s.replaced,['http://localhost/profile']);assert.equal(s.requests.length,2);assert.equal(s.activeTimers().length,0);
});
