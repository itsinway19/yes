import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const source=await readFile('public/brand/theme.js','utf8');
function page({preference=null,systemDark=false,denied=false}={}) {
 const handlers={},windowHandlers={},classes=new Set(),attrs={};
 const label={textContent:''};const button={dataset:{lightLabel:'الوضع النهاري',darkLabel:'الوضع الليلي'},setAttribute(k,v){attrs[k]=v;},querySelector(){return label;}};
 const media={matches:systemDark,addEventListener(_key,fn){handlers.media=fn;}};
 const store=new Map();if(preference!==null)store.set('builderad.theme',preference);
 const root={dataset:{},classList:{toggle(name,on){on?classes.add(name):classes.delete(name);}}};
 const document={documentElement:root,querySelectorAll(){return [button];},addEventListener(key,fn){handlers[key]=fn;}};
 const window={matchMedia(){return media;},addEventListener(key,fn){windowHandlers[key]=fn;}};
 const localStorage={getItem(key){if(denied)throw Error('denied');return store.get(key)??null;},setItem(key,value){if(denied)throw Error('denied');store.set(key,value);}};
 vm.runInNewContext(source,{window,document,localStorage});
 return {root,classes,attrs,label,button,media,store,click(){handlers.click({target:{closest(){return button;}}});},system(value){media.matches=value;handlers.media();},storage(value){windowHandlers.storage({key:'builderad.theme',newValue:value});}};
}
test('theme follows system until explicit choice, persists choice and preserves readable Arabic controls',()=>{
 const p=page({systemDark:true});assert.equal(p.root.dataset.theme,'dark');assert.ok(p.classes.has('dark'));assert.equal(p.attrs['aria-pressed'],'true');assert.equal(p.label.textContent,'الوضع النهاري');
 p.click();assert.equal(p.root.dataset.theme,'light');assert.equal(p.store.get('builderad.theme'),'light');assert.equal(p.attrs['aria-pressed'],'false');assert.equal(p.label.textContent,'الوضع الليلي');p.system(true);assert.equal(p.root.dataset.theme,'light');
});
test('theme rejects unsupported stored values and still operates with storage denied',()=>{
 for(const preference of ['<script>','invalid','system',null])assert.equal(page({preference,systemDark:true}).root.dataset.theme,'dark');
 const p=page({denied:true});assert.equal(p.root.dataset.theme,'light');p.click();assert.equal(p.root.dataset.theme,'dark');
});
test('theme synchronizes windows, changes labels with locale and reserves a visible logo in both shells',async()=>{
 const p=page({preference:'light'});p.storage('dark');assert.equal(p.root.dataset.theme,'dark');p.button.dataset.lightLabel='Light mode';p.storage('dark');assert.equal(p.label.textContent,'Light mode');
 const logo=await readFile('resources/js/Components/BrandLogo.vue','utf8');assert.match(logo,/aria-label="BuilderAd"/);assert.match(logo,/width="36" height="36"/);
 const main=await readFile('resources/js/spa/main.ts','utf8');assert.match(main,/h\(BrandLogo\)/);assert.match(main,/h\(ThemeToggle\)/);
 for(const path of ['resources/views/spa.blade.php','resources/views/welcome.blade.php','resources/views/maintenance.blade.php']){
  const html=await readFile(path,'utf8');assert.ok(html.indexOf('/brand/theme.js')<html.indexOf('</head>'));assert.match(html,/brand\/theme.css/);
 }
});
import * as Vue from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

