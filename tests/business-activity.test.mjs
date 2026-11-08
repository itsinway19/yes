import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

const source = await readFile('resources/js/business-foundation/activityPending.ts', 'utf8');
const stored = new Map();
globalThis.sessionStorage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
const pendingTransportUrl = 'data:text/javascript;base64,' + Buffer.from("export const scope='principal-a:lifecycle-1';export const callbacks=[];export const onClose=fn=>callbacks.push(fn);export const request=()=>{throw Error('No automatic request allowed');};").toString('base64');
const code = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText.replaceAll("'../spa/transport'", JSON.stringify(pendingTransportUrl));
const pending = await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
const pendingUrl = 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const activityModule = ts.transpileModule(await readFile('resources/js/business-foundation/activity.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText
    .replaceAll("'../spa/transport'", JSON.stringify(pendingTransportUrl)).replaceAll("'./activityPending'", JSON.stringify(pendingUrl));
await import('data:text/javascript;base64,' + Buffer.from(activityModule).toString('base64'));
const pendingTransport = await import(pendingTransportUrl);

test('an unresolved command cannot be silently replaced or rebased onto another revision', () => {
    pending.clearActivityCommands();
    const body = { command_id: 'original', expected_revision: 3, value: { name: 'Sell equipment' } };
    const retained = pending.retainActivityCommand('business-a:direct', body);
    body.value.name = 'Changed while sending';
    retained.uncertain = true;
    const replacement = pending.retainActivityCommand('business-a:direct', { command_id: 'replacement', expected_revision: 4 });
    assert.equal(replacement, retained);
    assert.deepEqual(replacement.command, { command_id: 'original', expected_revision: 3, value: { name: 'Sell equipment' } });
    assert.equal(pending.hasPendingActivity('business-a'), true);
    assert.equal(pending.hasPendingActivity('business-ab'), false);
    pending.settleActivityCommand('business-a:direct');
    assert.equal(pending.hasPendingActivity('business-a'), false);
});

test('generic conflict and a later validation failure cannot erase an uncertain outcome', () => {
    assert.equal(pending.definitiveActivityRejection(422, false), true);
    assert.equal(pending.definitiveActivityRejection(422, true), false);
    assert.equal(pending.definitiveActivityRejection(409, false), false);
    assert.equal(pending.definitiveActivityRejection(409, true), false);
    assert.equal(pending.definitiveActivityRejection(409, true, 'practice_revision_rejected'), true);
    assert.equal(pending.definitiveActivityRejection(403, true), false);
});

test('a same-identity document reload restores the exact command and another identity cannot see it', async () => {
    pending.clearActivityCommands();
    pending.retainActivityCommand('business-a:practice', { command_id: 'lost', authority_selection: { grant: 1 }, value: { name: 'Private declaration' } });
    for (const close of pendingTransport.callbacks) close();
    assert.equal(pending.activityCommand('business-a:practice'), undefined, 'A retired document retains no usable private command in memory');
    const reloaded = await import('data:text/javascript;base64,' + Buffer.from(code + '\n// reload').toString('base64'));
    assert.deepEqual(reloaded.activityCommand('business-a:practice')?.command, { command_id: 'lost', authority_selection: { grant: 1 }, value: { name: 'Private declaration' } });
    assert.equal(reloaded.activityCommand('business-a:practice').uncertain, true);
    const otherTransport = 'data:text/javascript;base64,' + Buffer.from("export const scope='principal-b:lifecycle-2';export const onClose=()=>{};").toString('base64');
    const other = await import('data:text/javascript;base64,' + Buffer.from(code.replace(JSON.stringify(pendingTransportUrl), JSON.stringify(otherTransport))).toString('base64'));
    assert.equal(other.activityCommand('business-a:practice'), undefined);
    assert.equal(reloaded.activityCommand('business-b:practice'), undefined);
    reloaded.clearActivityCommands(); pending.clearActivityCommands();
});

test('pending requests stay scoped to the Business and are removed on document retirement', () => {
    pending.clearActivityCommands();
    pending.retainActivityCommand('a:direct', { command_id: 'a' });
    pending.retainActivityCommand('b:agency', { command_id: 'b' });
    pending.settleActivityCommand('a:direct');
    assert.equal(pending.hasPendingActivity('a'), false);
    assert.equal(pending.hasPendingActivity('b'), true);
    pending.clearActivityCommands();
    assert.equal(pending.hasPendingActivity('b'), false);
});

const moduleUrl = value => 'data:text/javascript;base64,' + Buffer.from(value).toString('base64');
const vueUrl = import.meta.resolve('vue');
const transportUrl = moduleUrl(`export let generation=0;export const advance=()=>generation++;export const stamp=()=>generation;export const owned=value=>value===generation;export class TransportError extends Error{constructor(status,error){super('Rejected');this.status=status;this.body={error};}}`);
const localeUrl = moduleUrl(`import {ref} from ${JSON.stringify(vueUrl)};export const locale=ref('en');`);
const activityUrl = moduleUrl(`export const state={items:[],reads:[],writes:[],pending:[],deferred:[],historyAvailable:true};export const practiceId=()=>'019a0000-0000-7000-8000-000000000001';export const readPractices=(business,access,practice)=>{state.reads.push({business,access,practice});return state.deferred.length?state.deferred.shift():Promise.resolve({business_public_id:business,practices:structuredClone(state.items),history_available:state.historyAvailable});};export const writePractice=(business,body)=>{state.writes.push(JSON.parse(JSON.stringify({business,body})));return new Promise((resolve,reject)=>state.pending.push({resolve,reject}));};`);
const draftsUrl = moduleUrl('export const key=(business,selection)=>JSON.stringify([business,selection]);');
const { descriptor } = parse(await readFile('resources/js/business-foundation/BusinessPractices.vue', 'utf8'));
let componentCode = ts.transpileModule(compileScript(descriptor, { id: 'business-practice-test', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ './BusinessPracticeKnowledge.vue': moduleUrl('export default {render(){return null;}}'), vue: vueUrl, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './activityPending': pendingUrl, './activity': activityUrl })) componentCode = componentCode.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
const Practices = (await import(moduleUrl(componentCode))).default;
const activity = await import(activityUrl), transport = await import(transportUrl);
const language = await import(localeUrl);
globalThis.Document ??= class Document {};
globalThis.ShadowRoot ??= class ShadowRoot {};
globalThis.document ??= { activeElement: null };
function node(tag, text = '') { return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, children: [], parent: null, value: '', selectedIndex: -1,
    get options() { return this.children.filter(item => item.tag === 'option'); },
    addEventListener() {}, removeEventListener() {}, setAttribute() {}, removeAttribute() {}, getRootNode() { return {}; } }); }
