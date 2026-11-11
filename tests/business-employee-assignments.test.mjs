import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const vue = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export const context={scope:'document-a'};export const stamp=()=>context.scope;export const owned=value=>value===context.scope;`);
const draftsUrl = moduleUrl('export const agencyTeamDrafts=new Map();export const delegationDrafts=new Map();export const key=(business,selection)=>JSON.stringify([business,selection]);');
const apiUrl = moduleUrl('export const choices=[],surfaces=[],choicePending=[],surfacePending=[];export const businessChoices=(...args)=>{choices.push(structuredClone(args));return new Promise((resolve,reject)=>choicePending.push({resolve,reject}));};export const businessSurface=(...args)=>{surfaces.push(structuredClone(args));return new Promise((resolve,reject)=>surfacePending.push({resolve,reject}));};');
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const childUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface','recipientPublicId','active','refreshing'],emits:['refresh','pendingChange'],setup(p,{emit}){return()=>h('section',{'data-canonical-assignment':p.surface.business.business_public_id,'data-fixed-employee':p.recipientPublicId},[h('button',{'data-refresh-client':true,onClick:()=>emit('refresh')},'refresh')]);}};`);
const reviewUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface','showIssuance','showOwnSurrender'],emits:['refresh','pendingChange'],setup(p,{emit}){return()=>h('section',{'data-canonical-review':JSON.stringify(p.surface.delegation.issued.map(row=>row.grant_id)),'data-review-options':JSON.stringify(p.surface.delegation.options),'data-review-selection':JSON.stringify(p.surface.authority_selection),'data-review-issuance':p.showIssuance},[h('button',{'data-refresh-review':true,onClick:()=>emit('refresh')},'refresh')]);}};`);
const { descriptor } = parse(await readFile('resources/js/business-foundation/BusinessEmployeeAssignments.vue', 'utf8'));
let source = ts.transpileModule(compileScript(descriptor, { id: 'employee-assignment', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ vue, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './api': apiUrl, './BusinessAgencyTeam.vue': childUrl, './BusinessDelegation.vue': reviewUrl })) {
    source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
}
const Component = (await import(moduleUrl(source))).default;
const api = await import(apiUrl), drafts = await import(draftsUrl), transport = await import(transportUrl);
const own = '11111111-1111-7111-8111-111111111111', employee = '22222222-2222-7222-8222-222222222222', client = '33333333-3333-7333-8333-333333333333';
const selection = { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] };
const choice = (agency = own, target = client) => ({ business_public_id: target, name: 'Qualified client', state: 'active', read_grant_id: '51', realm_kind: 'direct', realm_workspace_public_id: null, agency_business_public_id: agency });
const page = (items = [], next_cursor = null) => ({ actor_public_id: own, items, next_cursor, scope: { principal_generation: 1, authority_fingerprint: 'qualified' } });
const surface = (allowed = true, target = client) => ({ business: { business_public_id: target, name: 'Qualified client' }, authority_selection: structuredClone(selection), agency_task: { can_assign: allowed, can_mandate: false, agency_business_public_id: own }, agency_business_public_id: own,
    delegation: { options: [{ grant_id: '51', can_issue: false }], issued: [] } });
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
function mount(remembered) {
    for (const list of [api.choices, api.surfaces, api.choicePending, api.surfacePending]) list.length = 0;
    transport.context.scope = 'document-a';
    const active = ref(true), selectedEmployee = ref(employee), root = node('root'), rememberedValues = [];
    const app = renderer.createApp({ render: () => h(Component, { business: own, employee: selectedEmployee.value, active: active.value, refreshing: false, remembered,
        onRemember: value => rememberedValues.push(value) }) }); app.mount(root);
    return { root, app, active, selectedEmployee, rememberedValues,
        async choices(value) { api.choicePending.shift().resolve(value); await flush(); },
        async choose() { find(root, 'data-employee-client').props['onUpdate:modelValue'](JSON.stringify([client, '51'])); await flush(); },
    };
}

test('employee assignment filters qualified choices to the owning Business and requalifies the exact selected client', async () => {
    drafts.agencyTeamDrafts.clear(); const view = mount();
    try {
        await view.choices(page([choice(), choice(employee, employee)]));
        assert.equal(all(view.root).filter(node => node.tag === 'option').length, 2, 'Only placeholder and exact agency client appear');
        await view.choose(); assert.deepEqual(api.surfaces[0], [client, 51]);
        api.surfacePending.shift().resolve(surface()); await flush();
        const panel = find(view.root, 'data-canonical-assignment'); assert.ok(panel); assert.equal(panel.props['data-fixed-employee'], employee);
        assert.equal(view.rememberedValues[0].resource, drafts.key(client, selection));
        find(view.root, 'data-refresh-client').props.onClick(); await flush();
        assert.deepEqual(api.surfaces[1], [client, 51], 'Acknowledgement refreshes the selected client, not the owning agency Business');
    } finally { view.app.unmount(); }
});

