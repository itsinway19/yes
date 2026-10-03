import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h } from 'vue';

// Real compiled Vue components/directives mounted on Vue's native custom renderer.
// Only HTTP/navigation and host elements are test doubles; component handlers are not exposed/mocked.
const transport = { requests: [], reloads: [], posts: [], send: async () => ({ data: {} }) };
const navigationGuards = new Set();
globalThis.__bb1NavigationGuards = navigationGuards;
globalThis.window = { location: { origin: 'http://localhost:8140', href: 'http://localhost:8140/internal/commercial' }, addEventListener() {}, removeEventListener() {} };
globalThis.__bb1Transport = transport;
globalThis.__bb1Page = reactive({ auth: { user: { id: 7 }, sessionContextId: 'a'.repeat(64) } });
const stored = new Map();
globalThis.sessionStorage = {
    get length() { return stored.size; }, key: index => [...stored.keys()][index] ?? null,
    getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, String(value)),
    removeItem: key => stored.delete(key), clear: () => stored.clear(),
};
const personalStored = new Map();
globalThis.localStorage = { getItem: key => personalStored.get(key) ?? null, setItem: (key, value) => personalStored.set(key, String(value)), removeItem: key => personalStored.delete(key) };
globalThis.route = (name, args) => name + '/' + (Array.isArray(args) ? args.join('/') : args);
globalThis.document = { getElementById: () => null }; globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const inertia = 'data:text/javascript;base64,' + Buffer.from(`import { reactive } from ${JSON.stringify(import.meta.resolve('vue'))};
export const Head = () => null; export const Link = (_, { slots }) => slots.default?.(); export const router = { on: (_, callback) => { globalThis.__bb1NavigationGuards.add(callback); return () => globalThis.__bb1NavigationGuards.delete(callback); }, reload: o => globalThis.__bb1Transport.reloads.push(o), post: (...a) => globalThis.__bb1Transport.posts.push(a) };
export const useForm = input => reactive({ ...input, errors: {}, processing: false, post() {} });
export const usePage = () => ({ props: globalThis.__bb1Page });`).toString('base64');
const http = 'data:text/javascript;base64,' + Buffer.from(`export default {
get: (...args) => globalThis.__bb1Transport.send(...args), post: (...args) => { globalThis.__bb1Transport.requests.push(args); return globalThis.__bb1Transport.send(...args); },
isAxiosError: e => e.isAxiosError === true };`).toString('base64');
async function compile(name, replacements = {}) {
    const filename = new URL('../resources/js/Pages/Internal/' + name + '.vue', import.meta.url);
    const { descriptor } = parse(await readFile(filename, 'utf8'));
    const script = compileScript(descriptor, { id: 'bb1-' + name, inlineTemplate: true });
    let output = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
    for (const [original, replacement] of Object.entries({ vue: import.meta.resolve('vue'), '@inertiajs/vue3': inertia, axios: http, ...replacements })) {
        output = output.replaceAll('from "' + original + '"', 'from ' + JSON.stringify(replacement))
            .replaceAll("from '" + original + "'", 'from ' + JSON.stringify(replacement));
    }
    const url = 'data:text/javascript;base64,' + Buffer.from(output).toString('base64');
    return { url, component: (await import(url)).default };
}
function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, _inputValue: '', get value() { return this.tag === 'select' ? this.options.find(o => o.selected)?._value ?? '' : this._inputValue; }, set value(value) { this._inputValue = value; if(this.tag === 'select') this.options.forEach(o => o.selected = o._value === value); }, multiple: false, selected: false,
        get options() { return this.children.filter(c => c.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(o => o.selected); },
        set selectedIndex(index) { this.options.forEach((o, i) => { o.selected = i === index; }); },
        getRootNode() { return {}; }, addEventListener(type, fn) { this.listeners[type] = fn; },
    };
}
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent;
    }, remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, old, value) { node.props[key] = value; if (key === 'value') node.value = node._value = value; },
});
const layout = 'data:text/javascript;base64,' + Buffer.from(`export default { setup(_, {slots}) { return () => slots.default?.(); } };`).toString('base64');
const { component } = await compile('Commercial', { '@/Layouts/InternalLayout.vue': layout });
const countryOptions = { SA: { ar: 'السعودية', en: 'Saudi Arabia', currency: 'SAR' }, AE: { ar: 'الإمارات', en: 'United Arab Emirates', currency: 'AED' }, KW: { ar: 'الكويت', en: 'Kuwait', currency: 'KWD' }, QA: { ar: 'قطر', en: 'Qatar', currency: 'QAR' }, BH: { ar: 'البحرين', en: 'Bahrain', currency: 'BHD' }, OM: { ar: 'عُمان', en: 'Oman', currency: 'OMR' }, JO: { ar: 'الأردن', en: 'Jordan', currency: 'JOD' } };
const currencyOptions = ['SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR', 'JOD', 'USD'];
const currencyNames = { USD: { ar: 'الدولار الأمريكي', en: 'US dollar' }, SAR: { ar: 'الريال السعودي', en: 'Saudi riyal' } };
const initial = { countryOptions, currencyOptions, currencyNames, markets: [{id:'sa',label:'Saudi',configuration_id:'sa-config',revision:2},{id:'jo',label:'Jordan',configuration_id:'jo-config',revision:4}], configurations:[{id:'sa-config',market_id:'sa',version:1,catalog_reference_id:'sa-ref',denomination_code:'SAR'},{id:'jo-config',market_id:'jo',version:1,catalog_reference_id:'jo-ref',denomination_code:'JOD'}],catalogReferences:[{id:'jo-ref',market_id:'jo',catalog_identity:'jo-catalog',catalog_version:'1',approval_reference:'approved:jo',source_sha256:'b'.repeat(64)},{id:'sa-ref',market_id:'sa',catalog_identity:'catalog',catalog_version:'1',approval_reference:'approved',source_sha256:'a'.repeat(64)}],targets:[...['ConfigureCommercialMarket','EnableCommercialMarket','DisableCommercialMarket'].flatMap(action=>['sa','jo'].map(target_id=>({action,target_id,version_id:null,workspace_public_id:null}))),{action:'RecordOrVerifyApprovedMarketReference',target_id:'sa',version_id:'authorized-ref',workspace_public_id:null}],obligations:[],newConfigurationId:'generated-config',result:null,freshAuthentication:true};
function mount(overrides = {}) { transport.requests=[]; transport.send=async()=>({data:{market_id:'sa'}}); const props=reactive({ ...structuredClone(initial), ...overrides }); const root=element('root'); const app=renderer.createApp({render:()=>h(component,props)});app.mount(root);return {root,props,unmount:()=>app.unmount()}; }
function nodes(root,predicate){return [root,...root.children.flatMap(child=>nodes(child,()=>true))].filter(predicate);}
function byId(view,id){return nodes(view.root,n=>n.props.id===id)[0];}
async function change(view,id,value){
    if(id==='commercial-operation'){
        view.requestedOperation=value;
        const button=nodes(view.root,n=>n.props['data-operation']===value)[0];
        if(!button&&value==='configuration') return;
        assert.ok(button,id); const advanced=byId(view,'commercial-advanced');
        if(value!=='configuration'&&advanced?.props.onToggle) advanced.props.onToggle({target:{open:true}});
        button.props.onClick(); await nextTick();return;
    }
    if(id==='commercial-market_id'&&!byId(view,id)){
        const button=nodes(view.root,n=>n.props['data-manage-market']===value)[0];assert.ok(button,id);
        button.props.onClick();await nextTick();
        if(view.requestedOperation) await change(view,'commercial-operation',view.requestedOperation);
        return;
    }
    const el=byId(view,id);assert.ok(el,id);el.value=value;el.selectedIndex=el.options.findIndex(o=>String(o.props.value)===String(value));el.listeners.change?.({target:el});el.props.onChange?.({target:el});await nextTick();
}
function submit(view){return nodes(view.root,n=>n.tag==='form')[0].props.onSubmit({preventDefault(){}});}
test('Founder can clear and reselect the generated reference without losing its option', async () => {
    const v = mount(); v.props.founderAccess = true; v.props.targets = []; v.props.newCatalogReferenceId = 'founder-reference';
    await change(v, 'commercial-operation', 'catalog'); await change(v, 'commercial-market_id', 'sa');
    assert.equal(byId(v, 'commercial-catalog_reference_id').value, 'founder-reference');
    await change(v, 'commercial-catalog_reference_id', '');
    assert.ok(byId(v, 'commercial-catalog_reference_id').options.some(o => o.props.value === 'founder-reference'));
    await submit(v); assert.equal(transport.requests.length, 0);
    await change(v, 'commercial-catalog_reference_id', 'founder-reference');
    await submit(v); assert.equal(transport.requests[0][1].catalog_reference_id, 'founder-reference');
    v.unmount();
});
test('market change clears a prior configuration and submits only a visible current-market choice',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');await change(v,'commercial-configuration_id','sa-config');await change(v,'commercial-market_id','jo');assert.notEqual(byId(v,'commercial-configuration_id').value,'sa-config');await change(v,'commercial-configuration_id','jo-config');await submit(v);const input=transport.requests.at(-1)[1];assert.notEqual(input.configuration_id,'sa-config');assert.equal(input.expected_revision,4);v.unmount();});
test('catalog reference uses the identity fixed by the exact grant',async()=>{const v=mount();await change(v,'commercial-operation','catalog');await change(v,'commercial-market_id','sa');assert.equal(byId(v,'commercial-catalog_reference_id').value,'authorized-ref');assert.ok(byId(v,'commercial-catalog_reference_id').props.readonly || byId(v,'commercial-catalog_reference_id').tag==='select');v.unmount();});
test('refresh rederives revision without resubmitting a mutation',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');v.props.markets[0].revision=9;await nextTick();assert.equal(String(byId(v,'commercial-expected_revision').value),'9');assert.equal(transport.requests.length,0);v.unmount();});
test('uncertain result retries the original payload and prevents replacement command',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');transport.send=async()=>{throw {isAxiosError:true};};await submit(v);await nextTick();const original=structuredClone(transport.requests[0][1]);await change(v,'commercial-market_id','jo');await submit(v);assert.deepEqual(transport.requests[1][1],original);const buttons=nodes(v.root,n=>n.tag==='button');const replacement=buttons.find(n=>n.children.some(c=>c.text.includes('بدء أمر جديد')));if(replacement)assert.equal(replacement.props.disabled,true);v.unmount();});

test('blank configuration is not a disable decision',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');await submit(v);assert.equal(transport.requests.length,0);v.unmount();});
test('multiple exact catalog grant identities are selectable without typing an identity',async()=>{const v=mount();v.props.targets.push({action:'RecordOrVerifyApprovedMarketReference',target_id:'sa',version_id:'second-ref',workspace_public_id:null});await change(v,'commercial-operation','catalog');await change(v,'commercial-market_id','sa');assert.equal(byId(v,'commercial-catalog_reference_id').tag,'select');await change(v,'commercial-catalog_reference_id','second-ref');await submit(v);assert.equal(transport.requests.at(-1)[1].catalog_reference_id,'second-ref');v.unmount();});

test('completed command is replaced for a new semantic operation',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');await submit(v);await nextTick();const first=transport.requests[0][1];await change(v,'commercial-market_id','jo');await submit(v);assert.notEqual(transport.requests[1][1].command_id,first.command_id);assert.equal(transport.requests[1][1].market_id,'jo');v.unmount();});
test('409 refreshes state without automatic retry',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');await change(v,'commercial-configuration_id','sa-config');transport.send=async()=>{throw {isAxiosError:true,response:{status:409}}};await submit(v);await nextTick();v.props.markets[0].revision=8;await nextTick();assert.equal(transport.requests.length,1);assert.equal(String(byId(v,'commercial-expected_revision').value),'8');v.unmount();});


test('authority evidence distinguishes read-only, missing prerequisite and unknown availability',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');let state=()=>nodes(v.root,n=>n.props['data-authority'])[0].props['data-authority'];assert.equal(state(),'EXECUTABLE');v.props.targets=[];await nextTick();assert.equal(state(),'VISIBLE_BUT_READ_ONLY');v.props.freshAuthentication=undefined;await nextTick();assert.equal(state(),'AUTHORITY_UNDETERMINED');v.props.freshAuthentication=false;await nextTick();assert.equal(state(),'PREREQUISITE_MISSING');v.unmount();});
test('described fields reference existing nodes and system evidence is readonly',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');const all=nodes(v.root,()=>true);const ids=new Set(all.map(n=>n.props.id).filter(Boolean));for(const n of all)for(const id of (n.props['aria-describedby']??'').split(' ').filter(Boolean))assert.ok(ids.has(id),id);for(const n of all.filter(n=>n.tag==='input'&&n.props.id?.startsWith('evidence-')))assert.notEqual(n.props.readonly,undefined);assert.equal(all.filter(n=>n.tag==='main').length,0);v.unmount();});
test('English mode translates the primary workflow and actions',async()=>{globalThis.__bb1Page.locale='en';const v=mount();await change(v,'commercial-market_id','sa');const content=nodes(v.root,()=>true).map(n=>n.text).join(' ');assert.match(content,/Approved commercial references/);assert.match(content,/Commercial markets/);assert.match(content,/Command receipt/);assert.match(content,/Audit delivery/);assert.doesNotMatch(content,/المراجع التجارية المعتمدة/);v.unmount();globalThis.__bb1Page.locale='ar';});
test('uncertain command locks editable workflow while preserving explicit original retry',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');transport.send=async()=>{throw {isAxiosError:true};};await submit(v);await nextTick();assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,true);assert.equal(transport.requests.length,1);v.unmount();});


test('registration snapshots follow refreshed canonical assignability and independent denomination',async()=>{const v=mount();for(const action of ['RegisterCommercialScope','RecordBeneficiaryBinding'])v.props.targets.push({action,target_id:'scope',version_id:null,workspace_public_id:'workspace'});for(const version_id of ['sa-config','new-config'])v.props.targets.push({action:'AssignCommercialMarket',target_id:'scope',version_id,workspace_public_id:'workspace'});transport.send=async()=>({data:{name:'Readable beneficiary',public_id:'workspace'}});await change(v,'commercial-operation','register');await change(v,'commercial-market_id','sa');await change(v,'scope-select','scope|workspace');await nextTick();v.props.configurations.push({id:'new-config',market_id:'sa',version:2,catalog_reference_id:'sa-ref',denomination_code:'JOD'});v.props.markets[0].configuration_id='new-config';await nextTick();await submit(v);const input=transport.requests.at(-1)[1];assert.equal(input.configuration_id,'new-config');assert.equal(input.catalog_reference_id,'sa-ref');assert.equal(input.denomination_code,'JOD');assert.equal(input.scope_id,'scope');v.unmount();});
test('removing the selected market clears selection and prevents a hidden submitted target',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');v.props.markets=v.props.markets.filter(m=>m.id!=='sa');v.props.targets=[];await nextTick();assert.equal(byId(v,'evidence-market_id'),undefined);await submit(v);assert.equal(transport.requests.length,0);v.unmount();});
test('denial of an uncertain retry does not resolve the original unknown outcome',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');transport.send=async()=>{throw {isAxiosError:true}};await submit(v);transport.send=async()=>{throw {isAxiosError:true,response:{status:403}}};await submit(v);await nextTick();assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,true);assert.deepEqual(transport.requests[0][1],transport.requests[1][1]);v.unmount();});
test('explicit disable submits null configuration and the canonical revision',async()=>{const v=mount();await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');await change(v,'assignability-decision','disable');await submit(v);assert.equal(transport.requests[0][1].configuration_id,null);assert.equal(transport.requests[0][1].expected_revision,2);assert.equal(transport.requests[0][1].reason_code,'commercial_assignability');v.unmount();});
test('beneficiary selection is keyed by the exact scope/workspace tuple, not list position',async()=>{const v=mount();v.props.targets.push({action:'RegisterCommercialScope',target_id:'scope',version_id:null,workspace_public_id:'workspace'});await nextTick();await change(v,'commercial-operation','register');await change(v,'commercial-market_id','sa');const option=byId(v,'scope-select').options[1];assert.equal(option.props.value,'scope|workspace');v.unmount();});

test('confirmed 409 rejection unlocks only after canonical refresh and explicit operator review',async()=>{const v=mount();transport.reloads=[];await change(v,'commercial-operation','assignability');await change(v,'commercial-market_id','sa');await change(v,'commercial-configuration_id','sa-config');transport.send=async()=>{throw {isAxiosError:true,response:{status:409}}};await submit(v);await nextTick();let review=()=>nodes(v.root,n=>n.props['data-conflict-review'])[0];assert.equal(review(),undefined);await submit(v);assert.equal(transport.requests.length,1);v.props.markets[0].revision=8;await nextTick();transport.reloads.at(-1).onSuccess?.();await nextTick();assert.ok(review());assert.equal(transport.requests.length,1);review().props.onClick();await nextTick();assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,false);transport.send=async()=>({data:{market_id:'sa'}});await change(v,'commercial-configuration_id','sa-config');await submit(v);assert.notEqual(transport.requests[0][1].command_id,transport.requests[1][1].command_id);assert.equal(transport.requests[1][1].expected_revision,8);v.unmount();});

test('market identity creation does not present an existing immutable identity as executable',async()=>{const v=mount();await change(v,'commercial-operation','market');await change(v,'commercial-market_id','sa');await submit(v);assert.equal(transport.requests.length,0);v.props.targets.push({action:'ConfigureCommercialMarket',target_id:'new-market',version_id:null,workspace_public_id:null});await nextTick();await change(v,'commercial-market_id','new-market');await submit(v);assert.equal(transport.requests[0][1].market_id,'new-market');v.unmount();});
test('a consumed server-generated configuration identity cannot be reused before refresh',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');v.props.configurations.push({id:'generated-config',market_id:'sa',version:2,catalog_reference_id:null,denomination_code:null});await nextTick();await submit(v);assert.equal(transport.requests.length,0);v.props.newConfigurationId='next-generated-config';await nextTick();await submit(v);assert.equal(transport.requests[0][1].configuration_id,'next-generated-config');v.unmount();});

test('only a receipt for the original uncertain command permits explicit reconciliation',async()=>{const v=mount();await change(v,'commercial-operation','configuration');await change(v,'commercial-market_id','sa');transport.send=async()=>{throw {isAxiosError:true}};await submit(v);await nextTick();const commandId=transport.requests[0][1].command_id;const receiptForm=nodes(v.root,n=>n.tag==='form')[1];transport.send=async()=>({data:{command_id:'different-command'}});await receiptForm.props.onSubmit({preventDefault(){}});await nextTick();let review=()=>nodes(v.root,n=>n.tag==='button'&&(n.text.includes('راجعت الإيصال والحالة')||n.children.some(c=>c.text.includes('راجعت الإيصال والحالة'))))[0];assert.equal(review(),undefined);assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,true);transport.send=async()=>({data:{command_id:commandId}});await receiptForm.props.onSubmit({preventDefault(){}});await nextTick();assert.ok(review());assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,true);review().props.onClick();await nextTick();assert.equal(nodes(v.root,n=>n.tag==='fieldset')[0].props.disabled,false);assert.equal(transport.requests.length,1);v.unmount();});

const { component: preparationComponent } = await compile('CommercialMarketPreparation', { '@/Layouts/InternalLayout.vue': layout });
function mountPreparation(overrides = {}) {
    transport.requests = []; transport.send = async () => ({ data: {} });
    const props = reactive({ countryOptions, currencyOptions, currencyNames, canPrepare: true, intentBinding: 'server-encrypted-intent-binding', intentId: '11111111-1111-4111-8111-111111111111', people: [{ public_id: '44444444-4444-4444-8444-444444444444', name: 'Eligible administrator', kind: 'employee' }], approvals: [], freshAuthentication: true, ...overrides });
    const root = element('root'); const app = renderer.createApp({ render: () => h(preparationComponent, props) }); app.config.globalProperties.route = globalThis.route; app.mount(root);
    return { root, props, unmount: () => app.unmount() };
}
async function fillInput(view, id, value) { const el = byId(view, id); assert.ok(el, id); el.value = value; el.listeners.input?.({ target: el }); await nextTick(); }
test('Founder direct market creation needs neither another employee nor a delegation expiry', async () => {
    stored.clear(); const v = mountPreparation({ people: [] });
    await change(v, 'market-country', 'SA');
    await fillInput(v, 'approval-reason', 'Founder decision');
    assert.ok(!byId(v, 'market-administrator'));
    assert.ok(!byId(v, 'approval-expiry'));
    assert.equal(nodes(v.root, n => n.tag === 'button' && n.props.type === 'submit')[0].props.disabled, false);
    await submit(v); await nextTick();
    assert.deepEqual(transport.requests[0][1], { intent_id: '11111111-1111-4111-8111-111111111111', intent_binding: 'server-encrypted-intent-binding', mode: 'direct', country_code: 'SA', denomination_code: 'SAR', reason: 'Founder decision' });
    v.unmount(); stored.clear();
});
test('existing-market delegation is a separate optional form with bounded human permissions', async () => {
    stored.clear(); const v = mountPreparation({ recordedMarkets: [{ id: 'recorded-market', label: 'Saudi market' }] });
    assert.ok(byId(v, 'market-delegation'));
    await change(v, 'delegate-market', 'recorded-market');
    await change(v, 'delegate-employee', '44444444-4444-4444-8444-444444444444');
    await change(v, 'delegate-permission', 'manage');
    await fillInput(v, 'delegate-reason', 'Optional employee work');
    await fillInput(v, 'delegate-expiry', '2030-01-01T12:00');
    await byId(v, 'market-delegation').props.onSubmit({ preventDefault() {} }); await nextTick();
    assert.equal(transport.requests[0][1].market_id, 'recorded-market');
    assert.equal(transport.requests[0][1].permission, 'manage');
    assert.equal(transport.requests[0][1].operator, '44444444-4444-4444-8444-444444444444');
    assert.equal(Object.hasOwn(transport.requests[0][1], 'effect'), false);
    v.unmount(); stored.clear();
});
test('Founder selects country and suggested currency without a required approval reason', async () => {
    stored.clear(); const v = mountPreparation({ people: [] });
    await change(v, 'market-country', 'KW');
    assert.equal(byId(v, 'approval-reason').props.required, false);
    await submit(v); await nextTick();
    assert.equal(transport.requests[0][1].mode, 'direct');
    assert.equal(transport.requests[0][1].reason, '');
    v.unmount(); stored.clear();
});
test('fresh-login renewal saves human market draft and restores it after a new session', async () => {
    stored.clear(); let v = mountPreparation({ freshAuthentication: false });
    await change(v, 'market-country', 'KW'); await change(v, 'market-currency', 'USD'); await fillInput(v, 'approval-reason', 'Optional note');
    const renew = byId(v, 'renew-market-login'); assert.ok(renew);
    renew.props.onClick(); assert.equal(transport.posts.at(-1)[0], 'internal.logout/undefined');
    v.unmount(); globalThis.__bb1Page.auth.sessionContextId = 'new-authenticated-session';
    v = mountPreparation({ freshAuthentication: true, intentId: '33333333-3333-4333-8333-333333333333' });
    assert.equal(byId(v, 'market-country').value, 'KW'); assert.equal(byId(v, 'market-currency').value, 'USD');
    assert.equal(byId(v, 'approval-reason').value, 'Optional note');
    await submit(v); assert.equal(transport.requests[0][1].intent_id, '33333333-3333-4333-8333-333333333333');
    v.unmount(); stored.clear(); globalThis.__bb1Page.auth.sessionContextId = 'a'.repeat(64);
});
test('new market choices use server consumption truth even when an existing market is unreadable', async () => {
    const v = mount(); v.props.markets = []; v.props.newMarketTargets = [];
    await change(v, 'commercial-operation', 'market');
    assert.equal(byId(v, 'commercial-market_id').options.length, 1);
    await submit(v); assert.equal(transport.requests.length, 0); v.unmount();
});
test('Founder preparation has human decisions only and retries the retained server intent after remount', async () => {
    stored.clear(); let v = mountPreparation();
    await change(v, 'preparation-mode', 'delegated'); await fillInput(v, 'market-name', 'Approved market'); await change(v, 'market-administrator', '44444444-4444-4444-8444-444444444444');
    await fillInput(v, 'approval-reason', 'Owner decision'); await fillInput(v, 'approval-expiry', '2030-01-01T12:00');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); await nextTick();
    const original = structuredClone(transport.requests[0][1]);
    assert.equal(original.intent_id, '11111111-1111-4111-8111-111111111111'); assert.equal(original.label, 'Approved market');
    assert.equal(Object.hasOwn(original, 'target_id'), false);
    assert.equal(nodes(v.root, n => n.tag === 'input' && /id|uuid|revision/.test(n.props.id ?? '')).length, 0);
    assert.ok(nodes(v.root, n => n.tag === 'fieldset').every(n => n.props.disabled));
    v.unmount(); v = mountPreparation(); transport.send = async () => { throw { isAxiosError: true }; };
    const retry = nodes(v.root, n => n.tag === 'button' && (n.text.includes('إعادة المحاولة بنفس الطلب') || n.children.some(x => x.text.includes('إعادة المحاولة بنفس الطلب'))))[0];
    assert.ok(retry); await retry.props.onClick(); await nextTick();
    assert.deepEqual(transport.requests[0][1], original); v.unmount(); stored.clear();
});
test('read-only approval does not expose an operator authorization form', async () => {
    stored.clear(); const v = mountPreparation({ canPrepare: false, intentId: null, approvals: [{ id: 'approval', target_id: 'target', label: 'Approved market', administrator_name: 'Another administrator', active: true, consumed: false, canAuthorize: false }] });
    assert.equal(nodes(v.root, n => n.tag === 'form').length, 0);
    assert.equal(nodes(v.root, n => n.tag === 'input').length, 0); v.unmount();
});

test('a first definitive denied preparation remains editable', async () => {
    stored.clear(); const v = mountPreparation();
    await change(v, 'preparation-mode', 'delegated'); await fillInput(v, 'market-name', 'Market'); await change(v, 'market-administrator', '44444444-4444-4444-8444-444444444444');
    await fillInput(v, 'approval-reason', 'Decision'); await fillInput(v, 'approval-expiry', '2030-01-01T12:00');
    transport.send = async () => { throw { isAxiosError: true, response: { status: 403 } }; };
    await submit(v); await nextTick(); assert.equal(nodes(v.root, n => n.tag === 'fieldset')[0].props.disabled, false); v.unmount(); stored.clear();
});
test('changed server intent persists the new uncertain request across remount', async () => {
    stored.clear(); let v = mountPreparation(); v.props.intentId = '22222222-2222-4222-8222-222222222222'; await nextTick();
    await change(v, 'preparation-mode', 'delegated'); await fillInput(v, 'market-name', 'Next market'); await change(v, 'market-administrator', '44444444-4444-4444-8444-444444444444');
    await fillInput(v, 'approval-reason', 'Next decision'); await fillInput(v, 'approval-expiry', '2030-01-01T12:00');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); await nextTick(); v.unmount();
    v = mountPreparation({ intentId: '22222222-2222-4222-8222-222222222222' });
    assert.ok(nodes(v.root, n => n.tag === 'button' && (n.text.includes('إعادة المحاولة بنفس الطلب') || n.children.some(x => x.text.includes('إعادة المحاولة بنفس الطلب')))).length);
    v.unmount(); stored.clear();
});

test('uncertain operator request may be explicitly retired only after consumed-target refresh', async () => {
    stored.clear(); const v = mountPreparation({ canPrepare: false, intentId: null, approvals: [{ id: 'approved-target', target_id: 'target', label: 'Market', administrator_name: 'Administrator', active: true, consumed: false, canAuthorize: true }] });
    await change(v, 'approved-market', 'approved-target'); await change(v, 'market-operator', '44444444-4444-4444-8444-444444444444'); await fillInput(v, 'operator-reason', 'Operator decision');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); await nextTick();
    const findReview = () => nodes(v.root, n => n.tag === 'button' && (n.text.includes('راجعت السوق المسجّل') || n.children.some(x => x.text.includes('راجعت السوق المسجّل'))))[0];
    assert.equal(findReview(), undefined); v.props.approvals[0].consumed = true; await nextTick(); assert.ok(findReview());
    findReview().props.onClick(); await nextTick();
    assert.ok([...stored.keys()].some(k => k.includes(':reviewed:approved-target')));
    assert.equal(nodes(v.root, n => n.tag === 'form').length, 0); v.unmount(); stored.clear();
});

test('recorded market disappears immediately from stale new-target projection and refresh requests new targets', async () => {
    const v = mount(); v.props.newMarketTargets = [{ action: 'ConfigureCommercialMarket', target_id: 'new-target', version_id: null, workspace_public_id: null, label: 'New market' }];
    await change(v, 'commercial-operation', 'market'); await change(v, 'commercial-market_id', 'new-target');
    v.props.markets.push({ id: 'new-target', label: 'New market', configuration_id: null, revision: 1 }); await nextTick();
    assert.equal(byId(v, 'commercial-market_id').options.some(o => o.props.value === 'new-target'), false);
    const refresh = nodes(v.root, n => n.tag === 'button' && (n.text.includes('تحديث الحالة والصلاحيات') || n.children.some(x => x.text.includes('تحديث الحالة والصلاحيات'))))[0];
    refresh.props.onClick(); assert.ok(transport.reloads.at(-1).only.includes('newMarketTargets')); v.unmount();
});

test('missing administrator or fresh login blocks approval but allows human fields to be filled', async () => {
    stored.clear(); const v = mountPreparation({ people: [], freshAuthentication: false }); await change(v, 'preparation-mode', 'delegated');
    assert.equal(nodes(v.root, n => n.tag === 'fieldset')[0].props.disabled, false);
    await fillInput(v, 'market-name', 'Saudi market'); assert.equal(byId(v, 'market-name').value, 'Saudi market');
    assert.equal(byId(v, 'market-administrator').props.disabled, true);
    assert.equal(nodes(v.root, n => n.tag === 'button' && n.props.type === 'submit')[0].props.disabled, true);
    assert.equal(transport.requests.length, 0); v.unmount(); stored.clear();
});

test('market list leads to human setup actions without an operation dropdown or editable reason code', async () => {
    const v = mount();
    assert.ok(!byId(v, 'commercial-operation'));
    assert.equal(nodes(v.root, n => n.props['data-manage-market']).length, 2);
    await nodes(v.root, n => n.props['data-manage-market'] === 'sa')[0].props.onClick(); await nextTick();
    assert.ok(!byId(v, 'commercial-catalog_reference_id'));
    await change(v, 'commercial-operation', 'configuration');
    assert.ok(byId(v, 'commercial-catalog_reference_id'));
    assert.equal(byId(v, 'scope-select'), undefined);
    assert.equal(byId(v, 'commercial-reason'), undefined);
    assert.equal(nodes(v.root, n => n.props['data-operation']).length, 5);
    v.unmount();
});
test('personal hiding persists per user and restores without a server mutation', async () => {
    personalStored.clear(); let v = mount();
    await nodes(v.root, n => n.props['data-hide-market'] === 'sa')[0].props.onClick(); await nextTick();
    assert.equal(nodes(v.root, n => n.props['data-manage-market'] === 'sa').length, 0);
    assert.equal(transport.requests.length, 0); v.unmount(); v = mount();
    assert.equal(nodes(v.root, n => n.props['data-manage-market'] === 'sa').length, 0);
    await nodes(v.root, n => n.props['data-restore-market'] === 'sa')[0].props.onClick(); await nextTick();
    assert.equal(nodes(v.root, n => n.props['data-manage-market'] === 'sa').length, 1);
    assert.equal(v.props.markets[0].configuration_id, 'sa-config'); assert.equal(transport.requests.length, 0);
    v.unmount(); globalThis.__bb1Page.auth.user.id = 8; v = mount();
    assert.equal(nodes(v.root, n => n.props['data-manage-market']).length, 2);
    v.unmount(); globalThis.__bb1Page.auth.user.id = 7; personalStored.clear();
});

test('uncertain command guards market, action and personal list changes even if a click is dispatched', async () => {
    personalStored.clear(); const v = mount(); await change(v, 'commercial-market_id', 'sa'); await change(v, 'commercial-operation', 'configuration');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); await nextTick();
    const input = structuredClone(transport.requests[0][1]);
    await change(v, 'commercial-market_id', 'jo'); await change(v, 'commercial-operation', 'register');
    nodes(v.root, n => n.props['data-hide-market'] === 'sa')[0].props.onClick(); await nextTick();
    assert.equal(byId(v, 'evidence-market_id').value, 'sa'); assert.ok(!byId(v, 'scope-select'));
    assert.equal(nodes(v.root, n => n.props['data-manage-market']).length, 2);
    await submit(v); assert.deepEqual(transport.requests[1][1], input); v.unmount(); personalStored.clear();
});

test('country selection suggests currency and supports USD override in the retained uncertain payload', async () => {
    stored.clear(); let v = mountPreparation(); assert.ok(!byId(v, 'market-name'));
    assert.equal(nodes(v.root, n => n.tag === 'button' && n.props.type === 'submit')[0].props.disabled, true);
    assert.equal(byId(v, 'market-country').options.length, 8);
    for (const [country, currency] of Object.entries({ SA: 'SAR', AE: 'AED', KW: 'KWD', QA: 'QAR', BH: 'BHD', OM: 'OMR', JO: 'JOD' })) {
        await change(v, 'market-country', country); assert.equal(byId(v, 'market-currency').value, currency);
    }
    assert.match(byId(v, 'market-currency').options.find(o => o.props.value === 'USD').text, /الدولار الأمريكي.*USD/);
    await change(v, 'market-currency', 'USD'); transport.send = async () => { throw { isAxiosError: true }; };
    await submit(v); await nextTick(); const input = structuredClone(transport.requests[0][1]);
    assert.equal(input.country_code, 'JO'); assert.equal(input.denomination_code, 'USD'); assert.equal(Object.hasOwn(input, 'label'), false);
    v.unmount(); v = mountPreparation(); transport.send = async () => { throw { isAxiosError: true }; };
    const retry = nodes(v.root, n => n.tag === 'button' && (n.text.includes('إعادة المحاولة بنفس الطلب') || n.children.some(x => x.text.includes('إعادة المحاولة بنفس الطلب'))))[0];
    await retry.props.onClick(); await nextTick(); assert.deepEqual(transport.requests[0][1], input); v.unmount(); stored.clear();
});
test('inactive market displays saved country and latest currency and configuration currency remains editable', async () => {
    const v = mount(); v.props.markets[0].country_code = 'JO'; v.props.markets[0].configuration_id = null;
    await change(v, 'commercial-market_id', 'sa'); await change(v, 'commercial-operation', 'configuration');
    assert.match(nodes(v.root, n => n.tag === 'div' && n.props.class === 'commercial-current')[0].children.map(n => n.text).join(' '), /الأردن/);
    assert.equal(byId(v, 'commercial-denomination_code').value, 'SAR');
    await change(v, 'commercial-denomination_code', 'USD'); await submit(v);
    assert.equal(transport.requests[0][1].denomination_code, 'USD'); assert.equal(transport.requests[0][1].catalog_reference_id, null);
    assert.equal(v.props.markets[0].configuration_id, null); v.unmount();
});

test('market overview stays uncluttered until explicit edit and advanced forms use native disclosures', async () => {
    const v = mount(); const manage = nodes(v.root, n => n.props['data-manage-market'] === 'sa')[0];
    manage.props.onClick(); await nextTick();
    assert.ok(!byId(v, 'commercial-denomination_code')); assert.ok(!byId(v, 'operation-result'));
    assert.equal(byId(v, 'commercial-advanced').tag, 'details'); assert.notEqual(byId(v, 'commercial-advanced').props.open, true);
    assert.ok(nodes(v.root, n => n.props['data-operation'] === 'configuration')[0]);
    assert.equal(byId(v, 'commercial-history').tag, 'details'); assert.notEqual(byId(v, 'commercial-history').props.open, true);
    assert.equal(byId(v, 'commercial-audit').tag, 'details'); assert.notEqual(byId(v, 'commercial-audit').props.open, true);
    nodes(v.root, n => n.props['data-operation'] === 'configuration')[0].props.onClick(); await nextTick();
    assert.ok(byId(v, 'commercial-denomination_code')); v.unmount();
});
test('activation lists only complete readable versions and gives the missing reference reason', async () => {
    const v = mount(); v.props.markets[0].configuration_id = null; v.props.configurations[0].catalog_reference_id = null;
    await change(v, 'commercial-market_id', 'sa'); await change(v, 'commercial-operation', 'assignability');
    assert.equal(byId(v, 'commercial-configuration_id').options.length, 1);
    assert.match(nodes(v.root, n => n.props['data-authority'])[0].text, /مرجع.*معتمد/);
    assert.equal(byId(v, 'commercial-advanced').props.open, true); await submit(v); assert.equal(transport.requests.length, 0); v.unmount();
});
test('uncertain result and unchanged retry remain outside closed advanced and audit disclosures', async () => {
    const v = mount(); await change(v, 'commercial-market_id', 'sa'); await change(v, 'commercial-operation', 'configuration');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); await nextTick();
    const result = byId(v, 'operation-result'); assert.ok(result); let parent = result.parent;
    while(parent){ assert.notEqual(parent.tag, 'details'); parent=parent.parent; }
    const retry = nodes(result, n => n.tag === 'button' && n.text.includes('إعادة الطلب الأصلي'))[0]; assert.ok(retry);
    const original = structuredClone(transport.requests[0][1]); await retry.props.onClick(); assert.deepEqual(transport.requests[1][1], original); v.unmount();
});

function buttonWithText(view, text) { return nodes(view.root, n => n.tag === 'button' && (n.text.includes(text) || n.children.some(child => child.text.includes(text))))[0]; }
function navigationDenied(method, url) {
    let prevented = false;
    for (const guard of navigationGuards) guard({ detail: { visit: { method, url: new URL(url, window.location.origin) } }, preventDefault() { prevented = true; } });
    return prevented;
}
test('preparation resumes original bound intent across login and retains it after an authentication denial', async () => {
    stored.clear(); let v = mountPreparation(); await change(v, 'market-country', 'KW'); await change(v, 'market-currency', 'USD');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v);
    const original = structuredClone(transport.requests[0][1]);
    assert.equal(navigationDenied('get', '/internal/administration'), true);
    byId(v, 'renew-market-login').props.onClick(); assert.equal(navigationDenied('post', 'internal.logout/undefined'), false);
    v.unmount(); globalThis.__bb1Page.auth.sessionContextId = 'fresh-login-session';
    v = mountPreparation({ intentId: '55555555-5555-4555-8555-555555555555', intentBinding: 'different-new-intent-binding' });
    transport.send = async () => { throw { isAxiosError: true, response: { status: 403 } }; };
    await buttonWithText(v, 'إعادة المحاولة بنفس الطلب').props.onClick();
    assert.deepEqual(transport.requests[0][1], original); assert.ok(buttonWithText(v, 'إعادة المحاولة بنفس الطلب'));
    v.unmount(); stored.clear(); globalThis.__bb1Page.auth.sessionContextId = 'a'.repeat(64);
});
test('preparation storage validates retry destinations and never loads another principal intent', async () => {
    stored.clear(); let v = mountPreparation(); await change(v, 'market-country', 'SA'); transport.send = async () => { throw { isAxiosError: true }; }; await submit(v); v.unmount();
    const key = 'commercial-market-approval:v2:7'; const saved = stored.get(key);
    globalThis.__bb1Page.auth.user.id = 8; v = mountPreparation(); assert.equal(buttonWithText(v, 'إعادة المحاولة بنفس الطلب'), undefined); v.unmount();
    globalThis.__bb1Page.auth.user.id = 7; const malicious = JSON.parse(saved); malicious.url = 'https://untrusted.example/collect'; stored.set(key, JSON.stringify(malicious));
    v = mountPreparation(); assert.equal(buttonWithText(v, 'إعادة المحاولة بنفس الطلب'), undefined); assert.equal(transport.requests.length, 0); v.unmount(); stored.clear();
});
test('Commercial preserves the exact command across remount and stale authentication without restoring authority', async () => {
    stored.clear(); const marketId = '66666666-6666-4666-8666-666666666666'; const configurationId = '77777777-7777-4777-8777-777777777777';
    const overrides = { markets: [{ id: marketId, label: 'Saudi', configuration_id: null, revision: 0 }], configurations: [], catalogReferences: [], founderAccess: true, newConfigurationId: configurationId };
    let v = mount(overrides); await change(v, 'commercial-market_id', marketId); await change(v, 'commercial-operation', 'configuration');
    transport.send = async () => { throw { isAxiosError: true }; }; await submit(v);
    const original = structuredClone(transport.requests[0][1]); assert.equal(JSON.parse(stored.get('commercial-command:v1:7')).payload.command_id, original.command_id); v.unmount();
    globalThis.__bb1Page.auth.sessionContextId = 'new-session';
    v = mount({ ...overrides, founderAccess: false, freshAuthentication: false, newConfigurationId: '88888888-8888-4888-8888-888888888888' });
    assert.equal(nodes(v.root, n => n.tag === 'fieldset')[0].props.disabled, true); assert.equal(navigationDenied('get', '/internal/administration'), true);
    transport.send = async () => { throw { isAxiosError: true, response: { status: 419 } }; };
    await buttonWithText(v, 'إعادة الطلب الأصلي دون تغيير').props.onClick(); assert.deepEqual(transport.requests[0][1], original);
    assert.equal(nodes(v.root, n => n.tag === 'fieldset')[0].props.disabled, true);
    buttonWithText(v, 'تسجيل الدخول مجددًا').props.onClick(); assert.equal(navigationDenied('post', 'internal.logout/undefined'), false);
    v.unmount(); stored.clear(); globalThis.__bb1Page.auth.sessionContextId = 'a'.repeat(64);
});
test('storage failure prevents a Commercial mutation before its original intent is retained', async () => {
    stored.clear(); const v = mount(); await change(v, 'commercial-market_id', 'sa'); await change(v, 'commercial-operation', 'configuration');
    const save = sessionStorage.setItem; sessionStorage.setItem = () => { throw new Error('Storage unavailable'); };
    try { await submit(v); assert.equal(transport.requests.length, 0); assert.ok(byId(v, 'commercial-alert')); }
    finally { sessionStorage.setItem = save; v.unmount(); stored.clear(); }
});
