import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'document-a'};export const stamp=()=>context.scope;export const owned=value=>value===context.scope;export class TransportError extends Error{constructor(status){super('Rejected');this.status=status;}}`);
const draftsUrl = moduleUrl('export const agencyTeamDrafts=new Map();export const key=(business,selection)=>JSON.stringify([business,selection]);');
const apiUrl = moduleUrl('export const reads=[],writes=[],readPending=[],writePending=[];export const agencyOptions=(...args)=>{reads.push(structuredClone(args));return new Promise((resolve,reject)=>readPending.push({resolve,reject}));};export const writeAgencyGrant=(business,body)=>{writes.push(structuredClone({business,body}));return new Promise((resolve,reject)=>writePending.push({resolve,reject}));};');
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const referenceUrl = moduleUrl(ts.transpileModule(await readFile('resources/js/business-foundation/businessReference.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText);
const { descriptor } = parse(await readFile('resources/js/business-foundation/BusinessAgencyTeam.vue', 'utf8'));
let source = ts.transpileModule(compileScript(descriptor, { id: 'agency-team', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ './businessReference': referenceUrl, vue, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './api': apiUrl })) {
    source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
}
const Team = (await import(moduleUrl(source))).default;
const api = await import(apiUrl), drafts = await import(draftsUrl), transport = await import(transportUrl);
const language = await import(localeUrl);
const business = '11111111-1111-7111-8111-111111111111', agency = '22222222-2222-7222-8222-222222222222', employee = '33333333-3333-7333-8333-333333333333';
const selection = { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] };
const surface = (target = business, mode = 'mandate') => ({ business: { business_public_id: target }, authority_selection: structuredClone(selection),
    agency_task: { can_mandate: mode === 'mandate', can_assign: mode === 'assign', agency_business_public_id: mode === 'assign' ? agency : null } });
const projection = (effect = 'mandate') => ({ business_public_id: business, agency_business_public_id: agency, effect,
    recipient: { public_id: employee, membership_grant_id: 72 }, scope: 'current_declaration_agency_only',
    options: [{ action: 'business.context.read', parent_grant_id: 51, authority_selection: structuredClone(selection), max_expires_at: '2031-01-01T00:00:00Z' }] });
globalThis.Document ??= class Document {};
globalThis.ShadowRoot ??= class ShadowRoot {};
function node(tag, text = '') { return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, style: {}, children: [], parent: null,
    get options() { return this.children.filter(child => child.tag === 'option'); },
    addEventListener() {}, removeEventListener() {}, getRootNode() { return {}; } }); }
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
function mount(reset = true, mode = 'mandate', fixedRecipient, names = {}) {
    if (reset) { drafts.agencyTeamDrafts.clear(); for (const list of [api.reads, api.writes, api.readPending, api.writePending]) list.length = 0; transport.context.scope = 'document-a'; }
    const selected = ref(surface(business, mode)), active = ref(true), refreshing = ref(false), refreshes = [], root = node('root');
    const fixed = ref(fixedRecipient);
    const app = renderer.createApp({ render: () => h(Team, { surface: selected.value, active: active.value, refreshing: refreshing.value, recipientPublicId: fixed.value, ...names, onRefresh: () => refreshes.push(true) }) });
    app.mount(root);
    const draft = drafts.agencyTeamDrafts.get(drafts.key(business, selection));
    const model = async (name, value) => { const field = find(root, name); assert.ok(field, name); field.props['onUpdate:modelValue'](value); await nextTick(); };
    return { root, app, selected, active, refreshing, refreshes, draft, model, fixed,
        inspect: () => find(root, 'data-agency-inspect').props.onClick(),
        send: () => find(root, 'data-agency-submit').props.onClick(),
        async qualify(effect = 'mandate') {
            if (!selected.value.agency_task[`can_${effect}`] || draft.mode !== effect) { selected.value = surface(business, effect); await nextTick(); }
            if (effect === 'mandate') await model('data-agency-business', agency);
            if (effect === 'assign') await model('data-agency-recipient', employee);
            const pending = this.inspect(); api.readPending.shift().resolve(projection(effect)); await pending; await nextTick();
            await model('data-agency-expiry', '2030-01-01T12:00'); await model('data-agency-confirm', true);
        },
    };
}

test('any business uses the same delegation task without choosing an agency account type', async () => {
    const view = mount();
    try {
        assert.match(text(view.root), /Delegate to another business/);
        assert.doesNotMatch(text(view.root), /Agency mandates|agency account|agency plan/);
        assert.equal(find(view.root, 'data-account-type'), undefined);
        language.locale.value = 'ar'; await nextTick();
        assert.match(text(view.root), /التفويض بين الأنشطة/);
        assert.match(text(view.root), /أي نشاط/);
    } finally { language.locale.value = 'en'; view.app.unmount(); }
});

test('roster assignment fixes its employee and preserves an older employee’s exact pending command', async () => {
    const view = mount(true, 'assign', employee);
    try {
        assert.equal(view.draft.recipient, employee);
        assert.equal(find(view.root, 'data-agency-recipient').props.readonly, true);
        await view.qualify('assign'); const sending = view.send(), original = structuredClone(api.writes[0]);
        api.writePending.shift().reject(Error('acknowledgement lost')); await sending;
        view.fixed.value = business; await nextTick();
        assert.equal(view.draft.recipient, employee, 'A different roster selection must not retarget the original');
        assert.match(text(view.root), /original request|original recipient/);
        const checking = view.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(new transport.TransportError(403)); await checking;
    } finally { view.app.unmount(); }
});

test('agency panel uses exact server options without exposing grant inputs and sends a confirmed finite command once', async () => {
    const view = mount();
    try {
        assert.equal(api.reads.length, 0); assert.equal(api.writes.length, 0);
        await view.qualify();
        assert.equal(find(view.root, 'data-agency-recipient'), undefined);
        assert.equal(all(view.root).some(field => ['membership_grant_id', 'parent_grant_id'].includes(field.props.name)), false);
        assert.match(text(view.root), /Source|source/); assert.match(text(view.root), /private/);
        const pending = view.send(); view.send(); assert.equal(api.writes.length, 1);
        const command = api.writes[0].body;
        assert.equal(command.agency_business_public_id, agency); assert.equal(command.recipient_public_id, employee);
        assert.equal(command.membership_grant_id, 72); assert.equal(command.parent_grant_id, 51); assert.equal(command.confirmed, true);
        assert.deepEqual(command.authority_selection, selection); assert.equal(command.effect, 'mandate');
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: agency, grant_id: 83, status: 'active' }); await pending; await nextTick();
        assert.equal(view.draft.pending, undefined); assert.equal(view.refreshes.length, 1); assert.match(text(view.root), /recorded/);
    } finally { view.app.unmount(); }
});

test('server affordances hide impossible new tasks while retained unknown commands stay reconcilable', async () => {
    const view = mount();
    try {
        view.selected.value = surface(business, 'employee'); await nextTick();
        assert.ok(find(view.root, 'data-agency-business') === undefined);
        assert.ok(find(view.root, 'data-agency-inspect') === undefined);
        view.selected.value = surface(); await nextTick();
        await view.qualify(); const pending = view.send(), original = structuredClone(api.writes[0]);
        api.writePending.shift().reject(Error('lost acknowledgement')); await pending;
        view.selected.value = surface(business, 'employee'); await nextTick();
        assert.ok(find(view.root, 'data-agency-submit'), 'Withdrawal of new-task eligibility cannot conceal an uncertain command');
        const retry = view.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(new transport.TransportError(403)); await retry;
        assert.deepEqual(view.draft.pending, original.body);
    } finally { view.app.unmount(); }
});

test('agency manager starts in assignment mode with a fixed agency and no impossible mandate selector', async () => {
    const view = mount(true, 'assign');
    try {
        assert.equal(view.draft.mode, 'assign'); assert.equal(view.draft.agency, agency);
        assert.equal(find(view.root, 'data-agency-mode'), undefined);
        assert.equal(find(view.root, 'data-agency-business').props.readonly, true);
        assert.ok(find(view.root, 'data-agency-recipient'));
    } finally { view.app.unmount(); }
});

test('named assignment requires an employee and keeps exact identity in discovery and command', async () => {
    const view = mount(true, 'assign');
    try {
        await view.inspect(); assert.equal(api.reads.length, 0);
        await view.qualify('assign'); assert.equal(api.reads[0][3], employee);
        const pending = view.send(); assert.equal(api.writes[0].body.effect, 'assign');
        api.writePending.shift().reject(Error('lost acknowledgement')); await pending;
        assert.ok(view.draft.pending);
    } finally { view.app.unmount(); }
});

test('unknown outcome preserves the frozen command across changes, unmount and explicit reconciliation', async () => {
    const view = mount(); await view.qualify(); const pending = view.send(), original = structuredClone(api.writes[0]);
    api.writePending.shift().reject(Error('lost acknowledgement')); await pending; await nextTick();
    view.draft.agency = employee; view.draft.action = 'business.context.manage'; view.app.unmount();
    const returned = mount(false);
    try {
        assert.equal(api.writes.length, 1);
        const retry = returned.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().reject(new transport.TransportError(422)); await retry; await nextTick();
        assert.deepEqual(returned.draft.pending, original.body, 'A rejection after unknown delivery cannot erase possible commit');
        assert.equal(find(returned.root, 'data-agency-submit').props.disabled, true);
    } finally { returned.app.unmount(); }
});

test('stale discovery and late acknowledgements cannot populate another Business or an inactive panel', async () => {
    for (const change of ['business', 'inactive', 'identity']) {
        const view = mount();
        try {
            await view.model('data-agency-business', agency); const pending = view.inspect();
            if (change === 'business') view.selected.value = surface(employee);
            else if (change === 'inactive') view.active.value = false;
            else transport.context.scope = 'document-b';
            await nextTick(); api.readPending.shift().resolve(projection()); await pending; await nextTick();
            assert.equal(find(view.root, 'data-agency-confirm'), undefined); assert.equal(api.writes.length, 0);
        } finally { view.app.unmount(); }
    }
});

test('changed target and first validation rejection require fresh discovery and confirmation', async () => {
    const view = mount();
    try {
        await view.qualify(); await view.model('data-agency-business', employee);
        assert.equal(find(view.root, 'data-agency-confirm'), undefined);
        await view.qualify(); const pending = view.send(); api.writePending.shift().reject(new transport.TransportError(422)); await pending; await nextTick();
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.confirmed, false); assert.equal(api.writes.length, 1);
    } finally { view.app.unmount(); }
});

test('a write completed while inactive remains unknown and can reconcile after the panel returns', async () => {
    const view = mount();
    try {
        await view.qualify(); const pending = view.send(), original = structuredClone(api.writes[0]);
        view.active.value = false; await nextTick();
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: agency, grant_id: 83, status: 'active' }); await pending;
        assert.ok(view.draft.pending); assert.equal(view.refreshes.length, 0);
        view.active.value = true; await nextTick();
        assert.equal(find(view.root, 'data-agency-submit').props.disabled, false);
        const retry = view.send(); assert.deepEqual(api.writes[1], original);
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: agency, grant_id: 83, status: 'active' }); await retry;
        assert.equal(view.draft.pending, undefined);
    } finally { view.app.unmount(); }
});

test('fresh discovery denial conceals prior options and a mismatched acknowledgement remains unknown', async () => {
    const view = mount();
    try {
        await view.qualify(); const checking = view.inspect(); await nextTick();
        assert.equal(find(view.root, 'data-agency-confirm'), undefined);
        api.readPending.shift().reject(new transport.TransportError(403)); await checking; await nextTick();
        assert.equal(find(view.root, 'data-agency-submit'), undefined); assert.equal(api.writes.length, 0);
        await view.qualify(); const sending = view.send(), original = structuredClone(api.writes[0].body);
        api.writePending.shift().resolve({ business_public_id: business, agency_business_public_id: employee, grant_id: 83, status: 'active' }); await sending;
        assert.deepEqual(view.draft.pending, original); assert.equal(view.refreshes.length, 0); assert.equal(view.draft.outcome, 'unknown');
    } finally { view.app.unmount(); }
});

test('two qualified paths for the same action keep the exact user-selected parent', async () => {
    const view = mount();
    try {
        await view.model('data-agency-business', agency); const pending = view.inspect(), options = projection();
        options.options.push({ ...structuredClone(options.options[0]), parent_grant_id: 61,
            authority_selection: { ...structuredClone(selection), requirements: [{ requirement_kind: 'business', leaf_grant_id: 61 }] } });
        api.readPending.shift().resolve(options); await pending; await nextTick();
        assert.equal(view.draft.action, '', 'Two paths must not silently pick the first');
        assert.match(text(view.root), /Authority path 1/); assert.match(text(view.root), /Authority path 2/);
        await view.model('data-agency-action', '61'); await view.model('data-agency-expiry', '2030-01-01T12:00'); await view.model('data-agency-confirm', true);
        const sending = view.send(); assert.equal(api.writes[0].body.parent_grant_id, 61);
        assert.equal(api.writes[0].body.authority_selection.requirements[0].leaf_grant_id, 61);
        api.writePending.shift().reject(Error('lost acknowledgement')); await sending;
    } finally { view.app.unmount(); }
});


test('Business links identify only same-origin Business paths without navigating or fetching URLs', async () => {
    const { businessReference } = await import(referenceUrl);
    assert.equal(businessReference(`https://builderad.test/businesses/${agency}?access=99`, 'https://builderad.test'), agency);
    assert.equal(businessReference(`/businesses/${agency}`, 'https://builderad.test'), agency);
    for (const value of [`https://other.test/businesses/${agency}`, `https://user:pass@builderad.test/businesses/${agency}`, `/workspaces/${agency}`, 'javascript:alert(1)', `/businesses/${agency}/unknown`]) assert.equal(businessReference(value, 'https://builderad.test'), undefined, value);
    const view = mount();
    try { await view.model('data-agency-business', `http://localhost/businesses/${agency}?access=999`); const checking = view.inspect(); assert.equal(api.reads[0][2],agency); api.readPending.shift().resolve(projection()); await checking; }
    finally {view.app.unmount();}
});

test('qualified known names are primary for fixed employee assignments while technical identifiers remain details', async () => {
    const view = mount(true, 'assign', employee, { recipientName: 'Named employee', agencyName: 'Known business' });
    try {
        assert.match(text(find(view.root,'data-agency-known-recipient')), /Named employee/);
        assert.match(text(find(view.root,'data-agency-known-business')), /Known business/);
        assert.equal(find(view.root,'data-agency-recipient'),undefined);assert.equal(find(view.root,'data-agency-business'),undefined);
        assert.ok(all(view.root).filter(item=>item.tag==='details').length>=2);
    } finally {view.app.unmount();}
});