const renderer = createRenderer({ createElement: tag => node(tag), createText: value => node('#text', value), createComment: value => node('#comment', value),
    setText(target, value) { target.text = value; }, setElementText(target, value) { target.text = value; target.children = []; },
    parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
    insert(target, parent, anchor) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(target); else parent.children.splice(index, 0, target); },
    remove(target) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); target.parent = null; }, patchProp(target, key, _old, value) { target.props[key] = value; if (['value', 'multiple', 'checked', 'selected'].includes(key)) target[key] = value; },
});
const all = root => [root, ...root.children.flatMap(all)];
const find = (root, property, value) => all(root).find(item => value === undefined ? property in item.props : item.props[property] === value);
const visibleText = root => root.text + root.children.map(visibleText).join('');
const flush = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick(); };
const surface = (business = 'business-a', writable = true) => ({ business: { business_public_id: business }, authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 11 }] }, context_write_options: writable ? [{ grant_id: '12' }] : [], catalog: { countries: [{ code: 'SA', name_ar: 'السعودية', name_en: 'Saudi Arabia' }] } });
function mount(initial = surface(), reset = true) {
    if (reset) { pending.clearActivityCommands(); activity.state.items = []; activity.state.reads = []; activity.state.writes = []; activity.state.pending = []; activity.state.deferred = []; activity.state.historyAvailable = true; language.locale.value = 'en'; }
    const selected = ref(initial), active = ref(true), root = node('root');
    const app = renderer.createApp({ render: () => h(Practices, { surface: selected.value, active: active.value }) }); app.mount(root);
    return { app, root, selected, active };
}
async function create(view) {
    await flush(); find(view.root, 'data-practice-add').props.onClick(); await nextTick();
    find(view.root, 'id', 'bf-practice-name').props['onUpdate:modelValue']('Sell equipment'); await nextTick();
    return { promise: find(view.root, 'data-practice-form').props.onSubmit({ preventDefault() {} }) };
}

