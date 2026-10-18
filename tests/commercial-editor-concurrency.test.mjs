import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, ref, shallowReactive, shallowRef } from 'vue';

globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.document = { activeElement: null, getElementById: () => null };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const vue = import.meta.resolve('vue');
const empty = moduleUrl('export default {render:()=>null};');
const locale = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const copy = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const useBusinessCopy=()=>({locale:ref('en'),localized:value=>value?.name_en??''});`);
async function compile(file, ports = {}) {
    const sourceFile = await readFile(new URL('../resources/js/commercial/' + file, import.meta.url), 'utf8');
    const { descriptor } = parse(sourceFile);
    const script = compileScript(descriptor, { id: file, inlineTemplate: true });
    let source = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries({ vue, '@/Components/ProviderBrand.vue': empty, '../spa/locale': locale, '@/localization/businessCopy': copy, ...ports })) {
        source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target)).replaceAll(`from "${name}"`, 'from ' + JSON.stringify(target));
    }
    const url = moduleUrl(source);
    return { url, component: (await import(url)).default };
}
function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, value: '', checked: false,
        get options() { return this.children.filter(child => child.tag === 'option'); },
        getRootNode() { return {}; }, addEventListener(name, handler) { this.listeners[name] = handler; } };
}
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText(node, text) { node.text = text; }, setElementText(node, text) { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, previous, value) { node.props[key] = value; if (key === 'value') node.value = node._value = value; if (key === 'checked') node.checked = value; },
});

globalThis.sessionStorage = { values: new Map(), getItem(key) { return this.values.get(key) ?? null; }, setItem(key,value) { this.values.set(key,value); }, removeItem(key) { this.values.delete(key); } };
globalThis.localStorage = globalThis.sessionStorage;
globalThis.confirm = () => true;
const descendants = root => [root, ...root.children.flatMap(descendants)];
const findAll = (root, predicate) => descendants(root).filter(predicate);
const textOf = root => root.text + root.children.map(textOf).join('');
const byId = (view, id) => findAll(view.root, node => node.props.id === id)[0];
const byData = (view, name, value) => findAll(view.root, node => node.props[name] === value)[0];
async function flush() { await nextTick(); await new Promise(setImmediate); await nextTick(); }
async function click(node) { assert.ok(node); await node.props.onClick({}); await flush(); }
async function input(node, value) { node.value = value; node.listeners.input({ target: node }); await flush(); }
async function submit(form) { const pending = form.props.onSubmit({ preventDefault() {} }); await flush(); return pending; }
const marketId = '00000000-0000-4000-8000-000000000001', configurationId = '00000000-0000-4000-8000-000000000002', referenceId = '00000000-0000-4000-8000-000000000003';
const projection = () => ({ markets: [{ id: marketId, label: 'Synthetic', country_code: 'JO', configuration_id: configurationId, revision: 1 }],
    configurations: [{ id: configurationId, market_id: marketId, version: 1, catalog_reference_id: referenceId, denomination_code: 'USD' }],
    catalogReferences: [{ id: referenceId, market_id: marketId, catalog_identity: referenceId, catalog_version: '1', approval_reference: 'synthetic:proof', source_sha256: 'a'.repeat(64) }],
    targets: [], newMarketTargets: [], obligations: [], newConfigurationId: '00000000-0000-4000-8000-000000000004', newCatalogReferenceId: referenceId, freshAuthentication: true, founderAccess: true, result: null, countryOptions: {}, currencyOptions: ['USD'], currencyNames: {} });
async function mountEditor() {
    sessionStorage.values.clear();
    const key = 'commercialEditor' + crypto.randomUUID().replaceAll('-', '');
    const props = shallowReactive(projection()), runtime = { writes: [], writer: async () => ({ saved: true }), reader: async () => props, context: async () => ({ marker: 'current' }), revision: 0, callbacks: new Set() };
    globalThis[key] = runtime;
    const state = moduleUrl(`const runtime=globalThis.${key};export async function writeCommercial(command){runtime.writes.push(structuredClone(command));return runtime.writer(command)}export const readOperations=()=>runtime.reader(),readCommercialContext=()=>runtime.context(),readCommercialWorkspace=async()=>null,readCommercialReceipt=async()=>({}),recoverCommercialAudit=async()=>({});`);
    const transport = moduleUrl(`const runtime=globalThis.${key};export const bootstrap={identity:{binding:{principal:7}}},close=()=>{runtime.revision++;runtime.callbacks.forEach(callback=>callback())},onClose=callback=>{runtime.callbacks.add(callback);return runtime.callbacks},owned=value=>value===runtime.revision,stamp=()=>runtime.revision,logout=async()=>{};export class TransportError extends Error{constructor(status){super();this.status=status;this.body={}}}`);
    const shell = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default{setup(props,{slots}){return()=>h('main',{},[slots.header?.(),slots.default?.()])}}`);
    const component = await compile('OperationsEditor.vue', { '../internal-operations/OperationsShell.vue': shell, './operationsState': state, './receiptProvenance': moduleUrl('export const matchesOriginalReceipt=async()=>false;'), '../spa/transport': transport, '../spa/presentation': moduleUrl('export const confirmDeparture={value:()=>true};'), 'vue-router': moduleUrl('export const onBeforeRouteLeave=()=>{},RouterLink={render:()=>null};') });
    const root = element('root'), app = renderer.createApp({render:()=>h(component.component,{...props})});app.mount(root);await flush();
    return { root, props, runtime, stop(){app.unmount();delete globalThis[key]} };
}

