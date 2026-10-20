import { withPresentationPorts } from './Support/presentation-ports.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, shallowReactive, shallowRef, ref, h } from 'vue';

const state = globalThis.__spaGoalsTest = { current: shallowRef(), locale: ref('ar'), posts: [], visits: [], guards: [], updateGuards: [], closed: false, revision: 0, confirmations: false, closures: [], reviews: [] };
globalThis.window = { listeners: {}, addEventListener(name, handler) { this.listeners[name] = handler; }, removeEventListener(name) { delete this.listeners[name]; } };
globalThis.confirm = () => state.confirmations;
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const transpile = code => ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
function rewrite(code, replacements) { for (const [from, to] of Object.entries(withPresentationPorts(replacements))) code = code.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`); return code; }
const transport = moduleUrl(`export const bootstrap={application:true};export const stamp=()=>globalThis.__spaGoalsTest.revision,owned=value=>!globalThis.__spaGoalsTest.closed&&value===globalThis.__spaGoalsTest.revision,onClose=handler=>globalThis.__spaGoalsTest.closures.push(handler);export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body;}}`);
const { TransportError } = await import(transport);
const drafts = moduleUrl(rewrite(transpile(await readFile(new URL('../resources/js/goals-spa/drafts.ts', import.meta.url), 'utf8')), { '../spa/transport': transport }));
const presentation = moduleUrl(`import {ref} from ${JSON.stringify(import.meta.resolve('vue'))};export const confirmDeparture=ref(()=>true);`);
const { confirmDeparture } = await import(presentation);
const reader = moduleUrl(`export {confirmDeparture} from ${JSON.stringify(presentation)};export const current=globalThis.__spaGoalsTest.current;export async function read(){const next=globalThis.__spaGoalsTest.reviews.shift();if(next)current.value=next;return next;}`);
const router = moduleUrl(`import {h} from ${JSON.stringify(import.meta.resolve('vue'))};export const RouterLink={props:['to'],setup:(props,{slots})=>()=>h('a',{href:props.to},slots.default?.())};export const useRouter=()=>({push:url=>{globalThis.__spaGoalsTest.visits.push(url);return Promise.resolve();}});export const onBeforeRouteUpdate=guard=>globalThis.__spaGoalsTest.updateGuards.push(guard); export const onBeforeRouteLeave=guard=>globalThis.__spaGoalsTest.guards.push(guard);`);
const api = moduleUrl(`export const saveGoals=(workspace,payload)=>new Promise((resolve,reject)=>globalThis.__spaGoalsTest.posts.push({workspace,payload,resolve,reject}));`);
const icons = moduleUrl(`export const ChartNoAxesColumnIncreasing={render:()=>null},Users=ChartNoAxesColumnIncreasing,Lightbulb=Users,Settings=Users,Store=Users,ArrowLeft=Users;`);
const messages = moduleUrl(transpile(await readFile(new URL('../resources/js/localization/messages.ts', import.meta.url), 'utf8')));
const copy = moduleUrl(rewrite(transpile(await readFile(new URL('../resources/js/localization/goalsCopy.ts', import.meta.url), 'utf8')), { vue: import.meta.resolve('vue'), './messages': messages }));
const logic = moduleUrl(transpile(await readFile(new URL('../resources/js/Components/Business/goalSelection.ts', import.meta.url), 'utf8')));
const paths = moduleUrl(rewrite(transpile(await readFile(new URL('../resources/js/goals-spa/paths.ts', import.meta.url), 'utf8')), { '../spa/transport': transport }));
const { descriptor } = parse(await readFile(new URL('../resources/js/goals-spa/GoalsEditor.vue', import.meta.url), 'utf8'));
const component = (await import(moduleUrl(rewrite(transpile(compileScript(descriptor, { id: 'spa-goals-test', inlineTemplate: true }).content), {
    vue: import.meta.resolve('vue'), 'vue-router': router, '@lucide/vue': icons, './state': reader, './drafts': drafts, '../spa/transport': transport,
    './api': api, './paths': paths, '../localization/goalsCopy': copy, '../spa/locale': moduleUrl('export const locale=globalThis.__spaGoalsTest.locale;'), '../Components/Business/goalSelection': logic,
})))).default;
function element(tag, text = '') { return { tag, text, children: [], props: {}, parent: null }; }
const renderer = createRenderer({ createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text), setText: (node, text) => node.text = text, setElementText: (node, text) => { node.text = text; node.children = []; }, parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null, insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; }, remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; }, patchProp(node, key, old, value) { node.props[key] = value; } });
const catalog = ['sales', 'customers', 'growth', 'efficiency'].map(id => ({ id, name_ar: 'عربي ' + id, name_en: 'English ' + id, description_ar: 'وصف ' + id, description_en: 'Description ' + id, results: [{ id: id + '_one', name_ar: 'نتيجة ' + id, name_en: 'Outcome ' + id }, { id: id + '_two', name_ar: 'ثانية ' + id, name_en: 'Second ' + id }] }));
const empty = () => ({ value: { goals: [], priority: null, results: {} }, version: 0 });
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); await nextTick(); };
async function mount(overrides = {}, retain = false) {
    if (!retain) state.closures.forEach(handler => handler());
    Object.assign(state, { posts: [], visits: [], guards: [], updateGuards: [], closed: false, confirmations: false, reviews: [] }); state.locale.value = 'ar';
    const props = shallowReactive({ workspace: { public_id: 'business', name: 'Real business' }, business: { type: null, canManage: true }, preferences: empty(), catalog, ...overrides });
    state.current.value = props;
    const root = element('root'), app = renderer.createApp({ render: () => h(component) }); app.mount(root); await flush();
    return { root, props, stop: () => app.unmount() };
}
const nodes = (node, predicate) => [node, ...node.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = node => node.text + node.children.map(text).join('');
const find = (view, key) => { const node = nodes(view.root, node => node.props['data-goals'] === key)[0]; assert.ok(node, 'Expected ' + key); return node; };
async function change(view, key) { find(view, key).props.onChange(); await nextTick(); }
async function click(view, key) { find(view, key).props.onClick(); await flush(); }
async function submit(view) { find(view, 'form').props.onSubmit({ preventDefault() {} }); await flush(); }

test('actual SPA editor blocks empty writes and continues through the canonical router after one acknowledgement', async () => {
    const view = await mount(); assert.equal(find(view, 'continue').props.disabled, true); await submit(view); assert.equal(state.posts.length, 0);
    await change(view, 'select-sales'); await submit(view); assert.deepEqual(state.posts[0].payload, { value: { goals: ['sales'], priority: 'sales', results: {} }, version: 0 });
    state.posts[0].resolve({ value: state.posts[0].payload.value, version: 1 }); await flush(); assert.deepEqual(state.visits, ['/workspaces/business/goals/review']);
    let warned = false; window.listeners.beforeunload({ preventDefault() { warned = true; } }); assert.equal(warned, false); view.stop();
});
test('actual SPA editor retains per-goal outcomes, priority and semantic IDs through locale changes', async () => {
    const view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); await change(view, 'result-sales_two'); await change(view, 'select-customers'); await click(view, 'priority-customers'); await change(view, 'result-customers_one'); await click(view, 'priority-sales');
    state.locale.value = 'en'; await nextTick(); assert.match(text(view.root), /English sales/); assert.equal(find(view, 'result-sales_two').props.checked, true);
    await submit(view); assert.deepEqual(state.posts[0].payload.value, { goals: ['sales', 'customers'], priority: 'sales', results: { sales: ['sales_one', 'sales_two'], customers: ['customers_one'] } }); view.stop();
});
test('actual SPA editor clears removed outcomes and requires priority selection only when ambiguous', async () => {
    const view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); await change(view, 'select-customers'); await change(view, 'select-growth'); await change(view, 'select-sales');
    assert.equal(find(view, 'continue').props.disabled, true); await change(view, 'select-customers'); assert.equal(find(view, 'continue').props.disabled, false);
    await change(view, 'select-sales'); await click(view, 'priority-sales'); assert.equal(find(view, 'result-sales_one').props.checked, false); view.stop();
});
test('actual SPA editor treats equivalent goal/outcome sets and JSON object ordering as clean', async () => {
    const view = await mount({ preferences: { version: 3, value: { goals: ['sales', 'customers'], priority: 'sales', results: { customers: [], sales: ['sales_one', 'sales_two'] } } } });
    await change(view, 'select-customers'); await change(view, 'select-customers'); await change(view, 'result-sales_one'); await change(view, 'result-sales_one'); assert.equal(confirmDeparture.value(), true);
    await submit(view); const value = state.posts[0].payload.value; state.posts[0].resolve({ version: 4, value: { results: { sales: value.results.sales, customers: [] }, priority: value.priority, goals: value.goals } }); await flush(); assert.equal(confirmDeparture.value(), true); view.stop();
});
test('actual SPA editor locks busy controls and retains the exact draft after 403, 422 or unknown delivery without replay', async () => {
    for (const failure of [new TransportError(403), new TransportError(422, { errors: { value: ['Rejected by server'] } }), new Error('network')]) {
        const view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); await submit(view); await change(view, 'select-growth'); assert.equal(find(view, 'select-growth').props.checked, false);
        state.posts[0].reject(failure); await flush(); assert.equal(state.posts.length, 1); assert.equal(find(view, 'result-sales_one').props.checked, true);
        assert.match(text(view.root), failure.status === 422 ? /Rejected by server/ : failure.status === 403 ? /إذن/ : /تأكيد الحفظ/);
        await submit(view); assert.deepEqual(state.posts[1].payload, state.posts[0].payload); view.stop();
    }
});
test('actual SPA editor reviews a CAS conflict without rebasing the dirty draft until explicit adoption', async () => {
    const view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); await submit(view); state.posts[0].reject(new TransportError(409)); await flush(); await submit(view); assert.equal(state.posts.length, 1);
    state.reviews.push({ ...view.props, preferences: { version: 4, value: { goals: ['growth'], priority: 'growth', results: { growth: ['growth_one'] } } } });
    await click(view, 'review'); assert.equal(find(view, 'select-sales').props.checked, true); assert.match(text(view.root), /نتيجة growth/); await click(view, 'adopt'); await submit(view);
    assert.equal(state.posts[1].payload.version, 4); assert.deepEqual(state.posts[1].payload.value, state.posts[0].payload.value); view.stop();
});
test('actual SPA editor refreshes clean data but does not silently rebase a dirty revision', async () => {
    const view = await mount(); view.props.preferences = { version: 2, value: { goals: ['growth'], priority: 'growth', results: {} } }; await nextTick(); assert.equal(find(view, 'select-growth').props.checked, true);
    await change(view, 'select-sales'); view.props.preferences = { version: 3, value: { goals: ['efficiency'], priority: 'efficiency', results: {} } }; await nextTick(); await submit(view); assert.equal(state.posts[0].payload.version, 2); assert.equal(find(view, 'select-sales').props.checked, true); view.stop();
});
test('actual SPA editor read-only outcome inspection never changes saved priority or exposes a writer', async () => {
    const preferences = { version: 2, value: { goals: ['sales', 'customers'], priority: 'sales', results: { sales: ['sales_one'], customers: ['customers_one'] } } };
    const view = await mount({ business: { type: null, canManage: false }, preferences }); await click(view, 'view-customers'); assert.equal(find(view, 'view-customers').props['aria-pressed'], true); assert.match(text(view.root), /نتيجة customers/);
    await submit(view); assert.equal(state.posts.length, 0); assert.equal(nodes(view.root, node => node.tag === 'input').length, 0); assert.equal(nodes(view.root, node => node.props['data-goals'] === 'continue').length, 0); assert.deepEqual(view.props.preferences, preferences); view.stop();
});
test('actual SPA editor protects dirty departures, waits for draft retention and cleans unload listeners', async () => {
    const view = await mount(); await change(view, 'select-sales'); assert.equal(await state.guards[0](), false); assert.equal(confirmDeparture.value(), false);
    state.confirmations = true; assert.notEqual(await state.guards[0](), false); let warned = false; window.listeners.beforeunload({ preventDefault() { warned = true; } }); assert.equal(warned, true);
    view.stop(); assert.equal(window.listeners.beforeunload, undefined); assert.equal(confirmDeparture.value(), true);
});
test('actual SPA editor restores same-document drafts with original revision and clears them on document closure', async () => {
    let view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); await change(view, 'select-customers'); view.stop();
    view = await mount({ preferences: { version: 7, value: { goals: ['growth'], priority: 'growth', results: {} } } }, true); assert.equal(find(view, 'select-customers').props.checked, true); assert.equal(find(view, 'result-sales_one').props.checked, true); await submit(view); assert.equal(state.posts[0].payload.version, 0); view.stop();
    view = await mount(); assert.equal(find(view, 'select-sales').props.checked, false); view.stop();
});
test('actual SPA editor cannot accept a late save after suspension or document closure', async () => {
    for (const terminal of [false, true]) {
        const view = await mount(); await change(view, 'select-sales'); await submit(view); state.revision++; state.closed = terminal;
        state.posts[0].resolve({ version: 9, value: { goals: ['customers'], priority: 'customers', results: {} } }); await flush(); assert.equal(find(view, 'select-sales').props.checked, true); assert.equal(find(view, 'select-customers').props.checked, false); assert.equal(state.visits.length, 0); view.stop();
    }
});
test('actual SPA editor keeps secondary removal stable and resets priority at zero selections', async () => {
    const view = await mount(); await change(view, 'select-sales'); await change(view, 'select-customers'); await change(view, 'select-growth'); await change(view, 'select-customers'); assert.match(text(view.root), /أولوية البداية/);
    await change(view, 'select-sales'); assert.equal(find(view, 'continue').props.disabled, false); await change(view, 'select-growth'); assert.equal(find(view, 'continue').props.disabled, true); await change(view, 'select-efficiency'); await submit(view);
    assert.deepEqual(state.posts[0].payload.value, { goals: ['efficiency'], priority: 'efficiency', results: {} }); view.stop();
});
test('actual SPA editor isolates remembered drafts by Workspace while preserving a returning original draft', async () => {
    let view = await mount(); await change(view, 'select-sales'); await change(view, 'result-sales_one'); view.stop();
    view = await mount({ workspace: { public_id: 'other', name: 'Other business' } }, true); assert.equal(find(view, 'select-sales').props.checked, false); await change(view, 'select-growth'); view.stop();
    view = await mount({}, true); assert.equal(find(view, 'select-sales').props.checked, true); assert.equal(find(view, 'result-sales_one').props.checked, true); assert.equal(find(view, 'select-growth').props.checked, false); view.stop();
});
test('actual SPA editor cannot navigate after its component was unmounted during a pending save', async () => {
    const view = await mount(); await change(view, 'select-sales'); await submit(view); view.stop(); state.posts[0].resolve({ value: state.posts[0].payload.value, version: 1 }); await flush(); assert.deepEqual(state.visits, []);
});
test('actual SPA editor keeps goal cards as the only selection surface', async () => {
    const view = await mount(); await change(view, 'select-sales'); await change(view, 'select-customers'); await change(view, 'select-growth'); assert.equal(nodes(view.root, node => node.props.class === 'goals-summary').length, 0);
    assert.equal(nodes(view.root, node => String(node.props['data-goals'] ?? '').startsWith('remove-')).length, 0); assert.doesNotMatch(text(view.root), /الأهداف المحددة|اختياري؛ يمكنك اختيار عدة نتائج/); assert.match(text(view.root), /النتيجة المطلوبة/); view.stop();
});

test('actual Goals component uses the same pending/dirty decision and persistence for same-record updates', async () => {
    const view=await mount();
    try {
        assert.equal(state.updateGuards.length,1);assert.equal(state.updateGuards[0],state.guards[0]);
        await change(view,'select-sales');assert.equal(await state.updateGuards[0](),false);
        state.confirmations=true;assert.notEqual(await state.updateGuards[0](),false);
        await change(view,'result-sales_one');await submit(view);
        assert.equal(await state.updateGuards[0](),false,'pending save blocks parameter/query replacement');
        assert.equal(state.posts.length,1);
        state.posts[0].reject(Error('unknown delivery'));await flush();
    }finally{view.stop()}
});
