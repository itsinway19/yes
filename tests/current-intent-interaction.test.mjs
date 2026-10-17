import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h } from 'vue';

const state = globalThis.__intentTest = { page: reactive({ locale: 'ar' }), posts: [], forms: [], visits: [] };
globalThis.route = (name, id) => name + '/' + (id ?? '');
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const inertia = moduleUrl(`import { reactive } from ${JSON.stringify(import.meta.resolve('vue'))};
export const usePage = () => ({ props: globalThis.__intentTest.page });
export const Head = { render: () => null };
export const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
export const router = { visit: url => globalThis.__intentTest.visits.push(url), reload: options => globalThis.__intentTest.visits.push(options),
 post: (url, payload, options) => globalThis.__intentTest.posts.push({ url, payload, options }) };
export const useForm = input => {
 const keys = Object.keys(input); const form = reactive({ ...input, errors: {}, processing: false, recentlySuccessful: false,
 post(url, options) { globalThis.__intentTest.posts.push({ url, payload: JSON.parse(JSON.stringify(Object.fromEntries(keys.map(k => [k, form[k]])))), options }); }
 }); globalThis.__intentTest.forms.push(form); return form;
};
export const useBusinessForm=(input,kind)=>{const form=useForm(input);form.submit=(workspace,options)=>form.post('workspaces.business.'+kind+'/'+workspace,options);return form;};
export const restore=()=>undefined,remember=()=>{},stamp=()=>0,owned=()=>true;
export const refresh=(workspace,options)=>router.reload(options),saveIntent=(workspace,payload,options)=>router.post('workspaces.business.current-intent.update/'+workspace,payload,options);`);
const replacements = { vue: import.meta.resolve('vue'), '@inertiajs/vue3': inertia };
function rewrite(code, extra = {}) {
    for (const [from, to] of Object.entries({ ...replacements, ...extra })) {
        code = code.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`);
    }
    return code;
}
const messages = moduleUrl(ts.transpileModule(await readFile(new URL('../resources/js/localization/messages.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText);
const neutralCopy = moduleUrl(rewrite(ts.transpileModule(await readFile(new URL('../resources/js/localization/businessCopy.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText, { './messages': messages }));
const copy = moduleUrl(rewrite(ts.transpileModule(await readFile(new URL('../resources/js/localization/business.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText, { './businessCopy': neutralCopy }));
async function compile(path, extra = {}) {
    const { descriptor } = parse(await readFile(new URL('../resources/js/' + path, import.meta.url), 'utf8'));
    const source = compileScript(descriptor, { id: 'dtm-test', inlineTemplate: true }).content;
    const url = moduleUrl(rewrite(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText,
        { '@/localization/business': copy, '@/localization/businessCopy': neutralCopy, '@/spa/locale': moduleUrl(`import {computed} from ${JSON.stringify(import.meta.resolve('vue'))};export const locale=computed(()=>globalThis.__intentTest.page.locale);`), '@/business-context/commands': inertia, './commands': inertia, '@/business-context/drafts': inertia, './drafts': inertia, '@/spa/transport': inertia, ...extra }));
    return { url, component: (await import(url)).default };
}
const editor = await compile('Components/Business/CurrentIntentEditor.vue');
const markets = await compile('Components/Business/TargetMarketsEditor.vue');
const layout = moduleUrl(`export default { template: '<main><slot /></main>' };`);
const context = await compile('business-context/ContextForm.vue', { '@/Layouts/AdvertiserLayout.vue': layout,
    '@/Components/Business/TargetMarketsEditor.vue': markets.url, '@/Components/Business/CurrentIntentEditor.vue': editor.url });
const languageSwitcher = await compile('Components/LanguageSwitcher.vue', {
    '@/localization/messages': messages, 'vue-i18n': moduleUrl('export const useI18n = () => ({ t: key => key });'),
});
function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, value: '', checked: false, multiple: false,
        get options() { return this.children.filter(child => child.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(option => option.selected); },
        set selectedIndex(index) { this.options.forEach((option, position) => { option.selected = index === position; }); },
        getRootNode() { return {}; }, addEventListener(type, fn) { this.listeners[type] = fn; } };
}
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, old, value) { node.props[key] = value; if (key === 'value') node.value = node._value = value; if (key === 'checked') node.checked = value; },
});
const empty = { state: 'NOT_DECLARED', value: null, version: 7 };
function mount(overrides = {}, component = editor.component) {
 state.posts = []; state.forms = []; state.visits = []; state.page.locale = 'en';
 const props = reactive(component === editor.component ? { workspaceId: 'business', currentIntent: empty, canManage: true, timezoneOptions: ['UTC','Europe/Istanbul','Etc/GMT+3'], ...overrides } : {timezoneOptions:['UTC','Europe/Istanbul'], ...overrides});
 const root = element('root'); const app = renderer.createApp({ render: () => h(component, props) });
 app.config.globalProperties.route = globalThis.route; app.mount(root);
 return { root, props, stop: () => app.unmount() };
}
const nodes = (root, predicate) => [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = root => root.text + root.children.map(text).join('');
const find = (view, key) => { const node = nodes(view.root, n => n.props['data-intent'] === key)[0]; assert.ok(node, 'Expected '+key); return node; };
async function click(view,key) { find(view,key).props.onClick(); await nextTick(); }
async function input(view,key,value) { const n=find(view,key); n.value=value; if(n.tag==='select') n.options.forEach(o=>o.selected=o.value===value); n.listeners[n.tag==='select'?'change':'input']({target:n}); await nextTick(); }
async function check(view,key,value=true) { const n=find(view,key); n.checked=value; n.listeners.change({target:n}); await nextTick(); }
const submit=view=>find(view,'form').props.onSubmit({preventDefault(){}});
test('optional summary has no inferred direction; confirmation and business-wide scope are explicit',async()=>{
 const v=mount(); assert.match(text(v.root),/Not declared/); await click(v,'edit');
 assert.equal(find(v,'direction').value,''); submit(v); assert.equal(state.posts.length,0);
 await input(v,'direction','preserve_value'); await input(v,'meaning','Completed bookings');
 submit(v); assert.equal(state.posts.length,0); await check(v,'scope'); await check(v,'outcome');
 await check(v,'declaration'); submit(v); assert.equal(state.posts.length,1,text(v.root));
 assert.equal(state.posts[0].payload.value.direction,'preserve_value'); assert.equal(state.posts[0].payload.value.scope,'business_wide');
 assert.equal(state.posts[0].payload.value.quantity,null); assert.equal(state.posts[0].payload.value.horizon,null); v.stop();
});
const saved = (overrides={}) => ({direction:'increase_value',scope:'business_wide',description:null,outcome:{count_unit:'buyers',meaning:'Completed bookings',clarification:null},quantity:'10',horizon:{deadline_date:'2020-01-01',timezone:'Europe/Istanbul'},...overrides});
const present=(value=saved(),version=7)=>({state:'PRESENT',value,version});
async function confirm(v) {
 await check(v,'scope');
 if(nodes(v.root,n=>n.props['data-intent']==='outcome').length) await check(v,'outcome');
 if(nodes(v.root,n=>n.props['data-intent']==='quantityConfirm').length) await check(v,'quantityConfirm');
 if(nodes(v.root,n=>n.props['data-intent']==='horizonConfirm').length) await check(v,'horizonConfirm');
 await check(v,'declaration');
}
async function valid(v) { await click(v,'edit'); await input(v,'direction','preserve_value'); await input(v,'meaning','Completed bookings'); await confirm(v); }
test('unchanged full outcome does not require a new confirmation regardless of JSON key order',async()=>{
 const v=mount({currentIntent:present()}); await click(v,'edit'); await input(v,'direction','preserve_value');
 assert.equal(nodes(v.root,n=>n.props['data-intent']==='outcome').length,0);
 await confirm(v); submit(v); assert.equal(state.posts[0].payload.confirmations.outcome,false); v.stop();
});
test('qualifier is progressive and required only when advertiser marks it relevant; editing invalidates final confirmation',async()=>{
 const v=mount(); await valid(v); await check(v,'needsClarification'); assert.equal(find(v,'declaration').checked,false);
 await confirm(v); submit(v); assert.equal(state.posts.length,0);
 await input(v,'clarification','Qualified for the requested service'); await confirm(v); submit(v);
 assert.equal(state.posts[0].payload.confirmations.clarification_required,true);
 assert.equal(state.posts[0].payload.value.outcome.clarification,'Qualified for the requested service'); v.stop();
});
test('quantity linked to changed outcome requires reconfirmation; exact 120 digits survive and 121 do not truncate',async()=>{
 const v=mount({currentIntent:present()}); await click(v,'edit'); await input(v,'meaning','Qualified buyers');
 assert.equal(find(v,'quantityConfirm').checked,false); await input(v,'quantity','9'.repeat(120)); await confirm(v); submit(v);
 assert.equal(state.posts[0].payload.value.quantity,'9'.repeat(120)); state.posts[0].options.onFinish();
 await input(v,'quantity','9'.repeat(121)); await confirm(v); submit(v); assert.equal(state.posts.length,1);
 assert.equal(find(v,'quantity').value,'9'.repeat(121)); v.stop();
});
for(const [key,limit] of [['description',2000],['meaning',2000],['clarification',2000],['count_unit',120]]) {
 test('raw Unicode code point boundary '+key,async()=>{
  const v=mount(); await valid(v); if(key==='clarification') await check(v,'needsClarification');
  const max=('ع😀\n"\\').repeat(Math.ceil(limit/5)); const raw=Array.from(max).slice(0,limit).join('');
  await input(v,key,raw); await confirm(v); submit(v); assert.equal(state.posts.length,1);
  assert.equal(state.posts[0].payload.value[key==='description'?key:'outcome'] instanceof Object || key==='description',true);
  const actual=key==='description'?state.posts[0].payload.value.description:state.posts[0].payload.value.outcome[key];
  assert.equal(actual,raw); assert.equal(find(v,key).props.maxlength,undefined); state.posts[0].options.onFinish();
  await input(v,key,raw+'😀'); await confirm(v); submit(v); assert.equal(state.posts.length,1); assert.equal(find(v,key).value,raw+'😀'); v.stop();
 });
}
test('retained unsupported saved horizon is faithful during direction and quantity edits but cannot be newly selected',async()=>{
 const v=mount({currentIntent:present(),timezoneOptions:['UTC']}); assert.match(text(v.root),/2020-01-01.*Europe\/Istanbul/);
 await click(v,'edit'); assert.equal(find(v,'timezone').options.find(o=>o.value==='Europe/Istanbul').props.disabled!==undefined,true);
 await input(v,'direction','preserve_value'); await input(v,'quantity','11'); await confirm(v); submit(v);
 assert.deepEqual(state.posts[0].payload.value.horizon,{deadline_date:'2020-01-01',timezone:'Europe/Istanbul'});
 assert.equal(state.posts[0].payload.confirmations.horizon,false); state.posts[0].options.onFinish();
 await input(v,'deadline_date','2020-01-02'); await confirm(v); submit(v); assert.equal(state.posts.length,1);
 await input(v,'timezone','UTC'); await confirm(v); submit(v); assert.equal(state.posts.length,2); assert.equal(state.posts[1].payload.confirmations.horizon,true); v.stop();
});
test('no implicit timezone; deterministic calendar rejection; past concrete horizon allowed and removal explicit',async()=>{
 const v=mount(); await valid(v); await input(v,'deadline_date','2020-01-01'); assert.equal(find(v,'timezone').value,'');
 await confirm(v); submit(v); assert.equal(state.posts.length,0); await input(v,'timezone','UTC'); await confirm(v); submit(v); assert.equal(state.posts.length,1);
 state.posts[0].options.onFinish(); await input(v,'deadline_date','2025-02-29'); await confirm(v); submit(v); assert.equal(state.posts.length,1);
 await click(v,'removeHorizon'); await confirm(v); submit(v); assert.equal(state.posts[1].payload.value.horizon,null); v.stop();
});
test('reader gets faithful summary without controls; narrow scope cancellation does not post; clear explains immutable history',async()=>{
 let v=mount({currentIntent:present(),canManage:false}); assert.match(text(v.root),/Completed bookings/); assert.equal(nodes(v.root,n=>['input','button','form'].includes(n.tag)).length,0); v.stop();
 v=mount(); await click(v,'edit'); assert.match(text(v.root),/specific product, market or campaign/); await click(v,'cancel'); assert.equal(state.posts.length,0); v.stop();
 v=mount({currentIntent:present()}); await click(v,'clear'); assert.match(text(v.root),/history is retained/); submit(v); assert.equal(state.posts.length,0);
 await check(v,'declaration'); submit(v); assert.deepEqual(state.posts[0].payload,{command:'clear',value:null,confirmations:{declaration:true},version:7}); v.stop();
});
test('dirty props, locale and conflict reload retain draft and old revision until separate adoption and reconfirmation',async()=>{
 const v=mount(); await valid(v); v.props.currentIntent=present(saved({quantity:'99'}),8); state.page.locale='ar'; await nextTick();
 assert.equal(find(v,'meaning').value,'Completed bookings'); assert.equal(find(v,'declaration').checked,true); submit(v); assert.equal(state.posts[0].payload.version,7);
 state.posts[0].options.onError({version:'conflict'}); state.posts[0].options.onFinish(); await click(v,'review'); state.visits[0].onSuccess(); await nextTick();
 submit(v); assert.equal(state.posts[1].payload.version,7); state.posts[1].options.onFinish(); await click(v,'adopt');
 assert.equal(find(v,'declaration').checked,false); submit(v); assert.equal(state.posts.length,2); await confirm(v); submit(v); assert.equal(state.posts[2].payload.version,8); v.stop();
});
test('clean refresh adopts canonical bundle and revision together',async()=>{
 const v=mount({currentIntent:present()}); v.props.currentIntent=present(saved({description:'Later state',quantity:'20'}),9); await nextTick(); await click(v,'edit'); await confirm(v); submit(v);
 assert.equal(state.posts[0].payload.version,9); assert.equal(state.posts[0].payload.value.description,'Later state'); assert.equal(state.posts[0].payload.value.quantity,'20'); v.stop();
});
test('late success adopts redirect bundle, while edits during save retain original revision and complete draft',async()=>{
 let v=mount(); await valid(v); submit(v); v.props.currentIntent=present(saved({description:'Other winner'}),10); await nextTick();
 state.posts[0].options.onSuccess(); state.posts[0].options.onFinish(); await nextTick(); await click(v,'edit'); await confirm(v); submit(v);
 assert.equal(state.posts[1].payload.version,10); assert.equal(state.posts[1].payload.value.description,'Other winner'); v.stop();
 v=mount(); await valid(v); submit(v); await input(v,'description','Edited during save'); v.props.currentIntent=present(saved(),8); await nextTick();
 state.posts[0].options.onSuccess(); state.posts[0].options.onFinish(); await nextTick(); await confirm(v); submit(v);
 assert.equal(state.posts[1].payload.version,7); assert.equal(state.posts[1].payload.value.description,'Edited during save'); v.stop();
});
test('transport failure and oversized input retain complete draft without a retry',async()=>{
 const v=mount(); await valid(v); submit(v); state.posts[0].options.onFinish(); await nextTick(); assert.match(text(v.root),/entire draft is retained/);
 assert.equal(find(v,'meaning').value,'Completed bookings'); assert.equal(state.posts.length,1);
 await input(v,'description','😀'.repeat(21000)); await confirm(v); submit(v); assert.equal(state.posts.length,1); assert.equal(find(v,'description').value,'😀'.repeat(21000)); v.stop();
});
function contextProps() { return {workspace:{public_id:'business',name:'Saved name',business_type_id:1,business_context_version:7},types:[{id:1,key:'example',name_ar:'مثال',name_en:'Example',active:true}],canManage:true,creationKey:null,version:7,business:{targetMarketsDeclared:true,targetMarkets:[],version:7,canManage:true,currentIntent:empty},countryOptions:[{code:'SA',name_ar:'السعودية',name_en:'Saudi Arabia'}]}; }
async function market(v) { const n=nodes(v.root,n=>n.tag==='input'&&n.value==='SA')[0]; assert.ok(n); n.checked=true; n.listeners.change({target:n}); await nextTick(); }
test('Intent success never rebases or posts dirty name and markets; siblings remain independent',async()=>{
 const v=mount(contextProps(),context.component); const name=state.forms.find(f=>'name'in f), markets=state.forms.find(f=>'country_codes'in f);
 name.name='Unsaved name'; await market(v); await valid(v); submit(v); assert.equal(state.posts.length,1);
 v.props.version=8; v.props.workspace={...v.props.workspace,business_context_version:8}; v.props.business={...v.props.business,version:8,currentIntent:present(saved(),8)}; await nextTick();
 state.posts[0].options.onSuccess(); state.posts[0].options.onFinish(); await nextTick();
 assert.equal(name.name,'Unsaved name'); assert.equal(name.version,7); assert.deepEqual([...markets.country_codes],['SA']); assert.equal(markets.version,7); v.stop();
});
test('name and market success do not rebase dirty Intent; locale preserves original draft and flags',async()=>{
 const v=mount(contextProps(),context.component); await valid(v); const name=state.forms.find(f=>'name'in f); name.name='Saved changed name';
 const nameNode=nodes(v.root,n=>n.tag==='form'&&n.props['data-intent']===undefined&&n.props['data-target-markets']===undefined)[0]; nameNode.props.onSubmit({preventDefault(){}});
 v.props.version=8; v.props.workspace={...v.props.workspace,name:'Saved changed name',business_context_version:8}; v.props.business={...v.props.business,version:8,currentIntent:{...empty,version:8}}; await nextTick();
 state.posts[0].options.onSuccess(); await nextTick(); await market(v);
 nodes(v.root,n=>n.tag==='form'&&n.props['data-target-markets']!==undefined)[0].props.onSubmit({preventDefault(){}});
 v.props.version=9; v.props.business={...v.props.business,version:9,currentIntent:{...empty,version:9}}; await nextTick(); state.posts[1].options.onSuccess(); state.page.locale='ar'; await nextTick();
 submit(v); assert.equal(state.posts[2].payload.version,7); assert.equal(state.posts[2].payload.confirmations.declaration,true); assert.equal(state.posts[2].payload.value.outcome.meaning,'Completed bookings'); v.stop();
});
test('creation flow has no Intent section until Workspace exists',()=>{
 const v=mount({...contextProps(),workspace:null,business:undefined,creationKey:'synthetic'},context.component);
 assert.equal(nodes(v.root,n=>n.props['data-current-intent']!==undefined).length,0); assert.equal(state.posts.length,0); v.stop();
});
test('technical 413 transport response is handled in place and never discards or retries the draft',async()=>{
 const v=mount(); await valid(v); submit(v); const options=state.posts[0].options;
 assert.equal(options.onHttpException({status:413}),false); options.onFinish(); await nextTick();
 assert.match(text(v.root),/technical safety limit/); assert.equal(find(v,'meaning').value,'Completed bookings'); assert.equal(state.posts.length,1); v.stop();
});
test('quantity and date lexical validation rejects final newline without clipping',async()=>{
 const v=mount({currentIntent:present()}); await click(v,'edit'); await input(v,'quantity','12\n'); await confirm(v); submit(v); assert.equal(state.posts.length,0);
 await input(v,'quantity','12'); await input(v,'deadline_date','2020-01-01\n'); await confirm(v); submit(v); assert.equal(state.posts.length,0); assert.equal(find(v,'deadline_date').value,'2020-01-01\n'); v.stop();
});
