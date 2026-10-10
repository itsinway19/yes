import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const routerModule = url('export const router={on:(n,fn)=>globalThis.__visibility.router[n]=fn,cancelAll(){},flushAll(){},clearHistory(){globalThis.__visibility.cleared++;}};');
const source = ts.transpileModule(await readFile(new URL('../resources/js/clientVisibility.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    .replace("from '@inertiajs/vue3'", `from ${JSON.stringify(routerModule)}`);
const { installClientVisibility } = await import(url(source));
const flush = async () => { for (let i=0;i<8;i++) await Promise.resolve(); };
function setup() {
    const state=globalThis.__visibility={router:{},window:{},document:{},requests:[],cleared:0,replaced:[],inert:false};
    globalThis.BroadcastChannel=undefined;
    globalThis.document={documentElement:{dataset:{}},visibilityState:'visible',title:'Private',
        getElementById:id=>id==='app'?{setAttribute(){state.inert=true;},removeAttribute(){state.inert=false;}}:null,
        addEventListener:(name,fn)=>state.document[name]=fn};
    globalThis.window={location:{origin:'http://localhost',pathname:'/a',search:'',href:'http://localhost/a',replace:url=>state.replaced.push(url)},crypto:{subtle:{}},
        addEventListener:(name,fn)=>state.window[name]=fn};
    globalThis.fetch=(url,options)=>new Promise((resolve,reject)=>state.requests.push({url,options,resolve,reject}));
    state.boundary=installClientVisibility();
    state.reply=(index,receipt,status=200)=>state.requests[index].resolve({ok:status===200,status,type:'basic',json:async()=>receipt});
    return state;
}
function page(path='/a',context='context-a') {return {url:path,encryptHistory:true,props:{clientVisibility:{protected:true,path,context}}};}

test('restored page stays masked until fresh evidence; routine return preserves the page',async()=>{
    const s=setup(),p=page();const waiting=s.boundary.authorize(p);
    assert.equal(document.documentElement.dataset.visibility,'checking');assert.equal(s.inert,true);
    s.reply(0,p.props.clientVisibility);await waiting;s.boundary.mounted(p);
    assert.equal(document.documentElement.dataset.visibility,'ready');assert.equal(s.cleared,0);
    s.window.pagehide();assert.equal(document.documentElement.dataset.visibility,'checking');
    s.window.pageshow({persisted:true});await flush();s.reply(1,p.props.clientVisibility);await flush();
    assert.equal(document.documentElement.dataset.visibility,'ready');assert.equal(s.cleared,0);
});
test('a late response from the previous workspace cannot unmask the new workspace',async()=>{
    const s=setup(),a=page(),b=page('/b','context-b');void s.boundary.authorize(a);
    window.location.pathname='/b';window.location.href='http://localhost/b';
    const second=s.boundary.authorize(b);s.reply(1,b.props.clientVisibility);await second;s.boundary.mounted(b);
    s.reply(0,a.props.clientVisibility);await flush();
    assert.equal(document.documentElement.dataset.visibility,'ready');assert.equal(s.cleared,0);assert.equal(s.requests[0].options.signal.aborted,true);
});
test('offline failure retains hidden state and retry requires fresh success',async()=>{
    const s=setup(),p=page();const waiting=s.boundary.authorize(p);s.requests[0].reject(new Error('offline'));await flush();
    assert.equal(document.documentElement.dataset.visibility,'unavailable');assert.equal(s.cleared,0);
    s.window.online();await flush();s.reply(1,p.props.clientVisibility);await waiting;s.boundary.mounted(p);
    assert.equal(document.documentElement.dataset.visibility,'ready');assert.equal(s.cleared,0);
});
test('server denial invalidates official history without revealing the restored component',async()=>{
    const s=setup(),p=page();void s.boundary.authorize(p);s.reply(0,{},403);await flush();
    assert.equal(document.documentElement.dataset.visibility,'checking');assert.equal(s.cleared,1);assert.deepEqual(s.replaced,['http://localhost/a']);
});
test('legacy entries lacking boundary evidence are never approved',async()=>{
    const s=setup();void s.boundary.authorize({url:'/a',props:{auth:{user:{id:1}}}});await flush();
    assert.equal(s.requests.length,0);assert.equal(s.cleared,1);assert.notEqual(document.documentElement.dataset.visibility,'ready');
});
