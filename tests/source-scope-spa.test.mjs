import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h, ref } from 'vue';

const transport = { requests: [], reloads: [], posts: [], send: async () => ({ data: {} }) };
globalThis.__bb1Transport = transport;
globalThis.__bb1Page = reactive({ auth: { user: { id: 7 }, sessionContextId: 'a'.repeat(64) } });
const stored = new Map();
globalThis.sessionStorage = {
    get length() { return stored.size; }, key: index => [...stored.keys()][index] ?? null,
    getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, String(value)),
    removeItem: key => stored.delete(key), clear: () => stored.clear(),
};
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
globalThis.__scopeStatus = ref('ready');
globalThis.__scopeError = class extends Error { constructor(status, body) { super('HTTP ' + status); this.status = status; this.body = body; } };
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const ports = {
    './api': moduleUrl(`export const sources = { command: async (workspace, command) => {
        const args = ['/workspaces/' + workspace + '/sources', command, { headers: { Accept: 'application/json' } }];
        globalThis.__bb1Transport.requests.push(args);
        return (await globalThis.__bb1Transport.send(...args)).data;
    } };`),
    './state': moduleUrl(`export const read = (workspace, passive) => globalThis.__bb1Transport.reloads.push({ workspace, passive });`),
    '../spa/transport': moduleUrl(`export const bootstrap = { identity: { binding: {
        get principal() { return globalThis.__bb1Page.auth.user?.id; },
        get context() { return globalThis.__bb1Page.auth.sessionContextId; }
    } } }; export const status = globalThis.__scopeStatus; export const TransportError = globalThis.__scopeError;`),
};
const { descriptor } = parse(await readFile(new URL('../resources/js/sources/DeclareSourceScope.vue', import.meta.url), 'utf8'));
let compiled = ts.transpileModule(compileScript(descriptor, { id: 'spa-source-scope', inlineTemplate: true }).content,
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
for (const [name, target] of Object.entries({ vue: import.meta.resolve('vue'), ...ports })) {
    compiled = compiled.replaceAll('from "' + name + '"', 'from ' + JSON.stringify(target)).replaceAll("from '" + name + "'", 'from ' + JSON.stringify(target));
}
const declaration = { component: (await import(moduleUrl(compiled))).default };

function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, value: '', multiple: false, selected: false,
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
const scope = { resource: 'server-account', operation: 'discovery.read', purpose: 'diagnostic_review' };
function mount(component = declaration.component, overrides = {}, retainStorage = false) {
    transport.requests = []; transport.reloads = []; transport.posts = []; transport.send = async () => ({ data: {} });
    if (!retainStorage) stored.clear();
    const props = reactive({ workspaceId: 'workspace', sourceId: 'source', version: 7, choices: [scope], ...overrides });
    const root = element('root');
    const app = renderer.createApp({ render: () => h(component, props) }); app.mount(root);
    return { root, props, unmount: () => app.unmount() };
}
function nodes(root, predicate) { return [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate); }
function text(root) { return root.text + root.children.map(text).join(''); }
async function select(root, index = 1) {
    const el = nodes(root, n => n.tag === 'select')[0]; el.selectedIndex = index;
    el.listeners.change({ target: el }); await nextTick();
}
function submit(root) { return nodes(root, n => n.tag === 'form')[0].props.onSubmit({ preventDefault() {} }); }
const error = (status, errors) => new globalThis.__scopeError(status, { errors });

test('server choices only, native selection/submission, bounded payload, loading and repeated submit', async () => {
    const view = mount();
    assert.equal(nodes(view.root, n => n.tag === 'option').length, 2);
    assert.equal(nodes(view.root, n => n.tag === 'button' && n.props.type === 'submit')[0].props.disabled, true);
    await select(view.root);
    let resolve; transport.send = () => new Promise(r => { resolve = r; });
    const pending = submit(view.root); await nextTick(); submit(view.root);
    assert.equal(transport.requests.length, 1);
    assert.equal(nodes(view.root, n => n.tag === 'button' && /جارٍ حسم الطلب الأصلي/.test(text(n)))[0].props.disabled, true);
    const [url, payload, config] = transport.requests[0];
    assert.equal(url, '/workspaces/workspace/sources');
    assert.deepEqual(Object.keys(payload).sort(), ['command_id', 'effect', 'expected_version', 'operation', 'purpose', 'reason', 'resource', 'source_id']);
    assert.deepEqual({ ...payload, command_id: 'key' }, { command_id: 'key', source_id: 'source', effect: 'declare_scope', expected_version: 7, ...scope, reason: 'owner_scope_declaration' });
    assert.equal(config.headers.Accept, 'application/json');
    resolve({ data: { version: 8 } }); await pending; await nextTick();
    assert.equal(transport.reloads.length, 1); assert.deepEqual(transport.reloads[0], { workspace: 'workspace', passive: true });
    assert.match(text(view.root), /تم إعلان النطاق محلياً/); assert.match(text(view.root), /غير متحقق/);
    submit(view.root); assert.equal(transport.requests.length, 1);
    view.props.version = 8; view.props.choices = []; await nextTick();
    assert.equal(nodes(view.root, n => n.tag === 'form').length, 0); view.unmount();
});
test('absence of server authorization renders no scope declaration form', () => {
    const view = mount(declaration.component, { choices: [] }); assert.equal(nodes(view.root, n => n.tag === 'form').length, 0); view.unmount();
});
test('field validation is visible beside the choice and retry retains the command identity', async () => {
    const view = mount(); await select(view.root);
    transport.send = async () => { throw error(422, { purpose: ['Purpose is invalid.'] }); };
    await submit(view.root); await nextTick();
    assert.equal(nodes(view.root, n => n.props.role === 'alert')[0].text, 'Purpose is invalid.');
    assert.equal(stored.size, 0);
    const first = transport.requests[0][1]; await submit(view.root);
    assert.deepEqual(transport.requests[1][1], first); assert.equal(transport.reloads.length, 0); view.unmount();
});
for (const status of [409, 403, 401, 419]) test(`HTTP ${status} shows repair and blocks another command until server refresh`, async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw error(status); };
    await submit(view.root); await nextTick();
    assert.match(text(view.root), status === 409 ? /تغير إصدار المصدر/ : /لم تعد الصلاحية/);
    assert.equal(stored.size, 0);
    submit(view.root); assert.equal(transport.requests.length, 1);
    const refresh = nodes(view.root, n => n.tag === 'button' && text(n) === 'تحديث الحالة من الخادم')[0]; refresh.props.onClick();
    assert.equal(transport.reloads.length, 1); view.unmount();
});
test('uncertain response retries exact command instead of duplicating an effect', async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); assert.match(text(view.root), /لم يصل تأكيد/);
    const retry = nodes(view.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0];
    assert.ok(retry);
    retry.props.onClick(); await nextTick();
    assert.deepEqual(transport.requests[0][1], transport.requests[1][1]); view.unmount();
});
test('uncertain command retains exact envelope when server choices change', async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]);
    view.props.choices = [{ resource: 'new-choice', operation: 'new.read', purpose: 'different_purpose' }]; await nextTick();
    assert.match(text(view.root), /server-account/);
    assert.equal(nodes(view.root, n => n.tag === 'select').length, 0);
    const retry = nodes(view.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0];
    retry.props.onClick(); await nextTick(); assert.deepEqual(transport.requests[1][1], original); view.unmount();
});
test('uncertain command replays original version after current version changes, then clears on definitive receipt', async () => {
    const view = mount(); await select(view.root);
    const receipts = new Map(); let effects = 0; let calls = 0;
    transport.send = async (_url, payload) => {
        calls++;
        if (!receipts.has(payload.command_id)) { effects++; receipts.set(payload.command_id, { command_id: payload.command_id, version: 8 }); }
        if (calls === 1) throw new Error('committed, response lost');
        return { data: receipts.get(payload.command_id) };
    };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]);
    view.props.version = 8; await nextTick();
    nodes(view.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0].props.onClick();
    await new Promise(resolve => setImmediate(resolve)); await nextTick();
    assert.deepEqual(transport.requests[1][1], original);
    assert.equal(effects, 1);
    assert.equal(stored.size, 0); assert.equal(transport.reloads.length, 1);
    view.props.choices = [scope]; await nextTick(); await select(view.root); await submit(view.root);
    assert.notEqual(transport.requests[2][1].command_id, original.command_id);
    assert.equal(transport.requests[2][1].expected_version, 8); view.unmount();
});
for (const status of [401, 403, 419]) test(`a ${status} retry denial does not claim the original uncertain effect was resolved`, async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]);
    transport.send = async () => { throw error(status); };
    nodes(view.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0].props.onClick();
    await new Promise(resolve => setImmediate(resolve)); await nextTick();
    assert.deepEqual(transport.requests[1][1], original);
    assert.equal(stored.size, 1);
    assert.match(text(view.root), /تعذّر حسم الطلب الأصلي/);
    view.unmount();
    const returned = mount(declaration.component, {}, true); await nextTick();
    assert.match(text(returned.root), /إعادة إرسال الطلب الأصلي/); returned.unmount();
});
test('unavailable tab storage prevents sending an untraceable command', async () => {
    const view = mount(); await select(view.root);
    const originalSet = globalThis.sessionStorage.setItem;
    globalThis.sessionStorage.setItem = () => { throw new Error('quota exceeded'); };
    try {
        await submit(view.root); await nextTick();
        assert.equal(transport.requests.length, 0); assert.match(text(view.root), /لم يُرسَل أي طلب/);
    } finally { globalThis.sessionStorage.setItem = originalSet; view.unmount(); }
});
test('uncertain command survives unmount, navigation return and remount', async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]); view.unmount();
    const returned = mount(declaration.component, { version: 8, choices: [] }, true);
    await nextTick();
    assert.match(text(returned.root), /server-account/);
    transport.send = async () => ({ data: { command_id: original.command_id } });
    nodes(returned.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0].props.onClick();
    await new Promise(resolve => setImmediate(resolve)); await nextTick();
    assert.deepEqual(transport.requests[0][1], original); assert.equal(stored.size, 0); returned.unmount();
});
test('editing cannot silently discard or mutate an uncertain command', async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]);
    assert.equal(nodes(view.root, n => n.tag === 'select').length, 0);
    submit(view.root); assert.equal(transport.requests.length, 1);
    nodes(view.root, n => n.tag === 'button' && /إعادة إرسال الطلب الأصلي/.test(text(n)))[0].props.onClick();
    await nextTick(); assert.deepEqual(transport.requests[1][1], original); view.unmount();
});
test('pending command is isolated by workspace and source and cannot cross a new session or actor', async () => {
    const view = mount(); await select(view.root); transport.send = async () => { throw new Error('lost response'); };
    await submit(view.root); await nextTick(); const original = structuredClone(transport.requests[0][1]); view.unmount();
    for (const overrides of [{ workspaceId: 'workspace-B' }, { sourceId: 'source-B' }]) {
        const other = mount(declaration.component, overrides, true);
        await nextTick(); assert.doesNotMatch(text(other.root), /إعادة إرسال الطلب الأصلي/); other.unmount();
    }
    const same = mount(declaration.component, {}, true); await nextTick(); assert.match(text(same.root), /إعادة إرسال الطلب الأصلي/); same.unmount();
    globalThis.__bb1Page.auth.sessionContextId = 'b'.repeat(64);
    const changedSession = mount(declaration.component, {}, true);
    await nextTick(); assert.doesNotMatch(text(changedSession.root), /إعادة إرسال الطلب الأصلي/); changedSession.unmount();
    globalThis.__bb1Page.auth.sessionContextId = 'a'.repeat(64); globalThis.__bb1Page.auth.user.id = 8;
    const changedActor = mount(declaration.component, {}, true);
    await nextTick(); assert.doesNotMatch(text(changedActor.root), /إعادة إرسال الطلب الأصلي/); changedActor.unmount();
    globalThis.__bb1Page.auth.user.id = 7;
    assert.equal(transport.requests.length, 0); assert.equal(original.source_id, 'source');
});
