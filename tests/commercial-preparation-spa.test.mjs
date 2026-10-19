import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { createRenderer, h, nextTick, ref, shallowRef } from 'vue';
import { parse, compileScript } from '@vue/compiler-sfc';
import { execFileSync } from 'node:child_process';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
async function fixture(reader) {
    const key = 'commercialPreparation' + crypto.randomUUID().replaceAll('-', '');
    const runtime = { reader, revision: 0, status: ref('checking'), closed: false, callbacks: [] };
    globalThis[key] = runtime;
    const transport = moduleUrl(`const runtime=globalThis.${key}; export const status=runtime.status,stamp=()=>runtime.revision,owned=value=>value===runtime.revision&&!runtime.closed,resumeLease=()=>{},onClose=callback=>runtime.callbacks.push(callback),close=()=>{runtime.closed=true;runtime.status.value='closed';runtime.callbacks.forEach(callback=>callback())}; export class TransportError extends Error{constructor(status){super();this.status=status}}`);
    let source = ts.transpileModule(await readFile(new URL('../resources/js/commercial/preparationState.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries({ vue: import.meta.resolve('vue'), '../spa/api': moduleUrl(`export const api={GET:(...args)=>globalThis.${key}.reader(...args),POST:(...args)=>globalThis.${key}.reader(...args)};`), '../spa/transport': transport })) source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target));
    return { state: await import(moduleUrl(source)), runtime, transport: await import(transport), dispose: () => delete globalThis[key] };
}
const visibility = { protected: true, path: '/internal/administration/commercial-markets', context: 'authoritative-current' };
const resource = intentId => ({ data: { data: { intentId }, visibility } });

test('passive preparation reads preserve exact intent and require a matching current witness', async () => {
    const calls = [], view = await fixture(async (_url, options) => { calls.push(options.headers); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource('original-intent'); });
    try {
        await view.state.readPreparation(true);
        assert.equal(view.state.preparationData.value.intentId, 'original-intent');
        assert.deepEqual(calls, [{ 'X-Builderad-Passive': '1' }, { 'X-Builderad-Witness': '1' }]);
    } finally { view.dispose(); }
});

test('A to B to A lifetime suppresses first A even when its aborted read resolves', async () => {
    let resolveFirst, reads = 0;
    const view = await fixture(async (_url, options) => {
        if (options.headers['X-Builderad-Witness']) return { data: { visibility } };
        if (++reads === 1) return new Promise(resolve => { resolveFirst = resolve; });
        return resource(reads === 2 ? 'B' : 'new-A');
    });
    try {
        const first = view.state.readPreparation();
        view.state.clearPreparation(); await view.state.readPreparation();
        view.state.clearPreparation(); await view.state.readPreparation();
        resolveFirst(resource('obsolete-A')); await first;
        assert.equal(view.state.preparationData.value.intentId, 'new-A');
    } finally { view.dispose(); }
});

test('post-load authority refusal closes and clears private preparation data', async () => {
    let denied = false;
    const view = await fixture(async (_url, options) => { if (denied) throw new view.transport.TransportError(403); return options.headers['X-Builderad-Witness'] ? { data: { visibility } } : resource('private'); });
    try {
        await view.state.readPreparation(); denied = true; await view.state.readPreparation(false, true);
        assert.equal(view.runtime.status.value, 'closed'); assert.equal(view.state.preparationData.value, undefined);
    } finally { view.dispose(); }
});

test('lost write ACK makes one request preserving original composite identities without replay', async () => {
    const calls = [], view = await fixture(async (url, options) => { calls.push({ url, body: structuredClone(options.body) }); throw Error('ACK lost'); });
    const body = Object.freeze({ mode: 'direct', intent_id: 'original-intent', intent_binding: 'original-founder-target-and-child-binding', country_code: 'JO', denomination_code: 'USD', reason: 'Explicit request' });
    try {
        await assert.rejects(view.state.writePreparation({ kind: 'prepare', body }), /ACK lost/);
        assert.deepEqual(calls, [{ url: '/internal/administration/commercial-markets', body }]);
    } finally { view.dispose(); }
});

const approvalA = '00000000-0000-4000-8000-000000000001';
const approvalB = '00000000-0000-4000-8000-000000000002';
const operatorId = '00000000-0000-4000-8000-000000000003';
const originalOperator = { kind: 'operator', approval: approvalA, body: { operator: operatorId, reason: 'Original operator follow-up' } };
const activeKey = 'commercial-preparation-spa:v1:7';
const projection = () => ({ intentId: approvalB, intentBinding: 'current-binding', canPrepare: true, freshAuthentication: true,
    approvals: [{ id: approvalA, label: 'Approval A', consumed: false, active: true, canAuthorize: true }], people: [],
    countryOptions: {}, currencyOptions: [], recordedMarkets: [] });
function hostElement(tag, text = '') {
    return { tag, text, props: {}, children: [], parent: null, listeners: {}, inputValue: '',
        get value() { return this.tag === 'select' ? this.options.find(option => option.selected)?._value ?? '' : this.inputValue; },
        set value(value) { this.inputValue = value; if (this.tag === 'select') this.options.forEach(option => { option.selected = option._value === value; }); },
        get options() { return this.children.filter(child => child.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(option => option.selected); },
        set selectedIndex(index) { this.options.forEach((option, position) => { option.selected = position === index; }); },
        getRootNode() { return {}; }, addEventListener(name, handler) { this.listeners[name] = handler; } };
}
const host = createRenderer({
    createElement: hostElement, createText: text => hostElement('#text', text), createComment: text => hostElement('#comment', text),
    setText(node, text) { node.text = text; }, setElementText(node, text) { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, previous, value) { node.props[key] = value; if (key === 'value') node.value = node._value = value; },
});
const nodes = root => [root, ...root.children.flatMap(nodes)];
const content = root => root.text + root.children.map(content).join('');
async function settle() { await nextTick(); await new Promise(setImmediate); await nextTick(); }
const reviewLabel = 'Review current state — not an acknowledgement';
const retireLabel = 'End operator follow-up — keep original outcome unknown';
async function mountPreparation({ saved = originalOperator, storage, data = projection(), principal = 7 } = {}) {
    globalThis.Document = class {};
    globalThis.ShadowRoot = class {};
    globalThis.document = { activeElement: null, getElementById: () => null };
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    const values = storage ?? new Map(saved ? [[activeKey, JSON.stringify(saved)]] : []);
    const runtime = { data: shallowRef(data), writes: [], revision: 0, closed: false, callbacks: new Set(), confirm: () => true,
        saveFailure: false, deleteFailure: false, reader: async () => runtime.data.value,
        writer: async () => { throw Error('Lost ACK'); } };
    globalThis.confirm = () => runtime.confirm();
    globalThis.sessionStorage = { get length() { return values.size; }, key: index => [...values.keys()][index],
        getItem: key => values.get(key) ?? null,
        setItem(key, value) { if (runtime.saveFailure && key.includes(':reviewed:')) throw Error('Archive unavailable'); values.set(key, value); if (runtime.failAfterSave && key.includes(':reviewed:')) throw Error('Archive saved before interruption'); },
        removeItem(key) { if (runtime.deleteFailure && key === activeKey) throw Error('Active cleanup unavailable'); values.delete(key); } };
    const key = 'preparationView' + crypto.randomUUID().replaceAll('-', ''); globalThis[key] = runtime;
    const vue = import.meta.resolve('vue');
    const transportUrl = moduleUrl(`const runtime=globalThis.${key};export const bootstrap={identity:{binding:{principal:${principal}}}},stamp=()=>runtime.revision,owned=value=>value===runtime.revision&&!runtime.closed,logout=async()=>{},onClose=callback=>{runtime.callbacks.add(callback);return runtime.callbacks},close=()=>{runtime.closed=true;runtime.revision++;runtime.callbacks.forEach(callback=>callback())};export class TransportError extends Error{constructor(status){super('HTTP '+status);this.status=status}}`);
    const ports = { vue, 'vue-router': moduleUrl('export const onBeforeRouteLeave=()=>{};'),
        '../internal-operations/OperationsShell.vue': moduleUrl(`import {h} from ${JSON.stringify(vue)};export default{setup(props,{slots}){return()=>h('main',{},[slots.header?.(),slots.default?.()])}}`),
        './preparationState': moduleUrl(`const runtime=globalThis.${key};export const preparationData=runtime.data,readPreparation=(...args)=>runtime.reader(...args),writePreparation=command=>{runtime.writes.push(structuredClone(command));return runtime.writer(command)};`),
        '../spa/transport': transportUrl, '../spa/locale': moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`),
        '../spa/presentation': moduleUrl('export const confirmDeparture={value:()=>true};') };
    ports['./preparationAttempt'] = moduleUrl(ts.transpileModule(await readFile(new URL('../resources/js/commercial/preparationAttempt.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText);
    const componentSource = process.env.COMMERCIAL_OPERATOR_BASELINE === 'reviewed'
        ? execFileSync('git', ['show', '8391d8d3d6733f8833372f10e1efa733c16ec80c:resources/js/commercial/PreparationView.vue'], { encoding: 'utf8' })
        : await readFile(new URL('../resources/js/commercial/PreparationView.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(componentSource);
    let source = ts.transpileModule(compileScript(descriptor, { id: 'operator-followup', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    for (const [name, target] of Object.entries(ports)) source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target)).replaceAll(`from "${name}"`, 'from ' + JSON.stringify(target));
    const component = (await import(moduleUrl(source))).default, root = hostElement('root'), app = host.createApp({ render: () => h(component) });
    app.mount(root); await settle();
    return { root, runtime, values, transport: await import(transportUrl),
        button: label => nodes(root).find(node => node.tag === 'button' && content(node) === label),
        async click(label) { const button = this.button(label); assert.ok(button, `Missing ${label}`); assert.ok(!button.props.disabled); await button.props.onClick({}); await settle(); },
        stop() { app.unmount(); delete globalThis[key]; } };
}
function consumedReview(view, approval = approvalA) {
    view.runtime.reader = async () => {
        const current = { ...projection(), approvals: [{ id: approval, consumed: true, active: false, canAuthorize: false }] };
        view.runtime.data.value = current; return current;
    };
}
const archived = view => [...view.values.entries()].filter(([key]) => key.includes(':reviewed:')).map(([, value]) => JSON.parse(value));

async function choose(node, value) {
    assert.ok(node);
    for (const option of node.options) option.selected = option._value === value;
    node.value = value; node.listeners.change({ target: node }); await node.props.onChange?.({ target: node }); await settle();
}
async function enter(node, value) { node.value = value; node.listeners.input({ target: node }); await settle(); }
const prepareForm = view => nodes(view.root).find(node => node.props['data-commercial-prepare'] !== undefined);
const field = (view, id) => nodes(view.root).find(node => node.props.id === id);
async function prepareDirect(view) {
    await choose(field(view, 'preparation-country'), 'JO');
    await choose(field(view, 'preparation-currency'), 'USD');
    await prepareForm(view).props.onSubmit({ preventDefault() {} }); await settle();
}
const directData = () => ({ ...projection(), approvals: [], countryOptions: { JO: { ar: 'الأردن', en: 'Jordan', currency: 'JOD' } }, currencyOptions: ['JOD', 'USD'] });

test('actual direct preparation needs neither employee, expiry nor reason and freezes country plus independent currency', async () => {
    const view = await mountPreparation({ saved: null, data: directData() });
    try {
        await choose(field(view, 'preparation-country'), 'JO');
        assert.equal(field(view, 'preparation-currency').value, 'JOD');
        const inputs = nodes(prepareForm(view)).filter(node => node.tag === 'input');
        assert.equal(inputs.length, 1); assert.equal(inputs[0].props.required, false);
        await choose(field(view, 'preparation-currency'), 'USD');
        await prepareForm(view).props.onSubmit({ preventDefault() {} }); await settle();
        assert.deepEqual(view.runtime.writes, [{ kind: 'prepare', body: { intent_id: approvalB, intent_binding: 'current-binding', reason: '', mode: 'direct', country_code: 'JO', denomination_code: 'USD' } }]);
        const original = structuredClone(view.runtime.writes[0]);
        await prepareForm(view).props.onSubmit({ preventDefault() {} }); assert.equal(view.runtime.writes.length, 1);
        view.runtime.data.value = { ...directData(), intentId: approvalA, intentBinding: 'newer-binding' }; await settle();
        await view.click('Explicitly retry the same request'); assert.deepEqual(view.runtime.writes[1], original);
    } finally { view.stop(); }
});

test('existing-market delegation keeps bounded permissions and exact market/operator without creating a market', async () => {
    const data = directData(); data.recordedMarkets = [{ id: approvalA, label: 'Recorded market' }];
    data.people = [{ public_id: operatorId, name: 'Eligible', kind: 'employee' }];
    const view = await mountPreparation({ saved: null, data });
    try {
        const form = nodes(view.root).find(node => node.tag === 'form' && nodes(node).some(child => child.tag === 'button' && content(child) === 'Delegate'));
        const selects = nodes(form).filter(node => node.tag === 'select');
        assert.deepEqual(selects[2].options.map(option => option._value), ['read', 'manage']);
        await choose(selects[0], approvalA); await choose(selects[1], operatorId); await choose(selects[2], 'manage');
        const inputs = nodes(form).filter(node => node.tag === 'input'); await enter(inputs[0], 'Explicit delegation'); await enter(inputs[1], '2030-01-01T12:00');
        await form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.equal(view.runtime.writes[0].kind, 'delegate');
        assert.deepEqual({ ...view.runtime.writes[0].body, expires_at: 'normalized' }, { market_id: approvalA, operator: operatorId, permission: 'manage', reason: 'Explicit delegation', expires_at: 'normalized' });
    } finally { view.stop(); }
});

test('read-only preparation presents evidence without founder forms or hidden command writes', async () => {
    const view = await mountPreparation({ saved: null, data: { ...directData(), canPrepare: false } });
    try { assert.equal(prepareForm(view), undefined); assert.equal(nodes(view.root).filter(node => node.tag === 'form').length, 0); assert.equal(view.runtime.writes.length, 0); }
    finally { view.stop(); }
});

test('confirmed preparation ACK with failed readback is not unknown and cannot silently repeat', async () => {
    const view = await mountPreparation({ saved: null, data: directData() });
    try {
        view.runtime.writer = async () => ({ acknowledged: true }); view.runtime.reader = async () => undefined;
        await prepareDirect(view);
        assert.ok(content(view.root).includes('Execution acknowledgement received.'));
        assert.equal(view.values.has(activeKey), false);
        await prepareDirect(view); assert.equal(view.runtime.writes.length, 1);
    } finally { view.stop(); }
});

test('lost new-intent issuance ACK is never retried automatically or through the original-command retry control', async () => {
    const view = await mountPreparation({ saved: null, data: directData() });
    try {
        await view.click('Issue next intent after current completion');
        assert.deepEqual(view.runtime.writes, [{ kind: 'newIntent' }]);
        assert.equal(view.button('Explicitly retry the same request'), undefined);
        await settle(); assert.equal(view.runtime.writes.length, 1); assert.equal(JSON.parse(view.values.get(activeKey)).kind, 'newIntent');
    } finally { view.stop(); }
});

test('retained preparation never restores another principal or an arbitrary retry URL', async () => {
    const values = new Map([[activeKey, JSON.stringify(originalOperator)]]);
    let view = await mountPreparation({ storage: values, principal: 8, data: directData() });
    try { assert.equal(view.button('Explicitly retry the same request'), undefined); assert.equal(view.runtime.writes.length, 0); }
    finally { view.stop(); }
    view = await mountPreparation({ saved: { ...originalOperator, url: 'https://untrusted.example/collect' }, data: directData() });
    try { assert.equal(view.button('Explicitly retry the same request'), undefined); assert.equal(view.runtime.writes.length, 0); }
    finally { view.stop(); }
});

test('actual preparation component retires consumed operator after lost ACK and 409 without inventing success, then reloads history', async () => {
    const data = projection(); data.people = [{ public_id: operatorId, name: 'Eligible operator', kind: 'employee' }];
    const view = await mountPreparation({ saved: null, data });
    let reloaded;
    try {
        const form = nodes(view.root).find(node => node.tag === 'form' && nodes(node).some(child => child.tag === 'button' && content(child) === 'Authorize'));
        const selects = nodes(form).filter(node => node.tag === 'select');
        for (const [index, value] of [approvalA, operatorId].entries()) {
            for (const option of selects[index].options) option.selected = option._value === value;
            selects[index].listeners.change();
        }
        const reason = nodes(form).find(node => node.tag === 'input'); reason.value = originalOperator.body.reason; reason.listeners.input({ target: reason });
        await form.props.onSubmit({ preventDefault() {} }); await settle();
        assert.deepEqual(JSON.parse(view.values.get(activeKey)), originalOperator);
        view.runtime.writer = async () => { throw new view.transport.TransportError(409); };
        await view.click('Explicitly retry the same request');
        assert.equal(view.runtime.writes.length, 2); assert.ok(!view.button(retireLabel));
        consumedReview(view); await view.click(reviewLabel); await view.click(retireLabel);
        assert.deepEqual(archived(view), [{ command: originalOperator, outcome: 'unknown' }]);
        assert.equal(view.values.has(activeKey), false); assert.equal(view.runtime.writes.length, 2);
        assert.ok(!content(view.root).includes('Execution acknowledgement received.'));
        assert.ok(nodes(view.root).filter(node => node.tag === 'fieldset').every(node => !node.props.disabled));
        view.stop(); reloaded = await mountPreparation({ storage: view.values });
        assert.ok(!reloaded.button('Explicitly retry the same request')); assert.equal(reloaded.runtime.writes.length, 0);
        assert.ok(content(reloaded.root).includes(originalOperator.body.reason));
    } finally { reloaded?.stop(); if (!reloaded) view.stop(); }
});

test('operator retirement refuses failed, unconsumed, different and superseded reads without losing the original', async () => {
    for (const mode of ['failed', 'unconsumed', 'different', 'unadmitted', 'replaced']) {
        const view = await mountPreparation();
        try {
            if (mode === 'failed') view.runtime.reader = async () => undefined;
            if (mode === 'different') consumedReview(view, approvalB);
            if (mode === 'unadmitted') view.runtime.reader = async () => ({ ...projection(), approvals: [{ id: approvalA, consumed: true }] });
            if (mode === 'replaced') { consumedReview(view); await view.click(reviewLabel); view.runtime.data.value = projection(); await settle(); }
            else await view.click(reviewLabel);
            assert.ok(!view.button(retireLabel), mode);
            assert.deepEqual(JSON.parse(view.values.get(activeKey)), originalOperator); assert.equal(archived(view).length, 0); assert.equal(view.runtime.writes.length, 0);
        } finally { view.stop(); }
    }
});

test('operator archive preserves pending on failed save and resumes partial cleanup without duplicate history', async () => {
    const view = await mountPreparation();
    try {
        consumedReview(view); await view.click(reviewLabel); view.runtime.saveFailure = true;
        await view.click(retireLabel); assert.equal(archived(view).length, 0); assert.ok(view.values.has(activeKey));
        view.runtime.saveFailure = false; view.runtime.deleteFailure = true;
        view.runtime.failAfterSave = true;
        await view.click(retireLabel); assert.equal(archived(view).length, 1); assert.ok(view.values.has(activeKey));
        view.runtime.failAfterSave = false;
        await view.click(retireLabel); assert.equal(archived(view).length, 1); assert.ok(view.values.has(activeKey));
        view.runtime.deleteFailure = false; await view.click(retireLabel);
        assert.equal(archived(view).length, 1); assert.ok(!view.values.has(activeKey)); assert.equal(view.runtime.writes.length, 0);
    } finally { view.stop(); }
});

test('reload resumes archived active cleanup without restoring or replaying the archived command', async () => {
    const view = await mountPreparation(); let reloaded;
    try {
        consumedReview(view); await view.click(reviewLabel); view.runtime.deleteFailure = true; await view.click(retireLabel);
        assert.ok(view.values.has(activeKey)); view.stop(); reloaded = await mountPreparation({ storage: view.values });
        assert.equal(archived(reloaded).length, 1); assert.ok(!reloaded.values.has(activeKey));
        assert.ok(!reloaded.button('Explicitly retry the same request')); assert.equal(reloaded.runtime.writes.length, 0);
    } finally { reloaded?.stop(); if (!reloaded) view.stop(); }
});

test('operator retirement requires confirmation and current ownership even across confirmation callbacks', async () => {
    const view = await mountPreparation();
    try {
        consumedReview(view); await view.click(reviewLabel); view.runtime.confirm = () => false;
        await view.click(retireLabel); assert.equal(archived(view).length, 0); assert.ok(view.values.has(activeKey));
        view.runtime.confirm = () => { view.transport.close(); return true; };
        await view.click(retireLabel); assert.equal(archived(view).length, 0); assert.ok(view.values.has(activeKey));
    } finally { view.stop(); }
});

test('pending review cannot race a write or publish after identity closure or replacement read', async () => {
    for (const mode of ['identity', 'superseded']) {
        const view = await mountPreparation();
        try {
            let release; view.runtime.reader = () => new Promise(resolve => { release = resolve; });
            const reviewing = view.button(reviewLabel).props.onClick({}); await settle();
            assert.ok(view.button('Explicitly retry the same request').props.disabled);
            if (mode === 'identity') view.transport.close();
            else view.runtime.data.value = projection();
            release({ ...projection(), approvals: [{ id: approvalA, consumed: true }] }); await reviewing; await settle();
            assert.ok(!view.button(retireLabel)); assert.ok(view.values.has(activeKey)); assert.equal(archived(view).length, 0); assert.equal(view.runtime.writes.length, 0);
        } finally { view.stop(); }
    }
});

test('operator retirement never generalizes to prepare, delegate or new-intent attempts', async () => {
    const commands = [{ kind: 'prepare', body: { mode: 'direct', intent_id: approvalA, intent_binding: 'original-binding', country_code: 'JO', denomination_code: 'USD', reason: '' } },
        { kind: 'delegate', body: { market_id: approvalA, operator: operatorId, permission: 'read', reason: 'Original delegation', expires_at: '2026-10-20T00:00:00Z' } }, { kind: 'newIntent' }];
    for (const saved of commands) {
        const view = await mountPreparation({ saved });
        try {
            consumedReview(view); await view.click(reviewLabel); assert.ok(!view.button(retireLabel));
            assert.deepEqual(JSON.parse(view.values.get(activeKey)), saved); assert.equal(archived(view).length, 0);
            if (saved.kind === 'newIntent') assert.ok(view.button('Archive unresolved attempt and load current identity'));
        } finally { view.stop(); }
    }
});