test('real activity form retains exact unknown save across remount and preserves a lost original after 422 retry', async () => {
    const first = mount(); let second;
    try {
        const { promise } = await create(first); await flush(); assert.equal(activity.state.writes.length, 1);
        const original = structuredClone(activity.state.writes[0]);
        activity.state.pending.shift().reject(Error('Lost reply')); await promise; await flush();
        assert.ok(find(first.root, 'data-practice-unknown'));
        first.app.unmount(); second = mount(surface(), false); await flush();
        const reconcile = all(second.root).find(item => item.tag === 'button' && visibleText(item).includes('Check using original request'));
        const retry = reconcile.props.onClick(); await flush();
        assert.deepEqual(activity.state.writes[1], original);
        activity.state.pending.shift().reject(new transport.TransportError(422)); await retry; await flush();
        assert.ok(find(second.root, 'data-practice-unknown'));
        assert.equal(find(second.root, 'data-practice-add'), undefined);
    } finally { if (second) second.app.unmount(); else first.app.unmount(); }
});

test('activity form conceals private values on 403 and preserves command without reporting success', async () => {
    const view = mount();
    try {
        const { promise } = await create(view); await flush(); activity.state.pending.shift().reject(new transport.TransportError(403)); await promise; await flush();
        assert.equal(find(view.root, 'data-practice-form'), undefined);
        assert.equal(find(view.root, 'data-practice-add'), undefined);
        assert.ok(find(view.root, 'data-practice-unknown'));
        assert.doesNotMatch(visibleText(view.root), /Sell equipment|saved with server confirmation/);
    } finally { view.app.unmount(); }
});

test('same Business authority refresh keeps the original unresolved command and prevents a replacement', async () => {
    const view = mount();
    try {
        const { promise } = await create(view); await flush();
        const original = structuredClone(activity.state.writes[0]);
        activity.state.pending.shift().reject(Error('Lost reply')); await promise; await flush();
        const renewed = surface();
        renewed.authority_selection.requirements[0].leaf_grant_id = 91;
        renewed.context_write_options = [{ grant_id: '92' }];
        view.selected.value = renewed; await flush();
        assert.ok(find(view.root, 'data-practice-unknown'), 'Authority refresh must not hide the original request');
        assert.equal(find(view.root, 'data-practice-add'), undefined);
        const retry = all(view.root).find(item => item.tag === 'button' && visibleText(item).includes('Check using original request')).props.onClick();
        await flush(); assert.deepEqual(activity.state.writes[1], original);
        activity.state.pending.shift().reject(new transport.TransportError(403)); await retry; await flush();
        assert.ok(find(view.root, 'data-practice-unknown'));
        assert.equal(activity.state.writes.length, 2);
    } finally { view.app.unmount(); }
});

test('unavailable recovery storage prevents dispatch rather than losing the original request on reload', async () => {
    const view = mount(), original = sessionStorage.setItem;
    try {
        sessionStorage.setItem = () => { throw Error('Storage disabled'); };
        const { promise } = await create(view); await promise; await flush();
        assert.equal(activity.state.writes.length, 0);
        assert.match(visibleText(view.root), /No new request was sent/);
    } finally { sessionStorage.setItem = original; view.app.unmount(); }
});

test('typed revision rejection preserves the draft and prevents silently applying it to a newer revision', async () => {
    const view = mount();
    try {
        const { promise } = await create(view); await flush();
        activity.state.pending.shift().reject(new transport.TransportError(409, 'practice_revision_rejected')); await promise; await flush();
        assert.ok(find(view.root, 'data-practice-conflict'));
        assert.equal(find(view.root, 'id', 'bf-practice-name').value, 'Sell equipment');
        assert.equal(find(view.root, 'data-practice-save').props.disabled, true);
        assert.equal(pending.hasPendingActivity('business-a'), false, 'Typed no-effect rejection is distinct from uncertainty');
        assert.equal(activity.state.writes.length, 1);
    } finally { view.app.unmount(); }
});

