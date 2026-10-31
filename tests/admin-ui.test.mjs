import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createSSRApp, createRenderer, h, nextTick, ref, markRaw, onMounted, onBeforeUnmount } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createMemoryHistory, createRouter } from 'vue-router';

const url = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const compileTs = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const vue = import.meta.resolve('vue');
const language = url(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const locale = (await import(language)).locale;
const icons = url(`export const Menu={render:()=>null},X=Menu,PanelRight=Menu,Users=Menu,ContactRound=Menu,ListTree=Menu,KeyRound=Menu,BriefcaseBusiness=Menu,Globe2=Menu,Palette=Menu,ShieldCheck=Menu;`);
const inventory = url(compileTs(await readFile('resources/js/internal-operations/administrationNavigation.ts', 'utf8')));
async function component(file, ports = {}) {
    const { descriptor } = parse(await readFile('resources/js/internal-operations/' + file, 'utf8'));
    let source = compileTs(compileScript(descriptor, { id: file, inlineTemplate: true }).content).replace("import './administration.css';", '');
    for (const [name, target] of Object.entries({ vue, 'vue-router': import.meta.resolve('vue-router'), '@lucide/vue': icons, '../spa/locale': language, './administrationNavigation': inventory, ...ports })) {
        source = source.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target)).replaceAll(`from "${name}"`, 'from ' + JSON.stringify(target));
    }
    return { url: url(source), value: (await import(url(source))).default };
}
const navigation = await component('AdministrationNavigation.vue');
test('real navigation renders nine authorized routes in four groups with one exact active link', async () => {
    const routes = (await import(inventory)).administrationNavigation.flatMap(group => group.links).map(link => ({ path: link.to, component: { render: () => null } }));
    const router = createRouter({ history: createMemoryHistory(), routes });
    const awaitedPaths=routes.map(route=>route.path);
    const renderNavigation = () => { const app = createSSRApp(navigation.value, {allowedPaths:(awaitedPaths)}); app.use(router); return renderToString(app); };
    for (const path of ['/internal/administration/customers', '/internal/administration/appearance', '/internal/administration', '/internal/services']) {
        await router.push(path); await router.isReady();
        const html = await renderNavigation();
        assert.equal((html.match(/<section /g) ?? []).length, 4);
        assert.equal((html.match(/<a /g) ?? []).length, 9);
        assert.equal((html.match(/aria-current="page"/g) ?? []).length, 1);
        assert.match(html, new RegExp(`href="${path}" aria-current="page"`));
    }
    locale.value = 'ar'; const html = await renderNavigation(); assert.match(html, /الفريق والصلاحيات/); assert.match(html, /المظهر العام/); locale.value = 'en';
});

const focusDocument = { activeElement: null };
function element(tag, text = '') {
    return markRaw({ tag, text, props: {}, children: [], parent: null, open: false, focusCount: 0, ownerDocument: focusDocument, tabIndex: tag === 'button' ? 0 : -1,
        focus() { this.focusCount++; focusDocument.activeElement = this; }, getBoundingClientRect() { return { left: 70, right: 390, top: 0, bottom: 1000 }; },
        getClientRects() { return this.props.hidden ? [] : [{}]; }, matches() { return !!this.props.disabled; },
        showModal() { this.open = true; }, close() { const wasOpen = this.open; this.open = false; if (wasOpen) this.props.onClose?.(); } });
}
const renderer = createRenderer({
    createElement: element, createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText(node, text) { node.text = text; }, setElementText(node, text) { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); const index = anchor ? parent.children.indexOf(anchor) : -1; parent.children.splice(index < 0 ? parent.children.length : index, 0, node); node.parent = parent; },
    remove(node) { node.parent?.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, previous, value) { node.props[key] = value; },
});


