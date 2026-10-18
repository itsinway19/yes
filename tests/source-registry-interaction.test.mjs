import './source-scope-spa.test.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, h } from 'vue';

const runtime = { calls: [], reads: [], send: async () => ({}), revision: 0, drafts: new Map() };
globalThis.__registrySpa = runtime;
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const emptyComponent = moduleUrl('export default { render: () => null };');
const ports = {
    vue: import.meta.resolve('vue'),
    '@/Components/ProviderBrand.vue': emptyComponent,
    '../Pages/Workspaces/SourceCapabilityStatus.vue': emptyComponent,
    './DeclareSourceScope.vue': emptyComponent,
    './commands': moduleUrl(`const runtime = globalThis.__registrySpa;
        export const submit = command => { runtime.calls.push(command); return runtime.send(command); };
        export const remember = (workspace, feature, value) => { runtime.drafts.set(workspace + feature, JSON.parse(JSON.stringify(value))); };
        export const restore = (workspace, feature) => runtime.drafts.get(workspace + feature);`),
    './state': moduleUrl('export const read = (...args) => globalThis.__registrySpa.reads.push(args);'),
    '../spa/transport': moduleUrl(`export class TransportError extends Error {}
        export const stamp = () => globalThis.__registrySpa.revision;
        export const owned = value => value === globalThis.__registrySpa.revision;`),
};
const { descriptor } = parse(await readFile(new URL('../resources/js/sources/SourceRegistryPanel.vue', import.meta.url), 'utf8'));
let compiled = ts.transpileModule(compileScript(descriptor, { id: 'registry-spa', inlineTemplate: true }).content,
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, target] of Object.entries(ports)) compiled = compiled.replaceAll('from "' + name + '"', 'from ' + JSON.stringify(target)).replaceAll("from '" + name + "'", 'from ' + JSON.stringify(target));
const component = (await import(moduleUrl(compiled))).default;
function element(tag, text = '') { return { tag, text, children: [], props: {}, parent: null, getRootNode() { return {}; }, addEventListener() {}, removeEventListener() {} }; }
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); },
    patchProp(node, key, _old, value) { node.props[key] = value; },
});
const nodes = (root, predicate) => [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = root => root.text + root.children.map(text).join('');
const scope = { resource: 'server-account', operation: 'discovery.read', purpose: 'diagnostic_review' };

test('mixed obligations preserve exact recovery authority and send only the selected obligation through the existing writer', async () => {
    const item = { id: 'source', label: 'Account', family: 'meta', namespace: 'account', declared_external_id: 'declared',
        version: 2, status: 'UNVERIFIED', ...scope, capability: { state: 'UNKNOWN', reason: 'provider_unverified', last_verified_at: null },
        axes: { provider_permission: 'UNKNOWN' }, can_manage: false, scope_choices: [], next_repair: 'provider_verification_needed',
        audit_delivery: [{ id: 'a', command_id: 'receipt-a', receipt_version: 1, ...scope, operation: 'report.read', state: 'PENDING', attempts: 0, can_recover: false, recovery_reason: 'exact_recovery_authority_required' },
            { id: 'b', command_id: 'receipt-b', receipt_version: 2, ...scope, state: 'PENDING', attempts: 0, can_recover: true, recovery_reason: null }] };
    let acknowledge; runtime.calls = []; runtime.reads = [];
    runtime.send = () => new Promise(resolve => { acknowledge = resolve; });
    const root = element('root'), app = renderer.createApp({ render: () => h(component, {
        workspaceId: 'workspace', candidateId: 'candidate', canRequestAuthority: false,
        sources: { state: 'SCOPED_SOURCES', items: [item], registration_authorities: [] },
    }) }); app.mount(root);
    try {
        const actions = nodes(root, node => node.tag === 'button'); assert.equal(actions.length, 1);
        assert.match(text(root), /لا توجد صلاحية استرداد مطابقة/); assert.match(text(root), /receipt-a/);
        actions[0].props.onClick(); await nextTick(); actions[0].props.onClick();
        assert.deepEqual(runtime.calls, [{ workspace: 'workspace', effect: 'audit', source: 'b' }]);
        assert.equal(actions[0].props.disabled, true); acknowledge({}); await nextTick(); await nextTick(); await nextTick();
        assert.deepEqual(runtime.reads, [['workspace', true]]);
        assert.equal(actions[0].props.disabled, false);
    } finally { app.unmount(); }
});
