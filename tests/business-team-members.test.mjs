import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'document-a'};export const stamp=()=>context.scope;export const owned=value=>value===context.scope;export class TransportError extends Error{constructor(status){super('Rejected');this.status=status;}}`);
const draftsUrl = moduleUrl('export const teamMembersDrafts=new Map();export const agencyTeamDrafts=new Map();export const delegationDrafts=new Map();export const key=(business,selection)=>JSON.stringify([business,selection]);');
const apiUrl = moduleUrl(`export const reads=[],lookups=[],writes=[],readPending=[],lookupPending=[],writePending=[];
export const businessTeam=(...args)=>{reads.push(structuredClone(args));return new Promise((resolve,reject)=>readPending.push({resolve,reject}));};
export const businessTeamRecipient=(...args)=>{lookups.push(structuredClone(args));return new Promise((resolve,reject)=>lookupPending.push({resolve,reject}));};
export const writeBusinessTeam=(...args)=>{writes.push(structuredClone(args));return new Promise((resolve,reject)=>writePending.push({resolve,reject}));};`);
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const assignmentsUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['employee'],render(){return h('section',{'data-client-assignment-helper':this.employee})}};`);
const custodyUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['members'],emits:['administration','pendingChange'],setup(p,{emit}){return()=>h('section',{'data-test-custody':p.members.length},[h('button',{'data-qualify-custody':true,onClick:()=>emit('administration',true)}),h('button',{'data-pending-custody':true,onClick:()=>emit('pendingChange',true)})]);}};`);
const { descriptor } = parse(await readFile('resources/js/business-foundation/BusinessTeamMembers.vue', 'utf8'));
let source = ts.transpileModule(compileScript(descriptor, { id: 'business-team', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ vue, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './api': apiUrl, './BusinessEmployeeAssignments.vue': assignmentsUrl, './BusinessFileCustody.vue':custodyUrl })) {
    source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
}
const Team = (await import(moduleUrl(source))).default;
const api = await import(apiUrl), drafts = await import(draftsUrl), transport = await import(transportUrl);
const business = '11111111-1111-7111-8111-111111111111', employee = '22222222-2222-7222-8222-222222222222';
const selection = { realm_kind: 'direct',  requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] };
const surface = (target = business, allowed = true) => ({ business: { business_public_id: target }, authority_selection: structuredClone(selection), agency_task: { can_mandate: allowed } });
const member = () => ({ public_id: employee, name: 'Registered Employee', email: 'employee@example.test', grants: [
    { grant_id: '71', version: 2, action: 'business.context.read', expires_at: null },
    { grant_id: '72', version: 3, action: 'business.context.manage', expires_at: '2030-01-01T00:00:00Z' },
] });
const roster = (members = []) => ({ business_public_id: business, members, next_cursor: null,
    actions: ['business.context.read', 'business.context.manage', 'business.intent.declare', 'business.markets.declare', 'business.preferences.manage'] });
globalThis.Document ??= class Document {};
globalThis.ShadowRoot ??= class ShadowRoot {};
function node(tag, text = '') { return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, style: {}, children: [], parent: null,
    get options() { return this.children.filter(child => child.tag === 'option'); }, addEventListener() {}, removeEventListener() {}, getRootNode() { return {}; } }); }
const renderer = createRenderer({
    createElement: tag => node(tag), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText(target, text) { target.text = text; }, setElementText(target, text) { target.text = text; target.children = []; },
    parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    insert(target, parent, anchor) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(target); else parent.children.splice(index, 0, target); },
    remove(target) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = null; },
    patchProp(target, key, _old, value) { target.props[key] = value; },
});
const all = root => [root, ...root.children.flatMap(all)];
const find = (root, key) => all(root).find(target => key in target.props);
const text = root => root.text + root.children.map(text).join('');
async function flush() { await nextTick(); await nextTick(); }
function mount(reset = true, allowed = true) {
    if (reset) { drafts.teamMembersDrafts.clear(); for (const list of [api.reads, api.lookups, api.writes, api.readPending, api.lookupPending, api.writePending]) list.length = 0; transport.context.scope = 'document-a'; }
    const selected = ref(surface(business, allowed)), active = ref(true), refreshing = ref(false), refreshes = [], root = node('root');
    const app = renderer.createApp({ render: () => h(Team, { surface: selected.value, active: active.value, refreshing: refreshing.value, onRefresh: () => refreshes.push(true) }) });
    app.mount(root);
    const draft = drafts.teamMembersDrafts.get(drafts.key(business, selection));
    const model = async (name, value) => { const field = find(root, name); assert.ok(field, name); field.props['onUpdate:modelValue'](value); await flush(); };
    return { root, app, selected, active, refreshing, refreshes, draft, model,
        async load(members = []) { api.readPending.shift().resolve(roster(members)); await flush(); },
        async add() { find(root, 'data-team-add').props.onClick(); await flush(); await model('data-team-email', 'employee@example.test');
            const pending = find(root, 'data-team-lookup').props.onClick(); api.lookupPending.shift().resolve({ business_public_id: business, recipient: { public_id: employee, name: 'Registered Employee', email: 'employee@example.test' } }); await pending; await flush(); },
        send: () => find(root, 'data-team-save').props.onClick(),
    };
}

test('employee-centric addition resolves a registered email then confirms exact Business permissions once', async () => {
    const view = mount();
    try {
        assert.deepEqual(api.reads[0], [business, 51, undefined]); await view.load(); await view.add();
        assert.deepEqual(api.lookups[0], [business, { access: 51, email: 'employee@example.test' }]);
        await view.model('data-team-permissions', ['business.context.read', 'business.context.manage']); await view.model('data-team-confirm', true);
        const sending = view.send(); view.send(); assert.equal(api.writes.length, 1);
        const command = api.writes[0][1]; assert.deepEqual(command.authority_selection, selection); assert.equal(command.recipient_public_id, employee);
        assert.deepEqual(command.expected_grants, []); assert.deepEqual(command.permissions, ['business.context.read', 'business.context.manage']);
        assert.equal(command.confirmed, true); assert.equal(command.expires_at, null);
        api.writePending.shift().resolve({ business_public_id: business, recipient_public_id: employee, status: 'recorded', grant_refs: {}, versions: { authority_epoch: 3 } }); await sending;
        assert.equal(view.draft.pending, undefined); assert.equal(view.refreshes.length, 1);
    } finally { view.app.unmount(); }
});

test('editing uses every returned staff grant and requires an explicit common expiry when existing grants differ', async () => {
    const view = mount();
    try {
        await view.load([member()]); find(view.root, 'data-team-edit').props.onClick(); await flush();
        assert.equal(view.draft.expiryMode, 'unreviewed'); await view.model('data-team-confirm', true);
        await view.send(); assert.equal(api.writes.length, 0);
        await view.model('data-team-expiry-mode', 'indefinite'); await view.model('data-team-confirm', true);
        const sending = view.send(), body = api.writes[0][1];
        assert.deepEqual(body.expected_grants, [{ grant_id: '71', version: 2 }, { grant_id: '72', version: 3 }]); assert.equal(body.expires_at, null);
        api.writePending.shift().reject(Error('lost acknowledgement')); await sending;
    } finally { view.app.unmount(); }
});

test('remove confirms empty permissions for exactly the returned direct staff grants and preserves an unknown original', async () => {
    const view = mount(); await view.load([member()]); find(view.root, 'data-team-remove').props.onClick(); await flush();
    await view.model('data-team-confirm', true); const sending = view.send(), original = structuredClone(api.writes[0]);
    assert.deepEqual(original[1].permissions, []); assert.equal(original[1].expected_grants.length, 2);
    api.writePending.shift().reject(Error('lost acknowledgement')); await sending; view.app.unmount();
    const returned = mount(false, false);
    try {
        assert.ok(find(returned.root, 'data-team-save')); const retry = returned.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(new transport.TransportError(403)); await retry;
        assert.deepEqual(returned.draft.pending, original[1]);
    } finally { returned.app.unmount(); }
});

test('unavailable recipient never creates an invitation or command and inactive reads cannot disclose employee metadata', async () => {
    const view = mount();
    try {
        await view.load(); find(view.root, 'data-team-add').props.onClick(); await flush();
        await view.model('data-team-email', 'missing@example.test'); const lookup = find(view.root, 'data-team-lookup').props.onClick();
        api.lookupPending.shift().reject(new transport.TransportError(403)); await lookup; await flush();
        assert.equal(api.writes.length, 0); assert.match(text(view.root), /registered|verified/); assert.ok(find(view.root, 'data-team-confirm') === undefined);
        view.selected.value = { ...surface() }; await flush(); const response = api.readPending.shift();
        view.active.value = false; await flush(); response.resolve(roster([member()])); await flush();
        assert.doesNotMatch(text(view.root), /Registered Employee|employee@example.test/);
    } finally { view.app.unmount(); }
});

test('nonempty permissions require reading and refreshed staff changes do not silently replace the edit baseline', async () => {
    const view = mount();
    try {
        await view.load([member()]); find(view.root, 'data-team-edit').props.onClick(); await flush();
        await view.model('data-team-expiry-mode', 'indefinite'); await view.model('data-team-permissions', ['business.context.manage']); await view.model('data-team-confirm', true);
        await view.send(); assert.equal(api.writes.length, 0, 'Editing cannot omit required reading');
        const original = JSON.parse(JSON.stringify(view.draft.expected));
        view.selected.value = { ...surface() }; await flush();
        const changed = member(); changed.grants[0].version = 4;
        api.readPending.shift().resolve(roster([changed])); await flush();
        assert.deepEqual(view.draft.expected, original, 'A current roster does not overwrite the user’s original CAS basis');
        assert.ok(find(view.root, 'data-team-confirm') === undefined);
        find(view.root, 'data-team-edit').props.onClick(); await flush();
        assert.equal(view.draft.expected[0].version, 4, 'Explicitly editing the current row adopts its new basis');
    } finally { view.app.unmount(); }
});

test('the team API contract exposes employee identity and exact expected staff grants without a parallel membership payload', async () => {
    const { spec } = await import('../scripts/application-openapi.mjs');
    assert.ok(spec.paths['/api/businesses/{business}/team'].get);
    assert.ok(spec.paths['/api/businesses/{business}/team'].post);
    const lookup = spec.paths['/api/businesses/{business}/team/recipient'].post.requestBody.content['application/json'].schema;
    assert.deepEqual(lookup.required, ['access', 'email']);
    const command = spec.components.schemas.BusinessTeamCommand;
    assert.equal(command.additionalProperties, false);
    assert.deepEqual(command.required, ['command_id', 'authority_selection', 'recipient_public_id', 'expected_grants', 'permissions', 'expires_at', 'confirmed']);
    assert.deepEqual(command.properties.expected_grants.items.required, ['grant_id', 'version']);
    assert.equal(command.properties.permissions.maxItems, 5); assert.equal(command.properties.confirmed.const, true);
    assert.equal(command.properties.workspace_public_id, undefined); assert.equal(command.properties.membership, undefined);
});

test('a late team acknowledgement cannot settle an inactive context and return retains the original recovery', async () => {
    const view = mount();
    try {
        await view.load(); await view.add(); await view.model('data-team-confirm', true);
        const sending = view.send(), original = structuredClone(api.writes[0]);
        view.active.value = false; await flush();
        api.writePending.shift().resolve({ business_public_id: business, recipient_public_id: employee, status: 'recorded', grant_refs: {}, versions: { authority_epoch: 3 } }); await sending;
        assert.ok(view.draft.pending); assert.equal(view.refreshes.length, 0);
        view.active.value = true; await flush();
        const retry = view.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(Error('still unknown')); await retry;
        assert.deepEqual(view.draft.pending, original[1]); assert.match(text(view.root), /outcome is unknown/);
    } finally { view.app.unmount(); }
});

test('a named roster employee opens the canonical client assignment task instead of another authority editor', async () => {
    const view = mount();
    try {
        await view.load([member()]); find(view.root, 'data-team-assign-clients').props.onClick(); await flush();
        const helper = find(view.root, 'data-client-assignment-helper'); assert.ok(helper);
        assert.equal(helper.props['data-client-assignment-helper'], employee);
        assert.equal(api.writes.length, 0); assert.equal(api.lookups.length, 0);
        find(view.root, 'data-team-edit').props.onClick(); await flush();
        assert.ok(find(view.root, 'data-client-assignment-helper') === undefined, 'One employee editing task is visible at a time');
    } finally { view.app.unmount(); }
});

test('direct custodian qualification loads the employee roster without agency authority and blocks parallel pending work', async () => {
    const view = mount(true, false);
    try {
        assert.ok(find(view.root, 'data-test-custody'), 'Custody qualification is reachable before a manageable roster');
        assert.equal(api.reads.length, 0);
        find(view.root, 'data-qualify-custody').props.onClick(); await flush();
        assert.equal(api.reads.length, 1); await view.load([member()]);
        assert.equal(find(view.root, 'data-test-custody').props['data-test-custody'], 1);
        assert.equal(view.selected.value.agency_task.can_mandate, false);
        assert.equal(find(view.root, 'data-team-assign-clients'), undefined, 'Custody grants no client-assignment authority');
        find(view.root, 'data-pending-custody').props.onClick(); await flush();
        assert.equal(find(view.root, 'data-team-add').props.disabled, true);
        find(view.root, 'data-team-add').props.onClick(); await flush(); assert.equal(find(view.root, 'data-team-email'), undefined);
    } finally { view.app.unmount(); }
});
