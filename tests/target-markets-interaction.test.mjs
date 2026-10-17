import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive, h } from 'vue';

const state = globalThis.__dtmTest = { page: reactive({ locale: 'ar' }), posts: [], forms: [], visits: [] };
globalThis.route = (name, id) => name + '/' + (id ?? '');
globalThis.Document = class {};
globalThis.ShadowRoot = class {};
const moduleUrl = code => 'data:text/javascript;base64,' + Buffer.from(code).toString('base64');
const inertia = moduleUrl(`import { reactive } from ${JSON.stringify(import.meta.resolve('vue'))};
export const usePage = () => ({ props: globalThis.__dtmTest.page });
export const Head = { render: () => null };
export const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
export const router = { visit: url => globalThis.__dtmTest.visits.push(url), reload: options => globalThis.__dtmTest.visits.push(options),
 post: (url, payload, options) => globalThis.__dtmTest.posts.push({ url, payload, options }) };
export const useForm = input => {
 const keys = Object.keys(input); const form = reactive({ ...input, errors: {}, processing: false, recentlySuccessful: false,
 post(url, options) { globalThis.__dtmTest.posts.push({ url, payload: JSON.parse(JSON.stringify(Object.fromEntries(keys.map(k => [k, form[k]])))), options }); }
 }); globalThis.__dtmTest.forms.push(form); return form;
};
export const useBusinessForm=(input,kind)=>{const form=useForm(input);form.submit=(workspace,options)=>form.post('workspaces.business.'+kind+'/'+workspace,options);return form;};
export const restore=()=>undefined,remember=()=>{},stamp=()=>0,owned=()=>true,RouterLink=Link;
export const refresh=(workspace,options)=>router.reload(options),saveIntent=(workspace,payload,options)=>router.post('workspaces.business.current-intent.update/'+workspace,payload,options);`);
const replacements = { vue: import.meta.resolve('vue'), '@inertiajs/vue3': inertia };
function rewrite(code, extra = {}) {
    for (const [from, to] of Object.entries({ ...replacements, ...extra })) {
        code = code.replaceAll(`from '${from}'`, `from ${JSON.stringify(to)}`).replaceAll(`from "${from}"`, `from ${JSON.stringify(to)}`);
    }
    return code;
}
const messages = moduleUrl(ts.transpileModule(await readFile(new URL('../resources/js/localization/messages.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText);
const neutralCopy = moduleUrl(rewrite(ts.transpileModule(await readFile(new URL('../resources/js/localization/businessCopy.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText, { './messages': messages }));
const copy = moduleUrl(rewrite(ts.transpileModule(await readFile(new URL('../resources/js/localization/business.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText, { './businessCopy': neutralCopy }));
async function compile(path, extra = {}) {
    const { descriptor } = parse(await readFile(new URL('../resources/js/' + path, import.meta.url), 'utf8'));
    const source = compileScript(descriptor, { id: 'dtm-test', inlineTemplate: true }).content;
    const url = moduleUrl(rewrite(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText,
        { '@/localization/business': copy, '@/localization/businessCopy': neutralCopy, '@/spa/locale': moduleUrl(`import {computed} from ${JSON.stringify(import.meta.resolve('vue'))};export const locale=computed(()=>globalThis.__dtmTest.page.locale);`), '@/business-context/commands': inertia, './commands': inertia, '@/business-context/drafts': inertia, './drafts': inertia, '@/spa/transport': inertia, 'vue-router': inertia, ...extra }));
    return { url, component: (await import(url)).default };
}
const editor = await compile('Components/Business/TargetMarketsEditor.vue');
const intent = await compile('Components/Business/CurrentIntentEditor.vue');
const layout = moduleUrl(`export default { template: '<main><slot /></main>' };`);
const context = await compile('business-context/ContextForm.vue', { '@/Layouts/AdvertiserLayout.vue': layout,
    '@/Components/Business/TargetMarketsEditor.vue': editor.url, '@/Components/Business/CurrentIntentEditor.vue': intent.url });
const languageSwitcher = await compile('Components/LanguageSwitcher.vue', {
    '@/localization/messages': messages, 'vue-i18n': moduleUrl('export const useI18n = () => ({ t: key => key });'),
});
function element(tag, text = '') {
    return { tag, text, children: [], props: {}, listeners: {}, parent: null, value: '', checked: false, multiple: false,
        get options() { return this.children.filter(child => child.tag === 'option'); },
        get selectedIndex() { return this.options.findIndex(option => option.selected); },
        set selectedIndex(index) { this.options.forEach((option, position) => { option.selected = index === position; }); },
        getRootNode() { return {}; }, addEventListener(type, fn) { this.listeners[type] = fn; } };
}
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, old, value) { node.props[key] = value; if (key === 'value') node.value = node._value = value; if (key === 'checked') node.checked = value; },
});
const countries = [{ code: 'AE', name_ar: 'الإمارات العربية المتحدة', name_en: 'United Arab Emirates' },
    { code: 'KW', name_ar: 'الكويت', name_en: 'Kuwait' }, { code: 'SA', name_ar: 'المملكة العربية السعودية', name_en: 'Saudi Arabia' }];
function mount(overrides = {}, component = editor.component) {
    state.posts = []; state.forms = []; state.visits = []; state.page.locale = 'ar';
    const props = reactive({ workspaceId: 'business', business: { targetMarketsDeclared: true, targetMarkets: [countries[2]], version: 7, canManage: true },
        countryOptions: countries, ...overrides });
    const root = element('root'); const app = renderer.createApp({ render: () => h(component, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(root);
    return { root, props, stop: () => app.unmount() };
}
const nodes = (root, predicate) => [root, ...root.children.flatMap(child => nodes(child, () => true))].filter(predicate);
const text = root => root.text + root.children.map(text).join('');
const find = (view, predicate) => { const node = nodes(view.root, predicate)[0]; assert.ok(node, 'Expected rendered control'); return node; };
async function search(view, value) { const input = find(view, n => n.tag === 'input' && n.props.type === 'search'); input.value = value; input.listeners.input({ target: input }); await nextTick(); }
async function toggle(view, code) { const input = find(view, n => n.tag === 'input' && n.props.type === 'checkbox' && n.value === code); input.checked = !input.checked; input.listeners.change({ target: input }); await nextTick(); }
const submit = view => find(view, n => n.tag === 'form' && n.props['data-target-markets'] !== undefined).props.onSubmit({ preventDefault() {} });

test('actual_locale_action_explicitly_preserves_context_state_without_posting_drafts', () => {
    const view = mount({ routeName: 'locale.update', preserveState: true }, languageSwitcher.component);
    find(view, n => n.tag === 'button').props.onClick();
    assert.equal(state.posts.length, 1);
    assert.deepEqual(state.posts[0].payload, { locale: 'en' });
    assert.equal(state.posts[0].options.preserveState, true);
    assert.equal(state.posts[0].options.preserveScroll, true);
    view.stop();
});
test('actual_locale_action_keeps_existing_default_remount_policy_for_other_pages', () => {
    const view = mount({ routeName: 'locale.update' }, languageSwitcher.component);
    find(view, n => n.tag === 'button').props.onClick();
    assert.equal(state.posts[0].options.preserveState, false);
    view.stop();
});

test('selected_values_survive_search_filtering', async () => {
    const view = mount(); await search(view, 'Kuwait');
    assert.equal(nodes(view.root, n => n.tag === 'input' && n.props.type === 'checkbox').length, 1);
    assert.ok(text(view.root).includes('المملكة العربية السعودية'));
    await toggle(view, 'KW'); await search(view, 'AE');
    const remove = find(view, n => n.tag === 'button' && n.props['aria-label']?.includes('المملكة العربية السعودية'));
    remove.props.onClick(); await nextTick(); submit(view);
    assert.deepEqual(state.posts[0].payload, { country_codes: ['KW'], version: 7 }); view.stop();
});
test('locale_switch_preserves_draft_codes', async () => {
    const view = mount(); await toggle(view, 'AE'); state.page.locale = 'en'; await nextTick();
    assert.ok(text(view.root).includes('Saudi Arabia')); await search(view, 'الكويت'); await toggle(view, 'KW'); submit(view);
    assert.deepEqual([...state.posts[0].payload.country_codes].sort(), ['AE', 'KW', 'SA']); view.stop();
});
test('skip_never_posts_or_clears_saved_set', async () => {
    const view = mount(); await toggle(view, 'AE');
    const skip = find(view, n => n.tag === 'a' && text(n).includes('ليس الآن'));
    assert.equal(skip.props.href, '/workspaces/business');
    assert.equal(state.posts.length, 0); assert.deepEqual(view.props.business.targetMarkets, [countries[2]]); view.stop();
});
test('readonly_members_cannot_submit', () => {
    const view = mount({ business: { targetMarketsDeclared: true, targetMarkets: [countries[2]], version: 7, canManage: false } });
    assert.equal(nodes(view.root, n => n.tag === 'form' || n.tag === 'input').length, 0);
    assert.ok(text(view.root).includes('المملكة العربية السعودية')); assert.equal(state.posts.length, 0); view.stop();
});
test('unknown_state_uses_server_semantics_without_default', () => {
    const view = mount({ business: { targetMarketsDeclared: false, targetMarkets: [], version: 0, canManage: true } });
    assert.ok(text(view.root).includes('لم تُحدّد الأسواق بعد')); submit(view);
    assert.deepEqual(state.posts[0].payload, { country_codes: [], version: 0 }); view.stop();
});
test('server_error_preserves_draft_and_requires_explicit_review', async () => {
    const view = mount(); await toggle(view, 'AE'); submit(view);
    state.forms[0].errors = { version: 'Reload and review' }; await nextTick();
    assert.ok(nodes(view.root, n => n.props.role === 'alert' && text(n).includes('Reload')).length);
    assert.equal(state.posts.length, 1); assert.ok(text(view.root).includes('الإمارات العربية المتحدة'));
    view.props.business = { ...view.props.business, version: 8, targetMarkets: [countries[1]] };
    state.forms[0].errors = {}; await nextTick();
    assert.ok(nodes(view.root, n => n.props.role === 'alert' && text(n).includes('الكويت')).length,
        'Explicit reload retains the conflict notice and displays current saved markets for review');
    assert.equal(state.posts.length, 1);
    find(view, n => n.tag === 'button' && text(n).includes('إعادة التحميل والمراجعة')).props.onClick();
    state.visits.at(-1).onSuccess();
    submit(view);
    assert.deepEqual(state.posts[1].payload, { country_codes: ['SA', 'AE'], version: 8 }); view.stop();
});
test('independent_forms_preserve_unrelated_drafts', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    const nameForm = state.forms.find(form => 'name' in form);
    nameForm.name = 'Unsaved name'; await toggle(view, 'AE'); submit(view);
    assert.deepEqual(state.posts[0].payload, { country_codes: ['SA', 'AE'], version: 7 });
    view.props.version = 8; view.props.business = { ...view.props.business, version: 8, targetMarkets: [countries[0], countries[2]] }; await nextTick();
    state.posts[0].options.onSuccess(); await nextTick();
    assert.equal(nameForm.name, 'Unsaved name');
    const nameElement = find(view, n => n.tag === 'form' && n.props['data-target-markets'] === undefined);
    nameElement.props.onSubmit({ preventDefault() {} });
    assert.equal(state.posts[1].payload.name, 'Unsaved name'); assert.equal(state.posts[1].payload.version, 8);
    assert.equal('country_codes' in state.posts[1].payload, false); view.stop();
});

test('locale_and_unrelated_refresh_do_not_rebase_dirty_independent_forms', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    const nameForm = state.forms.find(form => 'name' in form);
    nameForm.name = 'Unsaved name'; await toggle(view, 'AE');
    for (const [version, locale] of [[8, 'en'], [9, 'ar'], [10, 'ar']]) {
        view.props.version = version;
        view.props.workspace = { ...view.props.workspace, name: 'Competing saved name', business_context_version: version };
        view.props.business = { ...view.props.business, version, targetMarkets: [countries[1], countries[2]] };
        state.page.locale = locale; await nextTick();
        assert.equal(nameForm.version, 7);
        assert.equal(state.forms.find(form => 'country_codes' in form).version, 7);
    }
    submit(view);
    find(view, n => n.tag === 'form' && n.props['data-target-markets'] === undefined).props.onSubmit({ preventDefault() {} });
    assert.deepEqual(state.posts[0].payload, { country_codes: ['SA', 'AE'], version: 7 });
    assert.equal(state.posts[1].payload.name, 'Unsaved name'); assert.equal(state.posts[1].payload.version, 7);
    assert.equal('country_codes' in state.posts[1].payload, false); view.stop();
});

test('clean_refresh_updates_canonical_fields_and_revision_together', async () => {
    const view = mount();
    view.props.business = { ...view.props.business, version: 8, targetMarkets: [countries[1]] };
    state.page.locale = 'en'; await nextTick(); submit(view);
    assert.deepEqual(state.posts[0].payload, { country_codes: ['KW'], version: 8 }); view.stop();
});

test('successful_name_save_advances_independent_market_draft_without_posting_it', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    const nameForm = state.forms.find(form => 'name' in form);
    nameForm.name = 'New saved name'; await toggle(view, 'AE');
    find(view, n => n.tag === 'form' && n.props['data-target-markets'] === undefined).props.onSubmit({ preventDefault() {} });
    view.props.workspace = { ...view.props.workspace, name: 'New saved name', business_context_version: 8 };
    view.props.version = 8; view.props.business = { ...view.props.business, version: 8 }; await nextTick();
    assert.equal(state.forms.find(form => 'country_codes' in form).version, 7);
    state.posts[0].options.onSuccess(); await nextTick(); submit(view);
    assert.equal('country_codes' in state.posts[0].payload, false);
    assert.deepEqual(state.posts[1].payload, { country_codes: ['SA', 'AE'], version: 8 }); view.stop();
});

test('clean_name_type_refresh_updates_fields_with_shared_revision', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    view.props.workspace = { ...view.props.workspace, name: 'Current saved name', business_context_version: 8 };
    view.props.version = 8; state.page.locale = 'en'; await nextTick();
    const nameForm = state.forms.find(form => 'name' in form);
    assert.equal(nameForm.name, 'Current saved name'); assert.equal(nameForm.version, 8); view.stop();
});

test('reviewing_and_saving_markets_does_not_rebase_a_competing_name_change', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    const nameForm = state.forms.find(form => 'name' in form);
    nameForm.name = 'Older name draft'; await toggle(view, 'AE'); submit(view);
    const marketPost = state.posts[0];
    state.forms.find(form => 'country_codes' in form).errors = { version: 'Reload and review' };
    view.props.version = 8; view.props.workspace = { ...view.props.workspace, name: 'Competing name', business_context_version: 8 };
    view.props.business = { ...view.props.business, version: 8 }; await nextTick();
    find(view, n => n.tag === 'button' && text(n).includes('إعادة التحميل والمراجعة')).props.onClick();
    state.visits.at(-1).onSuccess(); submit(view);
    view.props.version = 9; view.props.business = { ...view.props.business, version: 9, targetMarkets: [countries[0], countries[2]] }; await nextTick();
    state.posts.at(-1).options.onSuccess(); await nextTick();
    assert.equal(marketPost.payload.version, 7); assert.equal(nameForm.version, 7);
    assert.equal(nameForm.name, 'Older name draft'); view.stop();
});

test('successful_name_save_does_not_rebase_an_older_competing_market_draft', async () => {
    const view = mount({ workspace: { public_id: 'business', name: 'Saved name', business_type_id: 1, business_context_version: 7 },
        types: [{ id: 1, key: 'example', name_ar: 'مثال', name_en: 'Example', active: true }], canManage: true, creationKey: null, version: 7 }, context.component);
    await toggle(view, 'AE');
    view.props.version = 8; view.props.business = { ...view.props.business, version: 8, targetMarkets: [countries[1], countries[2]] }; await nextTick();
    state.forms.find(form => 'name' in form).name = 'New saved name';
    find(view, n => n.tag === 'form' && n.props['data-target-markets'] === undefined).props.onSubmit({ preventDefault() {} });
    view.props.version = 9; view.props.workspace = { ...view.props.workspace, name: 'New saved name', business_context_version: 9 };
    view.props.business = { ...view.props.business, version: 9 }; await nextTick();
    state.posts[0].options.onSuccess(); await nextTick(); submit(view);
    assert.deepEqual(state.posts[1].payload, { country_codes: ['SA', 'AE'], version: 7 }); view.stop();
});

test('success_after_a_newer_competing_commit_updates_market_values_and_revision_together', async () => {
    const view = mount(); await toggle(view, 'AE'); submit(view);
    view.props.business = { ...view.props.business, version: 9, targetMarkets: [countries[1]] }; await nextTick();
    state.posts[0].options.onSuccess(); await nextTick(); submit(view);
    assert.deepEqual(state.posts[1].payload, { country_codes: ['KW'], version: 9 }); view.stop();
});

test('market_edits_made_while_saving_keep_their_original_revision', async () => {
    const view = mount(); await toggle(view, 'AE'); submit(view);
    await toggle(view, 'KW');
    view.props.business = { ...view.props.business, version: 9, targetMarkets: [countries[1]] }; await nextTick();
    state.posts[0].options.onSuccess(); await nextTick(); submit(view);
    assert.deepEqual(state.posts[1].payload, { country_codes: ['SA', 'AE', 'KW'], version: 7 }); view.stop();
});
