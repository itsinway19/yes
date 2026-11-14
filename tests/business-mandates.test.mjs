import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'document-a'};export const stamp=()=>context.scope;export const owned=value=>value===context.scope;export class TransportError extends Error{constructor(status){super('Rejected');this.status=status;}}`);
const draftsUrl = moduleUrl('export const businessMandatesDrafts=new Map();export const key=(business,selection)=>JSON.stringify([business,selection]);');
const apiUrl = moduleUrl('export const reads=[],writes=[],readPending=[],writePending=[];export const businessAgencyMandates=(...args)=>{reads.push(structuredClone(args));return new Promise((resolve,reject)=>readPending.push({resolve,reject}));};export const withdrawBusinessMandates=(...args)=>{writes.push(structuredClone(args));return new Promise((resolve,reject)=>writePending.push({resolve,reject}));};');
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const { descriptor } = parse(await readFile('resources/js/business-foundation/BusinessMandates.vue', 'utf8'));
let source = ts.transpileModule(compileScript(descriptor, { id: 'business-mandates', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ vue, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './api': apiUrl })) {
    source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
}
const Component = (await import(moduleUrl(source))).default;
const api = await import(apiUrl), drafts = await import(draftsUrl), transport = await import(transportUrl);
const business = '11111111-1111-7111-8111-111111111111', agency = '22222222-2222-7222-8222-222222222222';
const selection = { realm_kind: 'direct',  requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] };
const surface = (target = business, allowed = true) => ({ business: { business_public_id: target }, authority_selection: structuredClone(selection), agency_task: { can_mandate: allowed } });
const group = (complete = true) => ({ agency_business_public_id: agency, grants: [{ grant_id: '81', version: 2 }, { grant_id: '82', version: 3 }], complete });
const page = (items = [group()]) => ({ business_public_id: business, items, more: false, next_cursor: null });
function node(tag, text = '') { return markRaw({ tag, text, props: {}, style: {}, children: [], parent: null, addEventListener() {}, removeEventListener() {} }); }
const renderer = createRenderer({
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText(target, text) { target.text = text; }, setElementText(target, text) { target.text = text; target.children = []; },
    parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    insert(target, parent, anchor) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(target); else parent.children.splice(index, 0, target); },
    remove(target) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = null; },
    patchProp(target, key, _old, value) { target.props[key] = value; if (key === 'checked') target.checked = value; },
});
const all = root => [root, ...root.children.flatMap(all)];
const find = (root, key) => all(root).find(target => key in target.props);
const text = root => root.text + root.children.map(text).join('');
async function flush() { await nextTick(); await nextTick(); }
function mount(reset = true, allowed = true) {
    if (reset) { drafts.businessMandatesDrafts.clear(); for (const list of [api.reads, api.writes, api.readPending, api.writePending]) list.length = 0; transport.context.scope = 'document-a'; }
    const selected = ref(surface(business, allowed)), active = ref(true), root = node('root'), refreshes = [];
    const app = renderer.createApp({ render: () => h(Component, { surface: selected.value, active: active.value, refreshing: false, onRefresh: () => refreshes.push(true) }) }); app.mount(root);
    const draft = drafts.businessMandatesDrafts.get(`${drafts.key(business, selection)}:mandates:1`);
    return { root, app, selected, active, draft, refreshes,
        async load(value = page()) { api.readPending.shift().resolve(value); await flush(); },
        async choose() { find(root, 'data-mandate-review').props.onClick(); await flush(); },
        async confirm() { find(root, 'data-mandate-confirm').props['onUpdate:modelValue'](true); await flush(); },
        send: () => find(root, 'data-mandate-withdraw').props.onClick(),
    };
}

test('complete declaration mandate group withdrawal freezes every exact grant and requires explicit confirmation', async () => {
    const view = mount();
    try {
        assert.deepEqual(api.reads[0], [business, 51, undefined]); await view.load(); await view.choose();
        await view.send(); assert.equal(api.writes.length, 0); await view.confirm();
        const sending = view.send(); view.send(); assert.equal(api.writes.length, 1);
        const command = api.writes[0][1]; assert.deepEqual(command.expected_grants, group().grants); assert.equal(command.agency_business_public_id, agency);
        assert.deepEqual(command.authority_selection, selection); assert.equal(command.confirmed, true);
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: agency, status: 'withdrawn', versions: { authority_epoch: 7 } }); await sending;
        assert.equal(view.draft.pending, undefined); assert.equal(view.refreshes.length, 1); assert.match(text(view.root), /independent/);
    } finally { view.app.unmount(); }
});

test('partial groups and changed expected revisions cannot offer bulk withdrawal', async () => {
    const view = mount();
    try {
        await view.load(page([group(false)])); assert.ok(find(view.root, 'data-mandate-review') === undefined); assert.match(text(view.root), /partial|individual/);
        view.selected.value = { ...surface() }; await flush(); await view.load(); await view.choose();
        const original = JSON.parse(JSON.stringify(view.draft.expected));
        view.selected.value = { ...surface() }; await flush(); const changed = group(); changed.grants[0].version++;
        await view.load(page([changed])); await view.confirm(); await view.send();
        assert.equal(api.writes.length, 0); assert.deepEqual(view.draft.expected, original, 'A fresh list cannot silently replace the reviewed set');
    } finally { view.app.unmount(); }
});

test('unknown bulk withdrawal survives remount, withdrawn affordance and rejection without changing target or expected set', async () => {
    const view = mount(); await view.load(); await view.choose(); await view.confirm(); const sending = view.send(), original = structuredClone(api.writes[0]);
    api.writePending.shift().reject(Error('lost acknowledgement')); await sending; view.app.unmount();
    const returned = mount(false, false);
    try {
        assert.ok(find(returned.root, 'data-mandate-withdraw')); const checking = returned.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(new transport.TransportError(409)); await checking;
        assert.deepEqual(returned.draft.pending, original[1]); assert.equal(returned.draft.blocked, true);
    } finally { returned.app.unmount(); }
});

test('inactive or changed Business cannot accept late mandate inventory or acknowledgement', async () => {
    const view = mount();
    try {
        view.active.value = false; await flush(); await view.load(); assert.ok(find(view.root, 'data-mandate-review') === undefined);
        view.active.value = true; await flush(); await view.load(); await view.choose(); await view.confirm(); const sending = view.send();
        view.selected.value = surface(agency); await flush();
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: agency, status: 'withdrawn', versions: { authority_epoch: 7 } }); await sending;
        assert.ok(view.draft.pending); assert.equal(view.refreshes.length, 0);
    } finally { view.app.unmount(); }
});

test('mandate inventory loads later counterparties through the server cursor without losing previously qualified groups', async () => {
    const view = mount();
    try {
        await view.load({ ...page(), more: true, next_cursor: agency });
        find(view.root, 'data-mandates-more').props.onClick(); await flush(); assert.deepEqual(api.reads[1], [business, 51, agency]);
        const next = { ...group(), agency_business_public_id: business };
        await view.load(page([next]));
        assert.equal(all(view.root).filter(node => 'data-mandate-review' in node.props).length, 2);
    } finally { view.app.unmount(); }
});
