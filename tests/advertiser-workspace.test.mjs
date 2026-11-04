import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, createSSRApp, h, markRaw, nextTick, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';

const moduleUrl = text => 'data:text/javascript;base64,' + Buffer.from(text).toString('base64');
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const vue = import.meta.resolve('vue'), vueRouter = import.meta.resolve('vue-router');
const languageUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('ar');`);
const { locale } = await import(languageUrl);
const transportUrl = moduleUrl(`export const scope='document-one';export const callbacks=[];export const onClose=f=>callbacks.push(f);export const stamp=()=>scope;export const owned=()=>true;export class TransportError extends Error{}`);
const presentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const status=ref('ready');export let concealCalls=0;export const conceal=()=>{concealCalls++;};`);
const stateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();export const load=async()=>{};export const clear=()=>{current.value=undefined;};`);
const { current } = await import(stateUrl);
const iconsUrl = moduleUrl(`export const Building2={render:()=>null};export const ChevronLeft=Building2,Layers3=Building2,Link2=Building2,ShieldCheck=Building2,ArrowLeft=Building2,Plus=Building2,FileText=Building2,House=Building2,Megaphone=Building2,Settings2=Building2,Target=Building2,Users=Building2;`);
const plainUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {render(){return h('div',{'data-existing-editor':true})}};`);
const apiUrl = moduleUrl('export const calls=[];export const pending=[];export const create=command=>{calls.push(JSON.parse(JSON.stringify(command)));return new Promise((resolve,reject)=>pending.push({resolve,reject}));};export const factCalls=[];export const factPending=[];export const writeFact=(business,command)=>{factCalls.push({business,command:JSON.parse(JSON.stringify(command))});return new Promise((resolve,reject)=>factPending.push({resolve,reject}));};');
const creationApi = await import(apiUrl);
async function scriptModule(file) {
    return moduleUrl(compile(await readFile(file, 'utf8')).replaceAll("'../spa/transport'", JSON.stringify(transportUrl)));
}
const providerDestinationUrl = await scriptModule('resources/js/sources/providerDestination.ts');
const providerBrandUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['provider'],setup(p){return()=>h('span',{'data-provider-brand':p.provider})}};`);
const navigationUrl = await scriptModule('resources/js/business-foundation/advertiserNavigation.ts');
const workspacePresentationUrl = await scriptModule('resources/js/business-foundation/workspacePresentation.ts');
const draftsUrl = await scriptModule('resources/js/business-foundation/drafts.ts');
const presentation = await import(workspacePresentationUrl);
const drafts = await import(draftsUrl);
async function component(file, ports = {}) {
    const { descriptor } = parse(await readFile(file, 'utf8'));
    let source = compile(compileScript(descriptor, { id: file, inlineTemplate: true }).content).replace(/import ['"][^'"]+\.css['"];?/g, '');
    const imports = { vue, 'vue-router': vueRouter, '@lucide/vue': iconsUrl, '../spa/locale': languageUrl, '../spa/transport': transportUrl, '../spa/presentation': presentationUrl, './state': stateUrl, './api': apiUrl, './drafts': draftsUrl, './advertiserNavigation': navigationUrl, './workspacePresentation': workspacePresentationUrl, '../sources/providerDestination': providerDestinationUrl, './providerDestination': providerDestinationUrl, '../Components/ProviderBrand.vue': providerBrandUrl, ...ports };
    for (const [name, target] of Object.entries(imports)) source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(target)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(target)}`);
    const url = moduleUrl(source); return { url, value: (await import(url)).default };
}
const layout = await component('resources/js/business-foundation/AdvertiserWorkspaceLayout.vue');
const inquiryUrl = moduleUrl(`import {defineComponent,h,ref,onMounted,onBeforeUnmount} from ${JSON.stringify(vue)};export const events=[];export default defineComponent({props:['active','business'],setup(p){const draft=ref('');onMounted(()=>events.push(['mount',p.business]));onBeforeUnmount(()=>events.push(['unmount',p.business]));return()=>h('section',{'data-test-inquiry':p.business,'data-active':p.active},[h('input',{'data-draft':true,value:draft.value,onInput:e=>draft.value=e.target.value})]);}});`);
const { events } = await import(inquiryUrl);
const childPorts = Object.fromEntries(['BusinessEditor', 'BusinessFacts', 'BusinessCollaboration', 'BusinessDelegation', 'BusinessDataUse', 'BusinessPurposes', 'BusinessHistory', 'BusinessLegacyHistory'].map(name => [`./${name}.vue`, plainUrl]));
childPorts['./InternalClientFileTeam.vue'] = plainUrl;
const businessSourcesPanelUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){return()=>h('section',{'data-test-business-sources':p.surface.business.business_public_id},'Current scoped sources')}};`);
childPorts['./BusinessSources.vue'] = businessSourcesPanelUrl;
const dataUsePanelUrl = moduleUrl(`import {h,ref} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){const draft=ref('');return()=>h('section',{'data-test-data-use':p.surface.business.business_public_id},[h('input',{'data-use-draft':true,value:draft.value,onInput:e=>draft.value=e.target.value})]);}};`);
const purposePanelUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){return()=>h('section',{'data-test-business-purpose':p.surface.business.business_public_id},'Canonical Business purposes');}};`);
const workspace = await component('resources/js/business-foundation/BusinessWorkspace.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, '../inquiry-evidence/InquiryEvidencePanel.vue': inquiryUrl, ...childPorts, '../workspaces/OrganizationTeam.vue':plainUrl,'./DirectBusinessTeam.vue':plainUrl, './BusinessDataUse.vue': dataUsePanelUrl, './BusinessPurposes.vue': purposePanelUrl });
const creation = await component('resources/js/business-foundation/BusinessCreate.vue');
const goalSelectionUrl = moduleUrl(compile(await readFile('resources/js/Components/Business/goalSelection.ts', 'utf8')));
const facts = await component('resources/js/business-foundation/BusinessFacts.vue', { '../Components/Business/goalSelection': goalSelectionUrl });
const surface = await component('resources/js/business-foundation/BusinessSurface.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './BusinessWorkspace.vue': workspace.url, './BusinessCreate.vue': creation.url });
const sources = await component('resources/js/business-foundation/SourceRights.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './SourceRightsEditor.vue': plainUrl });
const boundaries = await component('resources/js/business-foundation/DataBoundaries.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './BoundaryEditor.vue': plainUrl });
const workspacesStateUrl = moduleUrl(`import {reactive,shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();export const creationDraft=reactive({name:'',business_type_id:'',creation_key:''});export const unresolvedCreation=shallowRef();export const resetCreationDraft=()=>{Object.assign(creationDraft,{name:'',business_type_id:'',creation_key:''});unresolvedCreation.value=undefined;};`);
const workspaceTransportUrl = moduleUrl('export const bootstrap={identity:{binding:{boundary:"advertiser"}}};export const owned=()=>true;export const stamp=()=>"document-one";export class TransportError extends Error{constructor(status,body={}){super("Request failed");this.status=status;this.body=body;}}');
const workspaceApiUrl = moduleUrl('export const calls=[],pending=[];export const createOrganization=command=>{calls.push(JSON.parse(JSON.stringify(command)));return new Promise((resolve,reject)=>pending.push({resolve,reject}));};export const reassessOwnerGrant=async()=>{throw Error("No reassessment request expected")};');
const chooser = await component('resources/js/workspaces/WorkspaceIndex.vue', { './state': workspacesStateUrl, './api': workspaceApiUrl, '../spa/transport': workspaceTransportUrl, '../spa/destination': moduleUrl('export const applicationDestination=()=>"/";'), '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const workspaceCreationPresentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const status=ref('ready'),confirmDeparture=ref(()=>true);`);
const workspaceCreation = await component('resources/js/workspaces/OrganizationCreate.vue', { './state': workspacesStateUrl, './api': workspaceApiUrl, '../spa/transport': workspaceTransportUrl, '../spa/presentation': workspaceCreationPresentationUrl, '../spa/destination': moduleUrl('export const applicationDestination=redirect=>new URL(redirect,"https://builderad.test").href;'), '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const sourceStateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();`);
const sourceCommandsUrl = moduleUrl('export const pending=new Map();');
const sourcePresentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const confirmDeparture=ref(()=>true);`);
const sourcesChildUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['workspace'],setup(p){return()=>h('section',{'data-existing-sources':p.workspace.public_id},p.workspace.name)}};`);
const sourcesView = await component('resources/js/sources/SourceView.vue', { '../workspaces/OrganizationTeam.vue':plainUrl,'../workspaces/team':moduleUrl('export const hasPendingTeamCommand=()=>false;'),'./Sources.vue': sourcesChildUrl, './state': sourceStateUrl, './commands': sourceCommandsUrl, '../spa/presentation': sourcePresentationUrl, '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const businessCopyUrl = moduleUrl(`export const useBusinessCopy=()=>({t:key=>key,localized:value=>value?.name_ar??value?.label??''});`);
const sourcesCommandsFullUrl = moduleUrl('export const commandsFor=()=>[];export const submit=async()=>{throw Error("No command expected")};export const remember=()=>{};export const restore=()=>undefined;');
const operationalSources = await component('resources/js/sources/Sources.vue', { '@/Components/ProviderBrand.vue':providerBrandUrl, '@/localization/businessCopy':businessCopyUrl, './commands':sourcesCommandsFullUrl, './PendingCommands.vue':plainUrl, './SourceRegistryPanel.vue':plainUrl, './MetaConnectionPanel.vue':plainUrl, './GoogleConnectionPanel.vue':plainUrl, './SnapchatConnectionPanel.vue':plainUrl, './TikTokConnectionPanel.vue':plainUrl, './XConnectionPanel.vue':plainUrl, './state': moduleUrl('export const read=async()=>{};') });

const historyStateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const commercialHistory=shallowRef();`);
const historyPage = await component('resources/js/workspaces/CommercialHistory.vue', { './commercialHistory': historyStateUrl, '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const routes = ['/businesses', '/businesses/new', '/businesses/:business', '/organizations/new', '/source-rights', '/source-rights/:source', '/data-rights', '/data-rights/:boundary', '/workspaces', '/workspaces/:workspace', '/workspaces/:workspace/commercial-history', '/workspaces/:workspace/purpose-history', '/workspaces/:workspace/purpose-history/:purpose'].map(path => ({ path, component: { render: () => null } }));
async function routerAt(path) { const router = createRouter({ history: createMemoryHistory(), routes }); await router.push(path); await router.isReady(); return router; }
async function render(component, path, data) { current.value = { data }; const app = createSSRApp(component); app.use(await routerAt(path)); return renderToString(app); }
const businessSurface = name => ({ private_history_available:true,direct_team_enrollment_available:false,business: { business_public_id: name, name, business_type_id: 1 }, catalog: { business_types: [{ id: 1, name_ar: 'خدمات', name_en: 'Services' }] }, authority_selection: { requirements: [{ leaf_grant_id: 'read-one' }] } });

test('real business list and empty state prioritize opening or creating a business inside one private shell', async () => {
    const empty = await render(surface.value, '/businesses', { items: [], actor_public_id: 'actor-one', next_cursor: null });
    assert.match(empty, /data-spa-private/); assert.match(empty, /data-advertiser-shell/); assert.match(empty, /data-business-empty/);
    assert.match(empty, /href="\/businesses\/new"/); assert.equal((empty.match(/<main /g) ?? []).length, 1);
    assert.ok(empty.indexOf('data-business-empty') < empty.indexOf('data-business-account-reference'));
    const listed = await render(surface.value, '/businesses', { items: [{ read_grant_id: 'read-grant', business_public_id: 'b-one', name: 'First business', realm_kind: 'business', state: 'declared_draft' }], actor_public_id: 'actor-one', next_cursor: 'next-page' });
    assert.match(listed, /First business/); assert.match(listed, /href="\/businesses\/b-one\?access=read-grant"/); assert.match(listed, /href="\/businesses\?after=next-page"/);
    assert.doesNotMatch(listed, /class="bf-nav"/);
    const created = await render(surface.value, '/businesses/new', { catalog: { business_types: [] } });
    assert.match(created, /id="bf-create-name"/); assert.match(created, /id="bf-create-type"/); assert.equal((created.match(/<main /g) ?? []).length, 1);
});

test('source and data-boundary empty states describe only manageable resources, with real next actions', async () => {
    const emptySource = await render(sources.value, '/source-rights', { sources: [], next_cursor: null });
    assert.match(emptySource, /data-source-rights-empty/); assert.match(emptySource, /لا توجد مصادر متاحة لك لإدارتها/); assert.match(emptySource, /href="\/workspaces"/);
    const sourceList = await render(sources.value, '/source-rights', { sources: [{ source_id: 'source-one', access_grant_id: 'source-grant', label: 'A source', status: 'ACTIVE', resource: 'resource', operation: 'read', purpose: 'review' }], next_cursor: null });
    assert.match(sourceList, /A source/); assert.match(sourceList, /href="\/source-rights\/source-one\?access=source-grant"/); assert.doesNotMatch(sourceList, /data-source-rights-empty/);
    const emptyBoundary = await render(boundaries.value, '/data-rights', { boundaries: [], next_cursor: null });
    assert.match(emptyBoundary, /data-boundary-empty/); assert.match(emptyBoundary, /href="\/businesses"/);
    const boundaryList = await render(boundaries.value, '/data-rights', { boundaries: [{ boundary_id: 'boundary-one', read_grant_id: 'boundary-grant', state: 'active' }], next_cursor: null });
    assert.match(boundaryList, /href="\/data-rights\/boundary-one\?access=boundary-grant"/); assert.equal((boundaryList.match(/<main /g) ?? []).length, 1);
});

test('workspace chooser shares the advertiser shell and keeps resource context distinct from a Business', async () => {
    const workspaceState = await import(workspacesStateUrl);
    const router = await routerAt('/workspaces');
    workspaceState.current.value = { data: { workspaces: [], reassessableWorkspaces: [], canCreateWorkspace: false } };
    const emptyApp = createSSRApp(chooser.value); emptyApp.use(router);
    const empty = await renderToString(emptyApp);
    assert.match(empty, /data-spa-private/); assert.match(empty, /data-advertiser-shell/); assert.match(empty, /data-workspaces-empty/);
    assert.equal((empty.match(/<main /g) ?? []).length, 1); assert.match(empty, /مساحات العمل/); assert.doesNotMatch(empty, /مساحات التعاون/);
    assert.match(empty, /href="\/businesses"/); assert.match(empty, /ليست النشاط التجاري نفسه/);
    assert.match(empty, /data-workspaces-creation-unavailable/); assert.doesNotMatch(empty, /data-workspaces-add|href="\/business\/start/); assert.match(empty, /لا تحتاج إلى تسجيل جهة للعمل منفردًا/);
    workspaceState.current.value = { data: { workspaces: [{ public_id: 'workspace-one', name: 'Shared resources A' }], reassessableWorkspaces: [], canCreateWorkspace: true } };
    const listApp = createSSRApp(chooser.value); listApp.use(router); const list = await renderToString(listApp);
    assert.match(list, /Shared resources A/); assert.match(list, /href="\/workspaces\/workspace-one"/); assert.doesNotMatch(list, /data-workspaces-empty/);
    assert.match(list, /data-workspaces-add[^>]*href="\/organizations\/new"|href="\/organizations\/new"[^>]*data-workspaces-add/); assert.doesNotMatch(list, /data-workspaces-creation-unavailable/);
    workspaceState.current.value.data.canCreateWorkspace = false;
    const deniedApp = createSSRApp(chooser.value); deniedApp.use(router); const denied = await renderToString(deniedApp);
    assert.match(denied, /href="\/workspaces\/workspace-one"/); assert.doesNotMatch(denied, /data-workspaces-add|href="\/business\/start/);
    const workspaceLink = [...list.matchAll(/<a [^>]*href="\/workspaces"[^>]*>/g)].map(m => m[0]);
    assert.ok(workspaceLink.some(tag => tag.includes('aria-current="page"')));
});

test('workspace source root keeps one private shell, parent navigation and resource props, with departure guards intact', async () => {
    const listeners = new Map(), removed = [];
    const previousWindow = globalThis.window, previousConfirm = globalThis.confirm;
    globalThis.window = { innerWidth: 1440, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }), addEventListener(name, handler) { listeners.set(name, handler); }, removeEventListener(name, handler) { removed.push([name, handler]); } };
    globalThis.confirm = () => false;
    const sourceState = await import(sourceStateUrl), sourceCommands = await import(sourceCommandsUrl), sourcePresentation = await import(sourcePresentationUrl);
    sourceState.current.value = { data: { workspace: { public_id: 'workspace-one', name: 'Private workspace A' } } };
    const root = element('root'), app = renderer.createApp({ render: () => h(RouterView) });
    const router = createRouter({ history: createMemoryHistory(), routes: routes.map(route => route.path === '/workspaces/:workspace' ? { ...route, component: sourcesView.value } : route) });
    await router.push('/workspaces/workspace-one'); await router.isReady(); app.use(router); app.mount(root);
    try {
        assert.equal(all(root).filter(node => node.tag === 'main').length, 1);
        const owner = find(root, 'data-sources-workspace', 'workspace-one'); assert.ok(owner); assert.ok('data-spa-private' in owner.props);
        assert.ok(find(root, 'data-advertiser-shell')); assert.equal(find(root, 'data-existing-sources').props['data-existing-sources'], 'workspace-one');
        const breadcrumb = all(root).find(node => node.tag === 'nav' && node.props.class === 'advertiser-breadcrumb');
        assert.match(text(breadcrumb), /مساحات العمل/); assert.match(text(breadcrumb), /Private workspace A/);
        assert.deepEqual(all(breadcrumb).filter(node => node.tag === 'a').map(node => node.props.href), ['/businesses', '/workspaces']);
        const workspacePageLink = all(root).find(node => node.tag === 'a' && node.props.href === '/workspaces/workspace-one' && node.props['aria-current'] === 'page'); assert.ok(workspacePageLink);
        sourceCommands.pending.set('uncertain-source-command', {});
        assert.equal(sourcePresentation.confirmDeparture.value(), false, 'Unresolved source commands still govern leaving');
        const event = { prevented: false, preventDefault() { this.prevented = true; }, returnValue: undefined }; listeners.get('beforeunload')(event); assert.equal(event.prevented, true); assert.equal(event.returnValue, '');
        sourceState.current.value = undefined; await nextTick(); assert.equal(find(root, 'data-sources-workspace'), undefined); assert.doesNotMatch(text(root), /Private workspace A/);
    } finally { sourceCommands.pending.clear(); app.unmount(); globalThis.window = previousWindow; globalThis.confirm = previousConfirm; }
    assert.ok(removed.some(([name, handler]) => name === 'beforeunload' && handler === listeners.get('beforeunload')));
});

test('compact header path matching covers only the explicitly wrapped workspace screens', async () => {
    const { isAdvertiserWorkspacePath, advertiserNavigationCurrent } = await import(navigationUrl);
    for (const path of ['/organizations/new', '/workspaces', '/workspaces/workspace-one', '/workspaces/workspace-one/', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/purpose-history', '/workspaces/workspace-one/purpose-history/purpose-one']) assert.equal(isAdvertiserWorkspacePath(path), true);
    for (const path of ['/business/start/unknown', '/workspaces/workspace-one/providers/meta', '/workspaces/workspace-one/commercial-history/unknown', '/profile', '/internal/administration']) assert.equal(isAdvertiserWorkspacePath(path), false);
    assert.equal(advertiserNavigationCurrent('/workspaces/workspace-one', '/workspaces'), true);
    assert.equal(advertiserNavigationCurrent('/workspaces-unrelated', '/workspaces'), false);
});

test('selected workspace navigation preserves all existing functions and gives only the actual section current-page semantics', async () => {
    const paths = ['/workspaces/workspace-one', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/purpose-history/purpose-one'];
    for (const path of paths) {
        const app = createSSRApp(layout.value, { workspaceId: 'workspace-one', context: 'Workspace private name', workspaceContext: true }); app.use(await routerAt(path));
        const html = await renderToString(app);
        const navigation = html.match(/<nav class="advertiser-navigation"[\s\S]*?<\/nav>/)?.[0]; assert.ok(navigation);
        assert.equal((navigation.match(/aria-current="page"/g) ?? []).length, 1, path);
        for (const href of ['/workspaces/workspace-one', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/commercial-history', '/workspaces/workspace-one/purpose-history']) assert.ok(navigation.includes(`href="${href}"`), href);
        assert.match(html, /Workspace private name/); assert.doesNotMatch(html, /اختر نشاطك التجاري/);
        const previousGroup = navigation.match(/<details[^>]*data-workspace-previous-navigation[^>]*>/)?.[0]; assert.ok(previousGroup);
        if (path === '/workspaces/workspace-one') {
            assert.doesNotMatch(previousGroup, / open(?:[ =]|>)/); assert.doesNotMatch(html, /data-workspace-previous-notice/);
        } else {
            assert.match(previousGroup, / open(?:[ =]|>)/); assert.match(html, /data-workspace-previous-notice/);
            assert.match(html, /حقائق النشاط الحالية تدار في النشاط المستقل/);
            assert.match(html, /href="\/businesses"/); assert.doesNotMatch(html, /href="\/businesses\/workspace-one/);
        }
    }
    const chooserApp = createSSRApp(layout.value, { workspaceContext: true }); chooserApp.use(await routerAt('/workspaces'));
    const html = await renderToString(chooserApp); assert.doesNotMatch(html, /href="\/workspaces\/[^"/]+\/goals"/);
});

function element(tag, text = '') {
    const listeners = new Map();
    return markRaw({ tag, tagName: tag.toUpperCase(), type: tag === 'input' ? 'text' : tag, text, value: '', props: {}, style: {}, children: [], parent: null, open: false, selected: false, selectedIndex: -1, multiple: false, focusCount: 0,
        get options() { return this.children.filter(node => node.tag === 'option'); },
        addEventListener(name, handler) { const handlers = listeners.get(name) ?? new Set(); handlers.add(handler); listeners.set(name, handlers); },
        removeEventListener(name, handler) { listeners.get(name)?.delete(handler); },
        dispatchEvent(event) { if (!event.target) Object.defineProperty(event, 'target', { value: this }); for (const handler of listeners.get(event.type) ?? []) handler(event); const handler = this.props['on' + event.type[0].toUpperCase() + event.type.slice(1)]; if (typeof handler === 'function') handler(event); return !event.defaultPrevented; },
        getRootNode() { return {}; }, querySelectorAll() { return []; }, showModal() { this.open = true; this.modalCount = (this.modalCount ?? 0) + 1; }, close() { this.open = false; },
        focus() { this.focusCount++; }, getBoundingClientRect() { return { top: 0 }; }, setAttribute(name, value) { this.props[name] = value; }, removeAttribute(name) { delete this.props[name]; },
    });
}
const renderer = createRenderer({
    createElement: tag => element(tag), createText: text => element('#text', text), createComment: text => element('#comment', text),
    setText(node, text) { node.text = text; }, setElementText(node, text) { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor = null) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(node) : parent.children.splice(at, 0, node); },
    remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, old, value) { node.props[key] = value; if (['type', 'checked', 'multiple', 'selected'].includes(key)) node[key] = value; if (key === 'value') { node.value = value == null ? '' : value; node._value = value; } },
});
const all = node => [node, ...node.children.flatMap(all)];
const find = (root, key, value) => all(root).find(node => key in node.props && (value === undefined || node.props[key] === value));
const text = node => node.text + node.children.map(text).join('');

test('settings data navigation activates BF03 only while visible and retains its mounted draft across local views', async () => {
    let mediaChanged, mediaRemoved = false;
    globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: true, addEventListener(_event, handler) { mediaChanged = handler; }, removeEventListener(_event, handler) { mediaRemoved = handler === mediaChanged; } }) };
    presentation.workspacePresentation.clear(); events.length = 0;
    const selected = ref(businessSurface('Business A'));
    const root = element('root'), app = renderer.createApp({ render: () => h(workspace.value, { key: selected.value.business.business_public_id, surface: selected.value }) });
    app.use(await routerAt('/businesses/Business-A')); app.mount(root);
    try {
        assert.equal(find(root, 'data-test-inquiry'), undefined);
        assert.equal(all(root).find(node => node.tag === 'details').open, true, 'Desktop navigation is genuinely open');
        find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-inquiry'), undefined, 'Opening settings alone must not activate inquiry reading');
        find(root, 'data-workspace-settings', 'data').props.onClick(); await nextTick();
        const panel = find(root, 'data-test-inquiry'), input = find(root, 'data-draft');
        assert.equal(panel.props['data-active'], true); input.props.onInput({ target: { value: 'Unsent aggregate draft' } }); await nextTick();
        find(root, 'data-workspace-settings', 'details').props.onClick(); await nextTick();
        assert.equal(panel.props['data-active'], false); assert.equal(find(root, 'data-test-inquiry'), panel); assert.equal(input.props.value, 'Unsent aggregate draft');
        find(root, 'data-workspace-section', 'direction').props.onClick(); await nextTick(); assert.equal(panel.props['data-active'], false);
        find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick(); find(root, 'data-workspace-settings', 'data').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-inquiry'), panel); assert.equal(panel.props['data-active'], true); assert.equal(events.filter(e => e[0] === 'mount').length, 1);
        locale.value = 'en'; await nextTick(); assert.match(text(root), /Data and declarations/); assert.match(text(root), /Business, team and settings/);
        selected.value = businessSurface('Business B'); await nextTick(); assert.equal(find(root, 'data-test-inquiry'), undefined, 'Another Business must not inherit the previous local data location');
        assert.deepEqual(events, [['mount', 'Business A'], ['unmount', 'Business A']]);
    } finally { app.unmount(); locale.value = 'ar'; delete globalThis.window; }
    assert.equal(mediaRemoved, true);
});

test('legacy local inquiries/access selections migrate without changing resource keys or granting authority', async () => {
    assert.deepEqual(presentation.workspaceLocation('inquiries'), { section: 'settings', settingsView: 'data' });
    assert.deepEqual(presentation.workspaceLocation('access'), { section: 'settings', settingsView: 'access' });
    const a = presentation.workspacePresentationKey(drafts.key('A', { grant: 'one' }));
    const b = presentation.workspacePresentationKey(drafts.key('B', { grant: 'one' }));
    const otherGrant = presentation.workspacePresentationKey(drafts.key('A', { grant: 'two' }));
    assert.notEqual(a, b); assert.notEqual(a, otherGrant);
    presentation.workspacePresentation.set(a, { section: 'inquiries', positions: { inquiries: 80 } });
    assert.equal(presentation.workspacePresentation.has(b), false);
    const { callbacks } = await import(transportUrl); callbacks.forEach(callback => callback()); assert.equal(presentation.workspacePresentation.size, 0);
});

test('workspace creation keeps its separate labels, exact unknown-command replay and departure/key guards', async () => {
    const restore = withFactDom(), previousWindow = globalThis.window, previousLocation = globalThis.location, previousConfirm = globalThis.confirm;
    const listeners = new Map(), removed = [];
    globalThis.window = { matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }), addEventListener(name, handler) { listeners.set(name, handler); }, removeEventListener(name, handler) { removed.push([name, handler]); } };
    globalThis.location = { origin: 'https://builderad.test', assign() { assert.fail('Known workspace destination must use the router'); } };
    globalThis.confirm = () => false;
    const state = await import(workspacesStateUrl), api = await import(workspaceApiUrl), page = await import(workspaceCreationPresentationUrl);
    state.resetCreationDraft(); api.calls.length = 0; api.pending.length = 0;
    Object.assign(state.creationDraft, { name: 'Original shared resources', creation_key: 'exact-creation-key' });
    state.current.value = { data: { creationKey: 'exact-creation-key', types: [{ id: 7, active: true, name_ar: 'خدمات', name_en: 'Services' }] } };
    const root = element('root'), app = renderer.createApp({ render: () => h(RouterView) });
    const router = createRouter({ history: createMemoryHistory(), routes: routes.map(route => route.path === '/organizations/new' ? { ...route, component: workspaceCreation.value } : route) });
    await router.push('/organizations/new'); await router.isReady(); app.use(router); app.mount(root);
    const submit = () => all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} });
    try {
        assert.ok(find(root, 'data-workspaces', 'create')); assert.ok(find(root, 'data-advertiser-shell'));
        assert.equal(all(root).filter(node => node.tag === 'main').length, 1);
        assert.equal(text(all(root).find(node => node.tag === 'h1')), 'تسجيل وكالة أو شركة');
        assert.match(text(root), /اسم الوكالة أو الشركة/); assert.match(text(root), /لا تحتاج إلى إنشاء حاوية أخرى/); assert.match(text(root), /لا ينشئ التسجيل نشاطًا تجاريًا/);
        assert.equal(find(root, 'data-workspaces', 'type'), undefined, 'Organization registration requires no commercial type');
        assert.ok(all(root).some(node => node.tag === 'a' && node.props.href === '/businesses/new'));
        assert.ok(all(root).some(node => node.tag === 'a' && node.props.href === '/workspaces' && /العودة/.test(text(node))));
        assert.equal(page.confirmDeparture.value(), false, 'An unfinished workspace still requires departure confirmation');
        const first = submit(); await nextTick(); assert.equal(api.calls.length, 1); assert.equal(find(root, 'data-workspaces', 'uncertain'), undefined);
        const original = structuredClone(api.calls[0]);
        assert.deepEqual(original, { name: 'Original shared resources', creation_key: 'exact-creation-key' });
        const unload = { preventDefault() { this.prevented = true; }, returnValue: undefined }; listeners.get('beforeunload')(unload); assert.equal(unload.prevented, true);
        api.pending.shift().reject(Error('Acknowledgement lost')); await first; await nextTick();
        assert.equal(all(root).filter(node => node.props['data-workspaces'] === 'uncertain').length, 1);
        assert.match(text(find(root, 'data-workspaces', 'uncertain')), /الجهة/);
        state.creationDraft.name = 'Later draft'; locale.value = 'en'; await nextTick();
        assert.match(text(root), /Agency or company name/); assert.match(text(root), /Check the outcome/);
        await submit(); assert.equal(api.calls.length, 1, 'An ordinary submit cannot replace an unresolved command');
        const retry = find(root, 'data-workspaces', 'retry').props.onClick(); await nextTick(); assert.deepEqual(api.calls[1], original);
        api.pending.shift().resolve({ redirect: '/workspaces/created-workspace' }); await retry; await flushFacts();
        assert.equal(router.currentRoute.value.path, '/workspaces/created-workspace'); assert.equal(state.unresolvedCreation.value, undefined); assert.equal(state.creationDraft.creation_key, '');

        Object.assign(state.creationDraft, { name: 'Keep this draft', business_type_id: 7, creation_key: 'old-key' });
        state.current.value = { data: { creationKey: 'new-key', types: [{ id: 7, active: true, name_ar: 'خدمات', name_en: 'Services' }] } };
        await router.push('/organizations/new'); await flushFacts();
        assert.match(text(root), /creation context changed/); assert.equal(all(root).find(node => node.tag === 'fieldset').props.disabled, true);
        await submit(); assert.equal(api.calls.length, 2, 'A changed creation key cannot produce a new command');
        assert.equal(state.creationDraft.name, 'Keep this draft');
    } finally {
        app.unmount(); state.resetCreationDraft(); locale.value = 'ar'; restore();
        globalThis.window = previousWindow; globalThis.location = previousLocation; globalThis.confirm = previousConfirm;
    }
    assert.ok(removed.some(([name, handler]) => name === 'beforeunload' && handler === listeners.get('beforeunload')));
    assert.equal(page.confirmDeparture.value(), true, 'Unmount restores the shared departure owner');
});

test('creation checks a lost acknowledgement with the exact original command and one localized outcome message', async () => {
    const originalDocument = globalThis.Document, originalShadowRoot = globalThis.ShadowRoot;
    globalThis.Document = class TestDocument {};
    globalThis.ShadowRoot = class TestShadowRoot {};
    Object.assign(drafts.creation, { name: 'Exact original name', type: null, pending: undefined, receipt: undefined });
    creationApi.calls.length = 0; creationApi.pending.length = 0;
    const root = element('root'), app = renderer.createApp(creation.value, { catalog: { business_types: [] } });
    app.use(await routerAt('/businesses/new')); app.mount(root);
    const form = () => all(root).find(node => node.tag === 'form');
    const submit = () => form().props.onSubmit({ preventDefault() {} });
    try {
        const first = submit(); await nextTick();
        assert.equal(creationApi.calls.length, 1);
        assert.equal(find(root, 'data-business-unknown'), undefined, 'Pending network work is not yet an unknown outcome');
        assert.match(text(root), /جارٍ الحفظ/);
        creationApi.pending.shift().reject(Error('Acknowledgement lost')); await first; await nextTick();
        assert.equal(all(root).filter(node => 'data-business-unknown' in node.props).length, 1);
        assert.match(text(root), /التحقق من النتيجة/);
        assert.equal(all(root).filter(node => node.props.role === 'status').length, 1, 'Unknown outcome must not repeat in a generic notice');
        const original = structuredClone(creationApi.calls[0]);
        assert.ok(original.command_id); assert.equal(original.name, 'Exact original name');
        locale.value = 'en'; await nextTick();
        assert.match(text(root), /Check the outcome/); assert.match(text(root), /We could not confirm creation/);
        drafts.creation.name = 'A later draft must not replace the command';
        const retry = submit(); await nextTick();
        assert.equal(find(root, 'data-business-unknown'), undefined, 'Checking hides the old unknown notice during the request');
        assert.deepEqual(creationApi.calls[1], original, 'Retry must preserve command identity, name and nullable field');
        creationApi.pending.shift().resolve({ business_public_id: 'created-one', name: original.name, grant_refs: { 'business.context.read': 'read-created' } });
        await retry; await nextTick();
        assert.ok(find(root, 'data-business-created'));
        assert.equal(drafts.creation.pending, undefined);
        assert.equal(find(root, 'data-business-unknown'), undefined);
        assert.deepEqual(creationApi.calls, [original, original]);
    } finally {
        app.unmount(); locale.value = 'ar';
        Object.assign(drafts.creation, { name: '', type: null, pending: undefined, receipt: undefined });
        if (originalDocument === undefined) delete globalThis.Document; else globalThis.Document = originalDocument;
        if (originalShadowRoot === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = originalShadowRoot;
    }
});

function factSurface(id = 'fact-business') {
    return {
        business: { business_public_id: id, declared_markets: ['SA'], current_intent: null, preferences: null, versions: { context: 3, intent: 2, preferences: 1 } },
        authority_selection: { realm_kind: 'direct', requirements: [{ requirement_kind: 'business', leaf_grant_id: 17 }] },
        fact_write_options: Object.fromEntries(['markets', 'intent', 'preferences'].map(kind => [kind, [{ grant_id: '17' }]])),
        catalog: { countries: [{ code: 'SA', name_ar: 'السعودية', name_en: 'Saudi Arabia' }, { code: 'JO', name_ar: 'الأردن', name_en: 'Jordan' }, { code: 'AE', name_ar: 'الإمارات', name_en: 'United Arab Emirates' }], intent_timezones: ['Asia/Riyadh'], goals: [{ id: 'sales', name_ar: 'المبيعات', name_en: 'Sales', results: [{ id: 'paid', name_ar: 'طلبات مدفوعة', name_en: 'Paid orders' }] }, { id: 'customers', name_ar: 'العملاء', name_en: 'Customers', results: [{ id: 'new', name_ar: 'عملاء جدد', name_en: 'New customers' }] }] },
    };
}
const flushFacts = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function factTab(root, kind) { find(root, 'data-business-fact-tab', kind).props.onClick(); await flushFacts(); }
async function inputFact(root, id, value) { const node = find(root, 'id', id); node.value = value; node.dispatchEvent({ type: 'input' }); await flushFacts(); }
async function checkFact(root, attribute, value, checked = true) { const node = find(root, attribute, value); node.checked = checked; node.dispatchEvent({ type: 'change' }); await flushFacts(); }
async function selectFact(root, id, value) { const node = find(root, 'id', id); node.options.forEach(option => { option.selected = option._value === value; }); node.dispatchEvent({ type: 'change' }); await flushFacts(); }
function withFactDom() { const old = { Document: globalThis.Document, ShadowRoot: globalThis.ShadowRoot }; globalThis.Document = class {}; globalThis.ShadowRoot = class {}; return () => { for (const [key, value] of Object.entries(old)) { if (value === undefined) delete globalThis[key]; else globalThis[key] = value; } }; }

test('Business facts keeps searched country selections, intent draft and preference choices across its three real subviews', async () => {
    const restore = withFactDom(); drafts.factDrafts.clear(); creationApi.factCalls.length = 0; creationApi.factPending.length = 0;
    const root = element('root'), app = renderer.createApp(facts.value, { surface: factSurface() }); app.mount(root);
    try {
        assert.equal((all(root).filter(node => node.tag === 'h1')).length, 0, 'Existing workspace owns the page heading');
        assert.equal(find(root, 'id', 'bf-market-codes').tag, 'div', 'Country selection is a bounded searchable group, not an eight-row native select');
        await inputFact(root, 'bf-market-search', 'Jordan');
        assert.equal(find(root, 'data-fact-country', 'SA'), undefined); await checkFact(root, 'data-fact-country', 'JO');
        await inputFact(root, 'bf-market-search', 'United'); await checkFact(root, 'data-fact-country', 'AE');
        const marketDraft = [...drafts.factDrafts.values()].find(draft => draft.kind === 'markets');
        assert.deepEqual([...marketDraft.fields.codes].sort(), ['AE', 'JO', 'SA'], 'Filtering does not discard other selected country codes');
        await factTab(root, 'intent'); assert.match(text(root), /ليست إعداد حملة إعلانية/);
        const disclosures = all(root).filter(node => node.tag === 'details' && node.props.class === 'bf-fact-disclosure');
        assert.equal(disclosures.length, 2); assert.ok(disclosures.every(node => !node.props.open), 'Optional blank fields start collapsed');
        await selectFact(root, 'bf-intent-direction', 'increase_value'); await inputFact(root, 'bf-intent-meaning', 'Completed maintenance contract');
        await factTab(root, 'preferences'); await checkFact(root, 'data-fact-goal', 'sales'); await checkFact(root, 'data-fact-goal', 'customers');
        await checkFact(root, 'data-fact-priority', 'customers'); await checkFact(root, 'data-fact-result', 'new');
        const preferenceDraft = [...drafts.factDrafts.values()].find(draft => draft.kind === 'preferences');
        assert.equal(preferenceDraft.fields.goals.priority, 'customers'); assert.deepEqual([...preferenceDraft.fields.goals.results.customers], ['new']);
        await factTab(root, 'intent'); assert.equal(find(root, 'id', 'bf-intent-meaning').value, 'Completed maintenance contract');
        await factTab(root, 'markets'); await inputFact(root, 'bf-market-search', '');
        assert.equal(find(root, 'data-fact-country', 'SA').checked, true); assert.equal(find(root, 'data-fact-country', 'JO').checked, true);
        locale.value = 'en'; await flushFacts(); assert.match(text(root), /Target markets/); assert.match(text(root), /Find a country/);
        await factTab(root, 'intent'); assert.match(text(root), /Business intent is the direction/); assert.equal(find(root, 'id', 'bf-intent-meaning').value, 'Completed maintenance contract');
        assert.equal(creationApi.factCalls.length, 0, 'Local navigation and selection alone never submit commands');
    } finally { app.unmount(); restore(); locale.value = 'ar'; drafts.factDrafts.clear(); }
});

test('Business intent preserves every explicit confirmation, resets them on edits and clears only by a confirmed null command', async () => {
    const restore = withFactDom(); drafts.factDrafts.clear(); creationApi.factCalls.length = 0; creationApi.factPending.length = 0;
    const surface = ref(factSurface());
    const root = element('root'), app = renderer.createApp({ render: () => h(facts.value, { surface: surface.value }) }); app.mount(root);
    const submit = () => all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} });
    try {
        await factTab(root, 'intent'); await selectFact(root, 'bf-intent-direction', 'preserve_value'); await inputFact(root, 'bf-intent-meaning', 'Maintain completed services');
        await inputFact(root, 'bf-intent-clarification', 'Completed with the agreed service'); await inputFact(root, 'bf-intent-quantity', '10'); await inputFact(root, 'bf-intent-unit', 'services');
        await inputFact(root, 'bf-intent-date', '2026-12-01'); await selectFact(root, 'bf-intent-timezone', 'Asia/Riyadh');
        for (const flag of ['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity']) await checkFact(root, 'data-fact-confirm', flag);
        await submit(); await flushFacts(); assert.equal(creationApi.factCalls.length, 0, 'Missing horizon confirmation must still prevent submission');
        await checkFact(root, 'data-fact-confirm', 'horizon'); await inputFact(root, 'bf-intent-meaning', 'Revised completed service definition');
        assert.ok(all(root).filter(node => 'data-fact-confirm' in node.props).every(node => !node.checked), 'Changing outcome meaning resets all explicit confirmations');
        for (const flag of ['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity', 'horizon']) await checkFact(root, 'data-fact-confirm', flag);
        const pending = submit(); await flushFacts();
        const command = creationApi.factCalls[0].command;
        assert.deepEqual(command.confirmations, { declaration: true, business_wide: true, outcome: true, acceptance_clear: true, clarification_required: true, quantity: true, horizon: true });
        assert.equal(command.value.scope, 'business_wide'); assert.equal(command.expected_context, 3); assert.equal(command.expected_intent, 2);
        creationApi.factPending.shift().resolve({ business_public_id: 'fact-business', value: command.value, versions: { context: 3, intent: 3 } }); await pending; await flushFacts();
        surface.value = { ...surface.value, business: { ...surface.value.business, current_intent: command.value, versions: { ...surface.value.business.versions, intent: 3 } } }; await flushFacts();
        await checkFact(root, 'data-fact-intent-clear');
        assert.equal(find(root, 'id', 'bf-intent-direction'), undefined); assert.equal(all(root).filter(node => 'data-fact-confirm' in node.props).length, 1);
        await submit(); await flushFacts(); assert.equal(creationApi.factCalls.length, 1, 'Clearing still requires the declaration confirmation');
        await checkFact(root, 'data-fact-confirm', 'declaration'); const clearing = submit(); await flushFacts();
        assert.equal(creationApi.factCalls[1].command.value, null); assert.equal(creationApi.factCalls[1].command.confirmations.declaration, true);
        creationApi.factPending.shift().resolve({ business_public_id: 'fact-business', value: null, versions: { context: 3, intent: 4 } }); await clearing; await flushFacts();
        assert.equal(find(root, 'data-fact-saved').props.role, 'status');
    } finally { app.unmount(); restore(); locale.value = 'ar'; drafts.factDrafts.clear(); }
});


test('original commercial history is read-only, has no inferred Business and conceals after resource clear', async () => {
    const state = await import(historyStateUrl);
    state.commercialHistory.value = { data: { workspace: { public_id: 'workspace-one', name: 'Original historical record' }, kind: 'context', read_only: true, current_business_inferred: false, head: {}, items: [{ id: 7, version: 2, occurred_at: '2026-10-01T12:00:00Z', new_name: 'Historic commercial name' }], next_after: null } };
    const app = createSSRApp(historyPage.value); app.use(await routerAt('/workspaces/workspace-one/commercial-history'));
    const html = await renderToString(app);
    assert.match(html, /Original historical record|Historic commercial name/); assert.match(html, /للقراءة/);
    assert.doesNotMatch(html, /<(?:form|input|textarea|select)\b/); assert.doesNotMatch(html, /href="\/businesses\/workspace-one/);
    state.commercialHistory.value = undefined;
    const cleared = createSSRApp(historyPage.value); cleared.use(await routerAt('/workspaces/workspace-one/commercial-history'));
    assert.doesNotMatch(await renderToString(cleared), /Historic commercial name|data-commercial-history/);
});
test('canonical Business sources and purposes are discoverable without inferring a Workspace association and retain local drafts', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    presentation.workspacePresentation.clear(); locale.value = 'ar';
    const root = element('root'), selected = ref(businessSurface('Business-source-A'));
    const app = renderer.createApp({ render: () => h(workspace.value, { key: selected.value.business.business_public_id, surface: selected.value }) });
    app.use(await routerAt('/businesses/Business-source-A')); app.mount(root);
    try {
        assert.doesNotMatch(text(root), /إدارة المساحة/);
        assert.equal(find(root, 'data-test-data-use'), undefined);
        const sourceNavigation = find(root, 'data-workspace-section', 'sources'); assert.ok(sourceNavigation); assert.match(text(sourceNavigation), /المصادر والحسابات الإعلانية/);
        find(root, 'data-business-source-task').props.onClick(); await nextTick();
        assert.notEqual(find(root, 'data-workspace-panel', 'sources').style.display, 'none');
        const panel = find(root, 'data-test-data-use'), input = find(root, 'data-use-draft');
        assert.equal(panel.props['data-test-data-use'], 'Business-source-A');
        assert.equal(all(root).filter(node => 'data-test-data-use' in node.props).length, 1, 'One canonical scope editor');
        input.props.onInput({ target: { value: 'Unsent exact scope selection' } }); await nextTick();
        assert.equal(find(root, 'data-test-business-sources').props['data-test-business-sources'], 'Business-source-A');
        assert.ok(all(root).some(node => node.tag === 'a' && node.props.href === '/source-rights'));
        assert.equal(all(root).filter(node => 'data-business-platform-open' in node.props).length, 0);
        assert.equal(find(root, 'data-business-source-advanced').props.open, undefined, 'Advanced rights are collapsed by default');
        assert.equal(all(root).filter(node => node.tag === 'a' && /\/workspaces\//.test(node.props.href ?? '')).length, 0, 'No workspace inferred from Business identity');
        find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-data-use'), panel, 'Existing data-use editor remains mounted');
        find(root, 'data-workspace-settings', 'purposes').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-business-purpose').props['data-test-business-purpose'], 'Business-source-A');
        find(root, 'data-workspace-section', 'sources').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-use-draft'), input); assert.equal(input.props.value, 'Unsent exact scope selection');
        locale.value = 'en'; await nextTick(); assert.match(text(root), /Sources and ad accounts/);
        selected.value = businessSurface('Business-source-B'); await nextTick();
        assert.equal(find(root, 'data-test-data-use'), undefined); assert.equal(find(root, 'data-test-business-purpose'), undefined);
    } finally { app.unmount(); globalThis.window = previousWindow; locale.value = 'ar'; presentation.workspacePresentation.clear(); }
});

test('Business source inventory discards a prior Business response and shows only current qualified sources', async () => {
    const inventoryApi = moduleUrl('export const calls=[];export const pending=[];export const businessSources=(business,access,after)=>{calls.push({business,access,after});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
    const { calls, pending } = await import(inventoryApi);
    const inventory = await component('resources/js/business-foundation/BusinessSources.vue', { './api': inventoryApi });
    const linked = { link_ref: 'link-a', workspace_public_id: 'workspace-a', workspace_slug: 'Agency tools', state: 'active' };
    const a = { ...businessSurface('business-a'), authority_selection: { requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] }, collaboration: { relationships: [linked] } };
    const b = { ...businessSurface('business-b'), authority_selection: { requirements: [{ requirement_kind: 'business', leaf_grant_id: 52 }] }, collaboration: { relationships: [] } };
    const selected = ref(a), root = element('root');
    const app = renderer.createApp({ render: () => h(inventory.value, { surface: selected.value, active: true }) });
    app.use(await routerAt('/businesses/business-a')); app.mount(root);
    try {
        assert.deepEqual(calls[0], { business: 'business-a', access: 51, after: undefined });
        selected.value = b; await nextTick();
        pending[0].resolve({ business_public_id: 'business-a', scope: 'current_operator_in_linked_workspaces', inventory_kind:'manageable_sources',establishes_business_data_use:false,next_cursor: null,
            sources: [{ source_id: 'source-a', label: 'Agency secret account', family: 'meta', namespace: 'ad_account',
                workspace_public_id: 'workspace-a', workspace_slug: 'Agency tools', resource: 'account', operation: 'report.read', purpose: 'analysis', status: 'UNVERIFIED', access_grant_id: '41' }] });
        await nextTick(); await nextTick();
        assert.doesNotMatch(text(root), /Agency secret account/);
        assert.deepEqual(calls[1], { business: 'business-b', access: 52, after: undefined });
        pending[1].resolve({ business_public_id: 'business-b', scope: 'current_operator_in_linked_workspaces', inventory_kind:'manageable_sources',establishes_business_data_use:false,next_cursor: null, sources: [] });
        await nextTick(); await nextTick();
        assert.ok(find(root, 'data-business-sources-empty'));
        assert.doesNotMatch(text(root), /Agency secret account|Agency tools/);
    } finally { app.unmount(); }
});

test('independent Business team opt-in preserves an unknown enrollment, exact target and explicit retry', async () => {
    const teamApi = moduleUrl('export const calls=[],pending=[];export const enrollDirectTeam=(business,body)=>{calls.push({business,body:JSON.parse(JSON.stringify(body))});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
    const Team = await component('resources/js/business-foundation/DirectBusinessTeam.vue', { './api': teamApi });
    const api = await import(teamApi), selected = { ...businessSurface('independent-team'), direct_team_enrollment_available: true };
    selected.authority_selection = { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] };
    const root = element('root'), refreshed = [], app = renderer.createApp(Team.value, { surface: selected, refreshing: false, onRefresh: () => refreshed.push(true) });
    app.mount(root);
    const button = () => all(root).find(node => node.tag === 'button');
    try {
        assert.ok(find(root, 'data-business-direct-team')); assert.equal(button().props.disabled, true);
        assert.match(text(root), /دون إنشاء مساحة عمل/);
        const draft = drafts.directTeamDrafts.get(drafts.key('independent-team', selected.authority_selection));
        draft.confirmed = true; await nextTick();
        const first = button().props.onClick(); await nextTick();
        const original = structuredClone(api.calls[0]);
        assert.equal(original.business, 'independent-team'); assert.ok(original.body.command_id);
        api.pending.shift().reject(Error('Lost enrollment acknowledgement')); await first; await nextTick();
        assert.match(text(root), /لم نتأكد من النتيجة/); assert.equal(api.calls.length, 1);
        const retry = button().props.onClick(); await nextTick(); assert.deepEqual(api.calls[1], original);
        api.pending.shift().resolve({ business_public_id: 'independent-team', grant_refs: { 'business.context.read': '91' } }); await retry; await nextTick();
        assert.equal(refreshed.length, 1); assert.equal(draft.pending, undefined);
        assert.equal(button().props.disabled, true, 'A receipt does not authorize automatic reenrollment');
    } finally { app.unmount(); drafts.directTeamDrafts.clear(); }
});

test('organizational employee UI preserves the exact membership command through a lost acknowledgement', async () => {
    const restore = withFactDom();
    const teamApi = moduleUrl(`export const drafts=new Map(),teamDrafts=drafts,teamKey=id=>id,calls=[],pending=[];export const value={workspace_public_id:'org-one',actor_public_id:'owner-one',revision:0,can_manage:true,can_leave:false,members:[{public_id:'owner-one',role:'owner',state:'active'}]};export const readTeam=async()=>structuredClone(value);export const writeTeam=(organization,body)=>{calls.push({organization,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
    const Team = await component('resources/js/workspaces/OrganizationTeam.vue', { './team': teamApi });
    const api = await import(teamApi), root = element('root'), app = renderer.createApp(Team.value, { workspace: 'org-one' });
    app.use(await routerAt('/workspaces/org-one')); app.mount(root); await flushFacts();
    try {
        assert.ok(find(root, 'data-organization-members')); assert.match(text(root), /العضوية لا تمنح حقًا تجاريًا/);
        const draft = api.teamDrafts.get('org-one'); draft.recipient = 'staff-one'; draft.confirmed = true; await nextTick();
        const first = all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} }); await nextTick();
        const original = structuredClone(api.calls[0]); assert.equal(original.organization, 'org-one'); assert.equal(original.body.expected_revision, 0);
        api.pending.shift().reject(Error('Committed membership ACK lost')); await first; await nextTick();
        assert.ok(find(root, 'data-organization-retry')); assert.equal(api.calls.length, 1);
        draft.recipient = 'another-user';
        const retry = find(root, 'data-organization-retry').props.onClick(); await nextTick(); assert.deepEqual(api.calls[1], original);
        api.value.revision = 1; api.value.members.push({ public_id: 'staff-one', role: 'member', state: 'active' });
        api.pending.shift().resolve({ workspace_public_id: 'org-one', recipient_public_id: 'staff-one', state: 'active', versions: { team: 1 } }); await retry; await flushFacts();
        assert.equal(draft.pending, undefined); assert.match(text(root), /staff-one/); assert.equal(find(root, 'data-organization-retry'), undefined);
    } finally { app.unmount(); api.teamDrafts.clear(); restore(); }
});

test('ordinary organizational members see only their membership and can leave through an exact self command', async () => {
    const teamApi = moduleUrl(`export const teamDrafts=new Map(),teamKey=id=>id,calls=[];export const readTeam=async()=>({workspace_public_id:'org-two',actor_public_id:'staff-two',revision:4,can_manage:false,can_leave:true,members:[{public_id:'staff-two',role:'member',state:'active'}]});export const writeTeam=async(organization,body)=>{calls.push({organization,body:structuredClone(body)});return {workspace_public_id:organization,recipient_public_id:body.recipient_public_id,state:'revoked',versions:{team:5}}};`);
    const Team = await component('resources/js/workspaces/OrganizationTeam.vue', { './team': teamApi });
    const api = await import(teamApi), root = element('root'), app = renderer.createApp(Team.value, { workspace: 'org-two' }), router = await routerAt('/workspaces/org-two');
    app.use(router); app.mount(root); await flushFacts();
    try {
        assert.equal(all(root).find(node => node.tag === 'form'), undefined);
        api.teamDrafts.get('org-two').confirmed = true; await nextTick();
        const leave = all(root).find(node => node.tag === 'button' && /مغادرة الجهة/.test(text(node)));
        await leave.props.onClick(); await flushFacts();
        assert.equal(router.currentRoute.value.path, '/workspaces'); assert.equal(api.calls.length, 1);
        assert.equal(api.calls[0].body.effect, 'leave'); assert.equal(api.calls[0].body.recipient_public_id, 'staff-two');
        assert.equal(api.calls[0].body.expected_revision, 4); assert.equal(find(root, 'data-organization-members'), undefined);
        assert.ok((await import(presentationUrl)).concealCalls > 0, 'Known membership surrender conceals the departed private resource before navigation');
    } finally { app.unmount(); api.teamDrafts.clear(); }
});


test('own Purpose opt-in requires explicit confirmation and reconciles one frozen statement-only command after lost ACK', async () => {
    const policyApi = moduleUrl('export const calls=[],pending=[];export const enrollPurposeStatement=(business,body)=>{calls.push({business,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
    const Policy = await component('resources/js/business-foundation/PurposeStatementEnrollment.vue', { './api': policyApi });
    const api = await import(policyApi), root = element('root');
    const surface = businessSurface('purpose-business');
    surface.purpose_statement = { authority_scope:'own_business_statement_only', establishes_external_authority:false, enrollment_available:true, previous_grant:'9007199254740992', current_grant:null };
    const app = renderer.createApp(Policy.value, { surface, refreshing:false }); app.use(await routerAt('/businesses/purpose-business')); app.mount(root);
    try {
        assert.match(text(root), /لا يثبت ملكية حملة أو عنصر إعلاني/);
        assert.equal(find(root, 'data-purpose-enrollment-submit').props.disabled,true);
        find(root, 'data-purpose-enrollment-confirm').props['onUpdate:modelValue'](true); await nextTick();
        const first = find(root,'data-purpose-enrollment-submit').props.onClick(); await nextTick();
        const original = structuredClone(api.calls[0]);
        assert.equal(original.body.previous_grant,'9007199254740992'); assert.equal(original.body.confirmed,true);
        api.pending.shift().reject(Error('Committed enrollment ACK lost')); await first; await nextTick();
        assert.equal(api.calls.length,1); assert.ok(find(root,'data-purpose-enrollment-retry'));
        surface.purpose_statement.previous_grant='999';
        const retry = find(root,'data-purpose-enrollment-retry').props.onClick(); await nextTick(); assert.deepEqual(api.calls[1],original);
        api.pending.shift().resolve({business_public_id:'purpose-business',grant_refs:{'business.purpose.declare':'44'},authority_scope:'own_business_statement_only',establishes_external_authority:false});
        await retry; await nextTick(); assert.equal(find(root,'data-purpose-enrollment-retry'),undefined);
        assert.match(text(root),/لم تُمنح صلاحية على أي عنصر أو مصدر خارجي/);
    } finally { app.unmount(); drafts.purposeEnrollmentDrafts.clear(); }
});

test('own Purpose surrender uses the existing grant command and retains an unknown result after its authority disappears', async () => {
    const port = moduleUrl('export const calls=[],pending=[];export const writeGrant=(business,body)=>{calls.push({business,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
    const Surrender = await component('resources/js/business-foundation/BusinessDelegation.vue', { './api': port });
    const api = await import(port), root = element('root');
    const initial = businessSurface('own-surrender');
    initial.authority_selection = {realm_kind:'direct',realm_workspace_public_id:null,requirements:[{requirement_kind:'business',leaf_grant_id:2}]};
    initial.purpose_statement = {current_grant:{id:'19',version:1}};
    initial.delegation = {actor_public_id:'00000000-0000-4000-8000-000000000001',options:[],issued:[],options_may_be_partial:false,issued_more:false};
    const selected = ref(initial), app = renderer.createApp({render:()=>h(Surrender.value,{surface:selected.value})});
    app.use(await routerAt('/businesses/own-surrender')); app.mount(root);
    try {
        assert.equal(find(root,'data-purpose-surrender').props.disabled,true);
        find(root,'data-purpose-surrender-confirm').props['onUpdate:modelValue'](true); await nextTick();
        find(root,'data-purpose-surrender').props.onClick(); await nextTick();
        const original = structuredClone(api.calls[0]);
        assert.equal(original.body.action,'business.purpose.declare'); assert.equal(original.body.effect,'revoke');
        assert.equal(original.body.grant_id,19); assert.equal(original.body.parent_grant_id,null);
        assert.equal(original.body.grantee_public_id,initial.delegation.actor_public_id);
        assert.deepEqual(original.body.authority_selection.requirements,[{requirement_kind:'business',leaf_grant_id:19}]);
        api.pending.shift().reject(Error('Surrender ACK lost')); await nextTick(); await nextTick();
        assert.equal(api.calls.length,1);
        find(root,'data-purpose-surrender-retry').props.onClick(); await nextTick(); assert.deepEqual(api.calls[1],original);
        const { TransportError } = await import(transportUrl); const denial = new TransportError('Right no longer qualifies'); denial.status=403;
        api.pending.shift().reject(denial); await nextTick(); await nextTick();
        selected.value = {...initial,purpose_statement:{current_grant:null}}; await nextTick();
        const pending = [...drafts.delegationDrafts.values()].find(row=>row.pending?.grant_id===19);
        assert.deepEqual(pending.pending,original.body); assert.equal(pending.blocked,true);
        assert.ok(find(root,'data-purpose-surrender-unresolved')); assert.equal(find(root,'data-purpose-surrender'),undefined);
        assert.equal(find(root,'data-purpose-surrender-retry').props.disabled,true); assert.equal(api.calls.length,2);
    } finally { app.unmount(); drafts.delegationDrafts.clear(); }
});

test('mobile previous-record disclosure does not close the outer sidebar while destination clicks still do', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };
    const root = element('root'), app = renderer.createApp({ render: () => h(layout.value, { workspaceId: 'workspace-mobile', context: 'Workspace M', workspaceContext: true }) });
    app.use(await routerAt('/workspaces/workspace-mobile')); app.mount(root);
    try {
        const outer = all(root).find(node => node.tag === 'details' && node.props.class === 'advertiser-sidebar');
        outer.open = true;
        const group = find(root, 'data-workspace-previous-navigation');
        const summary = group.children.find(node => node.tag === 'summary');
        const body = all(root).find(node => node.props.class === 'advertiser-sidebar-body');
        const event = { stopped: false, stopPropagation() { this.stopped = true; } };
        summary.props.onClick?.(event);
        if (!event.stopped) body.props.onClick(event);
        assert.equal(event.stopped, true, 'Disclosure click must not reach the close-on-destination ancestor');
        assert.equal(outer.open, true, 'Opening previous records leaves mobile navigation accessible');
        body.props.onClick({}); assert.equal(outer.open, false, 'A destination click retains existing mobile close behavior');
    } finally { app.unmount(); globalThis.window = previousWindow; }
});


test('platform card journey retains only a scalar allowlisted provider through an explicit admitted Workspace choice', async () => {
    const { advertisingPlatforms, selectedAdvertisingPlatform, initialAdvertisingPlatform } = await import(providerDestinationUrl);
    for (const bad of [undefined, null, ['google'], {provider:'google'}, 'GOOGLE', 'unknown', 'google&workspace=foreign']) assert.equal(selectedAdvertisingPlatform(bad), undefined);
    assert.equal(initialAdvertisingPlatform('google', '#meta-flow=callback'), 'meta');
    assert.equal(initialAdvertisingPlatform('google', '#snapchat-result=callback'), 'snapchat');
    assert.equal(initialAdvertisingPlatform('google', '#unrelated=value'), 'google');
    const ws = await import(workspacesStateUrl);
    ws.current.value = { data: { workspaces: [{public_id:'allowed-one',name:'Allowed one'},{public_id:'allowed-two',name:'Allowed two'}], reassessableWorkspaces:[], canCreateWorkspace:false } };
    for (const platform of advertisingPlatforms) {
        assert.equal(initialAdvertisingPlatform(platform.id, ''), platform.id);
        const app = createSSRApp(chooser.value); app.use(await routerAt('/workspaces?provider='+platform.id));
        const html = await renderToString(app);
        assert.match(html, /data-workspace-platform-selection/); assert.ok(html.includes(platform.label));
        assert.ok(html.includes('href="/workspaces/allowed-one?provider='+platform.id+'"'));
        assert.ok(html.includes('href="/workspaces/allowed-two?provider='+platform.id+'"'));
        assert.doesNotMatch(html, /href="\/workspaces\/foreign|data-workspaces-add/);
        assert.match(html, /لا تُختار مساحة تلقائيًا/);
    }
    for (const query of ['?provider=unknown','?provider=meta&provider=google']) {
        const app = createSSRApp(chooser.value); app.use(await routerAt('/workspaces'+query));
        const html = await renderToString(app); assert.doesNotMatch(html, /data-workspace-platform-selection/);
        assert.ok(html.includes('href="/workspaces/allowed-one"'));
    }
});


test('admitted Sources consumes the selected platform and lets OAuth callback identity win without issuing commands', async () => {
    const previousWindow=globalThis.window, previousLocation=globalThis.location;
    try {
        for (const [query, hash, expected] of [['google','','Google Ads'],['x','#meta-flow=callback','Meta Ads'],['meta','#snapchat-result=callback','Snapchat Ads']]) {
            globalThis.window={location:{hash}}; globalThis.location={hash,pathname:'/workspaces/admitted-one',search:'?provider='+query};
            const root=element('root'), app=renderer.createApp({render:()=>h(operationalSources.value,{workspace:{public_id:'admitted-one',name:'Admitted',slug:'a'},business:{type:null,version:0,canManage:false,targetMarketsDeclared:false,targetMarkets:[]},guidance:{sources:[],priors:[]},canRequestSourceAuthority:false,canManageServices:false,sourceCandidateId:'candidate',sources:[],services:[],commercial:{state:'UNAVAILABLE',context:null,agreement_state:'UNKNOWN',amount_state:'UNKNOWN'}})});
            app.use(await routerAt('/workspaces/admitted-one?provider='+query+hash)); app.mount(root); await nextTick();
            const dialog=all(root).find(node=>node.tag==='dialog');
            assert.equal(dialog.open,true); assert.equal(dialog.modalCount,1); assert.equal(dialog.props['aria-label'],expected);
            app.unmount();
        }
    } finally {globalThis.window=previousWindow;globalThis.location=previousLocation;}
});


test('fresh actual data-use draft publishes pending true and false to the collapsed parent reconcile affordance', async () => {
    const dataUseApi = moduleUrl('export const dataUseChoices=async()=>({scopes:[],next_cursor:null});export const writeBinding=async()=>{throw Error("No command expected")};export const writeResourceRight=async()=>{throw Error("No command expected")};');
    const actualDataUse = await component('resources/js/business-foundation/BusinessDataUse.vue', {'./api':dataUseApi});
    const actualWorkspace = await component('resources/js/business-foundation/BusinessWorkspace.vue', {'./AdvertiserWorkspaceLayout.vue':layout.url,'../inquiry-evidence/InquiryEvidencePanel.vue':inquiryUrl,...childPorts,'../workspaces/OrganizationTeam.vue':plainUrl,'./DirectBusinessTeam.vue':plainUrl,'./BusinessDataUse.vue':actualDataUse.url,'./BusinessPurposes.vue':purposePanelUrl});
    const previousWindow=globalThis.window;
    globalThis.window={scrollY:0,scrollTo(){},matchMedia:()=>({matches:true,addEventListener(){},removeEventListener(){}})};
    presentation.workspacePresentation.clear();
    const selected={...businessSurface('fresh-pending-business'),collaboration:{options:[{grant_id:'1'}]},data_use:{bindings:[],bindings_more:false}};
    const cacheKey=drafts.key(selected.business.business_public_id,selected.authority_selection)+':data-use:1';
    drafts.dataUseDrafts.delete(cacheKey);
    const root=element('root'),app=renderer.createApp({render:()=>h(actualWorkspace.value,{surface:selected,refreshing:false})});
    app.use(await routerAt('/businesses/fresh-pending-business'));app.mount(root);
    try {
        find(root,'data-business-source-task').props.onClick();await nextTick();await nextTick();
        const advanced=find(root,'data-business-source-advanced');
        assert.equal(advanced.open,false);assert.equal(find(root,'data-business-source-pending'),undefined);
        const freshDraft=drafts.dataUseDrafts.get(cacheKey);assert.ok(freshDraft);assert.equal(freshDraft.pending,undefined);
        freshDraft.pending={kind:'binding',business:'fresh-pending-business',body:{command_id:'exact-pending-command',data_use_scope_id:'scope-one',effect:'add',expected_relation:0,authority_selection:selected.authority_selection}};
        await nextTick();
        const notice=find(root,'data-business-source-pending');assert.ok(notice);assert.equal(advanced.open,false,'Pending notice remains outside the collapsed editor');
        assert.equal(all(advanced).includes(notice),false);
        all(notice).find(node=>node.tag==='button').props.onClick();assert.equal(advanced.open,true);
        assert.ok(find(root,'data-data-use-reconcile'),'The original pending command action is reachable');
        freshDraft.pending=undefined;await nextTick();
        assert.equal(find(root,'data-business-source-pending'),undefined);assert.equal(find(root,'data-data-use-reconcile'),undefined);
        assert.equal(advanced.open,true,'Resolving does not unexpectedly close the user-opened settings');
    } finally {app.unmount();globalThis.window=previousWindow;drafts.dataUseDrafts.delete(cacheKey);presentation.workspacePresentation.clear();}
});

test('internal file team opt-in retains an unknown command and checks the same original body explicitly', async () => {
    const custodyApi = moduleUrl(`export const writes=[],pending=[];export const internalTeamSurface=async(business)=>({business_public_id:business,creator_basis:true,can_admin:true,teams:[]});export const writeInternalTeam=(business,body)=>{writes.push({business,body:JSON.parse(JSON.stringify(body))});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
    const custody = await component('resources/js/business-foundation/InternalClientFileTeam.vue', {'./api':custodyApi});
    const {writes,pending}=await import(custodyApi);
    const business='01a1a326-5588-75c2-b5b3-392a12242d37',team='01a1a326-5588-75c2-b5b3-392a12242d38',actor='01a1a326-5588-75c2-b5b3-392a12242d39';
    const selected={...businessSurface(business),authority_selection:{realm_kind:'direct',realm_workspace_public_id:null,requirements:[{requirement_kind:'business',leaf_grant_id:51}]},delegation:{actor_public_id:actor},collaboration:{workspaces:[{public_id:team,slug:'Qualified team'}]}};
    const root=element('root'),app=renderer.createApp({render:()=>h(custody.value,{surface:selected,active:true})});
    app.use(await routerAt(`/businesses/${business}`));app.mount(root);
    const cacheKey=drafts.key(business,selected.authority_selection)+':internal-team:1';
    try {
        await nextTick();await nextTick();
        const draft=drafts.internalTeamDrafts.get(cacheKey);assert.ok(draft);
        draft.workspace=team;await nextTick();draft.confirmed=true;await nextTick();
        all(root).find(node=>node.tag==='button'&&text(node)==='تفعيل إدارة الفريق لهذا الملف').props.onClick();await nextTick();
        assert.equal(writes.length,1);assert.equal(writes[0].body.effect,'enroll');assert.equal(writes[0].body.recipient_public_id,actor);
        pending[0].reject(Error('Lost acknowledgement'));await nextTick();await nextTick();
        assert.ok(find(root,'data-internal-team-pending'));assert.equal(writes.length,1,'No automatic retry');
        all(root).find(node=>node.tag==='button'&&text(node)==='التحقق بالطلب الأصلي').props.onClick();await nextTick();
        assert.deepEqual(writes[1],writes[0]);
        pending[1].resolve({business_public_id:business,workspace_public_id:team,recipient_public_id:actor,link_ref:'link',state:'active',versions:{link:1},grant_refs:{'business.context.read':'99'}});
        await nextTick();await nextTick();
        assert.equal(draft.pending,undefined);assert.ok(find(root,'data-open-internal-team'));
        assert.match(text(root),/السجلات الخاصة/);
    } finally {app.unmount();drafts.internalTeamDrafts.delete(cacheKey);}
});

test('internal team workspace does not mount creator-only inquiries or historical readers', async () => {
    const oldWindow=globalThis.window;globalThis.window={scrollY:0,scrollTo(){},matchMedia:()=>({matches:true,addEventListener(){},removeEventListener(){}})};
    presentation.workspacePresentation.clear();events.length=0;
    const historical=moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {render(){return h('div',{'data-private-history-reader':true})}};`);
    const membership=moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['workspace'],setup(p){return()=>h('section',{'data-own-membership':p.workspace})}};`);
    const internalWorkspace=await component('resources/js/business-foundation/BusinessWorkspace.vue',{'./AdvertiserWorkspaceLayout.vue':layout.url,'../inquiry-evidence/InquiryEvidencePanel.vue':inquiryUrl,...childPorts,'../workspaces/OrganizationTeam.vue':membership,'./DirectBusinessTeam.vue':plainUrl,'./BusinessHistory.vue':historical,'./BusinessLegacyHistory.vue':historical});
    const selected={...businessSurface('internal-private-boundary'),private_history_available:false,authority_selection:{realm_kind:'internal_team',realm_workspace_public_id:'team-one',requirements:[{requirement_kind:'business',leaf_grant_id:51}]}};
    const root=element('root'),app=renderer.createApp({render:()=>h(internalWorkspace.value,{surface:selected})});app.use(await routerAt('/businesses/internal-private-boundary'));app.mount(root);
    try {
        find(root,'data-workspace-section','settings').props.onClick();await nextTick();
        assert.equal(find(root,'data-private-history-reader'),undefined);
        find(root,'data-workspace-settings','data').props.onClick();await nextTick();
        assert.equal(find(root,'data-test-inquiry'),undefined);assert.equal(events.length,0);assert.match(text(root),/صلاحية مستقلة/);
        find(root,'data-workspace-settings','access').props.onClick();await nextTick();
        assert.equal(find(root,'data-own-membership').props['data-own-membership'],'team-one','Internal-file employees can find self-membership without opening a Workspace profile');
    } finally {app.unmount();globalThis.window=oldWindow;presentation.workspacePresentation.clear();}
});

// Compile the real Team component and draft/API adapter; substitute only the HTTP
// and document ports so recovery is exercised through its rendered controls.
const teamTransportUrl = moduleUrl(`export const scope='team-document';export const onClose=()=>{};export const stamp=()=>0;export const owned=()=>true;export class TransportError extends Error{constructor(status){super('Request rejected');this.status=status;}}`);
const teamHttpUrl = moduleUrl(`export const reads=[],writes=[],readPending=[],writePending=[];export const api={GET:(path,options)=>{reads.push({path,options});return new Promise((resolve,reject)=>readPending.push({resolve,reject}));},POST:(path,options)=>{writes.push({path,options:structuredClone(options)});return new Promise((resolve,reject)=>writePending.push({resolve,reject}));}};`);
const teamModuleUrl = moduleUrl(compile(await readFile('resources/js/workspaces/team.ts', 'utf8')).replaceAll("'../spa/transport'", JSON.stringify(teamTransportUrl)).replaceAll("'../spa/api'", JSON.stringify(teamHttpUrl)));
const organizationTeam = await component('resources/js/workspaces/OrganizationTeam.vue', { './team': teamModuleUrl, '../spa/transport': teamTransportUrl });
const teamAdapter = await import(teamModuleUrl), teamHttp = await import(teamHttpUrl), teamTransport = await import(teamTransportUrl);
const settleTeam = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick(); };
const teamSurface = (changes = {}) => ({ workspace_public_id: 'organization-one', actor_public_id: 'owner-one', revision: 4, can_manage: true, can_leave: false, members: [{ public_id: 'owner-one', role: 'owner', state: 'active' }, { public_id: 'employee-one', role: 'member', state: 'active' }], ...changes });
async function mountTeam() {
    const restoreDom = withFactDom();
    teamAdapter.teamDrafts.clear();
    for (const entries of [teamHttp.reads, teamHttp.writes, teamHttp.readPending, teamHttp.writePending]) entries.length = 0;
    const root = element('root'), app = renderer.createApp({ render: () => h(organizationTeam.value, { workspace: 'organization-one' }) });
    app.use(await routerAt('/workspaces/organization-one')); app.mount(root);
    const unmount = app.unmount.bind(app); app.unmount = () => { unmount(); restoreDom(); };
    teamHttp.readPending.shift().resolve({ data: { data: teamSurface() } }); await settleTeam();
    const draft = teamAdapter.teamDrafts.get(teamAdapter.teamKey('organization-one'));
    const submit = () => all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} });
    const confirm = () => find(root, 'data-organization-confirm').props['onUpdate:modelValue'](true);
    const recipient = value => find(root, 'data-organization-recipient').props['onUpdate:modelValue'](value);
    return { root, app, draft, submit, confirm, recipient };
}

test('organization Team 422 releases the rejected command for correction and requires an explicit new submission', async () => {
    const view = await mountTeam();
    try {
        view.recipient('not-a-registered-account'); view.confirm(); await nextTick();
        view.submit(); view.submit();
        assert.equal(teamHttp.writes.length, 1, 'Double submission while waiting issues one command');
        const original = structuredClone(teamHttp.writes[0].options.body);
        teamHttp.writePending.shift().reject(new teamTransport.TransportError(422)); await settleTeam();
        assert.equal(view.draft.pending, undefined, 'A definitive rejection has no unknown command to replay');
        assert.equal(view.draft.confirmed, false); assert.equal(view.draft.blocked, false);
        assert.equal(all(view.root).find(node => node.tag === 'fieldset').props.disabled, false);
        assert.equal(find(view.root, 'data-organization-retry'), undefined);
        assert.equal(teamHttp.writes.length, 1); assert.equal(teamHttp.reads.length, 1);
        view.recipient('registered-employee'); await nextTick(); view.submit();
        assert.equal(teamHttp.writes.length, 1, 'Correcting the account requires fresh confirmation');
        view.confirm(); await nextTick();
        assert.equal(teamHttp.writes.length, 1, 'Editing and confirming never automatically resend');
        view.submit();
        const corrected = teamHttp.writes[1].options.body;
        assert.notEqual(corrected.command_id, original.command_id);
        assert.equal(corrected.recipient_public_id, 'registered-employee'); assert.equal(corrected.expected_revision, 4);
    } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
});

test('organization Team 409 refreshes membership and revision before allowing a new explicit command', async () => {
    const view = await mountTeam();
    try {
        view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
        const original = structuredClone(teamHttp.writes[0].options.body);
        teamHttp.writePending.shift().reject(new teamTransport.TransportError(409)); await settleTeam();
        assert.equal(teamHttp.reads.length, 2, 'A definitive revision conflict initiates a state read');
        assert.equal(teamHttp.writes.length, 1); assert.equal(find(view.root, 'data-organization-recipient'), undefined);
        teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 8, members: [] }) } }); await settleTeam();
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.confirmed, false); assert.equal(view.draft.blocked, false);
        assert.equal(all(view.root).find(node => node.tag === 'fieldset').props.disabled, false);
        assert.doesNotMatch(text(find(view.root, 'data-organization-members')), /employee-one/);
        assert.equal(teamHttp.writes.length, 1, 'A refresh never resends or creates a command');
        view.recipient('another-employee'); view.confirm(); await nextTick(); view.submit();
        const corrected = teamHttp.writes[1].options.body;
        assert.notEqual(corrected.command_id, original.command_id); assert.equal(corrected.expected_revision, 8);
        assert.equal(corrected.recipient_public_id, 'another-employee');
    } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
});

test('organization Team failed conflict refresh stays unavailable until a successful qualified read', async () => {
    const view = await mountTeam();
    try {
        view.recipient('registered-employee'); view.confirm(); await nextTick();
        view.submit(); teamHttp.writePending.shift().reject(new teamTransport.TransportError(409)); await settleTeam();
        assert.equal(teamHttp.reads.length, 2);
        teamHttp.readPending.shift().reject(Error('Read unavailable')); await settleTeam();
        assert.equal(find(view.root, 'data-organization-recipient'), undefined); assert.equal(teamHttp.writes.length, 1);
        const reload = () => all(view.root).find(node => node.tag === 'button' && text(node) === 'إعادة التحقق من الفريق').props.onClick();
        reload();
        teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ workspace_public_id: 'foreign-organization', revision: 99 }) } }); await settleTeam();
        assert.equal(find(view.root, 'data-organization-recipient'), undefined, 'A foreign response cannot unlock editing');
        reload(); teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 9, can_manage: false, can_leave: true }) } }); await settleTeam();
        assert.equal(view.draft.pending, undefined); assert.equal(find(view.root, 'data-organization-recipient'), undefined, 'Refreshed membership has no management right');
        view.draft.confirmed = true; await nextTick();
        assert.equal(teamHttp.writes.length, 1, 'Authority loss does not create or replay an add command');
        const leave = all(view.root).find(node => node.tag === 'button' && text(node) === 'مغادرة الجهة');
        assert.equal(leave.props.disabled, false); leave.props.onClick();
        assert.equal(teamHttp.writes[1].options.body.expected_revision, 9);
        assert.equal(teamHttp.writes[1].options.body.effect, 'leave');
    } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
});

test('organization Team unknown outcomes and mismatched receipts retain only the exact original explicit replay', async t => {
    for (const outcome of ['network', 'missing receipt', 'wrong organization', 'wrong recipient', 'wrong state']) {
        await t.test(outcome, async () => {
            const view = await mountTeam();
            try {
                view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
                const original = structuredClone(teamHttp.writes[0].options.body);
                const request = teamHttp.writePending.shift();
                if (outcome === 'network') request.reject(Error('Acknowledgement lost'));
                else {
                    const receipt = { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' };
                    if (outcome === 'wrong organization') receipt.workspace_public_id = 'foreign-organization';
                    if (outcome === 'wrong recipient') receipt.recipient_public_id = 'other-employee';
                    if (outcome === 'wrong state') receipt.state = 'revoked';
                    request.resolve({ data: outcome === 'missing receipt' ? undefined : { data: receipt } });
                }
                await settleTeam();
                assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original);
                assert.equal(teamAdapter.hasPendingTeamCommand(), true);
                assert.equal(teamHttp.writes.length, 1); assert.equal(teamHttp.reads.length, 1);
                assert.equal(all(view.root).find(node => node.tag === 'fieldset').props.disabled, true);
                assert.equal(find(view.root, 'data-organization-confirm').props.disabled, true);
                // Even a retained draft change cannot alter the replay body.
                view.draft.recipient = 'alternate-employee';
                const retry = find(view.root, 'data-organization-retry');
                retry.props.onClick(); retry.props.onClick(); await nextTick();
                assert.equal(teamHttp.writes.length, 2, 'Explicit replay also suppresses duplicate clicks');
                assert.deepEqual(teamHttp.writes[1].options.body, original);
                teamHttp.writePending.shift().resolve({ data: { data: { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' } } }); await settleTeam();
                assert.equal(view.draft.pending, undefined); assert.equal(teamAdapter.hasPendingTeamCommand(), false);
                assert.equal(teamHttp.writes.length, 2);
                teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 5 }) } }); await settleTeam();
            } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
        });
    }
});

test('organization Team 403 cannot replay or start an alternate command under denied authority', async () => {
    const view = await mountTeam();
    try {
        view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
        const original = structuredClone(teamHttp.writes[0].options.body);
        teamHttp.writePending.shift().reject(new teamTransport.TransportError(403)); await settleTeam();
        assert.equal(view.draft.blocked, true); assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original);
        const retry = find(view.root, 'data-organization-retry'); assert.equal(retry.props.disabled, true);
        retry.props.onClick(); await settleTeam(); assert.equal(teamHttp.writes.length, 1);
        assert.equal(teamHttp.reads.length, 1, 'Denial does not infer renewed authority');
    } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
});