test('current declaration access does not expose private revision controls when history is unavailable', async () => {
    const view = mount(surface('business-a', false));
    try {
        await flush(); activity.state.historyAvailable = false;
        activity.state.items = [{ practice_id: 'sale', revision: 1, operation: 'create', state: 'active', recorded_at: '2026-01-01T00:00:00Z',
            value: { name: 'Sell equipment', description: '', kind: 'sale', offering: null, target_markets: { status: 'unspecified', areas: [] }, fulfillment: { status: 'unspecified', areas: [] } } }];
        view.selected.value = surface('business-a', false); await flush();
        assert.match(visibleText(view.root), /Sell equipment/);
        assert.ok(find(view.root, 'data-practice-history-unavailable'));
        assert.equal(find(view.root, 'data-practice-history'), undefined);
        assert.equal(find(view.root, 'data-practice-revisions'), undefined);
    } finally { view.app.unmount(); }
});

test('late activity list cannot cross Business switch and read-only UI exposes no writer', async () => {
    let resolve;
    activity.state.deferred = [new Promise(done => { resolve = done; })];
    const view = mount(surface('business-a', false), false);
    try {
        view.selected.value = surface('business-b', false); await flush();
        resolve({ business_public_id: 'business-a', practices: [{ practice_id: 'private', revision: 1, operation: 'create', state: 'active', value: { name: 'Private old business' } }] }); await flush();
        assert.doesNotMatch(visibleText(view.root), /Private old business/);
        assert.ok(find(view.root, 'data-practice-readonly')); assert.equal(find(view.root, 'data-practice-add'), undefined);
        assert.equal(find(view.root, 'data-practice-form'), undefined);
    } finally { view.app.unmount(); }
});

const knowledgeUrl = moduleUrl(`export const state={pages:[],reads:[],writes:[],pending:[]};export const practiceKnowledge=async(business,access,after)=>{state.reads.push({business,access,after});return state.pages.shift()??{business_public_id:business,options:[],next_cursor:null};};export const assessPractice=(business,body)=>{state.writes.push(JSON.parse(JSON.stringify({business,body})));return new Promise((resolve,reject)=>state.pending.push({resolve,reject}));};`);
const knowledgePort = await import(knowledgeUrl);
const knowledgeDescriptor = parse(await readFile('resources/js/business-foundation/BusinessPracticeKnowledge.vue', 'utf8')).descriptor;
let knowledgeCode = ts.transpileModule(compileScript(knowledgeDescriptor, { id: 'practice-knowledge-test', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
for (const [name, url] of Object.entries({ vue: vueUrl, '../spa/transport': transportUrl, '../spa/locale': localeUrl, './drafts': draftsUrl, './activityPending': pendingUrl, './activity': knowledgeUrl })) knowledgeCode = knowledgeCode.replaceAll(`from '${name}'`, `from ${JSON.stringify(url)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(url)}`);
const Knowledge = (await import(moduleUrl(knowledgeCode))).default;
const material = { namespace: 'market.knowledge', key: 'market-a', version: 'qualified-version' };
const knowledgeOption = () => ({ material_ref: material, purpose: 'market_research', authority_selection: surface().authority_selection,
    finding: { method_ref: 'qualified_interest_change:1', status: 'comparable' }, practice_basis: { schema: 'market.practice_basis:1', signals: [{ source_ref: 'source-a', subject: { key: 'new-equipment', label: 'New equipment interest', identity_state: 'source_asserted' }, geography: { ref: 'SA', level: 'country' }, period: { from: '2026-01-01', until: '2026-01-02' }, measurement: {}, coverage: 'COMPLETE' }] } });
function mountKnowledge(pages) {
    pending.clearActivityCommands(); knowledgePort.state.pages = pages; knowledgePort.state.reads = []; knowledgePort.state.writes = []; knowledgePort.state.pending = [];
    const selected = ref(surface()), practices = ref([{ practice_id: 'sale', revision: 1, state: 'active', value: { name: 'Sell equipment', offering: 'New equipment' } }, { practice_id: 'maintenance', revision: 1, state: 'active', value: { name: 'Maintain equipment', offering: null } }]), root = node('root');
    const app = renderer.createApp({ render: () => h(Knowledge, { surface: selected.value, practices: practices.value, active: true, refreshing: false }) }); app.mount(root);
    return { root, app, selected, practices, open: async () => { find(root, 'data-practice-knowledge').props.onToggle({ currentTarget: { open: true } }); await flush(); } };
}

test('market question keeps practice and material explicit and paginates empty qualified pages', async () => {
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [], next_cursor: 9 }, { business_public_id: 'business-a', options: [knowledgeOption()], next_cursor: null }]);
    try {
        await view.open(); assert.ok(find(view.root, 'data-practice-knowledge-empty'));
        assert.ok(find(view.root, 'data-practice-knowledge-more')); await find(view.root, 'data-practice-knowledge-more').props.onClick(); await flush();
        assert.equal(knowledgePort.state.reads[1].after, 9);
        assert.equal(find(view.root, 'data-practice-assess').props.disabled, true);
        find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); await nextTick();
        assert.equal(find(view.root, 'data-practice-assess').props.disabled, true, 'One material is still selected explicitly');
        find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0'); await nextTick();
        assert.equal(find(view.root, 'data-practice-assess').props.disabled, false);
        assert.equal(find(view.root, 'id', 'bf-assess-offering').value, 'New equipment');
    } finally { view.app.unmount(); }
});

