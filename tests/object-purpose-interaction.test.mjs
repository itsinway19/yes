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
 const props = reactive(component === editor.component ? { workspaceId: 'business', purpose: null, contextVersion: 7, dtmTransitionVersion: 0, currentIntent: null, ...overrides } : { ...overrides });
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
test('editing a retained Intent relationship never silently rewires its version or full premise',async()=>{
 const original={direction:'increase_value',scope:'business_wide',description:null,outcome:{meaning:'Original booking intent',clarification:null,count_unit:null},quantity:null,horizon:null};
 const snapshot={meaning:'Existing use',qualifications:null,phase:null,included_objects:[{provider:'meta',account_namespace:'meta_ads_account',account_id:'100',object_kind:'campaign',object_id:'200',incarnation:null}],excluded_objects:[],provenance:{speaker:null,statement_at:null,source:'Owner',identity_basis:'Identified by Owner',authority_basis:'Owner',historical_support:null,effective_basis:'On acceptance'},dtm:null,intent:{version:3,value:original,statement:'Explicit relation to original booking intent'},end_condition:null,limitations:null,conflicts:[]};
 const current={...original,outcome:{...original.outcome,meaning:'A different current intent'}};
 const v=mount({contextVersion:9,currentIntent:{value:current,version:9},purpose:{purpose_id:'purpose-1',head_version:1,history:[{revision_id:'revision-1',version:1,snapshot,effective_from:'2020-01-01T00:00:00Z',accepted_at:'2020-01-01T00:00:00Z'}]}});
 await confirm(v); await check(v,'confirm-intent'); await check(v,'confirm-transition'); submit(v);
 assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.declaration.intent.version,3);
 assert.deepEqual(state.posts[0].payload.declaration.intent.value,original); assert.match(text(v.root),/Original booking intent/); v.stop();
});
test('historical withdrawal confirms its own retained support without needing support in the original declaration',async()=>{
 const snapshot={meaning:'Existing use',qualifications:null,phase:null,included_objects:[{provider:'meta',account_namespace:'meta_ads_account',account_id:'100',object_kind:'campaign',object_id:'200',incarnation:null}],excluded_objects:[],provenance:{speaker:null,statement_at:null,source:'Owner',identity_basis:'Identified by Owner',authority_basis:'Owner',historical_support:null,effective_basis:'On acceptance'},dtm:null,intent:null,end_condition:null,limitations:null,conflicts:[]};
 const v=mount({purpose:{purpose_id:'purpose-1',head_version:1,history:[{revision_id:'revision-1',version:1,snapshot,effective_from:'2026-10-07T00:00:00Z',accepted_at:'2026-10-07T00:00:00Z'}]}});
 await input(v,'operation','withdraw'); await input(v,'reason','Owner attests the preserved historical withdrawal record');
 const from=nodes(v.root,n=>n.tag==='input'&&n.props.placeholder)[0]; from.value='2025-01-01T00:00:00Z'; from.listeners.input({target:from}); await nextTick();
 await confirm(v); await check(v,'confirm-transition'); await check(v,'confirm-historical'); submit(v);
 assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.withdrawal_reason,'Owner attests the preserved historical withdrawal record'); assert.equal(state.posts[0].payload.effective_from,'2025-01-01T00:00:00Z'); v.stop();
});

