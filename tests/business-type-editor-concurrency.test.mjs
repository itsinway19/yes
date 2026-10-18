import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, nextTick, ref, shallowReactive, shallowRef } from 'vue';

globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.document = { activeElement: null };
globalThis.window = { addEventListener() {}, removeEventListener() {} };
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const vue = import.meta.resolve('vue');
const empty = moduleUrl('export default {render:()=>null};');
const locale = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const copy = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const useBusinessCopy=()=>({locale:ref('en'),localized:value=>value?.name_en??''});`);
async function compile(file, ports = {}) {
    const baseline = process.env.BUSINESS_EDITOR_BASELINE;
    if (baseline) assert.equal(baseline, '6cd246f6a621049d0e701f70d12aa484511b7fe2');
    const sourceFile = baseline ? execFileSync('git', ['show', baseline + ':resources/js/internal-operations/' + file], { encoding: 'utf8' }) : await readFile(new URL('../resources/js/internal-operations/' + file, import.meta.url), 'utf8');
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
const priorRow = (overrides = {}) => ({ id: 11, version: 1, provider: 'google', product: 'google.search', basis: 'ADMIN_APPROVED_HYPOTHESIS', documented_basis: 'Documented basis', applicability: 'Original applicability', limits: 'Documented limits', rationale_ar: 'مبرر موثق للاختبار', rationale_en: 'Original public rationale', internal_rationale: 'Private test rationale', approved: true, active: true, ...overrides });
const definition = (overrides = {}) => ({ id: 7, version: 1, key: 'operations', name_ar: 'العمليات', name_en: 'Original definition', active: true, source_guidance: [], promotion_priors: [priorRow()], ...overrides });
const data = type => ({ types: [type], providers: [{ key: 'google', label: 'Google' }], products: [{ key: 'google.search', provider: 'google', label_ar: 'بحث', label_en: 'Search' }], canManage: true });
const descendants = root => [root, ...root.children.flatMap(descendants)];
const findAll = (root, predicate) => descendants(root).filter(predicate);
const textOf = root => root.text + root.children.map(textOf).join('');
const forms = view => findAll(view.root, node => node.tag === 'form');
const inputs = root => findAll(root, node => node.tag === 'input');
const textareas = root => findAll(root, node => node.tag === 'textarea');
async function input(node, value) { node.value = value; node.listeners.input({ target: node }); await nextTick(); }
async function submit(form) { await form.props.onSubmit({ preventDefault() {} }); await flush(); }
async function click(node) { assert.ok(node, 'Expected explicit UI action'); await node.props.onClick({}); await flush(); }
const button = (view, text) => findAll(view.root, node => node.tag === 'button').find(node => textOf(node) === text);
async function flush() { await nextTick(); await new Promise(setImmediate); await nextTick(); }
const editor = await compile('BusinessTypeEditor.vue');
function mountEditor(type = definition()) {
    const events = [], root = element('root'), props = shallowReactive({ type, providers: data(type).providers, products: data(type).products, canManage: true });
    const app = renderer.createApp({ render: () => h(editor.component, { ...props, onSave: (command, settle) => events.push({ command, settle }) }) }); app.mount(root);
    return { root, props, events, stop: () => app.unmount() };
}
async function mountParent() {
    const key = 'businessConcurrency' + crypto.randomUUID().replaceAll('-', '');
    const runtime = { resource: shallowRef(data(definition())), busy: ref(false), outcome: ref(''), writes: [], writer: async () => ({ saved: true }), reader: async () => runtime.resource.value, closed: false };
    globalThis[key] = runtime;
    const state = moduleUrl(`const runtime=globalThis.${key};export const businessTypes=runtime.resource,businessBusy=runtime.busy,businessOutcome=runtime.outcome;export async function writeBusiness(command){runtime.writes.push(structuredClone(command));return runtime.writer(command)}export async function readBusinessTypes(){const result=await runtime.reader();if(result)runtime.resource.value=result;return result}`);
    const transport = moduleUrl(`const runtime=globalThis.${key};export const close=()=>{runtime.closed=true},onClose=()=>new Set(),owned=()=>!runtime.closed,stamp=()=>0;export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body}}`);
    const shell = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default{setup(props,{slots}){return()=>h('main',{},[slots.header?.(),slots.default?.()])}}`);
    const parent = await compile('BusinessTypesView.vue', { './BusinessTypeEditor.vue': editor.url, './OperationsShell.vue': shell, './businessState': state, '../spa/transport': transport, '../spa/presentation': moduleUrl(`export const confirmDeparture={value:()=>true};`), 'vue-router': moduleUrl('export const onBeforeRouteLeave=()=>{};') });
    const root = element('root'), app = renderer.createApp(parent.component); app.mount(root);
    return { root, runtime, stop() { app.unmount(); delete globalThis[key]; } };
}