const all = node => [node, ...node.children.flatMap(all)];
const find = (root, attribute) => all(root).find(node => attribute in node.props);
const navigationStub = url(`import {h} from ${JSON.stringify(vue)};export default {emits:['select'],setup(props,{emit}){return()=>h('button',{'data-navigation-select':true,onClick:()=>emit('select')},'Navigate')}};`);
const layout = await component('AdministrationLayout.vue', { './AdministrationNavigation.vue': navigationStub });
test('drawer interactions keep the actual page slot mounted, preserve draft state and restore toggle focus', async () => {
    let mounted = 0, unmounted = 0, mediaHandler, removed = false;
    globalThis.matchMedia = () => ({ matches: false, addEventListener(name, handler) { mediaHandler = handler; }, removeEventListener(name, handler) { removed = handler === mediaHandler; } });
    const draft = ref('Human draft');
    const page = { setup() { onMounted(() => mounted++); onBeforeUnmount(() => unmounted++); return () => h('input', { 'data-human-draft': true, value: draft.value }); } };
    const root = element('root'), app = renderer.createApp({ render: () => h(layout.value, {}, { header: () => h('span', 'BuilderAd'), default: () => h(page) }) });
    app.mount(root);
    const toggle = find(root, 'data-admin-menu-toggle'), dialog = find(root, 'data-admin-drawer');
    const original = find(root, 'data-human-draft');
    try {
        draft.value = 'Unsaved human input'; await nextTick();
        toggle.props.onClick(); await nextTick(); assert.equal(dialog.open, true); assert.equal(toggle.props['aria-expanded'], true);
        find(root, 'data-admin-drawer-close').props.onClick(); await nextTick(); assert.equal(dialog.open, false); assert.equal(toggle.props['aria-expanded'], false); assert.ok(toggle.focusCount > 0);
        toggle.props.onClick(); await nextTick(); dialog.props.onClick({ target: dialog, clientX: 10, clientY: 100 }); await nextTick(); assert.equal(dialog.open, false);
        toggle.props.onClick(); await nextTick(); dialog.props.onClick({ target: dialog, clientX: 100, clientY: 100 }); assert.equal(dialog.open, true, 'Inside click must not dismiss');
        all(dialog).find(node => 'data-navigation-select' in node.props).props.onClick(); await nextTick(); assert.equal(dialog.open, false);
        toggle.props.onClick(); await nextTick(); dialog.props.onCancel(); dialog.close(); await nextTick(); assert.equal(toggle.props['aria-expanded'], false);
        assert.equal(find(root, 'data-human-draft'), original); assert.equal(original.props.value, 'Unsaved human input'); assert.equal(mounted, 1); assert.equal(unmounted, 0);
    } finally { app.unmount(); assert.equal(unmounted, 1); assert.equal(removed, true); delete globalThis.matchMedia; }
});

test('open drawer wraps both Tab boundaries using only currently visible enabled controls', async () => {
    globalThis.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    globalThis.getComputedStyle = node => ({ visibility: node.props.visibility ?? 'visible' });
    const root = element('root'), app = renderer.createApp(layout.value); app.mount(root);
    const toggle = find(root, 'data-admin-menu-toggle'), dialog = find(root, 'data-admin-drawer');
    const first = find(root, 'data-admin-drawer-close'), last = all(dialog).find(node => 'data-navigation-select' in node.props);
    const disabled = element('button'), hidden = element('button'), invisible = element('button');
    disabled.props.disabled = true; hidden.props.hidden = true; invisible.props.visibility = 'hidden';
    dialog.querySelectorAll = () => [disabled, hidden, invisible, first, last];
    function key(key = 'Tab', shiftKey = false) { const event = { key, shiftKey, prevented: false, preventDefault() { this.prevented = true; } }; dialog.props.onKeydown(event); return event; }
    try {
        toggle.props.onClick(); await nextTick();
        last.focus(); assert.equal(key().prevented, true); assert.equal(focusDocument.activeElement, first);
        first.focus(); assert.equal(key('Tab', true).prevented, true); assert.equal(focusDocument.activeElement, last);
        first.focus(); assert.equal(key().prevented, false, 'Native forward traversal remains intact between boundaries');
        last.focus(); assert.equal(key('Tab', true).prevented, false, 'Native reverse traversal remains intact between boundaries');
        assert.equal(key('Escape').prevented, false, 'Native Escape cancellation remains intact');
        dialog.focus(); assert.equal(key().prevented, true); assert.equal(focusDocument.activeElement, first);
        last.props.disabled = true; first.focus(); assert.equal(key().prevented, true); assert.equal(focusDocument.activeElement, first, 'Disabled final control is excluded on the current key event');
        first.props.onClick(); await nextTick(); assert.equal(key().prevented, false, 'Closed dialog never intercepts keys');
    } finally { app.unmount(); delete globalThis.matchMedia; delete globalThis.getComputedStyle; focusDocument.activeElement = null; }
});

test('navigation hides destinations absent from the server projection and does not render empty groups',async()=>{
 const router=createRouter({history:createMemoryHistory(),routes:[{path:'/internal/security/devices',component:{render:()=>null}}]});
 await router.push('/internal/security/devices');await router.isReady();
 const app=createSSRApp(navigation.value,{allowedPaths:['/internal/security/devices']});app.use(router);
 const html=await renderToString(app);assert.equal((html.match(/<section /g)??[]).length,1);assert.equal((html.match(/<a /g)??[]).length,1);assert.doesNotMatch(html,/href="\/internal\/administration/);
});
