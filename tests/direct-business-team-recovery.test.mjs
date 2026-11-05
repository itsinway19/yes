import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'document-a'};export const stamp=()=>context.scope;export const owned=value=>value===context.scope;export class TransportError extends Error{constructor(status){super('Rejected');this.status=status;}}`);
const draftsUrl = moduleUrl('export const directTeamDrafts=new Map();export const key=(business,selection)=>JSON.stringify([business,selection]);');
const apiUrl = moduleUrl('export const calls=[],pending=[];export const enrollDirectTeam=(business,body)=>{calls.push(structuredClone({business,body}));return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const { descriptor } = parse(await readFile('resources/js/business-foundation/DirectBusinessTeam.vue', 'utf8'));
let source = ts.transpileModule(compileScript(descriptor, { id: 'direct-team', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ vue, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './api': apiUrl })) {
    source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
}
const Team = (await import(moduleUrl(source))).default;
const api = await import(apiUrl), drafts = await import(draftsUrl), transport = await import(transportUrl);
function node(tag, text = '') {
    return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, style: {}, children: [], parent: null,
        addEventListener() {}, removeEventListener() {}, getRootNode() { return {}; },
    });
}
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
const surface = (id = 'business-a', eligible = true) => ({ business: { business_public_id: id }, direct_team_enrollment_available: eligible,
    authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] } });
function mount(initial = surface(), reset = true) {
    if (reset) { drafts.directTeamDrafts.clear(); api.calls.length = 0; api.pending.length = 0; transport.context.scope = 'document-a'; }
    const selected = ref(initial), refreshing = ref(false), refreshes = [], root = node('root');
    const app = renderer.createApp({ render: () => h(Team, { surface: selected.value, refreshing: refreshing.value, onRefresh: () => refreshes.push(true) }) });
    app.mount(root);
    const draft = drafts.directTeamDrafts.get(drafts.key(initial.business.business_public_id, initial.authority_selection));
    return { root, app, selected, refreshing, refreshes, draft,
        confirm: async () => { const input = find(root, 'data-direct-team-confirm'); assert.ok(input, 'A real confirmation input is available'); input.props['onUpdate:modelValue'](true); await nextTick(); },
        send: () => find(root, 'data-direct-team-submit').props.onClick(),
        refresh: async (eligible = true) => { refreshing.value = true; await nextTick(); selected.value = surface(initial.business.business_public_id, eligible); await nextTick(); refreshing.value = false; await nextTick(); },
    };
}

test('422 restores confirmation and a reviewed retry gets a new command without automatic execution', async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(); view.send();
        assert.equal(api.calls.length, 1, 'A double click cannot duplicate execution');
        const first = structuredClone(api.calls[0]); api.pending.shift().reject(new transport.TransportError(422)); await attempt; await nextTick();
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.confirmed, false); assert.equal(view.draft.blocked, false);
        assert.ok(find(view.root, 'data-direct-team-confirm')); assert.equal(find(view.root, 'data-direct-team-submit').props.disabled, true);
        await view.send(); assert.equal(api.calls.length, 1, 'Rejected request cannot resubmit without a new confirmation');
        await view.confirm(); const retry = view.send(); assert.equal(api.calls.length, 2);
        assert.notEqual(api.calls[1].body.command_id, first.body.command_id);
        api.pending.shift().resolve({ business_public_id: 'business-a', grant_refs: { 'business.context.read': '91' } }); await retry; await nextTick();
        assert.equal(view.draft.pending, undefined); assert.equal(find(view.root, 'data-direct-team-submit').props.disabled, true);
    } finally { view.app.unmount(); }
});

test('409 waits for a newly qualified surface, then permits a newly confirmed command only if still eligible', async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(), first = structuredClone(api.calls[0]);
        api.pending.shift().reject(new transport.TransportError(409)); await attempt; await nextTick();
        assert.equal(view.refreshes.length, 1); assert.equal(view.draft.pending, undefined); assert.equal(view.draft.blocked, true);
        await view.send(); assert.equal(api.calls.length, 1); assert.equal(find(view.root, 'data-direct-team-confirm'), undefined);
        await view.refresh(); assert.equal(view.draft.blocked, false); assert.equal(view.draft.confirmed, false);
        await view.confirm(); const retry = view.send(); assert.notEqual(api.calls[1].body.command_id, first.body.command_id);
        api.pending.shift().resolve({ business_public_id: 'business-a', grant_refs: { 'business.context.read': '91' } }); await retry;
    } finally { view.app.unmount(); }
});

test('409 followed by unavailable enrollment reconciles the new surface without another activation', async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(); api.pending.shift().reject(new transport.TransportError(409)); await attempt;
        await view.refresh(false); assert.equal(api.calls.length, 1); assert.equal(view.draft.pending, undefined);
        assert.equal(find(view.root, 'data-direct-team-confirm'), undefined); assert.equal(find(view.root, 'data-direct-team-submit'), undefined);
        assert.match(text(view.root), /Review the available permissions/);
    } finally { view.app.unmount(); }
});

test('403 blocks activation until explicit authority refresh and requires a new confirmation', async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(); api.pending.shift().reject(new transport.TransportError(403)); await attempt; await nextTick();
        assert.equal(view.draft.blocked, true); assert.equal(view.draft.pending, undefined); assert.equal(view.refreshes.length, 0);
        find(view.root, 'data-direct-team-refresh').props.onClick(); assert.equal(view.refreshes.length, 1);
        await view.refresh(); assert.equal(view.draft.blocked, false); assert.equal(view.draft.confirmed, false);
        assert.ok(find(view.root, 'data-direct-team-confirm')); assert.equal(api.calls.length, 1);
    } finally { view.app.unmount(); }
});

test('unknown result retains exactly the original command through explicit reconciliation', async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(), first = structuredClone(api.calls[0]);
        api.pending.shift().reject(Error('Lost response')); await attempt; await nextTick();
        assert.ok(view.draft.pending); assert.equal(api.calls.length, 1); assert.equal(find(view.root, 'data-direct-team-confirm'), undefined);
        const retry = view.send(); assert.deepEqual(api.calls[1], first);
        api.pending.shift().resolve({ business_public_id: 'business-a', grant_refs: { 'business.context.read': '91' } }); await retry;
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.completed, true);
    } finally { view.app.unmount(); }
});

for (const status of [403, 409, 422]) test(`unknown result followed by ${status} never discards or replaces the original`, async () => {
    const view = mount();
    try {
        await view.confirm(); const attempt = view.send(), first = structuredClone(api.calls[0]);
        api.pending.shift().reject(Error('Lost response')); await attempt;
        const replay = view.send(); api.pending.shift().reject(new transport.TransportError(status)); await replay; await nextTick();
        assert.deepEqual(api.calls[1], first); assert.deepEqual(view.draft.pending, first.body); assert.equal(view.draft.blocked, true);
        await view.send(); assert.equal(api.calls.length, 2);
        find(view.root, 'data-direct-team-refresh').props.onClick(); await view.refresh(false);
        assert.deepEqual(view.draft.pending, first.body); assert.equal(view.draft.blocked, false);
        assert.equal(find(view.root, 'data-direct-team-confirm'), undefined);
        const final = view.send(); assert.deepEqual(api.calls[2], first);
        api.pending.shift().resolve({ business_public_id: 'business-a', grant_refs: { 'business.context.read': '91' } }); await final;
    } finally { view.app.unmount(); }
});

test('leaving during an in-flight activation retains it as unknown on return', async () => {
    const view = mount(); await view.confirm(); const attempt = view.send(), original = structuredClone(api.calls[0]);
    view.app.unmount();
    const returned = mount(surface(), false);
    try {
        assert.equal(returned.draft.uncertain, true);
        api.pending.shift().reject(Error('Response after departure')); await attempt;
        const replay = returned.send(); assert.deepEqual(api.calls[1], original);
        api.pending.shift().reject(new transport.TransportError(403)); await replay;
        assert.deepEqual(returned.draft.pending, original.body); assert.equal(returned.draft.blocked, true);
    } finally { returned.app.unmount(); }
});

test('a changed Business, authority selection or identity cannot accept the old response or expose its draft', async () => {
    for (const change of ['business', 'selection', 'identity']) {
        const view = mount();
        try {
            await view.confirm(); const attempt = view.send();
            if (change === 'business') view.selected.value = surface('business-b');
            else if (change === 'selection') view.selected.value = { ...surface(), authority_selection: { ...surface().authority_selection, requirements: [{ requirement_kind: 'business', leaf_grant_id: 99 }] } };
            else transport.context.scope = 'document-b';
            await nextTick();
            api.pending.shift().resolve({ business_public_id: 'business-a', grant_refs: { 'business.context.read': '91' } }); await attempt; await nextTick();
            assert.ok(view.draft.pending, 'The late response cannot reconcile an unowned operation'); assert.equal(view.refreshes.length, 0); assert.equal(view.draft.completed, undefined);
            if (change !== 'identity') assert.equal(find(view.root, 'data-business-direct-team'), undefined);
            assert.equal(api.calls.length, 1);
        } finally { view.app.unmount(); }
    }
});