let editorSequence = 0;
// Compile the real editor. Only transport/lifecycle ports are substituted; no editor logic is copied.
async function maintenanceEditor({ readback = 'same' } = {}) {
 const require = createRequire(import.meta.url);
 const vueUrl = pathToFileURL(path.join(path.dirname(require.resolve('vue/package.json')), 'index.mjs')).href;
 const url = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
 const initial = { active:false, revision:0, message_ar:'رسالة أ', message_en:'Message A', retry_after:60, changed_at:null };
 const newer = { ...initial, active:true, revision:2, message_ar:'رسالة ب', message_en:'Message B', retry_after:120 };
 const runtime = { platform:Vue.shallowRef({maintenance:{...initial},history:[]}), writes:[], readback, newer };
 globalThis.__maintenanceEditorTest = runtime;
 globalThis.window = { addEventListener(){}, removeEventListener(){} };
 globalThis.document = { title:'', getElementById(){return null;} };
 const state = url(`// isolated editor ${++editorSequence}
const r=globalThis.__maintenanceEditorTest;export const platform=r.platform;export async function sendPlatform(body){r.writes.push(structuredClone(body));r.receipt={active:body.active,revision:body.revision+1,message_ar:body.message_ar,message_en:body.message_en,retry_after:body.retry_after,changed_at:null};return {saved:true,command_id:body.command_id,maintenance:r.receipt};}export async function readPlatform(){if(r.readback==='failed')return;const value={maintenance:r.readback==='newer'?r.newer:r.receipt,history:[]};r.platform.value=value;return value;}`);
 const ports = {
  vue:vueUrl, 'vue-router':url('export const onBeforeRouteLeave=()=>{};'),
  '../spa/locale':url(`import {ref} from ${JSON.stringify(vueUrl)};export const locale=ref('en');`),
  '../spa/transport':url('export const close=()=>{},onClose=()=>new Set(),owned=()=>true,stamp=()=>0;export class TransportError extends Error{}'),
  '../spa/presentation':url('export const confirmDeparture={value:()=>true};'),
  '../internal-operations/OperationsShell.vue':url('export default {};'), '../Components/Modal.vue':url('export default {};'), './state':state,
 };
 const descriptor = parse(await readFile('resources/js/platform-maintenance/MaintenanceView.vue','utf8')).descriptor;
 let code = ts.transpileModule(compileScript(descriptor,{id:'maintenance-regression'}).content,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
 for(const [name,target] of Object.entries(ports))code=code.replaceAll(`from '${name}'`,'from '+JSON.stringify(target)).replaceAll(`from "${name}"`,'from '+JSON.stringify(target));
 const component=(await import(url(code))).default;component.render=()=>null;
 const renderer=Vue.createRenderer({createElement:()=>({}),createText:()=>({}),createComment:()=>({}),setText(){},setElementText(){},parentNode(){return null;},nextSibling(){return null;},insert(){},remove(){},patchProp(){}});
 const app=renderer.createApp(component);app.mount({});
 return {editor:app._instance.setupState,runtime,initial,newer,close(){app.unmount();delete globalThis.__maintenanceEditorTest;delete globalThis.window;delete globalThis.document;}};
}

test('acknowledged A commit followed by B commit/readback never promotes an old draft to B revision',async()=>{
 const h=await maintenanceEditor({readback:'newer'});try{
  const {editor:e,runtime:r,newer}=h;e.reason='Operator A';await e.save();
  assert.equal(r.platform.value.maintenance.revision,2);
  assert.equal(e.revision,1,'draft must retain the acknowledged revision until explicit adoption');
  assert.equal(e.locked,true);assert.equal(e.messageEn,'Message A');assert.equal(e.retry,60);
  e.reason='Attempt without adoption';await e.save();assert.equal(r.writes.length,1);
  await e.review();e.adopt();assert.equal(e.revision,2);assert.equal(e.messageAr,newer.message_ar);assert.equal(e.messageEn,newer.message_en);assert.equal(e.retry,120);
  e.messageEn='Unsaved edit';e.retry=90;e.discard();
  assert.equal(e.revision,2);assert.equal(e.messageEn,newer.message_en);assert.equal(e.retry,120,'discard restores the adopted snapshot, not the original page');
  e.reason='Explicit next command';await e.save();assert.equal(r.writes.length,2);assert.equal(r.writes[1].revision,2);assert.equal(r.writes[1].message_en,newer.message_en);assert.equal(r.writes[1].retry_after,120);
 }finally{h.close();}
});

test('a durable acknowledgement with unavailable current read remains saved but requires read/adoption, never replay',async()=>{
 const h=await maintenanceEditor({readback:'failed'});try{
  const {editor:e,runtime:r}=h;e.reason='Acknowledged command';await e.save();
  assert.equal(e.outcome,'saved-unverified');assert.equal(e.locked,true);assert.equal(e.revision,1);assert.equal(r.writes.length,1);
  e.reason='Not a retry';await e.save();assert.equal(r.writes.length,1);
  await e.review();assert.equal(e.reviewed,false);assert.ok(e.error);
  r.readback='newer';await e.review();assert.equal(e.reviewed,true);r.readback='failed';await e.review();assert.equal(e.reviewed,false,'failed refresh must not reuse an earlier adoption permission');e.adopt();assert.equal(e.revision,1);
  r.readback='newer';await e.review();e.adopt();assert.equal(e.revision,2);assert.equal(e.messageEn,'Message B');assert.equal(e.retry,120);assert.equal(r.writes.length,1);
 }finally{h.close();}
});

test('same-revision readback retains a coherent acknowledged snapshot including normalized messages',async()=>{
 const h=await maintenanceEditor();try{
  const {editor:e,runtime:r}=h;e.messageEn='  Normalized message  ';e.reason='First command';await e.save();
  assert.equal(e.outcome,'saved');assert.equal(e.locked,false);assert.equal(e.revision,1);assert.equal(e.messageEn,'Normalized message');
  e.messageEn='Edited';e.retry=90;e.discard();assert.equal(e.messageEn,'Normalized message');assert.equal(e.retry,60);assert.equal(e.revision,1);assert.equal(r.writes.length,1);
 }finally{h.close();}
});
