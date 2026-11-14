import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'a'};export const stamp=()=>context.scope;export const owned=v=>v===context.scope;export class TransportError extends Error{constructor(status,body={}){super('Rejected');this.status=status;this.body=body;}}`);
const draftsUrl = moduleUrl('export const internalTeamDrafts=new Map();export const key=(b,s)=>JSON.stringify([b,s]);');
const apiUrl = moduleUrl(`export const reads=[],writes=[],readPending=[],writePending=[];export const internalTeamSurface=(...args)=>{reads.push(structuredClone(args));return new Promise((resolve,reject)=>readPending.push({resolve,reject}));};export const writeInternalTeam=(...args)=>{writes.push(structuredClone(args));return new Promise((resolve,reject)=>writePending.push({resolve,reject}));};`);
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const {descriptor}=parse(await readFile('resources/js/business-foundation/BusinessFileCustody.vue','utf8'));
let source=ts.transpileModule(compileScript(descriptor,{id:'file-custody',inlineTemplate:true}).content,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ES2022}}).outputText;
for(const [name,url] of Object.entries({vue,'../spa/transport':transportUrl,'../spa/locale':localeUrl,'./drafts':draftsUrl,'./api':apiUrl}))source=source.replaceAll(`from '${name}'`,`from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`,`from ${JSON.stringify(url)}`);
const Custody=(await import(moduleUrl(source))).default;
const api=await import(apiUrl),drafts=await import(draftsUrl),transport=await import(transportUrl),{locale}=await import(localeUrl);
const business='11111111-1111-7111-8111-111111111111',employee='22222222-2222-7222-8222-222222222222';
const selection={realm_kind:'direct',requirements:[{requirement_kind:'business',leaf_grant_id:51}]};
const employeeRow={public_id:employee,name:'Named Employee',email:'employee@example.test'};
const projection=(custodians=[],allowed=true)=>({business_public_id:business,can_admin:allowed,can_appoint:allowed,creator_basis:true,teams:[],custodians,versions:{authority_epoch:7}});
globalThis.Document ??= class Document {};
globalThis.ShadowRoot ??= class ShadowRoot {};
function node(tag, text = '') { return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, style: {}, children: [], parent: null,
    get options() { return this.children.filter(child => child.tag === 'option'); }, addEventListener() {}, removeEventListener() {}, getRootNode() { return {}; } }); }
const renderer = createRenderer({
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText(target, text) { target.text = text; }, setElementText(target, text) { target.text = text; target.children = []; },
    parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    insert(target, parent, anchor) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(target); else parent.children.splice(index, 0, target); },
    remove(target) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = null; },
    patchProp(target, key, _old, value) { target.props[key] = value; },
});
const all = root => [root, ...root.children.flatMap(all)];
const find = (root, key) => all(root).find(target => key in target.props);
const text = root => root.text + root.children.map(text).join('');
async function flush() { await nextTick(); await nextTick(); }
function mount(reset=true){
 if(reset){drafts.internalTeamDrafts.clear();for(const rows of [api.reads,api.writes,api.readPending,api.writePending])rows.length=0;transport.context.scope='a';locale.value='en';}
 const selected=ref({business:{business_public_id:business},authority_selection:structuredClone(selection)}),active=ref(true),refreshing=ref(false),members=ref([employeeRow]),refreshes=[],pendingEvents=[],admins=[],root=node('root');
 const app=renderer.createApp({render:()=>h(Custody,{surface:selected.value,active:active.value,refreshing:refreshing.value,members:members.value,onRefresh:()=>refreshes.push(true),onPendingChange:v=>pendingEvents.push(v),onAdministration:v=>admins.push(v)})});app.mount(root);
 const draft=drafts.internalTeamDrafts.get(drafts.key(business,selection)+':business-file-custody:2');
 return{root,app,draft,active,refreshing,selected,members,refreshes,pendingEvents,admins,async load(value=projection()){api.readPending.shift().resolve(value);await flush();},async model(key,value){find(root,key).props['onUpdate:modelValue'](value);await flush();},send:()=>find(root,'data-custody-save').props.onClick()};
}
const receipt=body=>({business_public_id:business,recipient_public_id:body.recipient_public_id,state:'active',grant_refs:{},versions:{authority_epoch:8}});
test('named appointment requires confirmation and submits exact direct Business authority and epoch once',async()=>{
 const v=mount();try{await v.load();assert.deepEqual(api.reads[0],[business,51]);assert.match(text(v.root),/Activity custody continuity/);assert.match(text(v.root),/Named Employee/);assert.equal(all(v.root).some(n=>n.tag==='input'&&n.props.type!=='checkbox'),false);
 await v.model('data-custody-recipient',employee);await v.send();assert.equal(api.writes.length,0);await v.model('data-custody-confirm',true);assert.match(text(find(v.root,'data-custody-confirm').parent),/Named Employee/);const sending=v.send();v.send();assert.equal(api.writes.length,1);const body=api.writes[0][1];assert.equal(body.effect,'appoint');assert.equal(Object.hasOwn(body,'workspace_public_id'),false);assert.equal(body.recipient_public_id,employee);assert.equal(body.expected_revision,7);assert.deepEqual(body.authority_selection,selection);assert.match(body.command_id,/^[0-9a-f-]{36}$/);assert.equal(v.pendingEvents.at(-1),true);
 api.writePending.shift().resolve(receipt(body));await sending;await flush();assert.equal(v.draft.pending,undefined);assert.equal(v.refreshes.length,1);assert.equal(v.pendingEvents.at(-1),false);assert.equal(api.reads.length,2,'Success requests authoritative readback');await v.load(projection([{...employeeRow,qualification_ref:'qualified',status:'recorded',grant_refs:{}}]));assert.match(text(v.root),/employee@example.test/);
 locale.value='ar';await flush();assert.match(text(v.root),/\u0627\u0633\u062a\u0645\u0631\u0627\u0631\u064a\u0629/);
 }finally{v.app.unmount();}
});
test('custodian removal uses the named recorded custodian even after ordinary employee permissions disappear',async()=>{
 const v=mount();try{v.members.value=[];await v.load(projection([{...employeeRow,qualification_ref:'qualified',status:'recorded',grant_refs:{}}]));find(v.root,'data-custody-remove').props.onClick();await flush();await v.model('data-custody-confirm',true);const sending=v.send();assert.equal(api.writes[0][1].effect,'remove');assert.equal(api.writes[0][1].recipient_public_id,employee);api.writePending.shift().reject(Error('lost'));await sending;assert.ok(v.draft.pending);}finally{v.app.unmount();}
});
test('unknown delivery retains the exact command across remount and requires a fresh read before explicit recovery',async()=>{
 let v=mount();await v.load();await v.model('data-custody-recipient',employee);await v.model('data-custody-confirm',true);let sending=v.send();const original=structuredClone(api.writes[0]);api.writePending.shift().reject(Error('lost'));await sending;assert.ok(find(v.root,'data-custody-pending'));v.app.unmount();v=mount(false);try{assert.equal(api.writes.length,1);await v.send();assert.equal(api.writes.length,1);await v.load();sending=v.send();assert.deepEqual(api.writes[1],original);api.writePending.shift().reject(new transport.TransportError(422));await sending;assert.deepEqual(v.draft.pending,original[1],'An uncertain retry rejection does not disprove the original effect');}finally{v.app.unmount();}
});
test('late reads and acknowledgements cannot settle an inactive or changed authority context',async()=>{
 const v=mount();try{v.active.value=false;await flush();await v.load();assert.equal(find(v.root,'data-custody-recipient'),undefined);v.active.value=true;await flush();await v.load();await v.model('data-custody-recipient',employee);await v.model('data-custody-confirm',true);const sending=v.send();v.refreshing.value=true;await flush();api.writePending.shift().resolve(receipt(api.writes[0][1]));await sending;assert.ok(v.draft.pending);assert.equal(v.refreshes.length,0);assert.equal(find(v.root,'data-custody-recipient'),undefined);}finally{v.app.unmount();}
});
test('unavailable and ordinary employee surfaces do not imply an empty custodians list or permit writes',async()=>{
 const v=mount();try{api.readPending.shift().reject(Error('offline'));await flush();assert.ok(find(v.root,'data-custody-unavailable'));assert.equal(find(v.root,'data-custody-empty'),undefined);find(v.root,'data-custody-refresh').props.onClick();await v.load(projection([],false));assert.equal(find(v.root,'data-custody-recipient'),undefined);assert.equal(api.writes.length,0);assert.equal(v.admins.at(-1),false);}finally{v.app.unmount();}
});
test('initial rejection clears only a known non-effect while 403 conceals fresh authority',async()=>{
 const v=mount();try{await v.load();await v.model('data-custody-recipient',employee);await v.model('data-custody-confirm',true);const sending=v.send();api.writePending.shift().reject(new transport.TransportError(403));await sending;assert.equal(v.draft.pending,undefined);assert.ok(find(v.root,'data-custody-unavailable'));assert.equal(v.admins.at(-1),false);}finally{v.app.unmount();}
});

test('a reply from an earlier activation and a mismatched receipt keep the original unresolved', async () => {
 const v=mount();try{await v.load();await v.model('data-custody-recipient',employee);await v.model('data-custody-confirm',true);let sending=v.send();const original=structuredClone(api.writes[0]);v.active.value=false;await flush();v.active.value=true;await flush();await v.load();api.writePending.shift().resolve(receipt(original[1]));await sending;assert.deepEqual(v.draft.pending,original[1]);assert.equal(v.refreshes.length,0);sending=v.send();api.writePending.shift().resolve({...receipt(original[1]),business_public_id:'foreign'});await sending;assert.deepEqual(v.draft.pending,original[1]);assert.equal(v.refreshes.length,0);}finally{v.app.unmount();}
});
