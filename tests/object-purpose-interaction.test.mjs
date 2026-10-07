import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h } from 'vue';

const state = globalThis.__purposeTest = { page: reactive({ locale: 'ar' }), posts: [], forms: [], visits: [] };
globalThis.route = (name, id) => name + '/' + (id ?? '');
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const inertia = moduleUrl(`import { reactive } from ${JSON.stringify(import.meta.resolve('vue'))};
export const usePage = () => ({ props: globalThis.__purposeTest.page });
export const Head = { render: () => null };
export const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
export const router = { visit: url => globalThis.__purposeTest.visits.push(url), reload: options => globalThis.__purposeTest.visits.push(options),
 post: (url, payload, options) => globalThis.__purposeTest.posts.push({ url, payload, options }) };
export const useForm = input => {
 const keys = Object.keys(input); const form = reactive({ ...input, errors: {}, processing: false, recentlySuccessful: false,
 post(url, options) { globalThis.__purposeTest.posts.push({ url, payload: JSON.parse(JSON.stringify(Object.fromEntries(keys.map(k => [k, form[k]])))), options }); }
 }); globalThis.__purposeTest.forms.push(form); return form;
};`);
const replacements = { vue: import.meta.resolve('vue'), '@inertiajs/vue3': inertia };
function rewrite(code, extra = {}) {
    for (const [from, to] of Object.entries({ ...replacements, ...extra })) {
        code = code.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`);
    }
    return code;
}
const messages = moduleUrl(ts.transpileModule(await readFile(new URL('../resources/js/localization/messages.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText);
const copy = moduleUrl(rewrite(ts.transpileModule(await readFile(new URL('../resources/js/localization/business.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText, { './messages': messages }));
async function compile(path, extra = {}) {
    const { descriptor } = parse(await readFile(new URL('../resources/js/' + path, import.meta.url), 'utf8'));
    const source = compileScript(descriptor, { id: 'dtm-test', inlineTemplate: true }).content;
    const url = moduleUrl(rewrite(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText,
        { '@/localization/business': copy, ...extra }));
    return { url, component: (await import(url)).default };
}
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
const editor = await compile('Components/Business/ObjectPurposeEditor.vue');
function mount(overrides = {}, component = editor.component) {
 state.posts = []; state.forms = []; state.visits = []; state.page.locale = 'en';
 const props = reactive(component === editor.component ? { workspaceId: 'business', purpose: null, contextVersion: 7, currentIntent: null, ...overrides } : {timezoneOptions:['UTC','Europe/Istanbul'], ...overrides});
 const root = element('root'); const app = renderer.createApp({ render: () => h(component, props) });
 app.config.globalProperties.route = globalThis.route; app.mount(root);
 return { root, props, stop: () => app.unmount() };
}
const nodes = (root, predicate) => [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = root => root.text + root.children.map(text).join('');
const find = (view, key) => { const node = nodes(view.root, n => n.props['data-purpose'] === key)[0]; assert.ok(node, 'Expected '+key); return node; };
async function click(view,key) { find(view,key).props.onClick(); await nextTick(); }
async function input(view,key,value) { const n=find(view,key); n.value=value; if(n.tag==='select') n.options.forEach(o=>o.selected=o.value===value); n.listeners[n.tag==='select'?'change':'input']({target:n}); await nextTick(); }
async function check(view,key,value=true) { const n=find(view,key); n.checked=value; n.listeners.change({target:n}); await nextTick(); }
const submit=view=>find(view,'form').props.onSubmit({preventDefault(){}});
async function valid(v) {
 await input(v,'meaning','Introduce this business before launch');
 await input(v,'source','Original Owner statement');
 await input(v,'identity_basis','These exact objects belong to this business');
 await input(v,'authority_basis','Responsible Owner');
 await input(v,'effective_basis','Applies on acceptance');
 await input(v,'account-0','100'); await input(v,'object-0','200');
}
async function confirm(v) { for(const key of ['meaning','scope','authority','identity','standing']) await check(v,'confirm-'+key); }
test('finite declaration requires fresh confirmations and never fills DTM or Intent implicitly',async()=>{
 const v=mount(); await valid(v); submit(v); assert.equal(state.posts.length,0);
 await click(v,'add-included'); await input(v,'kind-1','ad_set'); await input(v,'account-1','100'); await input(v,'object-1','300');
 await confirm(v); submit(v); assert.equal(state.posts.length,1);
 const c=state.posts[0].payload; assert.equal(c.declaration.included_objects.length,2); assert.deepEqual(c.declaration.excluded_objects,[]);
 assert.equal(c.declaration.included_objects[1].object_id,'300'); assert.equal(c.declaration.dtm,null); assert.equal(c.declaration.intent,null);
 assert.equal(c.declaration.provenance.speaker,null); v.stop();
});
test('material edit clears confirmation; locale and revision refresh preserve dirty input',async()=>{
 const v=mount(); await valid(v); await confirm(v); await input(v,'meaning','Changed literal premise');
 submit(v); assert.equal(state.posts.length,0); assert.equal(find(v,'confirm-authority').checked,false);
 state.page.locale='ar'; v.props.contextVersion=8; await nextTick(); assert.equal(find(v,'meaning').value,'Changed literal premise'); v.stop();
});
test('stale HTTP response cannot silently retry and requires canonical review and reconfirmation',async()=>{
 const v=mount(); await valid(v); await confirm(v); submit(v);
 const options=state.posts[0].options; assert.equal(options.onHttpException({status:409,data:{message:'Review current revision'}}),false);
 options.onFinish(); await nextTick(); submit(v); assert.equal(state.posts.length,1);
 await click(v,'review'); assert.equal(state.visits.length,1); state.visits[0].onSuccess(); await nextTick();
 await click(v,'adopt'); submit(v); assert.equal(state.posts.length,1); assert.equal(find(v,'confirm-scope').checked,false); v.stop();
});
test('withdrawal has a separate transition confirmation and retains explicit reviewed head',async()=>{
 const snapshot={meaning:'Existing use',qualifications:null,phase:null,included_objects:[{provider:'meta',account_namespace:'meta_ads_account',account_id:'100',object_kind:'campaign',object_id:'200',incarnation:null}],excluded_objects:[],provenance:{speaker:null,statement_at:null,source:'Owner',identity_basis:'Identified by Owner',authority_basis:'Owner',historical_support:null,effective_basis:'On acceptance'},dtm:null,intent:null,end_condition:null,limitations:null,conflicts:[]};
 const v=mount({purpose:{purpose_id:'purpose-1',head_version:1,history:[{revision_id:'revision-1',version:1,snapshot,effective_from:'2020-01-01T00:00:00Z',accepted_at:'2020-01-01T00:00:00Z'}]}});
 await input(v,'operation','withdraw'); await input(v,'reason','Explicitly withdrawn by business'); await confirm(v); submit(v); assert.equal(state.posts.length,0);
 await check(v,'confirm-transition'); submit(v); assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.declaration,null);
 assert.equal(state.posts[0].payload.supersedes_revision_id,'revision-1'); assert.equal(state.posts[0].payload.expected_version,1); v.stop();
});
