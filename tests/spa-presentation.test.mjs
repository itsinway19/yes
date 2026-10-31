import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { ref, nextTick, createRenderer, defineComponent, h, onBeforeUnmount } from 'vue';
import ts from 'typescript';
const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const compile = source => ts.transpileModule(source, {compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
async function fixture() {
    const key='presentation'+crypto.randomUUID().replaceAll('-','');
    const state={documentStatus:ref('active'),closures:[]};globalThis[key]=state;
    const transport=url(`const state=globalThis.${key};export const documentStatus=state.documentStatus;export const onClose=callback=>state.closures.push(callback);`);
    const source=compile(await readFile('resources/js/spa/presentation.ts','utf8')).replace("from 'vue'",'from '+JSON.stringify(import.meta.resolve('vue'))).replace("from './transport'",'from '+JSON.stringify(transport));
    return {presentation:await import(url(source)),state,dispose(){delete globalThis[key]}};
}
test('a prepared target cannot publish until commit; source readiness and data remain admitted',async()=>{
    const {presentation:p,state,dispose}=await fixture();let view='source';
    const route={meta:{presentation:{clear(){},load:async(_r,_passive,admission)=>p.publish(admission,()=>{view='target'})}}};
    const source={meta:{presentation:{clear(){}}}};p.commitRoute(source,()=>{});
    const publish=await p.prepare(route,false,new AbortController().signal);
    assert.equal(view,'source');assert.equal(p.admittedRoute.value,source);assert.equal(p.status.value,'ready');
    p.commitRoute(route,publish);assert.equal(view,'target');assert.equal(p.admittedRoute.value,route);
    state.documentStatus.value='closed';state.closures.forEach(fn=>fn());assert.equal(p.status.value,'closed');assert.equal(p.admittedRoute.value,undefined);dispose();
});
test('concealment is independent of projection readiness and a failed restoration cannot reopen it',async()=>{
    const {presentation:p,dispose}=await fixture();p.commitRoute({meta:{}},()=>{});p.conceal();
    p.status.value='ready';assert.equal(p.status.value,'checking');p.failed();assert.equal(p.status.value,'unavailable');assert.equal(p.concealed.value,true);dispose();
});
test('aborted preparation and terminal documents never publish destination fields',async()=>{
    const {presentation:p,state,dispose}=await fixture();let published=false;
    const c=new AbortController();c.abort();
    await assert.rejects(p.prepare({meta:{presentation:{load:async(_r,_p,a)=>p.publish(a,()=>published=true)}}},false,c.signal),/abort/i);
    state.documentStatus.value='closed';p.publish(undefined,()=>published=true);assert.equal(published,false);dispose();
});
test('actual shell preserves the mounted editor during concealment and distinguishes handoff from failure',async()=>{
    const source=await readFile('resources/js/spa/main.ts','utf8');
    const render=compile(source.slice(source.indexOf('const app = createApp('),source.indexOf('app.config.errorHandler'))+'\nreturn app;');
    const status=ref('ready'),documentStatus=ref('active'),route={params:{workspace:'workspace'}};
    const state={unmounts:0},root={children:[]};
    const view=defineComponent({setup(){const draft=ref('unsaved');onBeforeUnmount(()=>state.unmounts++);return()=>h('main',{'data-spa-private':true},draft.value)}});
    const renderer=createRenderer({createElement:type=>({type,children:[]}),createText:text=>({text}),createComment:text=>({text}),setText(n,t){n.text=t},setElementText(n,t){n.text=t;n.children=[]},parentNode:n=>n.parent,nextSibling:()=>null,insert(n,p){(p.children??=[]).push(n);n.parent=p},remove(n){if(n.parent)n.parent.children=n.parent.children.filter(c=>c!==n)},patchProp(){}});
    const ports={BrandLogo:{render:()=>h('a','BuilderAd')},ThemeToggle:{render:()=>h('button','Theme')},createApp:renderer.createApp,defineComponent,h,RouterView:view,RouterLink:view,status,documentStatus,admittedRoute:ref(route),navigating:ref(false),navigationError:ref(false),renderFailure:ref(false),locale:ref('ar'),localeBusy:ref(false),localeError:ref(false),bootstrap:{identity:{binding:{principal:0}}},internalBoundary:false,router:{currentRoute:ref(route)},copy:(ar,en)=>en,confirmDeparture:ref(()=>true),logout(){},switchLocale(){},location:{reload(){}},departure:ref({reason:'handoff',destination:'/workspaces'})};
    const app=new Function(...Object.keys(ports),render)(...Object.values(ports));app.mount(root);
    const text=n=>[n.text??'',...(n.children??[]).map(text)].join(' ');
    status.value='checking';await nextTick();assert.equal(state.unmounts,0);assert.match(text(root),/unsaved/);
    status.value='ready';await nextTick();assert.equal(state.unmounts,0);
    documentStatus.value='closed';status.value='closed';await nextTick();assert.equal(state.unmounts,1);assert.match(text(root),/Opening a new document/);assert.doesNotMatch(text(root),/could not be verified/);app.unmount();
});

test('document closure erases the actual shared query cache through one registered owner',async()=>{
    const key='queryClosure'+crypto.randomUUID().replaceAll('-','');globalThis[key]=[];
    const transport=url(`export const onClose=callback=>globalThis.${key}.push(callback);`);
    const source=compile(await readFile('resources/js/spa/query.ts','utf8'))
        .replace("from './transport'",'from '+JSON.stringify(transport))
        .replace("from '@tanstack/vue-query'",'from '+JSON.stringify(import.meta.resolve('@tanstack/vue-query')));
    try {
        const {query}=await import(url(source));query.setQueryData(['private','workspace'],{secret:'private'});
        assert.equal(globalThis[key].length,1);globalThis[key][0]();
        assert.equal(query.getQueryCache().getAll().length,0);
    }finally{delete globalThis[key]}
});