test('dirty A draft keeps its v1 fields/version when B v2 is read and emits v1 for the existing Laravel conflict guard', async () => {
    const view = mountEditor();
    try {
        await input(inputs(forms(view)[0])[2], 'A unsaved edit'); view.props.type = definition({ version: 2, name_en: 'B committed edit', active: false }); await flush();
        assert.equal(inputs(forms(view)[0])[2].value, 'A unsaved edit'); await submit(forms(view)[0]);
        assert.equal(view.events[0].command.body.version, 1); assert.equal(view.events[0].command.body.name_en, 'A unsaved edit');
    } finally { view.stop(); }
});
test('a clean draft adopts server fields and version together', async () => {
    const view = mountEditor();
    try {
        view.props.type = definition({ version: 2, name_ar: 'تعديل باء', name_en: 'B committed edit', active: false }); await flush(); await submit(forms(view)[0]);
        assert.deepEqual(view.events[0].command.body, { key: 'operations', name_ar: 'تعديل باء', name_en: 'B committed edit', active: false, version: 2 });
    } finally { view.stop(); }
});
test('actual parent ACK plus admitted readback settles the definition, including server-normalized fields', async () => {
    const view = await mountParent();
    try {
        await input(inputs(forms(view)[1])[2], ' A saved edit '); view.runtime.reader = async () => data(definition({ version: 2, name_en: 'A saved edit' }));
        await submit(forms(view)[1]); assert.equal(view.runtime.outcome.value, 'acknowledged'); assert.equal(inputs(forms(view)[1])[2].value, 'A saved edit');
        await submit(forms(view)[1]); assert.equal(view.runtime.writes[1].body.version, 2);
    } finally { view.stop(); }
});
test('post-submission edits settle against ACK plus trusted readback without losing edits or guessing a version', async () => {
    const view = await mountParent(); let acknowledge;
    try {
        view.runtime.writer = () => new Promise(resolve => { acknowledge = resolve; }); view.runtime.reader = async () => data(definition({ version: 2, name_en: 'Submitted edit' }));
        await input(inputs(forms(view)[1])[1], ' العمليات '); await input(inputs(forms(view)[1])[2], 'Submitted edit'); const pending = submit(forms(view)[1]); await flush();
        await input(inputs(forms(view)[1])[2], 'New edit while waiting'); acknowledge({ saved: true }); await pending;
        assert.equal(inputs(forms(view)[1])[2].value, 'New edit while waiting'); view.runtime.writer = async () => ({ saved: true });
        await submit(forms(view)[1]); assert.equal(view.runtime.writes[1].body.version, 2); assert.equal(view.runtime.writes[1].body.name_en, 'New edit while waiting'); assert.equal(view.runtime.writes[1].body.name_ar, 'العمليات');
    } finally { view.stop(); }
});
test('lost ACK and explicit state review do not promote a submitted draft or automatically resend it', async () => {
    const view = await mountParent();
    try {
        await input(inputs(forms(view)[1])[2], 'Uncertain edit'); view.runtime.writer = async () => { throw Error('ACK lost'); }; await submit(forms(view)[1]);
        assert.equal(view.runtime.outcome.value, 'unknown'); view.runtime.reader = async () => data(definition({ version: 2, name_en: 'Server state after uncertain delivery' }));
        await click(button(view, 'Review state without replay')); assert.equal(view.runtime.writes.length, 1); assert.equal(inputs(forms(view)[1])[2].value, 'Uncertain edit');
        view.runtime.writer = async () => ({ saved: true }); await submit(forms(view)[1]); assert.equal(view.runtime.writes[1].body.version, 1);
    } finally { view.stop(); }
});
test('known ACK with failed readback settles only after explicit admitted read review', async () => {
    const view = await mountParent();
    try {
        await input(inputs(forms(view)[1])[2], ' Saved edit '); view.runtime.reader = async () => undefined; await submit(forms(view)[1]);
        assert.equal(view.runtime.outcome.value, 'acknowledged'); assert.equal(inputs(forms(view)[1])[2].value, ' Saved edit ');
        view.runtime.reader = async () => data(definition({ version: 2, name_en: 'Saved edit' })); await click(button(view, 'Review state without replay'));
        assert.equal(inputs(forms(view)[1])[2].value, 'Saved edit'); assert.equal(view.runtime.writes.length, 1);
    } finally { view.stop(); }
});
test('explicit reload discards a dirty definition only by user action and loads fields with version', async () => {
    const view = mountEditor();
    try {
        await input(inputs(forms(view)[0])[2], 'Unsaved edit'); view.props.type = definition({ version: 2, name_en: 'B server edit', active: false }); await flush();
        await click(button(view, 'Discard definition draft and load current state')); await submit(forms(view)[0]);
        assert.equal(view.events[0].command.body.version, 2); assert.equal(view.events[0].command.body.name_en, 'B server edit'); assert.equal(view.events[0].command.body.active, false);
    } finally { view.stop(); }
});
test('reusing the component for another definition clears old definition, guidance and prior drafts', async () => {
    const view = mountEditor();
    try {
        await input(inputs(forms(view)[0])[2], 'Private A draft'); await input(textareas(forms(view)[1])[0], 'Private guidance draft');
        await click(button(view, 'Edit / review')); await input(textareas(forms(view)[2])[1], 'Private prior draft');
        view.props.type = definition({ id: 8, key: 'different', version: 5, name_en: 'Definition B', promotion_priors: [] }); await flush();
        await submit(forms(view)[0]); assert.equal(view.events[0].command.type, 8); assert.equal(view.events[0].command.body.name_en, 'Definition B');
        assert.equal(textareas(forms(view)[1])[0].value, ''); assert.equal(textareas(forms(view)[2])[1].value, '');
    } finally { view.stop(); }
});
test('related Prior draft is not erased by a replacement read, even when its server row has not changed', async () => {
    const view = mountEditor();
    try {
        await click(button(view, 'Edit / review')); await input(textareas(forms(view)[2])[1], 'A local applicability edit');
        view.props.type = definition({ version: 2 }); await flush(); assert.equal(textareas(forms(view)[2])[1].value, 'A local applicability edit');
        await submit(forms(view)[2]); assert.equal(view.events[0].command.body.version, 1); assert.equal(view.events[0].command.body.applicability, 'A local applicability edit');
    } finally { view.stop(); }
});
test('clean selected Prior synchronizes all fields/version, and explicit reload replaces a dirty Prior consistently', async () => {
    const view = mountEditor();
    try {
        await click(button(view, 'Edit / review')); view.props.type = definition({ promotion_priors: [priorRow({ version: 2, applicability: 'B prior applicability', approved: false })] }); await flush();
        assert.equal(textareas(forms(view)[2])[1].value, 'B prior applicability'); await input(textareas(forms(view)[2])[1], 'New local prior draft');
        view.props.type = definition({ promotion_priors: [priorRow({ version: 3, applicability: 'Latest prior applicability', approved: false })] }); await flush();
        await click(button(view, 'Discard prior draft and load current state')); await submit(forms(view)[2]);
        assert.equal(view.events[0].command.body.version, 3); assert.equal(view.events[0].command.body.applicability, 'Latest prior applicability');
    } finally { view.stop(); }
});