test('an empty filtered page retains pagination and client task denial never renders an assignment writer', async () => {
    drafts.agencyTeamDrafts.clear(); const view = mount();
    try {
        await view.choices(page([choice(employee)], '70')); assert.ok(find(view.root, 'data-employee-clients-more'));
        find(view.root, 'data-employee-clients-more').props.onClick(); await flush(); assert.deepEqual(api.choices[1], [70]);
        await view.choices(page([choice()])); await view.choose(); api.surfacePending.shift().resolve(surface(false)); await flush();
        assert.ok(find(view.root, 'data-canonical-assignment') === undefined); assert.match(text(view.root), /unavailable/);
    } finally { view.app.unmount(); }
});

test('late client reads cannot cross employee or active context and an original pending reference blocks target replacement', async () => {
    for (const change of ['inactive', 'employee']) {
        drafts.agencyTeamDrafts.clear(); const view = mount();
        try {
            await view.choices(page([choice()])); await view.choose();
            if (change === 'inactive') view.active.value = false; else view.selectedEmployee.value = own;
            await flush(); api.surfacePending.shift().resolve(surface()); await flush(); assert.ok(find(view.root, 'data-canonical-assignment') === undefined);
        } finally { view.app.unmount(); }
    }
    const reference = { business: client, readGrant: '51', resource: drafts.key(client, selection), employee: own };
    drafts.agencyTeamDrafts.set(reference.resource, { pending: { recipient_public_id: own, command_id: 'original' } });
    const returned = mount(reference);
    try {
        await returned.choices(page([choice()])); api.surfacePending.shift().resolve(surface()); await flush();
        assert.equal(find(returned.root, 'data-employee-client').props.disabled, true);
        assert.ok(find(returned.root, 'data-canonical-assignment'));
        assert.equal(drafts.agencyTeamDrafts.get(reference.resource).pending.recipient_public_id, own);
    } finally { returned.app.unmount(); drafts.agencyTeamDrafts.clear(); }
});

test('employee review scopes issued rows without changing canonical options and retains a different employee’s original revoke', async () => {
    drafts.agencyTeamDrafts.clear(); drafts.delegationDrafts.clear(); const view = mount();
    try {
        await view.choices(page([choice()])); await view.choose(); const qualified = surface();
        qualified.delegation.issued = [
            { grant_id: '81', recipient_public_id: employee, agency_business_public_id: own },
            { grant_id: '82', recipient_public_id: own, agency_business_public_id: own },
            { grant_id: '83', recipient_public_id: employee, agency_business_public_id: null },
        ];
        api.surfacePending.shift().resolve(qualified); await flush(); const review = find(view.root, 'data-canonical-review'); assert.ok(review);
        assert.deepEqual(JSON.parse(review.props['data-canonical-review']), ['81']);
        assert.deepEqual(JSON.parse(review.props['data-review-options']), qualified.delegation.options);
        assert.deepEqual(JSON.parse(review.props['data-review-selection']), selection); assert.equal(review.props['data-review-issuance'], false);
        find(view.root, 'data-refresh-review').props.onClick(); await flush(); assert.deepEqual(api.surfaces[1], [client, 51]);
    } finally { view.app.unmount(); }
    const reference = { business: client, readGrant: '51', resource: drafts.key(client, selection), employee: own };
    const original = { command_id: 'original-revoke', grantee_public_id: own };
    drafts.delegationDrafts.set(`${reference.resource}:delegations:1`, { pending: original });
    const returned = mount(reference);
    try {
        await returned.choices(page([choice()])); api.surfacePending.shift().resolve(surface()); await flush();
        assert.equal(find(returned.root, 'data-employee-client').props.disabled, true);
        assert.ok(find(returned.root, 'data-canonical-review')); assert.ok(find(returned.root, 'data-canonical-assignment') === undefined);
        assert.deepEqual(drafts.delegationDrafts.get(`${reference.resource}:delegations:1`).pending, original);
        assert.match(text(returned.root), /original/);
    } finally { returned.app.unmount(); drafts.delegationDrafts.clear(); }
});
