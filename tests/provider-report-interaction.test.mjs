import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h } from 'vue';

// Exercise the real compiled component; only HTTP and host elements are replaced.
const transport = { gets: [], posts: [], get: async () => ({}), post: async () => ({}) };
globalThis.__bb2ReportTransport = transport;
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const http = 'data:text/javascript;base64,' + Buffer.from(`export default {
get: async (...a) => { globalThis.__bb2ReportTransport.gets.push(a); return { data: await globalThis.__bb2ReportTransport.get(...a) }; },
post: async (...a) => { globalThis.__bb2ReportTransport.posts.push(a); return { data: await globalThis.__bb2ReportTransport.post(...a) }; },
isCancel: () => false, isAxiosError: e => e.isAxiosError === true };`).toString('base64');
const { descriptor } = parse(await readFile(new URL('../resources/js/Pages/Workspaces/ProviderReportRequest.vue', import.meta.url), 'utf8'));
let output = ts.transpileModule(compileScript(descriptor, { id: 'bb2-report', inlineTemplate: true }).content,
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
for (const [original, replacement] of Object.entries({ vue: import.meta.resolve('vue'), axios: http })) {
    output = output.replaceAll(`from "${original}"`, `from ${JSON.stringify(replacement)}`).replaceAll(`from '${original}'`, `from ${JSON.stringify(replacement)}`);
}
const component = (await import('data:text/javascript;base64,' + Buffer.from(output).toString('base64'))).default;
function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, value: '',
        multiple: false, selected: false,
        get options() { return this.children.filter(c => c.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(o => o.selected); },
        set selectedIndex(index) { this.options.forEach((o, i) => { o.selected = i === index; }); },
        getRootNode() { return {}; }, addEventListener(event, handler) { this.listeners[event] = handler; },
        removeEventListener(event) { delete this.listeners[event]; } };
}
const renderer = createRenderer({
    createElement: element, createText: t => element('#text', t), createComment: t => element('#comment', t),
    setText: (n, t) => { n.text = t; }, setElementText: (n, t) => { n.text = t; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] ?? null,
    insert(n, parent, anchor) {
        if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1);
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        parent.children.splice(index < 0 ? parent.children.length : index, 0, n); n.parent = parent;
    },
    remove(n) { n.parent?.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; },
    patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') n.value = n._value = value; },
});
const nodes = (root, predicate) => [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = root => root.text + root.children.map(text).join('');
function mount(provider = 'google') {
    transport.gets = []; transport.posts = [];
    transport.get = async () => ({ generation: 0, timezone: 'Asia/Amman', deadline: new Date(Date.now() + 300000).toISOString(), last_run: null });
    transport.post = async () => ({ queued: true });
    const props = reactive({ workspaceId: 'workspace', sourceId: 'source', version: 1, allowed: true, provider });
    const root = element('root');
    const app = renderer.createApp({ render: () => h(component, props) }); app.mount(root);
    return { root, props, unmount: () => app.unmount() };
}
async function dates(root) {
    const inputs = nodes(root, n => n.tag === 'input' && n.props.type === 'date');
    for (const [i, value] of ['2026-09-01', '2026-09-02'].entries()) {
        inputs[i].value = value; inputs[i].listeners.input({ target: inputs[i] });
    }
    await nextTick();
}
const submitButton = root => nodes(root, n => n.tag === 'button' && n.props.type === 'submit')[0];
const submit = root => nodes(root, n => n.tag === 'form')[0].props.onSubmit({ preventDefault() {} });
const refresh = root => nodes(root, n => n.tag === 'button' && /تحديث حالة الفترة/.test(text(n)))[0].props.onClick();

test('uncertain HTTP acknowledgement retries the same command without repeated status acquisition', async () => {
    const view = mount(); await dates(view.root);
    transport.post = async () => { throw new Error('lost acknowledgement'); };
    await submit(view.root); await nextTick();
    await submit(view.root); await nextTick();
    assert.deepEqual(transport.posts[0][1], transport.posts[1][1]);
    assert.equal(transport.gets.length, 1);
    view.unmount();
});

for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) test(`${provider} waiting ends at the original deadline even without a new journal generation`, async () => {
    const view = mount(provider); await dates(view.root);
    await submit(view.root); await nextTick();
    assert.equal(submitButton(view.root).props.disabled, true);
    const originalDeadline = Date.parse(transport.posts[0][1].deadline);
    const originalNow = Date.now;
    Date.now = () => originalDeadline + 1;
    try {
        await refresh(view.root); await nextTick();
        assert.equal(submitButton(view.root).props.disabled, false);
        assert.match(text(view.root), /انتهت مهلة/);
        assert.equal(transport.posts.length, 1, 'Refresh must not issue a replacement report automatically.');
    } finally { Date.now = originalNow; view.unmount(); }
});

test('completed Google snapshot renders an accessible download link and period changes remove it', async () => {
    const view = mount(); await dates(view.root);
    transport.get = async () => ({ generation: 1, timezone: 'Asia/Amman', deadline: new Date(Date.now() + 300000).toISOString(),
        last_run: { coverage: 'COMPLETE' }, download: { generation: 1, url: '/workspaces/workspace/providers/google/source/report/1/download?stream=bound' } });
    await refresh(view.root); await nextTick();
    const link = nodes(view.root, n => n.tag === 'a')[0];
    assert.equal(link.props.href, '/workspaces/workspace/providers/google/source/report/1/download?stream=bound');
    assert.match(text(link), /تنزيل تقرير حملات Google/);
    const input = nodes(view.root, n => n.tag === 'input' && n.props.type === 'date')[0];
    input.value = '2026-09-02'; input.listeners.input({ target: input }); await nextTick();
    assert.equal(nodes(view.root, n => n.tag === 'a').length, 0);
    view.unmount();
});

test('Google optional metric selection changes only download URL while required fields remain included', async () => {
    const view = mount(); await dates(view.root);
    transport.get = async () => ({ generation: 1, timezone: 'Asia/Amman', deadline: new Date(Date.now() + 300000).toISOString(),
        last_run: { coverage: 'COMPLETE' }, download: { generation: 1, url: '/report/1/download?stream=bound' } });
    await refresh(view.root); await nextTick();
    const checkboxes = nodes(view.root, n => n.tag === 'input' && n.props.type === 'checkbox');
    assert.equal(checkboxes.length, 5);
    assert.ok(checkboxes.slice(0, 4).every(checkbox => checkbox.props.disabled));
    checkboxes[4].checked = false; checkboxes[4].listeners.change({ target: checkboxes[4] }); await nextTick();
    const selected = new URL(nodes(view.root, n => n.tag === 'a')[0].props.href, 'https://preview.example');
    assert.deepEqual(selected.searchParams.getAll('metrics[]'), ['clicks', 'impressions', 'conversions', 'all_conversions']);
    assert.equal(selected.searchParams.get('stream'), 'bound');
    assert.equal(transport.gets.length, 1); assert.equal(transport.posts.length, 0);
    assert.equal(nodes(view.root, n => n.tag === 'a').length, 1);
    view.unmount();
});

test('Meta exposes explicit async reporting and sends the selected mode without a native task identifier', async () => {
    const view = mount('meta'); await dates(view.root);
    const select = nodes(view.root, n => n.tag === 'select')[0];
    assert.ok(select, 'Meta must expose the historical async option.');
    await submit(view.root); await nextTick();
    assert.equal(transport.posts[0][1].mode, 'ASYNC');
    assert.equal(transport.posts[0][1].report_run_id, undefined);
    assert.equal(transport.gets[0][1].params.mode, 'ASYNC');
    view.unmount();
});