test('confirmed Prior save settles the selected row with authoritative approval/version rather than merely seeing a read', async () => {
    const view = await mountParent();
    try {
        await click(button(view, 'Edit / review')); await input(textareas(forms(view)[3])[1], 'New applicability');
        view.runtime.reader = async () => data(definition({ promotion_priors: [priorRow({ version: 2, applicability: 'New applicability', approved: false })] }));
        await submit(forms(view)[3]); assert.equal(view.runtime.outcome.value, 'acknowledged');
        await submit(forms(view)[3]); assert.equal(view.runtime.writes[1].body.version, 2); assert.equal(view.runtime.writes[1].body.approved, false);
    } finally { view.stop(); }
});

test('Prior post-submission edits survive ACK/readback and inherit actual SQL approval and version', async () => {
    const view = await mountParent(); let acknowledge;
    try {
        await click(button(view, 'Edit / review')); await input(textareas(forms(view)[3])[1], 'Submitted applicability');
        view.runtime.writer = () => new Promise(resolve => { acknowledge = resolve; });
        view.runtime.reader = async () => data(definition({ promotion_priors: [priorRow({ version: 2, applicability: 'Submitted applicability', approved: false })] }));
        const pending = submit(forms(view)[3]); await flush(); await input(textareas(forms(view)[3])[1], 'Later applicability edit'); acknowledge({ saved: true }); await pending; await flush();
        assert.equal(textareas(forms(view)[3])[1].value, 'Later applicability edit'); view.runtime.writer = async () => ({ saved: true });
        await submit(forms(view)[3]); assert.equal(view.runtime.writes[1].body.version, 2); assert.equal(view.runtime.writes[1].body.approved, false);
    } finally { view.stop(); }
});

