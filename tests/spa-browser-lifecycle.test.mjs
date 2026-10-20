import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { ref } from 'vue';
import ts from 'typescript';
const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const deferred = () => {let resolve;const promise=new Promise(yes=>resolve=yes);return {promise,resolve}};
async function fixture() {
    const previous={document:globalThis.document,addEventListener:globalThis.addEventListener,removeEventListener:globalThis.removeEventListener,BroadcastChannel:globalThis.BroadcastChannel};
    const events=new EventTarget(),document=new EventTarget();document.hidden=false;globalThis.document=document;
    globalThis.addEventListener=events.addEventListener.bind(events);globalThis.removeEventListener=events.removeEventListener.bind(events);
    const key='browserLifecycle'+crypto.randomUUID().replaceAll('-',''),state={documentStatus:ref('active'),concealed:false,reads:[],publications:0,failures:0,cancelled:0};globalThis[key]=state;
    globalThis.BroadcastChannel=class {constructor(){state.channel=this}close(){this.closed=true}};
    const transport=url(`export const documentStatus=globalThis.${key}.documentStatus,internalBoundary=false;`);
    const presentation=url(`const state=globalThis.${key};export const conceal=()=>state.concealed=true;export const failed=()=>state.failures++;export async function prepare(route,passive,signal){state.reads.push({route,passive,signal});await state.wait.promise;return ()=>{};}export function commitRoute(route,publish){publish();state.concealed=false;state.publications++;}`);
    let source=ts.transpileModule(await readFile('resources/js/spa/browserLifecycle.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
    source=source.replace("from './transport'",'from '+JSON.stringify(transport)).replace("from './presentation'",'from '+JSON.stringify(presentation));
    const module=await import(url(source)),route={path:'/profile'};state.wait=deferred();
    const lifecycle=module.installBrowserLifecycle({currentRoute:ref(route),cancelCandidate(){state.cancelled++},beforeEach(guard){state.navigate=guard;return ()=>{state.navigate=undefined}}});
    return {lifecycle,state,events,document,dispose(){lifecycle.dispose();Object.assign(globalThis,previous);delete globalThis[key]}};
}
test('overlapping visible restoration events share one passive preparation and dispose removes listeners',async()=>{
    const f=await fixture();try{
        const first=f.lifecycle.resume(),second=f.lifecycle.resume();assert.equal(first,second);
        f.document.dispatchEvent(new Event('visibilitychange'));const shown=new Event('pageshow');shown.persisted=true;f.events.dispatchEvent(shown);
        await Promise.resolve();assert.equal(f.state.reads.length,1);assert.equal(f.state.reads[0].passive,true);
        f.state.wait.resolve();await first;assert.equal(f.state.publications,1);
        f.lifecycle.dispose();f.document.dispatchEvent(new Event('visibilitychange'));assert.equal(f.state.reads.length,1);
    }finally{f.dispose()}
});
test('hidden hints conceal immediately, defer reads, and repeated identical hints do not compete',async()=>{
    const f=await fixture();try{
        f.document.hidden=true;f.state.channel.onmessage({data:{boundary:'advertiser',epoch:2}});await Promise.resolve();
        assert.equal(f.state.concealed,true);assert.equal(f.state.reads.length,0);
        f.document.hidden=false;const pending=f.lifecycle.resume();await Promise.resolve();
        f.state.channel.onmessage({data:{boundary:'advertiser',epoch:2}});await Promise.resolve();assert.equal(f.state.reads.length,1);
        f.state.wait.resolve();await pending;assert.equal(f.state.publications,1);
    }finally{f.dispose()}
});
test('a hint invalidates a pre-hint witness; hidden or closed documents cannot publish its late result',async()=>{
    const f=await fixture();try{
        const initial=f.lifecycle.resume();await Promise.resolve();const old=f.state.reads[0];
        f.state.channel.onmessage({data:{boundary:'advertiser',epoch:3}});await Promise.resolve();assert.equal(old.signal.aborted,true);assert.equal(f.state.reads.length,2);
        f.document.hidden=true;f.document.dispatchEvent(new Event('visibilitychange'));f.state.wait.resolve();await initial;await Promise.resolve();
        assert.equal(f.state.publications,0);assert.equal(f.state.concealed,true);
        f.document.hidden=false;f.state.documentStatus.value='closed';await f.lifecycle.resume();assert.equal(f.state.reads.length,2);
    }finally{f.dispose()}
});

test('ordinary navigation cancels a pending restoration before it can publish source data',async()=>{
    const f=await fixture();try{
        const restoration=f.lifecycle.resume();await Promise.resolve();
        f.state.navigate();assert.equal(f.state.reads[0].signal.aborted,true);
        f.state.wait.resolve();await restoration;assert.equal(f.state.publications,0);
        f.lifecycle.dispose();assert.equal(f.state.navigate,undefined);
    }finally{f.dispose()}
});