test('positive response visibly retains declared relationship and cannot survive practice changes as a current result', async () => {
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [knowledgeOption()], next_cursor: null }]);
    try {
        await view.open(); find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0');
        find(view.root, 'id', 'bf-assess-relation').props['onUpdate:modelValue']('business_declared'); find(view.root, 'id', 'bf-assess-applicability').props['onUpdate:modelValue']('related'); await nextTick();
        const promise = find(view.root, 'data-practice-assessment-form').props.onSubmit({ preventDefault() {} }); await flush();
        const body = knowledgePort.state.writes[0].body;
        assert.equal(body.relation.basis, 'business_declared'); assert.equal(body.practice_id, 'sale');
        knowledgePort.state.pending.shift().resolve({ assessment_id: 'assessment-a', business_public_id: 'business-a', practice_id: 'sale', practice_revision: 1, material_ref: { version: material.version, namespace: material.namespace, key: material.key }, assessment: 'applicable', relation_basis: 'business_declared', relation_independently_verified: false, supports_investigating_reach: true, currentness: 'current', target_market: 'unknown', fulfillment: 'unknown', relation: body.relation, question: body.question });
        await promise; await flush();
        assert.ok(find(view.root, 'data-practice-assessment-result')); assert.match(visibleText(view.root), /not independently verified/); assert.match(visibleText(view.root), /buyers, profitability and actual reach are not established/);
        view.practices.value = view.practices.value.map(item => item.practice_id === 'maintenance' ? { ...item, revision: 2 } : item); await nextTick();
        assert.ok(find(view.root, 'data-practice-assessment-result'), 'An independent maintenance revision does not invalidate the sale result');
        find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('maintenance'); await nextTick();
        assert.equal(find(view.root, 'data-practice-assessment-result'), undefined); assert.equal(knowledgePort.state.writes.length, 1);
    } finally { view.app.unmount(); }
});

test('late market assessment is discarded after access refresh and preserves original unknown command', async () => {
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [knowledgeOption()], next_cursor: null }]);
    try {
        await view.open(); find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0'); await nextTick();
        const promise = find(view.root, 'data-practice-assessment-form').props.onSubmit({ preventDefault() {} }); await flush();
        view.selected.value = surface('business-b'); await flush();
        knowledgePort.state.pending.shift().resolve({ business_public_id: 'business-a', practice_id: 'sale', material_ref: material }); await promise; await flush();
        assert.equal(find(view.root, 'data-practice-assessment-result'), undefined); assert.equal(pending.hasPendingActivity('business-a'), true);
        assert.equal(find(view.root, 'data-practice-assessment-unknown'), undefined);
    } finally { view.app.unmount(); }
});