test('a late acknowledgement cannot settle a reused definition component, including A to B to A', async () => {
    const view = mountEditor();
    try {
        await input(inputs(forms(view)[0])[2], 'A submitted'); await submit(forms(view)[0]); const settle = view.events[0].settle;
        view.props.type = definition({ id: 8, key: 'other', version: 4, name_en: 'B definition' }); await flush();
        view.props.type = definition({ version: 3, name_en: 'A latest definition' }); await flush();
        assert.equal(typeof settle, 'function'); settle(definition({ version: 2, name_en: 'Late old A acknowledgement' })); await flush();
        await submit(forms(view)[0]); assert.equal(view.events[1].command.body.version, 3); assert.equal(view.events[1].command.body.name_en, 'A latest definition');
    } finally { view.stop(); }
});

test('overlapping B changes after A ACK do not rebase newer local edits past the original concurrency guard', async () => {
    const view = await mountParent(); let acknowledge;
    try {
        view.runtime.writer = () => new Promise(resolve => { acknowledge = resolve; });
        view.runtime.reader = async () => data(definition({ version: 3, name_en: 'B overwrote the acknowledged value' }));
        await input(inputs(forms(view)[1])[2], 'A submitted edit'); const pending = submit(forms(view)[1]); await flush();
        await input(inputs(forms(view)[1])[2], 'A later unsaved edit'); acknowledge({ saved: true }); await pending; await flush();
        assert.equal(inputs(forms(view)[1])[2].value, 'A later unsaved edit'); view.runtime.writer = async () => ({ saved: true });
        await submit(forms(view)[1]); assert.equal(view.runtime.writes[1].body.version, 1);
    } finally { view.stop(); }
});