test('actual commercial editor never advances a dirty assignability revision from an ordinary newer read', async () => {
    const view = await mountEditor();
    try {
        await click(byData(view,'data-manage-market',marketId));await click(byData(view,'data-primary-availability','true'));
        assert.equal(byId(view,'commercial-expected_revision').value,'1');
        view.props.markets=[{...view.props.markets[0],revision:2}];await flush();
        assert.equal(byId(view,'commercial-expected_revision').value,'1');
        const form=findAll(view.root,node=>node.tag==='form')[0];await submit(form);
        assert.equal(view.runtime.writes.length,1);assert.equal(view.runtime.writes[0].body.expected_revision,1);
    } finally { view.stop(); }
});

test('actual commercial context callback from first A cannot publish after A to B to A', async () => {
    const view=await mountEditor();
    try {
        let finish;view.runtime.context=()=>new Promise(resolve=>{finish=resolve});
        const field=byId(view,'context-scope');await input(field,'A');
        let form=field;while(form.tag!=='form')form=form.parent;
        const pending=form.props.onSubmit({preventDefault(){}});await flush();
        await input(field,'B');await input(field,'A');finish({marker:'obsolete-private-first-A'});await pending;await flush();
        assert.equal(textOf(view.root).includes('obsolete-private-first-A'),false);
    } finally { view.stop(); }
});

test('known write ACK and failed readback remain distinct without a second write', async () => {
    const view=await mountEditor();
    try {
        await click(byData(view,'data-manage-market',marketId));await click(byData(view,'data-primary-availability','true'));view.runtime.reader=async()=>undefined;
        await submit(findAll(view.root,node=>node.tag==='form')[0]);
        assert.equal(view.runtime.writes.length,1);assert.equal(textOf(view.root).includes('The server confirmed the operation result.'),true);
        await submit(findAll(view.root,node=>node.tag==='form')[0]);assert.equal(view.runtime.writes.length,1);
    } finally { view.stop(); }
});

test('lost ACK preserves frozen original payload and only explicit same-command retry writes again', async () => {
    const view=await mountEditor();
    try {
        await click(byData(view,'data-manage-market',marketId));await click(byData(view,'data-primary-availability','true'));view.runtime.writer=async()=>{throw Error('lost ACK')};
        const form=findAll(view.root,node=>node.tag==='form')[0];await submit(form);
        const original=structuredClone(view.runtime.writes[0]);view.props.markets=[{...view.props.markets[0],revision:5}];await flush();
        assert.equal(view.runtime.writes.length,1);assert.equal(sessionStorage.values.size,1);
        await submit(form);assert.deepEqual(view.runtime.writes[1],original);
    } finally { view.stop(); }
});