test('historical assessment is visibly marked for review even when the original outcome was positive', async () => {
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [knowledgeOption()], next_cursor: null }]);
    try {
        await view.open(); find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0'); await nextTick();
        const promise = find(view.root, 'data-practice-assessment-form').props.onSubmit({ preventDefault() {} }); await flush();
        knowledgePort.state.pending.shift().resolve({ assessment_id: 'old', business_public_id: 'business-a', practice_id: 'sale', practice_revision: 1, material_ref: material, assessment: 'applicable', relation_basis: 'business_declared', relation_independently_verified: false, supports_investigating_reach: true, historical_receipt: true, currentness: 'review_required', target_market: 'unknown', fulfillment: 'unknown' });
        await promise; await flush();
        assert.match(visibleText(view.root), /historical result/); assert.match(visibleText(view.root), /do not use it as a current assessment/);
        assert.doesNotMatch(visibleText(view.root), /Investigating reach is warranted/);
    } finally { view.app.unmount(); }
});

test('assessment authority refresh retains exact uncertain payload and a denied retry never permits a replacement', async () => {
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [knowledgeOption()], next_cursor: null }]);
    try {
        await view.open(); find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale');
        find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0'); await nextTick();
        const first = find(view.root, 'data-practice-assessment-form').props.onSubmit({ preventDefault() {} }); await flush();
        const original = structuredClone(knowledgePort.state.writes[0]);
        knowledgePort.state.pending.shift().reject(Error('Lost acknowledgement')); await first; await flush();
        const renewed = surface(); renewed.authority_selection.requirements[0].leaf_grant_id = 91;
        view.selected.value = renewed; await flush();
        assert.ok(find(view.root, 'data-practice-assessment-unknown'));
        const button = all(view.root).find(item => item.tag === 'button' && visibleText(item).includes('Check using original request'));
        const retry = button.props.onClick(); await flush();
        assert.deepEqual(knowledgePort.state.writes[1], original);
        knowledgePort.state.pending.shift().reject(new transport.TransportError(403)); await retry; await flush();
        assert.ok(find(view.root, 'data-practice-assessment-unknown'));
        assert.equal(find(view.root, 'data-practice-assessment-result'), undefined);
        assert.equal(find(view.root, 'data-practice-assessment-form'), undefined);
    } finally { view.app.unmount(); }
});

test('saved relation and exact question are reused only after explicit matching selections without assessing automatically', async () => {
    const savedQuestion = { geography: { authority: 'source-city', level: 'city', ref: 'city-17' }, offering: 'Previously specified equipment',
        period: { from: '2026-01-01T00:00:00.123Z', until: '2026-01-02T00:00:00.456Z' } };
    const option = knowledgeOption(); option.saved_relations = [{ practice_id: 'sale', practice_revision: 1, assessment_id: 'earlier',
        relation: { basis: 'business_declared', applicability: 'related', rationale: 'My declared equipment relationship' }, question: savedQuestion }];
    const view = mountKnowledge([{ business_public_id: 'business-a', options: [option], next_cursor: null }]);
    try {
        await view.open(); find(view.root, 'id', 'bf-assess-knowledge').props['onUpdate:modelValue']('0'); await nextTick();
        assert.equal(find(view.root, 'data-practice-reused-relation'), undefined);
        find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); await nextTick();
        assert.ok(find(view.root, 'data-practice-reused-relation'));
        assert.equal(find(view.root, 'id', 'bf-assess-rationale').value, 'My declared equipment relationship');
        const countrySelect = find(view.root, 'id', 'bf-assess-country');
        assert.equal(countrySelect.options[countrySelect.selectedIndex].value, '__saved__');
        assert.equal(knowledgePort.state.writes.length, 0);
        find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('maintenance'); await nextTick();
        assert.equal(find(view.root, 'data-practice-reused-relation'), undefined);
        assert.equal(find(view.root, 'id', 'bf-assess-rationale').value, '');
        find(view.root, 'id', 'bf-assess-practice').props['onUpdate:modelValue']('sale'); await nextTick();
        const promise = find(view.root, 'data-practice-assessment-form').props.onSubmit({ preventDefault() {} }); await flush();
        assert.deepEqual(knowledgePort.state.writes[0].body.question, savedQuestion);
        assert.equal(knowledgePort.state.writes[0].body.relation.basis, 'business_declared');
        knowledgePort.state.pending.shift().reject(new transport.TransportError(422)); await promise; await flush();
    } finally { view.app.unmount(); }
});