const retainedSnapshot = () => ({meaning:'A',qualifications:'Retained qualification',phase:'launch',included_objects:[{provider:'meta',account_namespace:'meta_ads_account',account_id:'100',object_kind:'campaign',object_id:'200',incarnation:null}],excluded_objects:[],provenance:{speaker:'Owner',statement_at:null,source:'Original statement',identity_basis:'Exact identified objects',authority_basis:'Responsible Owner',historical_support:null,effective_basis:'Bounded period'},dtm:null,intent:null,end_condition:'Ends if launch is cancelled',limitations:'Retained limitation',conflicts:['First conflict','Second conflict']});
const retainedPurpose = (snapshot=retainedSnapshot()) => {
 const revision={purpose_id:'purpose-1',revision_id:'revision-1',version:1,operation:'declare',snapshot,effective_from:'2026-10-07T00:00:00Z',effective_until:'2030-01-01T00:00:00Z',accepted_at:'2026-10-07T00:00:00Z',actor_user_id:1,actor_generation:1,ownership_version:1,reason:null,supersedes_revision_id:null,affected_revision_ids:[]};
 return {purpose_id:'purpose-1',head_version:1,state:'DECLARED',applicable:revision,history:[revision],relationship_revalidation_required:false,dtm_standing:'NO_RELATION_DECLARED',continuity:'CONDITION_CONTINUITY_NOT_ESTABLISHED'};
};
const endInput = v => nodes(v.root,n=>n.tag==='label'&&text(n).includes('Exclusive effective end'))[0].children.find(n=>n.tag==='input');
async function editEnd(v,value) { const n=endInput(v); n.value=value; n.listeners.input({target:n}); await nextTick(); }
test('wording-only correction preserves the exact retained end and all unchanged snapshot fields',async()=>{
 const purpose=retainedPurpose(); const v=mount({purpose});
 await input(v,'operation','correct'); await input(v,'meaning','B'); await input(v,'reason','Corrects wording only');
 await confirm(v); await check(v,'confirm-transition'); submit(v);
 assert.equal(state.posts.length,1); const c=state.posts[0].payload;
 assert.equal(c.effective_until,'2030-01-01T00:00:00Z');
 assert.deepEqual(c.declaration,{...purpose.history[0].snapshot,meaning:'B'}); v.stop();
});
test('explicit end removal clears standing confirmation and must be reconfirmed as a transition',async()=>{
 const v=mount({purpose:retainedPurpose()}); await confirm(v); await check(v,'confirm-transition');
 assert.equal(endInput(v).value,'2030-01-01T00:00:00Z'); await editEnd(v,''); submit(v);
 assert.equal(state.posts.length,0); assert.equal(find(v,'confirm-standing').checked,false); assert.equal(find(v,'confirm-transition').checked,false);
 await confirm(v); await check(v,'confirm-transition'); submit(v); assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.effective_until,null); v.stop();
});
const layout = moduleUrl(`import { h } from ${JSON.stringify(import.meta.resolve('vue'))}; export default {render(){return h('main',this.$slots.default?.());}};`);
const purposePage = await compile('Pages/Business/ObjectPurposes.vue',{'@/Layouts/AdvertiserLayout.vue':layout,'@/Components/Business/ObjectPurposeEditor.vue':editor.url});
test('current DTM labels warn only for actual drift and historical references stay historical in both languages',async()=>{
 for (const mode of ['current_alignment','current_dependency','historical_attestation']) {
  const snapshot=retainedSnapshot(); snapshot.dtm={mode,version:7,countries:['SA'],subset:[],statement:'Exact retained relationship'};
  const item=retainedPurpose(snapshot); item.dtm_standing=mode==='historical_attestation'?'HISTORICAL_ATTESTATION':'CURRENT_REFERENCE_AT_READ';
  const v=mount({workspace:{public_id:'business',name:'Real workspace'},projection:{state:'DECLARED',items:[item],current_context_version:7,latest_dtm_event_context_version:7,effective_at:'2026-10-07T00:00:00Z',known_at:'2026-10-07T00:00:00Z'},selectedPurpose:'purpose-1',business:{currentIntent:{value:null,version:7}}},purposePage.component);
  assert.doesNotMatch(text(v.root),/current relationship requires revalidation/);
  if(mode==='historical_attestation') assert.match(text(v.root),/Historical attestation; not a current claim/);
  else {
   assert.match(text(v.root),mode==='current_alignment'?/Current market alignment claim/:/Current market dependency/);
   v.props.projection.items[0].relationship_revalidation_required=true; await nextTick(); assert.match(text(v.root),/current relationship requires revalidation/);
   v.props.projection.items[0].relationship_revalidation_required=false; await nextTick();
  }
  state.page.locale='ar'; await nextTick(); assert.doesNotMatch(text(v.root),/العلاقة بالحاضر تحتاج إعادة تحقق/); v.stop();
 }
});

test('a retained database timestamp is resubmitted as the same timezone-qualified instant without losing precision',async()=>{
 const purpose=retainedPurpose(); purpose.history[0].effective_until='2030-01-01 00:00:00.123456+00';
 const v=mount({purpose}); await input(v,'operation','correct'); await input(v,'meaning','B'); await input(v,'reason','Wording correction only');
 await confirm(v); await check(v,'confirm-transition'); submit(v);
 assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.effective_until,'2030-01-01T00:00:00.123456+00:00'); v.stop();
});

test('DTM draft currentness follows DTM events instead of unrelated Context version changes',async()=>{
 const snapshot=retainedSnapshot(); snapshot.dtm={mode:'current_dependency',version:7,countries:['SA'],subset:[],statement:'Exact current market dependency'};
 const v=mount({purpose:retainedPurpose(snapshot),contextVersion:7,dtmTransitionVersion:7});
 await confirm(v); await check(v,'confirm-dtm'); await check(v,'confirm-transition');
 v.props.contextVersion=8; await nextTick();
 assert.equal(find(v,'confirm-dtm').checked,true); submit(v); assert.equal(state.posts.length,1); assert.equal(state.posts[0].payload.declaration.dtm.version,7);
 state.posts[0].options.onFinish(); await nextTick();
 v.props.dtmTransitionVersion=9; v.props.contextVersion=9; await nextTick();
 assert.equal(find(v,'confirm-dtm').checked,false); submit(v); assert.equal(state.posts.length,1); assert.match(text(v.root),/reference changed/); v.stop();
});
