import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createHash } from 'node:crypto';
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
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, inputValue: '', checked: false,
        get value() { return this.tag === 'select' ? this.options.find(option => option.selected)?._value ?? '' : this.inputValue; },
        set value(value) { this.inputValue = value; if (this.tag === 'select') this.options.forEach(option => { option.selected = option._value === value; }); },
        get options() { return this.children.filter(child => child.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(option => option.selected); },
        set selectedIndex(index) { this.options.forEach((option, position) => { option.selected = position === index; }); },
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
async function mountEditor({ retained = [], principal = 7 } = {}) {
    sessionStorage.values = new Map(retained);
    const key = 'commercialEditor' + crypto.randomUUID().replaceAll('-', '');
    const props = shallowReactive(projection()), runtime = { writes: [], writer: async () => ({ saved: true }), reader: async () => props, context: async () => ({ marker: 'current' }), workspace: async () => ({ name: 'Readable beneficiary', public_id: 'workspace' }), receipt: async () => ({}), revision: 0, callbacks: new Set() };
    globalThis[key] = runtime;
    const state = moduleUrl(`const runtime=globalThis.${key};export async function writeCommercial(command){runtime.writes.push(structuredClone(command));return runtime.writer(command)}export const readOperations=()=>runtime.reader(),readCommercialContext=()=>runtime.context(),readCommercialWorkspace=(...args)=>runtime.workspace(...args),readCommercialReceipt=(...args)=>runtime.receipt(...args),recoverCommercialAudit=async()=>({});`);
    const transport = moduleUrl(`const runtime=globalThis.${key};export const bootstrap={identity:{binding:{principal:${principal}}}},close=()=>{runtime.revision++;runtime.callbacks.forEach(callback=>callback())},onClose=callback=>{runtime.callbacks.add(callback);return runtime.callbacks},owned=value=>value===runtime.revision,stamp=()=>runtime.revision,logout=async()=>{};export class TransportError extends Error{constructor(status){super();this.status=status;this.body={}}}`);
    const shell = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default{setup(props,{slots}){return()=>h('main',{},[slots.header?.(),slots.default?.()])}}`);
    const receipt = moduleUrl(ts.transpileModule(await readFile(new URL('../resources/js/commercial/receiptProvenance.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText);
    const component = await compile('OperationsEditor.vue', { '../internal-operations/OperationsShell.vue': shell, './operationsState': state, './receiptProvenance': receipt, '../spa/transport': transport, '../spa/presentation': moduleUrl('export const confirmDeparture={value:()=>true};'), 'vue-router': moduleUrl('export const onBeforeRouteLeave=()=>{},RouterLink={render:()=>null};') });
    const root = element('root'), app = renderer.createApp({render:()=>h(component.component,{...props})});app.mount(root);await flush();
    return { root, props, runtime, transport: await import(transport), stop(){app.unmount();delete globalThis[key]} };
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

async function select(node, value) {
    assert.ok(node, 'Expected selection control');
    for (const option of node.options) option.selected = option._value === value;
    node.value = value; node.listeners.change({ target: node });
    await node.props.onChange?.({ target: node }); await flush();
}
const formOf = view => findAll(view.root, node => node.tag === 'form')[0];
const buttonWith = (view, label) => findAll(view.root, node => node.tag === 'button' && textOf(node) === label)[0];
async function open(view, operation = 'configuration') {
    await click(byData(view, 'data-manage-market', marketId));
    if (operation !== 'configuration') await click(byData(view, 'data-operation', operation));
    else await click(byData(view, 'data-operation', 'configuration'));
}

test('exact catalog grant choices cannot become typed or cross-market identities', async () => {
    const view = await mountEditor();
    try {
        view.props.founderAccess = false;
        view.props.targets = [referenceId, configurationId].map(version_id => ({ action: 'RecordOrVerifyApprovedMarketReference', target_id: marketId, version_id, workspace_public_id: null }));
        view.props.catalogReferences = []; await flush(); await open(view, 'catalog');
        const control = byId(view, 'commercial-catalog_reference_id'); assert.equal(control.tag, 'select');
        await select(control, configurationId); await submit(formOf(view));
        assert.equal(view.runtime.writes[0].body.catalog_reference_id, configurationId);
        assert.equal(view.runtime.writes[0].body.market_id, marketId);
    } finally { view.stop(); }
});

test('blank enable decision is not disable; explicit disable freezes null and the original revision', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await submit(formOf(view)); assert.equal(view.runtime.writes.length, 0);
        await select(byId(view, 'assignability-decision'), 'disable'); await submit(formOf(view));
        assert.equal(view.runtime.writes[0].body.configuration_id, null);
        assert.equal(view.runtime.writes[0].body.expected_revision, 1);
        assert.equal(view.runtime.writes[0].body.reason_code, 'commercial_assignability');
    } finally { view.stop(); }
});

test('authority display distinguishes readonly, missing fresh authentication and unknown without granting writes', async () => {
    const view = await mountEditor();
    try {
        view.props.founderAccess = false; await open(view); await flush();
        const authority = () => findAll(view.root, node => node.props['data-authority'])[0].props['data-authority'];
        assert.equal(authority(), 'VISIBLE_BUT_READ_ONLY'); await submit(formOf(view));
        view.props.freshAuthentication = false; await flush(); assert.equal(authority(), 'PREREQUISITE_MISSING');
        view.props.freshAuthentication = undefined; await flush(); assert.equal(authority(), 'AUTHORITY_UNDETERMINED');
        assert.equal(view.runtime.writes.length, 0);
    } finally { view.stop(); }
});

test('explicit newer-command review adopts current revision together, never refresh-only rebasing', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.writer = async () => { throw new view.transport.TransportError(409); };
        let finishReview, reads = 0;
        view.runtime.reader = () => { reads++; return new Promise(resolve => { finishReview = resolve; }); };
        const rejected = formOf(view).props.onSubmit({ preventDefault() {} }); await flush();
        assert.equal(reads, 1, 'Definitive conflict requests one admitted read, never another write');
        assert.equal(byData(view, 'data-conflict-review', 'true'), undefined);
        const original = view.runtime.writes[0];
        view.props.markets = [{ ...view.props.markets[0], revision: 8 }]; await flush();
        finishReview(view.props); await rejected; await flush();
        await submit(formOf(view)); assert.equal(view.runtime.writes.length, 1);
        assert.ok(textOf(view.root).includes('A conflict occurred. State refresh was requested without resubmission.'));
        assert.ok(byData(view, 'data-conflict-review', 'true'), 'A definitive 409 must expose explicit review without fabricating a successful receipt');
        await click(byData(view, 'data-conflict-review', 'true'));
        assert.equal(byId(view, 'commercial-expected_revision').value, '8');
        await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.reader = async () => view.props;
        view.runtime.writer = async () => ({ saved: true }); await submit(formOf(view));
        assert.equal(view.runtime.writes[1].body.expected_revision, 8);
        assert.notEqual(view.runtime.writes[1].body.command_id, original.body.command_id);
    } finally { view.stop(); }
});

test('known ACK cannot be resent; only explicit new operation gets a new command identity', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        await submit(formOf(view)); await submit(formOf(view)); assert.equal(view.runtime.writes.length, 1);
        await click(buttonWith(view, 'Start a new operation'));
        await select(byId(view, 'assignability-decision'), 'disable'); await submit(formOf(view));
        assert.equal(view.runtime.writes.length, 2);
        assert.notEqual(view.runtime.writes[0].body.command_id, view.runtime.writes[1].body.command_id);
    } finally { view.stop(); }
});

test('failed conflict readback cannot enable review; explicit read retry never resubmits the rejected command', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.writer = async () => { throw new view.transport.TransportError(409); };
        view.runtime.reader = async () => undefined; await submit(formOf(view));
        assert.equal(byData(view, 'data-conflict-review', 'true'), undefined);
        await submit(formOf(view)); assert.equal(view.runtime.writes.length, 1);
        view.props.markets = [{ ...view.props.markets[0], revision: 9 }];
        view.runtime.reader = async () => view.props;
        await click(buttonWith(view, 'Refresh state and permissions'));
        assert.ok(byData(view, 'data-conflict-review', 'true')); assert.equal(view.runtime.writes.length, 1);
        assert.equal(byId(view, 'commercial-expected_revision').value, '1');
        await click(byData(view, 'data-conflict-review', 'true'));
        assert.equal(byId(view, 'commercial-expected_revision').value, '9'); assert.equal(view.runtime.writes.length, 1);
    } finally { view.stop(); }
});

test('changed market selection cannot submit hidden prior configuration and removed selection cannot write', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'commercial-configuration_id'), configurationId);
        const other = '00000000-0000-4000-8000-000000000099';
        view.props.markets = [...view.props.markets, { id: other, label: 'Other', configuration_id: null, revision: 4 }]; await flush();
        await click(byData(view, 'data-manage-market', other)); await click(byData(view, 'data-operation', 'assignability')); await submit(formOf(view));
        assert.equal(view.runtime.writes.length, 0); assert.notEqual(byId(view, 'commercial-configuration_id')?.value, configurationId);
        view.props.markets = []; await flush(); await submit(formOf(view)); assert.equal(view.runtime.writes.length, 0);
    } finally { view.stop(); }
});

test('scope selection uses exact scope/workspace and canonical configuration with independent denomination', async () => {
    const view = await mountEditor();
    try {
        view.props.targets = [{ action: 'RegisterCommercialScope', target_id: 'scope', workspace_public_id: 'workspace', version_id: null }];
        view.props.configurations = [{ ...view.props.configurations[0], denomination_code: 'JOD' }]; await flush();
        await open(view, 'register');
        await select(byId(view, 'scope-select'), 'scope|workspace'); await submit(formOf(view));
        const body = view.runtime.writes[0].body;
        assert.equal(body.scope_id, 'scope'); assert.equal(body.workspace_public_id, 'workspace');
        assert.equal(body.configuration_id, configurationId); assert.equal(body.catalog_reference_id, referenceId); assert.equal(body.denomination_code, 'JOD');
    } finally { view.stop(); }
});

test('uncertain command survives remount with exact original identifiers but never crosses principal', async () => {
    let view = await mountEditor(); let saved, original;
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.writer = async () => { throw Error('ACK lost'); }; await submit(formOf(view));
        original = view.runtime.writes[0]; saved = [...sessionStorage.values];
    } finally { view.stop(); }
    view = await mountEditor({ retained: saved });
    try {
        assert.equal(view.runtime.writes.length, 0);
        await click(buttonWith(view, 'Retry the unchanged original request'));
        assert.deepEqual(view.runtime.writes, [original]);
    } finally { view.stop(); }
    view = await mountEditor({ retained: saved, principal: 8 });
    try { assert.equal(buttonWith(view, 'Retry the unchanged original request'), undefined); assert.equal(view.runtime.writes.length, 0); }
    finally { view.stop(); }
});

test('retention failure prevents mutation and identity closure suppresses a late committed acknowledgement', async () => {
    const view = await mountEditor(); const setter = sessionStorage.setItem;
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        sessionStorage.setItem = () => { throw Error('Storage unavailable'); };
        await submit(formOf(view)); assert.equal(view.runtime.writes.length, 0);
        sessionStorage.setItem = setter; let finish;
        view.runtime.writer = () => new Promise(resolve => { finish = resolve; });
        const pending = formOf(view).props.onSubmit({ preventDefault() {} }); await flush();
        view.transport.close(); finish({ private: 'obsolete ACK' }); await pending; await flush();
        assert.ok(!textOf(view.root).includes('obsolete ACK')); assert.equal(view.runtime.writes.length, 1);
    } finally { sessionStorage.setItem = setter; view.stop(); }
});

test('receipt reconciliation requires original command, actor, target, effect, reason and full payload fingerprint', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.writer = async () => { throw Error('ACK lost'); }; await submit(formOf(view));
        const original = view.runtime.writes[0].body;
        const serialized = ['MarketAssignability', marketId, null, 1, 'commercial_assignability'].map(value => JSON.stringify(value)).join(', ');
        const valid = { command_id: original.command_id, actor_user_id: 7, effect: 'DisableCommercialMarket', target_id: marketId, reason_code: original.reason_code,
            request_fingerprint: createHash('sha256').update('[' + serialized + ']').digest('hex') };
        const form = findAll(view.root, node => node.tag === 'form' && findAll(node, child => child.props.id === 'receipt-id').length)[0];
        assert.ok(form, 'Receipt lookup remains separate from mutation');
        for (const wrong of [{ command_id: configurationId }, { actor_user_id: 8 }, { effect: 'EnableCommercialMarket' }, { target_id: configurationId }, { reason_code: 'other' }, { request_fingerprint: '0'.repeat(64) }]) {
            view.runtime.receipt = async () => ({ ...valid, ...wrong }); await submit(form);
            assert.equal(buttonWith(view, 'I reviewed the receipt and state — start a new operation'), undefined);
        }
        view.runtime.receipt = async () => valid; await submit(form);
        assert.ok(buttonWith(view, 'I reviewed the receipt and state — start a new operation'));
        assert.equal(view.runtime.writes.length, 1);
    } finally { view.stop(); }
});

test('activation requires a readable approved reference and denomination; incomplete references never become truth', async () => {
    const view = await mountEditor();
    try {
        view.props.markets = [{ ...view.props.markets[0], configuration_id: null }];
        view.props.catalogReferences = []; await flush(); await open(view, 'assignability');
        assert.equal(byId(view, 'commercial-configuration_id')?.options.some(option => option._value === configurationId) ?? false, false);
        await submit(formOf(view)); assert.equal(view.runtime.writes.length, 0);
        assert.ok(textOf(view.root).includes('approved commercial reference missing'));
    } finally { view.stop(); }
});

test('generated catalog identity remains selectable after clearing and consumed configuration identities cannot write', async () => {
    const view = await mountEditor();
    try {
        view.props.newCatalogReferenceId = configurationId; await flush(); await open(view, 'catalog');
        const control = byId(view, 'commercial-catalog_reference_id'); await select(control, '');
        assert.ok(control.options.some(option => option._value === configurationId));
        await submit(formOf(view)); assert.equal(view.runtime.writes.length, 0);
        await select(control, configurationId); await submit(formOf(view)); assert.equal(view.runtime.writes[0].body.catalog_reference_id, configurationId);
    } finally { view.stop(); }
    const consumed = await mountEditor();
    try {
        consumed.props.configurations = [...consumed.props.configurations, { ...consumed.props.configurations[0], id: consumed.props.newConfigurationId }];
        await flush(); await open(consumed); await submit(formOf(consumed)); assert.equal(consumed.runtime.writes.length, 0);
    } finally { consumed.stop(); }
});

test('personal hiding is local, reversible and blocks hidden target writes without server mutation', async () => {
    const view = await mountEditor();
    try {
        await click(byData(view, 'data-hide-market', marketId)); assert.equal(byData(view, 'data-manage-market', marketId), undefined);
        assert.equal(view.runtime.writes.length, 0);
        await click(buttonWith(view, 'Restore to my list')); assert.ok(byData(view, 'data-manage-market', marketId));
        assert.equal(view.runtime.writes.length, 0);
    } finally { view.stop(); }
});

test('current commercial fields retain accessible descriptions, readonly evidence and translated human workflow', async () => {
    const view = await mountEditor();
    try {
        await click(byData(view, 'data-manage-market', marketId));
        assert.equal(byId(view, 'commercial-denomination_code'), undefined);
        for (const id of ['commercial-advanced', 'commercial-history', 'commercial-audit']) {
            assert.equal(byId(view, id).tag, 'details'); assert.notEqual(byId(view, id).props.open, true);
        }
        await click(byData(view, 'data-operation', 'configuration'));
        const nodes = descendants(view.root), ids = new Set(nodes.map(node => node.props.id).filter(Boolean));
        for (const node of nodes) for (const id of (node.props['aria-describedby'] ?? '').split(' ').filter(Boolean)) assert.ok(ids.has(id));
        for (const node of nodes.filter(node => node.tag === 'input' && node.props.id?.startsWith('evidence-'))) assert.notEqual(node.props.readonly, undefined);
        assert.equal(byId(view, 'commercial-reason'), undefined); assert.equal(byId(view, 'commercial-operation'), undefined);
        assert.ok(textOf(view.root).includes('Command receipt')); assert.ok(textOf(view.root).includes('Commercial markets'));
    } finally { view.stop(); }
});

test('inactive country/currency evidence is not assignability and editable currency remains explicit', async () => {
    const view = await mountEditor();
    try {
        view.props.countryOptions = { JO: { ar: 'الأردن', en: 'Jordan', currency: 'JOD' } };
        view.props.markets = [{ ...view.props.markets[0], country_code: 'JO', configuration_id: null }]; await flush(); await open(view);
        assert.ok(textOf(view.root).includes('Jordan')); assert.equal(byId(view, 'commercial-denomination_code').value, 'USD');
        await select(byId(view, 'commercial-denomination_code'), 'USD'); await submit(formOf(view));
        assert.equal(view.runtime.writes[0].body.denomination_code, 'USD'); assert.equal(view.props.markets[0].configuration_id, null);
    } finally { view.stop(); }
});

test('uncertain result remains outside disclosures; programmatic selection and hide cannot replace original request', async () => {
    const view = await mountEditor();
    try {
        await open(view, 'assignability'); await select(byId(view, 'assignability-decision'), 'disable');
        view.runtime.writer = async () => { throw Error('Lost ACK'); }; await submit(formOf(view));
        const original = structuredClone(view.runtime.writes[0]);
        await click(byData(view, 'data-operation', 'register')); await click(byData(view, 'data-hide-market', marketId));
        assert.equal(byId(view, 'scope-select'), undefined); assert.ok(byData(view, 'data-manage-market', marketId));
        let node = byId(view, 'operation-result'); assert.ok(node);
        while (node) { assert.notEqual(node.tag, 'details'); node = node.parent; }
        await click(buttonWith(view, 'Retry the unchanged original request')); assert.deepEqual(view.runtime.writes[1], original);
        view.runtime.writer = async () => { throw new view.transport.TransportError(403); };
        await click(buttonWith(view, 'Retry the unchanged original request'));
        assert.deepEqual(JSON.parse(sessionStorage.values.get('commercial-command:v1:7')).payload, original.body);
        assert.equal(view.runtime.writes.length, 3); assert.equal(view.runtime.revision, 1);
    } finally { view.stop(); }
});

test('new-market choices exclude every recorded identity including stale projected grant targets', async () => {
    const view = await mountEditor();
    try {
        view.props.founderAccess = false;
        const fresh = '00000000-0000-4000-8000-000000000099';
        view.props.newMarketTargets = [marketId, fresh].map(target_id => ({ action: 'ConfigureCommercialMarket', target_id, version_id: null, workspace_public_id: null }));
        view.props.targets = view.props.newMarketTargets; await flush(); await open(view, 'market');
        const choices = byId(view, 'commercial-market_id');
        assert.equal(choices.options.some(option => option._value === marketId), false);
        await select(choices, fresh); await submit(formOf(view)); assert.equal(view.runtime.writes[0].body.market_id, fresh);
    } finally { view.stop(); }
});
