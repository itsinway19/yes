import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, createSSRApp, h, markRaw, nextTick, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';

const moduleUrl = text => 'data:text/javascript;base64,' + Buffer.from(text).toString('base64');
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const vue = import.meta.resolve('vue'), vueRouter = import.meta.resolve('vue-router');
const languageUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('ar');`);
const { locale } = await import(languageUrl);
const transportUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const scope='document-one';export const documentStatus=ref('ready');export let generation=0;export const advance=()=>generation++;export const callbacks=[];export const onClose=f=>callbacks.push(f);export const stamp=()=>generation;export const owned=captured=>captured===generation;export class TransportError extends Error{}`);
let headerHost;
globalThis.HTMLElement ??= class HTMLElement {};
globalThis.document = { getElementById: id => id === 'advertiser-header-context' ? headerHost : null, body: {style:{}}, addEventListener() {}, removeEventListener() {} };
const transport = await import(transportUrl);
const presentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const status=ref('ready');export let concealCalls=0;export const conceal=()=>{concealCalls++;};`);
const stateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();export const load=async()=>{};export const clear=()=>{current.value=undefined;};`);
const { current } = await import(stateUrl);
const iconsUrl = moduleUrl(`export const Building2={render:()=>null};export const ChevronLeft=Building2,ChevronDown=Building2,Layers3=Building2,Link2=Building2,ShieldCheck=Building2,ArrowLeft=Building2,ArrowRight=Building2,Check=Building2,ClipboardList=Building2,Globe2=Building2,MessageCircle=Building2,Plus=Building2,FileText=Building2,House=Building2,Megaphone=Building2,Settings2=Building2,Target=Building2,Users=Building2,Menu=Building2,X=Building2,
BriefcaseBusiness=Building2,CircleDollarSign=Building2,Clock3=Building2,Factory=Building2,Gauge=Building2,Hammer=Building2,Handshake=Building2,KeyRound=Building2,MapPin=Building2,Package=Building2,Phone=Building2,ShoppingBag=Building2,Stethoscope=Building2,Store=Building2,TrendingUp=Building2,Utensils=Building2,Wrench=Building2;`);
const plainUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {render(){return h('div',{'data-existing-editor':true})}};`);
let businessTaskRowUrl = plainUrl;
const apiUrl = moduleUrl(`
export const businessSurface=async()=>{throw Error("Unexpected surface read")};
export const businessChoices=async()=>({items:[],next_cursor:null,scope:{principal_generation:1,authority_fingerprint:"qualified"}});
export const calls=[], pending=[];
const createObservers=[];
export const nextCreate=()=>new Promise(resolve=>createObservers.push(resolve));
export const create=command=>new Promise((resolve,reject)=>{
    calls.push(JSON.parse(JSON.stringify(command)));
    pending.push({resolve,reject});
    for (const observe of createObservers.splice(0)) observe();
});
export const factCalls=[], factPending=[];
export const writeFact=(business,command)=>{
    factCalls.push({business,command:JSON.parse(JSON.stringify(command))});
    return new Promise((resolve,reject)=>factPending.push({resolve,reject}));
};
`);
const dataLicenseJourneyUrl = moduleUrl('export const hasPendingDataLicense=()=>false;');
const creationApi = await import(apiUrl);
async function scriptModule(file, ports = {}) {
    let source = compile(await readFile(file, 'utf8'));
    for (const [name, target] of Object.entries({vue, '../spa/transport':transportUrl, './api':apiUrl, './dataLicenseJourney':dataLicenseJourneyUrl, ...ports})) source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(target)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(target)}`);
    return moduleUrl(source);
}
const choicesUrl = await scriptModule('resources/js/business-foundation/businessChoices.ts');
const providerDestinationUrl = await scriptModule('resources/js/sources/providerDestination.ts');
const providerBrandUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['provider'],setup(p){return()=>h('span',{'data-provider-brand':p.provider})}};`);
const navigationUrl = await scriptModule('resources/js/business-foundation/advertiserNavigation.ts');
const workspacePresentationUrl = await scriptModule('resources/js/business-foundation/workspacePresentation.ts');
const draftsUrl = await scriptModule('resources/js/business-foundation/drafts.ts');
const businessWizardUrl = await scriptModule('resources/js/business-foundation/businessWizard.ts');
const setupActivityUrl = moduleUrl('export const practiceId=()=>crypto.randomUUID();export const calls=[], pending=[];export const writePractice=(business,command)=>{calls.push({business,command:JSON.parse(JSON.stringify(command))});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
const businessSetupUrl = await scriptModule('resources/js/business-foundation/businessSetup.ts', { './businessWizard': businessWizardUrl, './activity': setupActivityUrl });
const { emptyWizardAnswers, storeRegistrationBusiness } = await import(businessWizardUrl);
const { setupKey, writeSetup, readSetup } = await import(businessSetupUrl);
const setupActivity = await import(setupActivityUrl);
const presentation = await import(workspacePresentationUrl);
const drafts = await import(draftsUrl);
const purposeCommandsUrl = await scriptModule('resources/js/object-purpose/commands.ts', { '../spa/presentation': presentationUrl, './api': moduleUrl('export const writePurpose=async()=>{throw Error("Use explicit test writer")};'), './state': moduleUrl('export const read=async()=>({});') });
const purposeCommands = await import(purposeCommandsUrl);
const inquiryEditorUrl = await scriptModule('resources/js/inquiry-evidence/editor.ts');
const inquiryEditor = await import(inquiryEditorUrl);
const nativePendingUrl = moduleUrl(`import {shallowReactive} from ${JSON.stringify(vue)};export const pending=shallowReactive(new Map());export const commandsFor=owner=>[...pending.values()].filter(item=>item.command.business===owner.publicId&&owner.kind==='business');`);
const nativePending = await import(nativePendingUrl);
const activityPendingUrl = await scriptModule('resources/js/business-foundation/activityPending.ts');
const businessPendingUrl = await scriptModule('resources/js/business-foundation/businessPending.ts', { './activityPending':activityPendingUrl, './drafts': draftsUrl, '../object-purpose/commands': purposeCommandsUrl, '../inquiry-evidence/editor': inquiryEditorUrl, '../sources/commands': nativePendingUrl });
const businessPending = await import(businessPendingUrl);
async function component(file, ports = {}) {
    const { descriptor, errors } = parse(await readFile(file, 'utf8'), { filename: file });
    assert.deepEqual(errors.map(String), [], `${file}: SFC parsing must succeed`);
    if (descriptor.template) {
        const template = compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { expressionPlugins: ['typescript'] } });
        assert.deepEqual(template.errors.map(String), [], `${file}: template compilation must succeed`);
    }
    let source = compile(compileScript(descriptor, { id: file, inlineTemplate: true }).content).replace(/import ['"][^'"]+\.css['"];?/g, '');
    const imports = { '../sources/AdvertisingAccounts.vue': plainUrl, '../workspaces/api': moduleUrl('export const readWorkspaces=async()=>({data:{workspaces:[],reassessableWorkspaces:[],canCreateWorkspace:false}});'), '../sources/api':moduleUrl('export const sources={read:async()=>{throw Error("Unexpected source read")}};'), '../sources/businessReturn':moduleUrl('export const rememberBusinessReturn=()=>{};'), '../sources/commands':nativePendingUrl, vue, 'vue-router': vueRouter, '@lucide/vue': iconsUrl, '../spa/locale': languageUrl, '../spa/transport': transportUrl, '../spa/presentation': presentationUrl, './state': stateUrl, './api': apiUrl, './drafts': draftsUrl, './advertiserNavigation': navigationUrl, './businessChoices': choicesUrl, './businessPending':businessPendingUrl, './workspacePresentation': workspacePresentationUrl, '../sources/providerDestination': providerDestinationUrl, './providerDestination': providerDestinationUrl, '../Components/ProviderBrand.vue': providerBrandUrl, ...ports };
    for (const [name, target] of Object.entries({ './BusinessTaskRow.vue':businessTaskRowUrl, './activityPending':activityPendingUrl, './BusinessPractices.vue':plainUrl, './BusinessDataLicenses.vue':plainUrl, './dataLicenseJourney':dataLicenseJourneyUrl, ...imports })) source = source.replaceAll(`from '${name}'`, `from ${JSON.stringify(target)}`).replaceAll(`from "${name}"`, `from ${JSON.stringify(target)}`);
    const url = moduleUrl(source); return { url, value: (await import(url)).default };
}
businessTaskRowUrl = (await component('resources/js/business-foundation/BusinessTaskRow.vue')).url;
const brandLogo = await component('resources/js/Components/BrandLogo.vue');
const switcher = await component('resources/js/business-foundation/BusinessSwitcher.vue');
const layout = await component('resources/js/business-foundation/AdvertiserWorkspaceLayout.vue', { '../Components/BrandLogo.vue': brandLogo.url, './BusinessSwitcher.vue': switcher.url });
const inquiryUrl = moduleUrl(`import {defineComponent,h,ref,onMounted,onBeforeUnmount} from ${JSON.stringify(vue)};export const events=[];export default defineComponent({props:['active','business'],setup(p){const draft=ref('');onMounted(()=>events.push(['mount',p.business]));onBeforeUnmount(()=>events.push(['unmount',p.business]));return()=>h('section',{'data-test-inquiry':p.business,'data-active':p.active},[h('input',{'data-draft':true,value:draft.value,onInput:e=>draft.value=e.target.value})]);}});`);
const { events } = await import(inquiryUrl);
const childPorts = Object.fromEntries(['BusinessEditor', 'BusinessLifecycle', 'BusinessFacts', 'BusinessCollaboration', 'BusinessDelegation', 'BusinessAgencyTeam', 'BusinessTeamMembers', 'BusinessMandates', 'BusinessDataUse', 'BusinessPurposes', 'BusinessHistory', 'BusinessLegacyHistory'].map(name => [`./${name}.vue`, plainUrl]));
childPorts['./InternalClientFileTeam.vue'] = plainUrl;
const businessSourcesPanelUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){return()=>h('section',{'data-test-business-sources':p.surface.business.business_public_id},'Current scoped sources')}};`);
childPorts['./BusinessSources.vue'] = businessSourcesPanelUrl;
childPorts['./activity'] = moduleUrl('export const readPractices=async business=>({business_public_id:business,practices:[]});');
const dataUsePanelUrl = moduleUrl(`import {h,ref} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){const draft=ref('');return()=>h('section',{'data-test-data-use':p.surface.business.business_public_id},[h('input',{'data-use-draft':true,value:draft.value,onInput:e=>draft.value=e.target.value})]);}};`);
const purposePanelUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['surface'],setup(p){return()=>h('section',{'data-test-business-purpose':p.surface.business.business_public_id},'Canonical Business purposes');}};`);
const workspace = await component('resources/js/business-foundation/BusinessWorkspace.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, '../inquiry-evidence/InquiryEvidencePanel.vue': inquiryUrl, ...childPorts, '../workspaces/OrganizationTeam.vue':plainUrl,'./DirectBusinessTeam.vue':plainUrl, './BusinessDataUse.vue': dataUsePanelUrl, './BusinessPurposes.vue': purposePanelUrl });
const wizard = await component('resources/js/business-foundation/BusinessWizard.vue', { './businessWizard': businessWizardUrl });
const creation = await component('resources/js/business-foundation/BusinessCreate.vue', { './businessWizard': businessWizardUrl, './businessSetup': businessSetupUrl, './BusinessWizard.vue': wizard.url, './activity': setupActivityUrl });
const goalSelectionUrl = moduleUrl(compile(await readFile('resources/js/Components/Business/goalSelection.ts', 'utf8')));
const facts = await component('resources/js/business-foundation/BusinessFacts.vue', { '../Components/Business/goalSelection': goalSelectionUrl });
const surface = await component('resources/js/business-foundation/BusinessSurface.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './BusinessWorkspace.vue': workspace.url, './BusinessCreate.vue': creation.url, './businessWizard': moduleUrl('export const hasRegistrationBusiness=()=>false;') });
const sources = await component('resources/js/business-foundation/SourceRights.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './SourceRightsEditor.vue': plainUrl });
const boundaries = await component('resources/js/business-foundation/DataBoundaries.vue', { './AdvertiserWorkspaceLayout.vue': layout.url, './BoundaryEditor.vue': plainUrl });
const workspacesStateUrl = moduleUrl(`import {reactive,shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();export const creationDraft=reactive({name:'',business_type_id:'',creation_key:''});export const unresolvedCreation=shallowRef();export const resetCreationDraft=()=>{Object.assign(creationDraft,{name:'',business_type_id:'',creation_key:''});unresolvedCreation.value=undefined;};`);
const workspaceTransportUrl = moduleUrl('export const bootstrap={identity:{binding:{boundary:"advertiser"}}};export const owned=()=>true;export const stamp=()=>"document-one";export class TransportError extends Error{constructor(status,body={}){super("Request failed");this.status=status;this.body=body;}}');
const workspaceApiUrl = moduleUrl('export const calls=[],pending=[];export const createOrganization=command=>{calls.push(JSON.parse(JSON.stringify(command)));return new Promise((resolve,reject)=>pending.push({resolve,reject}));};export const reassessOwnerGrant=async()=>{throw Error("No reassessment request expected")};');
const chooser = await component('resources/js/workspaces/WorkspaceIndex.vue', { './state': workspacesStateUrl, './api': workspaceApiUrl, '../spa/transport': workspaceTransportUrl, '../spa/destination': moduleUrl('export const applicationDestination=()=>"/";'), '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const workspaceCreationPresentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const status=ref('ready'),confirmDeparture=ref(()=>true);`);
const sourceStateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const current=shallowRef();`);
const sourceCommandsUrl = moduleUrl('export const pending=new Map();');
const sourcePresentationUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const confirmDeparture=ref(()=>true);`);
const sourcesChildUrl = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['workspace'],setup(p){return()=>h('section',{'data-existing-sources':p.workspace.public_id},p.workspace.name)}};`);
const sourcesView = await component('resources/js/sources/SourceView.vue', { '../workspaces/OrganizationTeam.vue':plainUrl,'../workspaces/team':moduleUrl('export const hasPendingTeamCommand=()=>false;'),'./Sources.vue': sourcesChildUrl, './businessReturn':moduleUrl('export const businessReturn=()=>undefined;export const businessReturnPath=()=>"/businesses";'), './state': sourceStateUrl, './commands': sourceCommandsUrl, '../spa/presentation': sourcePresentationUrl, '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const businessCopyUrl = moduleUrl(`export const useBusinessCopy=()=>({t:key=>key,localized:value=>value?.name_ar??value?.label??''});`);
const sourcesCommandsFullUrl = moduleUrl('export const commandsFor=()=>[];export const submit=async()=>{throw Error("No command expected")};export const remember=()=>{};export const restore=()=>undefined;');
const actualAccounts = await component('resources/js/sources/AdvertisingAccounts.vue', {'../Components/ProviderBrand.vue':providerBrandUrl,'./providerDestination':providerDestinationUrl,...Object.fromEntries(['Meta','Google','TikTok','Snapchat','X'].map(name=>['./'+name+'ConnectionPanel.vue',plainUrl]))});
const operationalSources = await component('resources/js/sources/Sources.vue', { './AdvertisingAccounts.vue':actualAccounts.url, '@/Components/ProviderBrand.vue':providerBrandUrl, '@/localization/businessCopy':businessCopyUrl, './commands':sourcesCommandsFullUrl, './PendingCommands.vue':plainUrl, './SourceRegistryPanel.vue':plainUrl, './MetaConnectionPanel.vue':plainUrl, './GoogleConnectionPanel.vue':plainUrl, './SnapchatConnectionPanel.vue':plainUrl, './TikTokConnectionPanel.vue':plainUrl, './XConnectionPanel.vue':plainUrl, './state': moduleUrl('export const read=async()=>{};') });

const historyStateUrl = moduleUrl(`import {shallowRef} from ${JSON.stringify(vue)};export const commercialHistory=shallowRef();`);
const historyPage = await component('resources/js/workspaces/CommercialHistory.vue', { './commercialHistory': historyStateUrl, '../business-foundation/AdvertiserWorkspaceLayout.vue': layout.url });
const routes = ['/profile', '/businesses', '/businesses/new', '/businesses/:business', '/organizations/new', '/source-rights', '/source-rights/:source', '/data-rights', '/data-rights/:boundary', '/workspaces', '/workspaces/:workspace', '/workspaces/:workspace/commercial-history', '/workspaces/:workspace/purpose-history', '/workspaces/:workspace/purpose-history/:purpose'].map(path => ({ path, component: { render: () => null } }));
async function routerAt(path) { const router = createRouter({ history: createMemoryHistory(), routes }); await router.push(path); await router.isReady(); return router; }
async function render(component, path, data) { current.value = { data }; const app = createSSRApp(component); app.use(await routerAt(path)); return renderToString(app); }
const businessSurface = name => ({ lifecycle:{revision:0,archive_options:[]},fact_write_options:{markets:[],intent:[],preferences:[],purpose:[]},private_history_available:true,direct_team_enrollment_available:false,business: { business_public_id: name, name, business_type_id: 1, declared_markets:[] }, catalog: { countries: [], business_types: [{ id: 1, name_ar: 'خدمات', name_en: 'Services' }] }, authority_selection: { realm_kind:'business',realm_workspace_public_id:null,requirements: [{ requirement_kind:'business',leaf_grant_id: 'read-one' }] } });

test('Business agency qualifications have a distinct label while the evaluator selection remains direct', async () => {
    const item = { business_public_id: 'client-one', name: 'Client A', state: 'active', read_grant_id: '51', realm_kind: 'direct',
        realm_workspace_public_id: null, agency_business_public_id: 'agency-one' };
    const app = createSSRApp(switcher.value, { id: 'agency-picker', items: [item], selected: item, phase: 'ready', more: false });
    app.use(await routerAt('/businesses'));
    const html = await renderToString(app);
    assert.match(html, /بتفويض بين الأنشطة/); assert.doesNotMatch(html, /وصول مباشر/);
    assert.equal(item.realm_kind, 'direct'); assert.equal(item.realm_workspace_public_id, null);
});

test('real business list and empty state prioritize opening or creating a business inside one private shell', async () => {
    const empty = await render(surface.value, '/businesses', { items: [], actor_public_id: 'actor-one', next_cursor: null });
    assert.match(empty, /data-spa-private/); assert.match(empty, /data-advertiser-shell/); assert.match(empty, /data-business-empty/);
    assert.match(empty, /href="\/businesses\/new"/); assert.equal((empty.match(/<main /g) ?? []).length, 1);
    assert.ok(empty.indexOf('data-business-empty') < empty.indexOf('data-business-account-reference'));
    assert.doesNotMatch(empty, /data-business-organizations-entry/, 'A new advertiser has no previous-resource navigation');
    const listed = await render(surface.value, '/businesses', { legacy_resources_available: true, items: [{ read_grant_id: 'read-grant', business_public_id: 'b-one', name: 'First business', realm_kind: 'business', state: 'declared_draft' }], actor_public_id: 'actor-one', next_cursor: 'next-page' });
    assert.match(listed, /First business/); assert.match(listed, /href="\/businesses\/b-one\?access=read-grant"/); assert.match(listed, /href="\/businesses\?after=next-page"/);
    assert.doesNotMatch(listed, /class="bf-nav"/);
    assert.doesNotMatch(listed, /data-business-organizations-entry|href="\/workspaces/, 'Business selection has no public previous-resource exit');
    const created = await render(surface.value, '/businesses/new', { catalog: { countries:[], business_types: [], goals: [] } });
    assert.match(created, /data-wizard-step="sector"/); assert.match(created, /id="bf-create-type"/); assert.equal((created.match(/<main /g) ?? []).length, 1);
});

test('source and data-boundary empty states describe only manageable resources, with real next actions', async () => {
    const emptySource = await render(sources.value, '/source-rights', { sources: [], next_cursor: null });
    assert.match(emptySource, /data-source-rights-empty/); assert.match(emptySource, /لا توجد مصادر متاحة لك لإدارتها/); assert.match(emptySource, /href="\/businesses"/);
    const sourceList = await render(sources.value, '/source-rights', { sources: [{ source_id: 'source-one', access_grant_id: 'source-grant', label: 'A source', status: 'ACTIVE', resource: 'resource', operation: 'read', purpose: 'review' }], next_cursor: null });
    assert.match(sourceList, /A source/); assert.match(sourceList, /href="\/source-rights\/source-one\?access=source-grant"/); assert.doesNotMatch(sourceList, /data-source-rights-empty/);
    const emptyBoundary = await render(boundaries.value, '/data-rights', { boundaries: [], next_cursor: null });
    assert.match(emptyBoundary, /data-boundary-empty/); assert.match(emptyBoundary, /href="\/businesses"/);
    const boundaryList = await render(boundaries.value, '/data-rights', { boundaries: [{ boundary_id: 'boundary-one', read_grant_id: 'boundary-grant', state: 'active' }], next_cursor: null });
    assert.match(boundaryList, /href="\/data-rights\/boundary-one\?access=boundary-grant"/); assert.equal((boundaryList.match(/<main /g) ?? []).length, 1);
});

test('isolated historical workspace component cannot restore public creation or advertiser navigation', async () => {
    const workspaceState = await import(workspacesStateUrl);
    const router = await routerAt('/workspaces');
    workspaceState.current.value = { data: { workspaces: [], reassessableWorkspaces: [], canCreateWorkspace: false } };
    const emptyApp = createSSRApp(chooser.value); emptyApp.use(router);
    const empty = await renderToString(emptyApp);
    assert.match(empty, /data-spa-private/); assert.match(empty, /data-advertiser-shell/); assert.match(empty, /data-workspaces-empty/);
    assert.equal((empty.match(/<main /g) ?? []).length, 1); assert.match(empty, /الموارد والسجلات السابقة/); assert.doesNotMatch(empty, /الفريق والموارد المشتركة|تسجيل وكالة أو شركة|مساحات التعاون/);
    assert.match(empty, /href="\/businesses"/); assert.match(empty, /إدارة نشاطك وفريقك وتفويض الوكالات/);
    assert.match(empty, /data-workspaces-creation-unavailable/); assert.doesNotMatch(empty, /data-workspaces-add|href="\/business\/start/); assert.match(empty, /أُوقف إنشاء جهات عمل مستقلة/);
    workspaceState.current.value = { data: { workspaces: [{ public_id: 'workspace-one', name: 'Shared resources A' }], reassessableWorkspaces: [], canCreateWorkspace: true } };
    const listApp = createSSRApp(chooser.value); listApp.use(router); const list = await renderToString(listApp);
    assert.match(list, /Shared resources A/); assert.match(list, /href="\/workspaces\/workspace-one"/); assert.doesNotMatch(list, /data-workspaces-empty/);
    assert.doesNotMatch(list, /data-workspaces-add|href="\/organizations\/new"/, 'A stale creation flag cannot restore a retired creation path'); assert.match(list, /data-workspaces-creation-unavailable/);
    workspaceState.current.value.data.canCreateWorkspace = false;
    const deniedApp = createSSRApp(chooser.value); deniedApp.use(router); const denied = await renderToString(deniedApp);
    assert.match(denied, /href="\/workspaces\/workspace-one"/); assert.doesNotMatch(denied, /data-workspaces-add|href="\/business\/start/);
    const workspaceLink = [...list.matchAll(/<a [^>]*href="\/workspaces"[^>]*>/g)].map(m => m[0]);
    assert.deepEqual(workspaceLink, [], 'The advertiser shell never restores a Workspace navigation destination');
    locale.value = 'en';
    try { const englishApp = createSSRApp(chooser.value); englishApp.use(router); const english = await renderToString(englishApp); assert.match(english, /Previous resources and records/); assert.doesNotMatch(english, /Preserved records/); assert.doesNotMatch(english, /Register an agency or company|client businesses/); } finally { locale.value = 'ar'; }
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
        assert.match(text(breadcrumb), /الموارد والسجلات السابقة/); assert.match(text(breadcrumb), /Private workspace A/);
        assert.deepEqual(all(breadcrumb).filter(node => node.tag === 'a').map(node => node.props.href), ['/businesses', '/workspaces']);
        const workspacePageLink = all(root).find(node => node.tag === 'a' && node.props.href === '/workspaces/workspace-one' && node.props['aria-current'] === 'page'); assert.equal(workspacePageLink, undefined, 'The shared shell exposes no Workspace destination');
        assert.equal(all(root).some(node => node.tag === 'nav' && node.props.class === 'organization-sections'), false, 'A previous record has no parallel team/account work sections');
        const legacyPermissions = find(root, 'data-legacy-resource-permissions'); assert.ok(legacyPermissions); legacyPermissions.props.onToggle({ target: { open: true } }); await nextTick();
        assert.match(text(root), /أذونات السجل السابق/); assert.match(text(root), /فريق النشاط وتكليف موظفي الوكالة يُداران داخل النشاط/); assert.doesNotMatch(text(root), /الفريق والعملاء|فتح أنشطة العملاء/);
        locale.value = 'en'; await nextTick();
        const globalBusinesses = all(root).find(node => node.tag === 'a' && text(node) === 'Manage my businesses and their teams'); assert.ok(globalBusinesses); assert.equal(globalBusinesses.props.href, '/businesses');
        assert.match(text(root), /Business teams and agency employee assignments are managed inside the relevant Business/); assert.doesNotMatch(text(root), /Team and clients|Open available client businesses/); locale.value = 'ar'; await nextTick();
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

test('advertiser shell exposes no Workspace or archive navigation even with obsolete context attributes', async () => {
    for (const path of ['/workspaces/old', '/workspaces/old/commercial-history', '/workspaces/old/purpose-history']) {
        const app = createSSRApp(layout.value, { workspaceId: 'old', workspaceContext: true }); app.use(await routerAt(path));
        const html = await renderToString(app);
        assert.doesNotMatch(html, /href="\/workspaces|data-workspace-previous|advertiser-organization-context|advertiser-organization-tools/);
        assert.match(html, /href="\/businesses"/);
    }
});

function element(tag, text = '') {
    const listeners = new Map();
    return markRaw({ tag, tagName: tag.toUpperCase(), type: tag === 'input' ? 'text' : tag, text, value: '', props: {}, style: {}, children: [], parent: null, open: false, selected: false, selectedIndex: -1, multiple: false, focusCount: 0,
        get options() { return this.children.filter(node => node.tag === 'option'); },
        addEventListener(name, handler) { const handlers = listeners.get(name) ?? new Set(); handlers.add(handler); listeners.set(name, handlers); },
        removeEventListener(name, handler) { listeners.get(name)?.delete(handler); },
        dispatchEvent(event) { if (!event.target) Object.defineProperty(event, 'target', { value: this }); for (const handler of listeners.get(event.type) ?? []) handler(event); const handler = this.props['on' + event.type[0].toUpperCase() + event.type.slice(1)]; if (typeof handler === 'function') handler(event); return !event.defaultPrevented; },
        closest(selectors) { for (let node = this; node; node = node.parent) { if (selectors.split(',').some(selector => { const match = /^(\w+)(?:\[([^\]]+)\])?$/.exec(selector.trim()); return match && node.tag === match[1] && (!match[2] || match[2] in node.props); })) return node; } return null; },
        getRootNode() { return {}; }, querySelectorAll() { return []; }, showModal() { this.open = true; this.modalCount = (this.modalCount ?? 0) + 1; }, close() { const wasOpen = this.open; this.open = false; if (wasOpen) this.dispatchEvent({ type: 'close' }); },
        focus() { this.focusCount++; }, getBoundingClientRect() { return { top: 0, left: 0, right: 320, bottom: 800 }; }, setAttribute(name, value) { this.props[name] = value; }, removeAttribute(name) { delete this.props[name]; },
    });
}
const renderer = createRenderer({
    querySelector: () => headerHost,
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
    const router = await routerAt('/businesses/Business-A'); app.use(router); app.mount(root);
    try {
        assert.equal(find(root, 'data-test-inquiry'), undefined);
        assert.ok(find(root, 'data-advertiser-sidebar'), 'Desktop navigation is a persistent sidebar');
        assert.equal(all(root).filter(node => 'data-workspace-section' in node.props && node.props['data-workspace-section'] === 'settings').length, 1, 'Only one breakpoint navigation is rendered');
        await find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-inquiry'), undefined, 'Opening settings alone must not activate inquiry reading');
        await find(root, 'data-workspace-settings', 'data').props.onClick(); await nextTick();
        const panel = find(root, 'data-test-inquiry'), input = find(root, 'data-draft');
        assert.equal(panel.props['data-active'], true); input.props.onInput({ target: { value: 'Unsent aggregate draft' } }); await nextTick();
        await find(root, 'data-workspace-settings', 'details').props.onClick(); await nextTick();
        assert.equal(panel.props['data-active'], false); assert.equal(find(root, 'data-test-inquiry'), panel); assert.equal(input.props.value, 'Unsent aggregate draft');
        await find(root, 'data-workspace-section', 'direction').props.onClick(); await nextTick(); assert.equal(panel.props['data-active'], false);
        await find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick(); await find(root, 'data-workspace-settings', 'data').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-inquiry'), panel); assert.equal(panel.props['data-active'], true); assert.equal(events.filter(e => e[0] === 'mount').length, 1);
        locale.value = 'en'; await nextTick(); assert.match(text(root), /Data and declarations/); assert.match(text(root), /Business management/);
        await router.push('/businesses/Business-B'); selected.value = businessSurface('Business B'); await nextTick(); assert.equal(find(root, 'data-test-inquiry'), undefined, 'Another Business must not inherit the previous local data location');
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

test('legacy resource tasks require qualified existing resources, a legacy context or an exact retained request', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    try {
        for (const reason of ['none', 'workspace', 'relationship', 'context', 'linkPending', 'teamPending']) {
            const selected = businessSurface('legacy-resources-' + reason);
            selected.collaboration = { options: [], workspaces: [], relationships: [] };
            if (reason === 'workspace') selected.collaboration.workspaces.push({ public_id: 'known', slug: 'Known resource' });
            if (reason === 'relationship') selected.collaboration.relationships.push({ link_ref: 'known' });
            if (reason === 'context') selected.authority_selection.realm_workspace_public_id = 'known';
            const resource = drafts.key(selected.business.business_public_id, selected.authority_selection);
            if (reason === 'linkPending') drafts.linkDrafts.set(`${resource}:relationships:1`, { pending: { command_id: 'original-link' } });
            if (reason === 'teamPending') drafts.internalTeamDrafts.set(`${resource}:internal-team:1`, { pending: { command_id: 'original-team' } });
            const root = element('root'), app = renderer.createApp(workspace.value, { surface: selected });
            app.use(await routerAt(`/businesses/${selected.business.business_public_id}?section=settings&settings=access`)); app.mount(root); await nextTick();
            try {
                const pending = reason.endsWith('Pending');
                assert.equal(find(root, 'data-business-task', 'organization-link'), undefined, reason);
                assert.equal(find(root, 'data-business-organization-links'), undefined, 'Old resource state does not create a public task: ' + reason);
            } finally { app.unmount(); drafts.linkDrafts.clear(); drafts.internalTeamDrafts.clear(); }
        }
    } finally { globalThis.window = previousWindow; }
});

test('Business team is primary on desktop and mobile and keeps the exact Business access when opening collaboration', async () => {
    const previousWindow = globalThis.window;
    const selected = { ...businessSurface('team-business'), collaboration: { relationships: [], workspaces: [{ public_id: 'existing-workspace', slug: 'Existing resource' }] }, agency_task: { can_mandate: true, can_assign: false, agency_business_public_id: null }, authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] } };
    for (const desktop of [true, false]) {
        globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: desktop, addEventListener() {}, removeEventListener() {} }) };
        headerHost = desktop ? undefined : element('header');
        const root = element('root'), router = await routerAt('/businesses/team-business?access=51');
        const app = renderer.createApp(workspace.value, { surface: selected });
        app.use(router); app.mount(root);
        await nextTick(); // The header Teleport target is discovered in onMounted.
        try {
            if (!desktop) { find(headerHost, 'data-advertiser-menu-toggle').props.onClick(); assert.equal(find(root, 'data-advertiser-drawer').open, true); }
            assert.equal(all(root).some(node => node.props.href === '/workspaces'), false, 'The selected Business shell has no organization directory exit');
            const team = find(root, 'data-workspace-section', 'access');
            assert.ok(team); assert.match(text(team), /الفريق والصلاحيات/);
            await team.props.onClick(); await nextTick();
            assert.equal(router.currentRoute.value.fullPath, '/businesses/team-business?access=51&section=settings&settings=access');
            assert.equal(team.props['aria-current'], 'page');
            assert.equal(find(root, 'data-workspace-section', 'settings').props['aria-current'], undefined);
            assert.equal(find(root, 'data-workspace-settings', 'access'), undefined);
            assert.ok(find(root, 'data-workspace-panel', 'access'));
            assert.ok(find(root, 'data-business-task', 'business-agency'), 'Agency delegation is a separate Business task');
            assert.equal(find(root, 'data-business-organization-links'), undefined, 'Organization operations are an explicit advanced task');
            assert.equal(find(root, 'data-business-task', 'organization-link'), undefined);
            assert.equal(router.currentRoute.value.query.access, '51');
            await router.push('/businesses/team-business?access=51&section=access'); await nextTick();
            assert.equal(team.props['aria-current'], 'page', 'The legacy local alias opens the same Team view');
            await find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick();
            assert.equal(router.currentRoute.value.query.settings, 'details', 'Management from Team opens the daily details');
        } finally { app.unmount(); presentation.workspacePresentation.clear(); }
    }
    globalThis.window = previousWindow;
    headerHost = undefined;
});

test('retired organization creation redirects to Business and exposes no parallel creation form', async () => {
    const contract = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const source = await readFile('resources/js/workspaces/routes.ts', 'utf8');
    assert.match(source, /path: '\/organizations\/new'[^\n]+redirect: '\/businesses\/new'/);
    assert.match(source, /path: '\/business\/start'[^\n]+redirect: '\/businesses\/new'/);
    const index = await readFile('resources/js/workspaces/WorkspaceIndex.vue', 'utf8');
    assert.doesNotMatch(index, /data-workspaces-add|to="\/organizations\/new"/);
    const api = await readFile('resources/js/workspaces/api.ts', 'utf8');
    assert.doesNotMatch(api, /createOrganization|readCreation/);
    assert.equal(contract.paths['/organizations'].post.responses[410] !== undefined, true);
    assert.equal(contract.paths['/workspaces'].post.responses[410] !== undefined, true);
});

test('creation checks a lost acknowledgement with the exact original command and one localized outcome message', async () => {
    const restore = withSetupDraft({ name: 'Exact original name' });
    creationApi.calls.length = 0; creationApi.pending.length = 0;
    let view = await mountSetup();
    try {
        const { root } = view;
        submitWizard(root); await flushFacts();
        assert.equal(creationApi.calls.length, 1);
        assert.equal(find(root, 'data-business-unknown'), undefined, 'Pending network work is not yet an unknown outcome');
        assert.match(text(root), /جارٍ حفظ معلومات النشاط/);
        creationApi.pending.shift().reject(Error('Acknowledgement lost')); await flushFacts();
        assert.equal(all(root).filter(node => 'data-business-unknown' in node.props).length, 1);
        assert.match(text(root), /التحقق من النتيجة/);
        assert.equal(all(root).filter(node => node.props.role === 'alert').length, 1, 'Unknown outcome must not repeat in a generic notice');
        const original = structuredClone(creationApi.calls[0]);
        assert.ok(original.command_id); assert.equal(original.name, 'Exact original name');
        view.app.unmount();
        const stored = readSetup(setupKey());
        stored.answers.name = 'A later draft must not replace the command';
        writeSetup(setupKey(), stored);
        locale.value = 'en';
        view = await mountSetup();
        assert.match(text(view.root), /Check the outcome/); assert.match(text(view.root), /could not confirm the save/);
        const retry = find(view.root, 'data-setup-reconcile').props.onClick(); await flushFacts();
        assert.equal(find(view.root, 'data-business-unknown'), undefined, 'Checking hides the old unknown notice during the request');
        assert.deepEqual(creationApi.calls[1], original, 'Retry must preserve command identity, name and nullable field');
        creationApi.pending.shift().resolve(setupReceipt('created-one', original.name));
        await retry; await flushFacts();
        assert.equal(view.router.currentRoute.value.fullPath, '/businesses/created-one?access=51');
        assert.equal(readSetup(setupKey()), undefined);
        assert.equal(find(view.root, 'data-business-unknown'), undefined);
        assert.deepEqual(creationApi.calls, [original, original]);
    } finally {
        view.app.unmount(); locale.value = 'ar'; restore();
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
function withSetupDraft(answers = {}, step = 5) {
    const restore = withFactDom(), previousWindow = globalThis.window, previousStorage = globalThis.sessionStorage;
    const storage = new Map();
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    globalThis.sessionStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) };
    writeSetup(setupKey(), { answers: { ...emptyWizardAnswers(), ...answers }, step, language: 'ar' });
    return () => { drafts.creation.clear(); globalThis.window = previousWindow; globalThis.sessionStorage = previousStorage; restore(); };
}
async function mountSetup(countries = [], registrationAttempt, extraCatalog = {}) {
    const root = element('root');
    const router = createRouter({ history: createMemoryHistory(), routes: routes.map(route => route.path === '/businesses/new'
        ? { ...route, component: creation.value, props: { catalog: { business_types: [], countries, goals: [], ...extraCatalog }, registrationAttempt } }
        : route) });
    await router.push('/businesses/new');
    const app = renderer.createApp({ render: () => h(RouterView) });
    app.use(router); app.mount(root); return { root, app, router };
}
const setupReceipt = (business_public_id, name) => ({
    business_public_id, name, grant_refs: { 'business.context.read': 51, 'business.markets.declare': 52 }, versions: { context: 0 },
});
const setupCountry = { code: 'SA', name_ar: 'السعودية', name_en: 'Saudi Arabia' };
const submitWizard = root => all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} });

test('registration hands off only its admitted activity and saves each declaration under the new Business grants', { timeout: 5000 }, async () => {
    const restore = withSetupDraft({}, 0);
    const attempt = '019a1234-5678-7aaa-aaaa-aaaaaaaaaaaa';
    const foreign = '019a1234-5678-7bbb-bbbb-bbbbbbbbbbbb';
    const catalog = { business_types: [{ id: 7, key: 'ecommerce', active: true, name_ar: 'متجر', name_en: 'Online store' }],
        goals: [{ id: 'sales', name_ar: 'المبيعات', name_en: 'Sales', description_ar: '', description_en: '', results: [] }] };
    storeRegistrationBusiness(attempt, { type: 7, name: 'Qualified business', codes: ['SA'], channels: ['phone'],
        goal: 'sales', practices: [{ kind: 'sale', name: 'My declared products' }] }, 'en');
    creationApi.calls.length = 0; creationApi.pending.length = 0; creationApi.factCalls.length = 0; creationApi.factPending.length = 0;
    setupActivity.calls.length = 0; setupActivity.pending.length = 0;
    let view;
    try {
        view = await mountSetup([setupCountry], foreign, catalog); await flushFacts();
        assert.equal(creationApi.calls.length, 0);
        assert.doesNotMatch(text(view.root), /Qualified business/);
        view.app.unmount();
        const createObserved = creationApi.nextCreate();
        view = await mountSetup([setupCountry], attempt, catalog);
        await createObserved;
        assert.deepEqual(creationApi.calls, [{ command_id: attempt, name: 'Qualified business', business_type_id: 7 }]);
        creationApi.pending.shift().resolve({ business_public_id: 'new-business', name: 'Qualified business', versions: { context: 0, preferences: 0 },
            grant_refs: { 'business.context.read': 11, 'business.context.manage': 12, 'business.markets.declare': 13, 'business.preferences.manage': 14 } });
        await flushFacts();
        assert.deepEqual(creationApi.factCalls[0].command.codes, ['SA']);
        assert.equal(creationApi.factCalls[0].command.authority_selection.requirements[0].leaf_grant_id, 13);
        creationApi.factPending.shift().resolve({ business_public_id: 'new-business' }); await flushFacts();
        assert.deepEqual(creationApi.factCalls[1].command.value, { goals: ['sales'], priority: 'sales', results: {} });
        assert.equal(creationApi.factCalls[1].command.authority_selection.requirements[0].leaf_grant_id, 14);
        creationApi.factPending.shift().resolve({ business_public_id: 'new-business' }); await flushFacts();
        const practice = setupActivity.calls[0];
        assert.equal(practice.business, 'new-business');
        assert.match(practice.command.practice_id, /^019a1234-5678-7/);
        assert.equal(practice.command.value.name, 'My declared products');
        assert.deepEqual(practice.command.value.channels, ['Phone']);
        assert.deepEqual(practice.command.value.target_markets, { status: 'unspecified', areas: [] });
        assert.equal(practice.command.authority_selection.requirements[0].leaf_grant_id, 12);
        assert.equal(practice.command.read_selection.requirements[0].leaf_grant_id, 11);
        setupActivity.pending.shift().resolve({ business_public_id: 'new-business', practice: { practice_id: practice.command.practice_id } });
        await flushFacts();
        assert.equal(view.router.currentRoute.value.fullPath, '/businesses/new-business?access=11');
        assert.equal(readSetup(setupKey(attempt)), undefined);
    } finally { view?.app.unmount(); restore(); }
});

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
        await factTab(root, 'intent'); assert.match(text(root), /حدد اتجاه عملك والنتيجة/);
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
        locale.value = 'en'; await flushFacts(); assert.match(text(root), /Where do you work/); assert.match(text(root), /Find a country/);
        await factTab(root, 'intent'); assert.match(text(root), /Choose your business direction and an outcome/); assert.equal(find(root, 'id', 'bf-intent-meaning').value, 'Completed maintenance contract');
        assert.equal(creationApi.factCalls.length, 0, 'Local navigation and selection alone never submit commands');
    } finally { app.unmount(); restore(); locale.value = 'ar'; drafts.factDrafts.clear(); }
});

test('summary-first markets opens one deliberate editor and retains unsaved changes when returning to the saved overview', async () => {
    const restore = withFactDom(); drafts.factDrafts.clear();
    const writesBefore = creationApi.factCalls.length;
    const selected = factSurface();
    const root = element('root'), app = renderer.createApp(facts.value, { surface: selected, summaryFirst: true });
    app.mount(root);
    try {
        const summary = find(root, 'data-business-direction-summary');
        assert.ok(summary);
        const editor = all(root).find(node => node.props.class === 'business-task-editor');
        assert.equal(editor.style.display, 'none', 'Saved summaries are the initial surface');
        find(summary, 'data-business-task', 'markets').props.onClick(); await flushFacts();
        assert.notEqual(editor.style.display, 'none');
        assert.equal(all(editor).find(node => node.tag === 'h2').focusCount, 1, 'The opened editor receives keyboard focus');
        await checkFact(root, 'data-fact-country', 'JO');
        const retained = [...drafts.factDrafts.values()].find(draft => draft.kind === 'markets');
        assert.ok(retained.fields.codes.includes('JO'));
        find(root, 'data-business-direction-back').props.onClick(); await flushFacts();
        assert.equal(editor.style.display, 'none');
        assert.equal(find(root, 'data-business-task', 'markets').focusCount, 1, 'Back restores the original summary task focus');
        assert.equal(selected.business.declared_markets.includes('JO'), false, 'Returning never changes qualified saved data');
        assert.match(text(find(root, 'data-business-direction-summary')), /استكمال التعديل/);
        find(root, 'data-business-task', 'markets').props.onClick(); await flushFacts();
        assert.equal(find(root, 'data-fact-country', 'JO').checked, true, 'The unsaved answer remains in the existing editor draft');
        assert.equal(creationApi.factCalls.length, writesBefore, 'Opening, changing and returning do not issue a save command');
        locale.value = 'en'; await flushFacts();
        find(root, 'data-business-direction-back').props.onClick(); await flushFacts();
        assert.match(text(root), /Continue editing/);
    } finally { app.unmount(); restore(); locale.value = 'ar'; drafts.factDrafts.clear(); }
});

test('guided intent advances with one localized inline error and first-invalid focus while preserving an unsaved draft', async () => {
    const restore = withFactDom(); drafts.factDrafts.clear();
    const writesBefore = creationApi.factCalls.length;
    const root = element('root'), app = renderer.createApp(facts.value, { surface: factSurface(), summaryFirst: true });
    app.mount(root);
    try {
        await find(root, 'data-business-task', 'intent').props.onClick(); await flushFacts();
        const direction = find(root, 'id', 'bf-intent-direction');
        const meaning = find(root, 'id', 'bf-intent-meaning');
        await find(root, 'data-intent-next').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'id', 'bf-intent-step-error')), /اختر اتجاه العمل/);
        assert.equal(direction.props['aria-invalid'], true);
        assert.equal(direction.props['aria-describedby'], 'bf-intent-step-error');
        assert.equal(direction.focusCount, 1);
        assert.equal(all(root).filter(node => node.props.id === 'bf-intent-step-error').length, 1);
        locale.value = 'en'; await flushFacts();
        assert.match(text(find(root, 'id', 'bf-intent-step-error')), /Choose a business direction/);
        await selectFact(root, 'bf-intent-direction', 'increase_value');
        assert.equal(find(root, 'id', 'bf-intent-step-error'), undefined, 'Correcting the field clears its error');
        await find(root, 'data-intent-next').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'id', 'bf-intent-step-error')), /Describe the outcome/);
        assert.equal(meaning.props['aria-invalid'], true);
        assert.equal(meaning.focusCount, 1);
        await inputFact(root, 'bf-intent-meaning', 'Completed maintenance contracts');
        await find(root, 'data-intent-next').props.onClick(); await flushFacts();
        assert.match(text(all(root).find(node => node.tag === 'h3')), /What time horizon/);
        assert.equal(all(root).find(node => node.tag === 'h3').focusCount, 1, 'The new step has a focused heading');
        await inputFact(root, 'bf-intent-quantity', '007');
        await find(root, 'data-intent-next').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'id', 'bf-intent-step-error')), /count unit/);
        assert.equal(find(root, 'id', 'bf-intent-unit').focusCount, 1);
        await inputFact(root, 'bf-intent-unit', 'Completed contract');
        await inputFact(root, 'bf-intent-date', '2027-01-15');
        await find(root, 'data-intent-next').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'id', 'bf-intent-step-error')), /timezone/);
        assert.equal(find(root, 'id', 'bf-intent-timezone').focusCount, 1);
        await find(root, 'data-intent-back').props.onClick(); await flushFacts();
        assert.equal(find(root, 'id', 'bf-intent-step-error'), undefined);
        assert.equal(find(root, 'id', 'bf-intent-meaning').value, 'Completed maintenance contracts');
        assert.equal(creationApi.factCalls.length, writesBefore, 'Advancing steps never issues a business command');
    } finally { app.unmount(); restore(); locale.value = 'ar'; drafts.factDrafts.clear(); }
});

for (const language of ['ar', 'en']) test(`summary-first existing intent clears only after confirmation and shows saved readback (${language})`, async () => {
    const restore = withFactDom(); drafts.factDrafts.clear(); creationApi.factCalls.length = 0; creationApi.factPending.length = 0;
    locale.value = language;
    const selected = ref(factSurface());
    selected.value.business.current_intent = { direction: 'preserve_value', scope: 'business_wide', description: null,
        outcome: { meaning: 'Retain completed services', clarification: null, count_unit: null }, quantity: null, horizon: null };
    let refreshes = 0;
    const root = element('root'), app = renderer.createApp({ render: () => h(facts.value, { surface: selected.value, summaryFirst: true, onRefresh: () => refreshes++ }) });
    app.mount(root);
    const submit = () => all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} });
    try {
        find(root, 'data-business-task', 'intent').props.onClick(); await flushFacts();
        await inputFact(root, 'bf-intent-meaning', 'Unsaved revised result');
        find(root, 'data-business-direction-back').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'data-business-direction-summary')), /Retain completed services/);
        find(root, 'data-business-task', 'intent').props.onClick(); await flushFacts();
        assert.equal(find(root, 'id', 'bf-intent-meaning').value, 'Unsaved revised result');
        await checkFact(root, 'data-fact-intent-clear');
        assert.equal(find(root, 'data-intent-next'), undefined);
        await submit(); await flushFacts();
        assert.equal(creationApi.factCalls.length, 0, 'Unconfirmed clearing never writes');
        await checkFact(root, 'data-fact-confirm', 'declaration');
        const pending = submit(); await flushFacts();
        assert.equal(creationApi.factCalls.length, 1, 'Confirmed clearing delivers one command');
        const { business, command } = creationApi.factCalls[0];
        assert.equal(business, 'fact-business'); assert.equal(command.kind, 'intent'); assert.equal(command.value, null);
        assert.equal(command.expected_context, 3); assert.equal(command.expected_intent, 2);
        assert.deepEqual(command.authority_selection, { realm_kind: 'direct', requirements: [{ requirement_kind: 'business', leaf_grant_id: 17 }] });
        assert.deepEqual(command.confirmations, { declaration: true, business_wide: false, outcome: false, acceptance_clear: false, clarification_required: false, quantity: false, horizon: false });
        await submit(); assert.equal(creationApi.factCalls.length, 1, 'Busy submissions do not duplicate the command');
        creationApi.factPending.shift().resolve({ business_public_id: business, value: null, versions: { context: 3, intent: 3 } });
        await pending; await flushFacts(); assert.equal(refreshes, 1);
        selected.value = { ...selected.value, business: { ...selected.value.business, current_intent: null, versions: { ...selected.value.business.versions, intent: 3 } } };
        await flushFacts(); find(root, 'data-business-direction-back').props.onClick(); await flushFacts();
        assert.match(text(find(root, 'data-business-direction-summary')), language === 'ar' ? /\u0644\u0645 \u062a\u062d\u062f\u062f \u0646\u062a\u064a\u062c\u0629 \u0623\u0648 \u0645\u062f\u0629 \u0628\u0639\u062f/ : /No outcome or time horizon specified yet/);
        assert.doesNotMatch(text(find(root, 'data-business-direction-summary')), /Retain completed services|Unsaved revised result/);
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
    const history = html.match(/<section[^>]*data-commercial-history[\s\S]*?<\/section>/)?.[0]; assert.ok(history); assert.doesNotMatch(history, /<(?:form|input|textarea|select)\b/, 'The historical resource stays read-only; its shared shell may select another Business'); assert.doesNotMatch(html, /href="\/businesses\/workspace-one/);
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
    const router = await routerAt('/businesses/Business-source-A'); app.use(router); app.mount(root);
    try {
        assert.doesNotMatch(text(root), /إدارة المساحة/);
        assert.equal(find(root, 'data-test-data-use'), undefined);
        const sourceNavigation = find(root, 'data-workspace-section', 'sources'); assert.ok(sourceNavigation); assert.equal(text(sourceNavigation), 'المصادر');
        await find(root, 'data-workspace-section', 'sources').props.onClick(); await nextTick();
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
        await find(root, 'data-workspace-section', 'settings').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-data-use'), panel, 'Existing data-use editor remains mounted');
        await find(root, 'data-workspace-settings', 'purposes').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-test-business-purpose').props['data-test-business-purpose'], 'Business-source-A');
        await find(root, 'data-workspace-section', 'sources').props.onClick(); await nextTick();
        assert.equal(find(root, 'data-use-draft'), input); assert.equal(input.props.value, 'Unsent exact scope selection');
        locale.value = 'en'; await nextTick(); assert.equal(text(find(root, 'data-workspace-section', 'sources')), 'Sources');
        await router.push('/businesses/Business-source-B'); selected.value = businessSurface('Business-source-B'); await nextTick();
        assert.equal(find(root, 'data-test-data-use'), undefined); assert.equal(find(root, 'data-test-business-purpose'), undefined);
    } finally { app.unmount(); globalThis.window = previousWindow; locale.value = 'ar'; presentation.workspacePresentation.clear(); }
});

test('Business sources never fetch or expose Workspace inventory across Business switching', async () => {
    const previousWindow = globalThis.window; globalThis.window = {addEventListener(){},removeEventListener(){}};
    const forbidden = moduleUrl('export const calls=[];export const businessSources=()=>{calls.push(true);throw Error("Forbidden workspace inventory");};');
    const inventory = await component('resources/js/business-foundation/BusinessSources.vue', { './api': forbidden });
    const selected = ref(businessSurface('business-a')), root = element('root');
    const app = renderer.createApp({ render: () => h(inventory.value, { surface: selected.value, active: true }) }); app.use(await routerAt('/businesses/business-a')); app.mount(root);
    try {
        selected.value = businessSurface('business-b'); await nextTick();
        assert.deepEqual((await import(forbidden)).calls, []);
        assert.equal(find(root, 'data-business-task', 'source-shared'), undefined);
        assert.equal(find(root, 'data-business-source-inventory-details'), undefined);
        assert.equal(all(root).some(node => node.props.href?.startsWith('/workspaces')), false);
        assert.ok(find(root, 'data-business-task', 'source-licenses'));
    } finally { app.unmount(); globalThis.window = previousWindow; }
});

test('Business source licensing remains distinct from native provider account management', async () => {
    const previousWindow = globalThis.window; globalThis.window = {addEventListener(){},removeEventListener(){}};
    const accounts = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['businessId'],setup(p){return()=>h('div',{'data-canonical-provider-owner':p.businessId})}}`);
    const licenses = moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {props:['active'],setup(p){return()=>h('div',{'data-canonical-data-license':p.active})}}`);
    const inventory = await component('resources/js/business-foundation/BusinessSources.vue', {'../sources/AdvertisingAccounts.vue':accounts,'./BusinessDataLicenses.vue':licenses});
    const root=element('root'), app=renderer.createApp(inventory.value,{surface:businessSurface('independent'),active:true});app.use(await routerAt('/businesses/independent'));app.mount(root);
    try {
        find(root,'data-business-task','source-licenses').props.onClick(); await nextTick();
        assert.equal(find(root,'data-canonical-provider-owner').props['data-canonical-provider-owner'],'independent');
        assert.equal(find(root,'data-canonical-data-license').props['data-canonical-data-license'],true);
        assert.equal(all(root).some(node=>node.props.href?.startsWith('/workspaces')),false);
        find(root,'data-source-task-back').props.onClick();await nextTick();
        assert.equal(find(root,'data-canonical-data-license').props['data-canonical-data-license'],false);
    } finally {app.unmount();globalThis.window=previousWindow;}
});

test('Business always shows all five brands and opens account controls directly without an organization chooser', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { location: { hash: '' }, addEventListener() {}, removeEventListener() {} };
    const workspaceApi = moduleUrl('export const calls=[];export const readWorkspaces=async()=>{calls.push("forbidden");throw Error("Unexpected organization dependency")};');
    const panel = moduleUrl(`import {h,onMounted} from ${JSON.stringify(vue)};export default {props:['businessId','workspaceId','canManage'],emits:['summary'],setup(p,{emit}){onMounted(()=>emit('summary',{state:'restricted',canConnect:false,hasConnections:false}));return()=>h('section',{'data-native-business':p.businessId,'data-native-workspace':p.workspaceId,'data-can-manage':p.canManage})}};`);
    const Accounts = await component('resources/js/sources/AdvertisingAccounts.vue', {'../Components/ProviderBrand.vue':providerBrandUrl,'./providerDestination':providerDestinationUrl,...Object.fromEntries(['Meta','Google','TikTok','Snapchat','X'].map(name=>['./'+name+'ConnectionPanel.vue',panel]))});
    const Inventory = await component('resources/js/business-foundation/BusinessSources.vue', {'./api':moduleUrl('export const businessSources=async(business)=>({business_public_id:business,sources:[],next_cursor:null,inventory_kind:"manageable_sources",establishes_business_data_use:false});'),'../sources/AdvertisingAccounts.vue':Accounts.url,'../workspaces/api':workspaceApi});
    const selected = ref({...businessSurface('native-a'),collaboration:{relationships:[]},authority_selection:{requirements:[{requirement_kind:'business',leaf_grant_id:51}]}});
    const root=element('root'), app=renderer.createApp({render:()=>h(Inventory.value,{surface:selected.value,active:true})});
    app.use(await routerAt('/businesses/native-a'));app.mount(root);await nextTick();await nextTick();
    try {
        const platforms=all(root).filter(node=>'data-provider' in node.props);
        assert.deepEqual(platforms.map(node=>node.props['data-provider']),['google','meta','snapchat','tiktok','x']);
        assert.equal(all(root).filter(node=>'data-provider-brand' in node.props).length,5);
        assert.equal(find(root,'data-account-administration-choice'),undefined);
        assert.equal(all(root).filter(node=>'data-native-business' in node.props).length,5);
        assert.ok(all(root).filter(node=>'data-native-business' in node.props).every(node=>node.props['data-native-business']==='native-a' && node.props['data-native-workspace']===undefined));
        for(const platform of platforms){assert.doesNotMatch(text(platform),/اختيار إدارة الحساب|بيانات جاهزة/);}
        const trigger=all(find(root,'data-provider','x')).find(node=>node.tag==='button');
        trigger.props.onClick({currentTarget:trigger});await nextTick();await nextTick();
        const dialog=all(root).find(node=>node.tag==='dialog'&&node.props.class==='advertising-dialog');
        assert.equal(dialog.open,true);assert.equal(dialog.props['aria-label'],'X Ads');
        const before=trigger.focusCount;dialog.props.onCancel({preventDefault(){}});await nextTick();
        assert.equal(dialog.open,false);assert.equal(trigger.focusCount,before+1);
        selected.value={...selected.value,business:{...selected.value.business,business_public_id:'native-b'}};await nextTick();await nextTick();
        assert.ok(all(root).filter(node=>'data-native-business' in node.props).every(node=>node.props['data-native-business']==='native-b'));
        assert.deepEqual((await import(workspaceApi)).calls,[],'No organizational read or hidden entity is required');
        locale.value='en';await nextTick();assert.match(text(root),/Each platform’s state and available account action/);assert.doesNotMatch(text(root),/Sources shared/);locale.value='ar';
    } finally { app.unmount();globalThis.window=previousWindow;locale.value='ar'; }
});

test('Business setup retains committed identity and retries only the exact markets command after an unknown outcome', async()=>{
    const restore=withSetupDraft({name:'New independent business',codes:['SA']});
    creationApi.calls.length=0;creationApi.pending.length=0;creationApi.factCalls.length=0;creationApi.factPending.length=0;
    const {root,app,router}=await mountSetup([setupCountry]);
    try{
        submitWizard(root);await flushFacts();assert.equal(creationApi.factCalls.length,0);
        creationApi.pending.shift().resolve(setupReceipt('setup-business','New independent business'));await flushFacts();
        assert.equal(creationApi.calls.length,1);assert.equal(creationApi.factCalls.length,1);const original=structuredClone(creationApi.factCalls[0]);
        creationApi.factPending.shift().reject(Error('Market ACK lost'));await flushFacts();
        assert.ok(find(root,'data-business-created'));assert.ok(find(root,'data-business-unknown'));
        assert.deepEqual(readSetup(setupKey()).uncertain,[original.command.command_id]);
        const retry=find(root,'data-setup-reconcile').props.onClick();await flushFacts();assert.equal(creationApi.calls.length,1,'A market retry must not create a second business');assert.deepEqual(creationApi.factCalls[1],original);
        creationApi.factPending.shift().resolve({business_public_id:'setup-business',versions:{context:1}});await retry;await flushFacts();
        assert.equal(readSetup(setupKey()),undefined);
        assert.equal(router.currentRoute.value.path,'/businesses/setup-business');
    }finally{app.unmount();restore();}
});

test('a newly rejected markets write after creation reconciliation keeps the created identity and releases only that rejected command',async()=>{
    const restore=withSetupDraft({name:'Recovered creation',codes:['SA']});
    creationApi.calls.length=0;creationApi.pending.length=0;creationApi.factCalls.length=0;creationApi.factPending.length=0;
    const {root,app}=await mountSetup([setupCountry]);
    try{
        submitWizard(root);await flushFacts();creationApi.pending.shift().reject(Error('Creation ACK lost'));await flushFacts();const original=structuredClone(creationApi.calls[0]);
        const retry=find(root,'data-setup-reconcile').props.onClick();await flushFacts();assert.deepEqual(creationApi.calls[1],original);
        creationApi.pending.shift().resolve(setupReceipt('retained-created-identity','Recovered creation'));await flushFacts();
        const {TransportError}=await import(transportUrl),denied=new TransportError('Market request rejected');denied.status=403;creationApi.factPending.shift().reject(denied);await retry;await nextTick();
        assert.equal(find(root,'data-business-created'), undefined, 'An access denial conceals the previous receipt');
        assert.equal(readSetup(setupKey()).created, true, 'The qualified creation was already acknowledged');
        assert.deepEqual(readSetup(setupKey()).uncertain,[],'The new markets request was definitely rejected; it is not the reconciled creation');
        assert.equal(creationApi.calls.length,2);assert.equal(creationApi.factCalls.length,1);assert.equal(all(root).some(node=>node.props['data-business-unknown']!==undefined),false,'No unknown-outcome attribute is rendered after a definite rejection');assert.match(text(root),/لم يعد الحفظ متاحًا/);
    }finally{app.unmount();restore();}
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
        assert.match(text(root), /فريق النشاط/);
        const draft = drafts.directTeamDrafts.get(drafts.key('independent-team', selected.authority_selection));
        draft.confirmed = true; await nextTick();
        const first = button().props.onClick(); await nextTick();
        const original = structuredClone(api.calls[0]);
        assert.equal(original.business, 'independent-team'); assert.ok(original.body.command_id);
        assert.equal('workspace_public_id' in original.body, false, 'Direct Business team enrollment needs no organization identifier');
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
        assert.ok(find(root, 'data-organization-members')); assert.match(text(root), /لا تمنح هذه العضوية حقًا تجاريًا/);
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

test('agency task affordances offer only admitted work and retain an unknown task after withdrawal', async () => {
    const initial = { ...businessSurface('agency-task-client'), direct_team_enrollment_available: true,
        agency_task: { can_mandate: false, can_assign: false, agency_business_public_id: null } };
    const render = async value => {
        const app = createSSRApp(workspace.value, { surface: value });
        app.use(await routerAt('/businesses/agency-task-client?section=settings&settings=access'));
        return renderToString(app);
    };
    const unavailable = await render(initial);
    assert.doesNotMatch(unavailable, /data-business-task="business-agency"/);
    assert.match(unavailable, /data-agency-team-prerequisite/);
    const admitted = await render({ ...initial, agency_task: { can_mandate: true, can_assign: false, agency_business_public_id: null } });
    assert.match(admitted, /data-business-task="business-agency"/); assert.doesNotMatch(admitted, /data-agency-team-prerequisite/);
    const resource = drafts.key(initial.business.business_public_id, initial.authority_selection);
    drafts.agencyTeamDrafts.set(resource, { pending: { command_id: 'exact-unknown-command' } });
    try {
        const retained = await render(initial);
        assert.match(retained, /data-business-agency-editor/); assert.doesNotMatch(retained, /data-agency-team-prerequisite/);
    } finally { drafts.agencyTeamDrafts.delete(resource); }
});

test('other grant history is collapsed for a team owner and exact pending recovery opens the same mounted component', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    const initial = { ...businessSurface('collapsed-grant-history'), agency_task: { can_mandate: true, can_assign: false, agency_business_public_id: null } };
    const resource = `${drafts.key(initial.business.business_public_id, initial.authority_selection)}:delegations:1`;
    const retained = ref({ pending: undefined }); drafts.delegationDrafts.set(resource, retained.value);
    const root = element('root'), app = renderer.createApp(workspace.value, { surface: initial });
    app.use(await routerAt('/businesses/collapsed-grant-history?section=settings&settings=access')); app.mount(root); await nextTick();
    try {
        const history = find(root, 'data-business-other-grants'); assert.ok(history);
        assert.equal(history.props.open, false); assert.match(text(history), /تفويضات أخرى وسجل السحب/);
        const child = find(history, 'data-existing-editor'); assert.ok(child, 'The collapsed grant component remains mounted');
        retained.value.pending = { command_id: 'retained-original' }; await nextTick();
        assert.equal(history.props.open, true); assert.equal(find(history, 'data-existing-editor'), child, 'Recovery opens without remounting the writer');
        retained.value.pending = undefined; await nextTick(); assert.equal(find(history, 'data-existing-editor'), child);
    } finally { app.unmount(); drafts.delegationDrafts.delete(resource); globalThis.window = previousWindow; }
});

test('an employee client assignment pending in the canonical store protects departure and archive of the owning Business', () => {
    const owner = 'owning-business', clientResource = 'canonical-client-assignment';
    const ownResource = drafts.key(owner, { currentRead: 51 });
    drafts.teamMembersDrafts.set(ownResource, { assignment: { business: 'client-business', readGrant: '72', resource: clientResource, employee: 'employee-one' } });
    drafts.agencyTeamDrafts.set(clientResource, { pending: { command_id: 'original-client-command' } });
    try {
        assert.equal(drafts.hasPendingBusinessDraft(owner), true);
        assert.equal(drafts.teamMembersDrafts.get(ownResource).pending, undefined, 'The team draft stores a pointer, not a second copy of the command');
        drafts.agencyTeamDrafts.get(clientResource).pending = undefined;
        assert.equal(drafts.hasPendingBusinessDraft(owner), false);
        drafts.delegationDrafts.set(`${clientResource}:delegations:1`, { pending: { command_id: 'original-client-revoke' } });
        assert.equal(drafts.hasPendingBusinessDraft(owner), true, 'The canonical client revoke also protects its owning roster context');
        drafts.delegationDrafts.delete(`${clientResource}:delegations:1`);
    } finally { drafts.teamMembersDrafts.delete(ownResource); drafts.agencyTeamDrafts.delete(clientResource); drafts.delegationDrafts.delete(`${clientResource}:delegations:1`); }
});

test('generic grant issuance can be concealed without losing exact revoke and unknown-command recovery', async () => {
    const priorLocale = locale.value; locale.value = 'en'; drafts.delegationDrafts.clear();
    const port = moduleUrl('export const fixture="issuance-visibility",calls=[],pending=[];export const writeGrant=(business,body)=>{calls.push({business,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};');
    const Delegation = await component('resources/js/business-foundation/BusinessDelegation.vue', { './api': port });
    const api = await import(port), root = element('root');
    const initial = { ...businessSurface('generic-grant-client'), delegation: { actor_public_id: 'owner-one', options: [{ grant_id: '17', action: 'business.context.read', can_issue: true }],
        issued: [{ grant_id: '20', parent_grant_id: '17', recipient_public_id: 'recipient-one', action: 'business.context.read', recorded_status: 'active', revision: 1, realm_kind: 'direct', realm_workspace_public_id: null }], issued_more: false } };
    const selected = ref(initial), visible = ref(false);
    const app = renderer.createApp({ render: () => h(Delegation.value, { surface: selected.value, showIssuance: visible.value }) });
    app.use(await routerAt('/businesses/generic-grant-client')); app.mount(root);
    try {
        assert.ok(find(root, 'data-delegation-form') === undefined);
        const revoke = all(root).find(node => node.tag === 'button' && text(node) === 'Withdraw this right'); assert.ok(revoke);
        revoke.props.onClick(); await nextTick();
        assert.equal(api.calls.length, 1); const original = structuredClone(api.calls[0]);
        api.pending.shift().reject(Error('revoke acknowledgement lost')); await nextTick(); await nextTick();
        const recovery = find(root, 'data-delegation-form'); assert.ok(recovery);
        assert.equal(all(recovery).some(node => node.tag === 'input'), false, 'Recovery is not another issuance form');
        recovery.props.onSubmit({ preventDefault() {} }); await nextTick(); assert.deepEqual(api.calls[1], original);
        api.pending.shift().resolve({ business_public_id: 'generic-grant-client', status: 'revoked', grant_id: 20 }); await nextTick(); await nextTick();
        visible.value = true; selected.value = { ...initial, delegation: { ...initial.delegation, options: [{ ...initial.delegation.options[0], can_issue: false }] } }; await nextTick();
        assert.ok(find(root, 'data-delegation-form') === undefined, 'A revoke-only path never gets a disabled new-issuance form');
    } finally { app.unmount(); drafts.delegationDrafts.clear(); locale.value = priorLocale; }
});

test('Business lifecycle API adapter sends the exact existing HTTP contract and requires an acknowledgement', async () => {
    const httpUrl = moduleUrl(`export const calls=[];export let response={data:{data:{business_public_id:'adapter-business',state:'archived',versions:{lifecycle:8}}}};export const respond=value=>response=value;export const api={POST:async(path,options)=>{calls.push({path,options:structuredClone(options)});return response;}};`);
    const http = await import(httpUrl), adapter = await import(await scriptModule('resources/js/business-foundation/api.ts', { '../spa/api': httpUrl }));
    const original = { command_id: 'existing-command', effect: 'archive', target_ref: null, payload: {}, expected_revision: 7, authority_selection: { realm_kind: 'business', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 81 }] } };
    assert.deepEqual(await adapter.writeLifecycle('adapter-business', original), { business_public_id: 'adapter-business', state: 'archived', versions: { lifecycle: 8 } });
    assert.deepEqual(http.calls[0], { path: '/api/businesses/{business}/lifecycle', options: { params: { path: { business: 'adapter-business' } }, body: original } });
    http.respond({}); await assert.rejects(adapter.writeLifecycle('adapter-business', original), /acknowledgement unavailable/);
});

const lifecycleApiUrl = moduleUrl(`export const writes=[],pending=[];export const writeLifecycle=(business,body)=>{writes.push({business,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
const lifecycleApi = await import(lifecycleApiUrl);
const modalVueUrl = moduleUrl(`export * from ${JSON.stringify(vue)};export const Transition={setup(_props,{slots}){return()=>slots.default?.();}};`);
const canonicalModal = await component('resources/js/Components/Modal.vue', { vue: modalVueUrl });
const lifecycleComponent = await component('resources/js/business-foundation/BusinessLifecycle.vue', { './api': lifecycleApiUrl, '../Components/Modal.vue':canonicalModal.url });
const lifecycleSurface = (name = 'lifecycle-business') => ({ ...businessSurface(name), lifecycle: { revision: 7, archive_options: [{ grant_id: '81' }] } });
async function mountLifecycle({ selected = lifecycleSurface(), reset = true } = {}) {
    if (reset) { drafts.lifecycleDrafts.clear(); lifecycleApi.writes.length = 0; lifecycleApi.pending.length = 0; }
    const subject = ref(selected), router = await routerAt(`/businesses/${selected.business.business_public_id}?access=read-one`), root = element('root');
    let refreshed = 0;
    const app = renderer.createApp({ render: () => h(lifecycleComponent.value, { surface: subject.value, onRefresh: () => refreshed++ }) }); app.use(router); app.mount(root);
    return { app, root, subject, router, get refreshed() { return refreshed; }, get draft() { return drafts.lifecycleDrafts.get(drafts.key(subject.value.business.business_public_id, subject.value.authority_selection) + ':lifecycle:1'); } };
}
const lifecycleReceipt = (business = 'lifecycle-business', version = 8) => ({ business_public_id: business, state: 'archived', versions: { lifecycle: version } });
function lifecycleError(status, code = '') { const error = new transport.TransportError('Rejected'); error.status = status; error.body = { error: code }; return error; }

async function beginArchive(view) {
    find(view.root, 'data-business-archive').props.onClick(); await nextTick(); await nextTick();
    return { request: find(view.root, 'data-business-archive-confirm').props.onClick() };
}

test('qualified draft lifecycle uses the canonical confirmation and acknowledges only the exact archive before opening the qualified list', async () => {
    const previousConfirm = globalThis.confirm; let confirmed = false, prompts = 0;
    globalThis.confirm = () => { prompts++; return confirmed; };
    const view = await mountLifecycle();
    try {
        const archive = find(view.root, 'data-business-archive'); assert.ok(archive);
        archive.props.onClick(); await nextTick(); await nextTick();
        const cancel = find(view.root, 'data-business-archive-cancel'), confirm = find(view.root, 'data-business-archive-confirm');
        assert.equal(cancel.props.autofocus, ''); assert.match(text(find(view.root, 'data-business-archive-confirmation')), /lifecycle-business/);
        assert.equal(all(view.root).find(node=>node.tag==='dialog').open, true);
        cancel.props.onClick(); await nextTick(); assert.equal(lifecycleApi.writes.length, 0, 'Cancel sends nothing');
        const { request: first } = await beginArchive(view); find(view.root, 'data-business-archive-confirm').props.onClick(); await nextTick();
        assert.equal(lifecycleApi.writes.length, 1, 'Concurrent confirms cannot create another command');
        assert.ok(find(view.root, 'data-business-archive-processing'));
        assert.equal(find(view.root, 'data-business-archive-unknown'), undefined, 'In-flight delivery has no reported outcome yet');
        const original = lifecycleApi.writes[0];
        assert.equal(original.business, 'lifecycle-business'); assert.equal(original.body.effect, 'archive'); assert.equal(original.body.target_ref, null); assert.deepEqual(original.body.payload, {}); assert.equal(original.body.expected_revision, 7);
        assert.deepEqual(original.body.authority_selection, { realm_kind: 'business', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 81 }] });
        assert.match(original.body.command_id, /^[0-9a-f-]{36}$/i);
        lifecycleApi.pending.shift().resolve(lifecycleReceipt()); await first; await nextTick();
        assert.equal(view.router.currentRoute.value.path, '/businesses'); assert.equal(view.draft.pending, undefined); assert.equal(view.draft.uncertain, false); assert.ok(find(view.root, 'data-business-archive-saved'));
        assert.equal(find(view.root, 'data-business-archive'), undefined); assert.equal(all(view.root).some(node => node.tag === 'button' && /^(استعادة|Restore)/.test(text(node))), false, 'No restore action is offered');
    } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); globalThis.confirm = previousConfirm; }
});

test('qualified draft lifecycle retains the immutable original through unknown outcomes and replay rejections without inferred success', async t => {
    const previousConfirm = globalThis.confirm; globalThis.confirm = () => true;
    try {
        for (const status of [422, 409, 403]) await t.test(String(status), async () => {
            const view = await mountLifecycle();
            try {
                const { request: first } = await beginArchive(view), original = structuredClone(lifecycleApi.writes[0]);
                lifecycleApi.pending.shift().reject(Error('Archive acknowledgement lost')); await first; await nextTick();
                assert.equal(view.refreshed, 0, 'A read cannot settle an unknown archive'); assert.ok(find(view.root, 'data-business-archive-unknown')); assert.equal(find(view.root, 'data-business-archive'), undefined);
                view.draft.selected = 'different-grant'; view.draft.revision = 999;
                const retry = find(view.root, 'data-business-archive-reconcile').props.onClick(); await nextTick(); assert.deepEqual(lifecycleApi.writes[1], original);
                lifecycleApi.pending.shift().reject(lifecycleError(status, status === 409 ? 'business_conflict' : '')); await retry; await nextTick();
                assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original.body); assert.equal(view.draft.uncertain, true); assert.equal(view.router.currentRoute.value.path, '/businesses/lifecycle-business');
                assert.equal(find(view.root, 'data-business-archive-reconcile').props.disabled, status === 403);
                locale.value = 'en'; await nextTick(); assert.match(text(view.root), /archive outcome is unconfirmed/); locale.value = 'ar';
                if (status === 403) {
                    assert.ok(find(view.root, 'data-business-archive-denied')); assert.equal(view.refreshed, 1);
                    await find(view.root, 'data-business-archive-reconcile').props.onClick(); assert.equal(lifecycleApi.writes.length, 2, 'Denied authority blocks replay and alternate commands');
                } else {
                    const success = find(view.root, 'data-business-archive-reconcile').props.onClick(); assert.deepEqual(lifecycleApi.writes[2], original);
                    lifecycleApi.pending.shift().resolve(lifecycleReceipt()); await success; assert.equal(view.draft.pending, undefined); assert.equal(view.router.currentRoute.value.path, '/businesses');
                }
            } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); locale.value = 'ar'; }
        });
    } finally { globalThis.confirm = previousConfirm; }
});

test('qualified draft lifecycle first validation rejection allows only a newly confirmed command and version adoption is explicit', async () => {
    const previousConfirm = globalThis.confirm; let prompts = 0; globalThis.confirm = () => { prompts++; return true; };
    const view = await mountLifecycle();
    try {
        const { request: first } = await beginArchive(view), original = structuredClone(lifecycleApi.writes[0]);
        lifecycleApi.pending.shift().reject(lifecycleError(422)); await first; await nextTick();
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.uncertain, false); assert.ok(find(view.root, 'data-business-archive-rejected'));
        view.subject.value = { ...view.subject.value, lifecycle: { ...view.subject.value.lifecycle, revision: 8 } }; await nextTick();
        assert.equal(find(view.root, 'data-business-archive').props.disabled, true); assert.equal(view.draft.revision, 7);
        find(view.root, 'data-business-lifecycle-adopt').props.onClick(); await nextTick(); assert.equal(view.draft.revision, 8);
        const { request: next } = await beginArchive(view); assert.equal(lifecycleApi.writes.length, 2); assert.notEqual(lifecycleApi.writes[1].body.command_id, original.body.command_id); assert.equal(lifecycleApi.writes[1].body.expected_revision, 8);
        lifecycleApi.pending.shift().resolve(lifecycleReceipt('lifecycle-business', 9)); await next;
    } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); globalThis.confirm = previousConfirm; }
});

test('qualified draft lifecycle never substitutes a changed grant and retains an in-flight original through remount', async () => {
    const previousConfirm = globalThis.confirm; globalThis.confirm = () => true;
    let view = await mountLifecycle();
    try {
        const { request: first } = await beginArchive(view), original = structuredClone(lifecycleApi.writes[0]), late = lifecycleApi.pending.shift();
        view.app.unmount(); view = await mountLifecycle({ reset: false });
        assert.equal(lifecycleApi.writes.length, 1); assert.ok(find(view.root, 'data-business-archive-unknown'));
        late.resolve(lifecycleReceipt()); await first; assert.ok(view.draft.pending, 'A receipt from the unmounted owner cannot settle the current view');
        view.subject.value = { ...view.subject.value, lifecycle: { revision: 7, archive_options: [{ grant_id: '82' }] } }; await nextTick();
        assert.ok(find(view.root, 'data-business-archive-denied')); assert.equal(find(view.root, 'data-business-archive-reconcile').props.disabled, true);
        assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original.body); await find(view.root, 'data-business-archive-reconcile').props.onClick(); assert.equal(lifecycleApi.writes.length, 1);
        assert.equal(view.router.currentRoute.value.path, '/businesses/lifecycle-business');
    } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); globalThis.confirm = previousConfirm; }
});

test('qualified draft lifecycle rejects mismatched acknowledgements and unsafe grant projection without creating fallback authority', async t => {
    const previousConfirm = globalThis.confirm; globalThis.confirm = () => true;
    try {
        for (const receipt of [lifecycleReceipt('sibling-business'), lifecycleReceipt('lifecycle-business', 9), { business_public_id: 'lifecycle-business', branch_public_id: 'branch', status: 'archived', versions: { branch: 8 } }]) await t.test(JSON.stringify(receipt), async () => {
            const view = await mountLifecycle();
            try {
                const { request: first } = await beginArchive(view), original = structuredClone(lifecycleApi.writes[0]);
                lifecycleApi.pending.shift().resolve(receipt); await first; await nextTick();
                assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original.body); assert.ok(find(view.root, 'data-business-archive-unknown')); assert.equal(view.router.currentRoute.value.path, '/businesses/lifecycle-business');
            } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); }
        });
        const selected = lifecycleSurface(); selected.lifecycle.archive_options = [{ grant_id: '9007199254740993' }];
        const view = await mountLifecycle({ selected });
        try { assert.equal(find(view.root, 'data-business-archive'), undefined); assert.equal(lifecycleApi.writes.length, 0); } finally { view.app.unmount(); drafts.lifecycleDrafts.clear(); }
    } finally { globalThis.confirm = previousConfirm; }
});

test('archive safety recognizes every same-Business draft owner across admitted paths and ignores sibling and other-document scopes', async () => {
    const business = 'pending-business', selection = { realm_kind: 'agency', realm_workspace_public_id: 'other-path', requirements: [] };
    drafts.creation.set('setup-markets-original', business);
    try {
        assert.equal(businessPending.hasPendingBusinessOperation(business), true);
        assert.equal(businessPending.hasPendingBusinessOperation('sibling'), false);
    } finally {
        drafts.creation.clear();
    }
    const stores = ['drafts', 'factDrafts', 'linkDrafts', 'delegationDrafts', 'internalTeamDrafts', 'directTeamDrafts', 'purposeEnrollmentDrafts', 'dataUseDrafts', 'lifecycleDrafts'];
    for (const name of stores) {
        const store = drafts[name], resource = drafts.key(business, selection) + ':existing-owner:1', original = { pending: { command_id: 'exact-original' } };
        store.set(resource, original);
        try { assert.equal(businessPending.hasPendingBusinessOperation(business), true, name); assert.equal(businessPending.hasPendingBusinessOperation('pending-business-sibling'), false, name); assert.equal(store.get(resource), original); } finally { store.delete(resource); }
    }
    const wrongDocument = JSON.stringify(['another-document', business, selection, 'business.context:1']);
    drafts.drafts.set(wrongDocument, { pending: { command_id: 'foreign-document' } });
    try { assert.equal(businessPending.hasPendingBusinessOperation(business), false); } finally { drafts.drafts.delete(wrongDocument); }
    inquiryEditor.pendingCommands.set(`${transport.stamp()}:${business}:another-basis`, { kind: 'record', body: { command_id: 'inquiry-original' } });
    try { assert.equal(businessPending.hasPendingBusinessOperation(business), true); assert.equal(businessPending.hasPendingBusinessOperation('sibling'), false); } finally { inquiryEditor.pendingCommands.clear(); }
    nativePending.pending.set('workspace-owned', { command: { workspace: business, effect: 'disconnect' } });
    assert.equal(businessPending.hasPendingBusinessOperation(business), false, 'A Workspace with the same identifier is a different owner');
    nativePending.pending.set('business-owned', { command: { business, effect: 'disconnect' } });
    try { assert.equal(businessPending.hasPendingBusinessOperation(business), true); assert.equal(businessPending.hasPendingBusinessOperation('sibling'), false); } finally { nativePending.pending.clear(); }
    const scope = drafts.key(business, selection) + ':purpose'; let acknowledged = false;
    await purposeCommands.submitPurpose(scope, { command_id: 'purpose-original', purpose_id: 'purpose-one' }, {}, async () => { if (!acknowledged) throw Error('Lost purpose ACK'); return { purpose_id: 'purpose-one', revision_id: 'revision-one', version: 1 }; });
    assert.equal(businessPending.hasPendingBusinessOperation(business), true); assert.equal(businessPending.hasPendingBusinessOperation('sibling'), false);
    acknowledged = true; await purposeCommands.retryPurpose(scope, 'purpose-one', {}); assert.equal(businessPending.hasPendingBusinessOperation(business), false);
});

test('fresh archive checks live pending work again at final confirmation while preserving its own exact reconciliation', async () => {
    const view = await mountLifecycle(), resource = drafts.key('lifecycle-business', { realm_kind: 'other-admitted-path' }) + ':markets:1';
    try {
        find(view.root, 'data-business-archive').props.onClick(); await nextTick(); await nextTick();
        const original = { command_id: 'late-other-write' }; drafts.factDrafts.set(resource, { pending: original });
        await find(view.root, 'data-business-archive-confirm').props.onClick(); await nextTick();
        assert.equal(lifecycleApi.writes.length, 0); assert.equal(drafts.factDrafts.get(resource).pending, original); assert.ok(find(view.root, 'data-business-archive-pending-work')); assert.equal(find(view.root, 'data-business-archive').props.disabled, true);
        drafts.factDrafts.delete(resource);
        const sibling = drafts.key('unrelated-business', {}) + ':markets:1'; drafts.factDrafts.set(sibling, { pending: { command_id: 'sibling-request' } });
        const { request } = await beginArchive(view); lifecycleApi.pending.shift().reject(Error('Archive ACK lost')); await request; await nextTick();
        assert.ok(view.draft.pending); drafts.factDrafts.set(resource, { pending: original });
        const reconcile = find(view.root, 'data-business-archive-reconcile'); assert.equal(reconcile.props.disabled, false, 'Other work cannot prevent checking an already-issued archive');
        const replay = reconcile.props.onClick(); assert.deepEqual(lifecycleApi.writes[1], lifecycleApi.writes[0]); lifecycleApi.pending.shift().resolve(lifecycleReceipt()); await replay;
        drafts.factDrafts.delete(sibling);
    } finally { view.app.unmount(); drafts.factDrafts.delete(resource); drafts.lifecycleDrafts.clear(); }
});

test('actual Business route guards permit local reconciliation navigation and still guard read-path and Business departures', async () => {
    const previousWindow = globalThis.window, previousConfirm = globalThis.confirm; let prompts = 0;
    globalThis.window = { scrollY: 0, scrollTo() {}, matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    globalThis.confirm = () => { prompts++; return false; };
    const selected = businessSurface('guard-business'), root = element('root'), app = renderer.createApp({ render: () => h(RouterView) });
    const router = createRouter({ history: createMemoryHistory(), routes: routes.map(route => route.path === '/businesses/:business' ? { ...route, component: workspace.value, props: () => ({ surface: selected }) } : route) });
    await router.push('/businesses/guard-business?access=17'); await router.isReady(); app.use(router); app.mount(root);
    const pending = { command: { business: 'guard-business', effect: 'disconnect' } }; nativePending.pending.set('guard-original', pending);
    const enrollmentKey = drafts.key(selected.business.business_public_id, selected.authority_selection) + ':purpose-enrollment:1'; drafts.purposeEnrollmentDrafts.set(enrollmentKey, { pending: { command_id: 'purpose-enrollment-original' } });
    try {
        await router.push('/businesses/guard-business?access=17&section=settings&settings=purposes'); await nextTick();
        assert.equal(prompts, 0); assert.equal(router.currentRoute.value.query.settings, 'purposes'); assert.equal(nativePending.pending.get('guard-original'), pending);
        await router.push('/businesses/guard-business?access=17&section=sources'); await nextTick(); assert.equal(prompts, 0); assert.equal(router.currentRoute.value.query.section, 'sources');
        await router.push('/businesses/guard-business?access=18&section=settings'); assert.equal(prompts, 1); assert.equal(router.currentRoute.value.query.access, '17');
        await router.push('/businesses/another-business?access=17'); assert.equal(prompts, 2); assert.equal(router.currentRoute.value.params.business, 'guard-business');
        assert.equal(drafts.purposeEnrollmentDrafts.get(enrollmentKey).pending.command_id, 'purpose-enrollment-original');
    } finally { app.unmount(); nativePending.pending.clear(); drafts.purposeEnrollmentDrafts.delete(enrollmentKey); globalThis.window = previousWindow; globalThis.confirm = previousConfirm; }
});

test('Business lifecycle settings require an archive projection or the same scoped unresolved original', async () => {
    const selected = businessSurface('lifecycle-placement'); selected.context_write_options = [{ grant_id: '999' }];
    async function html(subject) { const app = createSSRApp(workspace.value, { surface: subject }); app.use(await routerAt(`/businesses/${subject.business.business_public_id}?section=settings&settings=lifecycle`)); return renderToString(app); }
    const hidden = await html(selected); assert.doesNotMatch(hidden, /data-workspace-settings="lifecycle"/); assert.match(hidden, /data-workspace-settings-panel="details"/);
    const qualified = await html({ ...selected, lifecycle: { revision: 7, archive_options: [{ grant_id: '81' }] } }); assert.match(qualified, /data-workspace-settings="lifecycle"/); assert.match(qualified, /data-workspace-settings-panel="lifecycle"/);
    const resource = drafts.key(selected.business.business_public_id, selected.authority_selection) + ':lifecycle:1';
    drafts.lifecycleDrafts.set(resource, { selected: '81', revision: 7, blocked: true, outcome: 'unknown', pending: { effect: 'archive', command_id: 'retained', expected_revision: 7, payload: {}, target_ref: null, authority_selection: selected.authority_selection } });
    try {
        assert.match(await html(selected), /data-workspace-settings="lifecycle"/, 'The same scoped unresolved request remains discoverable without granting an action');
        assert.doesNotMatch(await html(businessSurface('sibling-placement')), /data-workspace-settings="lifecycle"/);
    } finally { drafts.lifecycleDrafts.clear(); }
});

test('Business lifecycle stays document scoped while overview offers one next action and declared-business shortcuts', async () => {
    const selected = businessSurface('work-review-current');
    const app = createSSRApp(workspace.value, { surface: selected }); app.use(await routerAt('/businesses/work-review-current'));
    const html = await renderToString(app), overview = html.match(/<section[^>]*data-workspace-panel="overview"[\s\S]*?<section[^>]*data-workspace-panel="direction"|<section[^>]*data-workspace-panel="overview"[\s\S]*/)?.[0];
    assert.ok(overview); assert.equal((overview.match(/data-business-review-task/g) ?? []).length, 1); assert.match(overview, /data-current-work/); assert.match(overview, /معلومات أضفتها عن نشاطك/); assert.match(overview, /workspace-task-list/); assert.doesNotMatch(overview, /data-business-performance|data-business-revenue/);
    const resource = drafts.key(selected.business.business_public_id, selected.authority_selection) + ':lifecycle:1';
    drafts.lifecycleDrafts.set(resource, { revision: 7, selected: '81', blocked: false, outcome: 'unknown', pending: { command_id: 'scoped-original' } });
    for (const close of transport.callbacks) close();
    assert.equal(drafts.lifecycleDrafts.size, 0, 'Closing the document clears its lifecycle command store');
});

const choice = (business, grant = '17', name = business) => ({ business_public_id: business, read_grant_id: grant, name, state: 'declared_draft', realm_kind: 'business', realm_workspace_public_id: null });
const choicePage = (items, next_cursor = null, fingerprint = 'authority-one') => ({ items, next_cursor, scope: { principal_generation: 1, authority_fingerprint: fingerprint } });
let choicesScenario = 0;
async function controlledChoices() {
    const api = moduleUrl(`export const scenario=${++choicesScenario},calls=[],pending=[];export const businessChoices=after=>{calls.push(after);return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
    const url = await scriptModule('resources/js/business-foundation/businessChoices.ts', { './api': api });
    return { url, module: await import(url), api: await import(api) };
}

test('accepted Vue shell choices paginate exact qualified paths and reject changed authority scope and unsafe cursors', async () => {
    const scenario = await controlledChoices(), model = scenario.module.createBusinessChoices();
    try {
        const first = model.load(); assert.equal(model.phase.value, 'loading');
        scenario.api.pending.shift().resolve(choicePage([choice('same', '17'), choice('same', '18')], '25')); await first;
        assert.equal(model.items.value.length, 2, 'Two grants for the same Business remain distinct choices');
        const more = model.load(true); assert.deepEqual(scenario.api.calls, [undefined, 25]);
        scenario.api.pending.shift().resolve(choicePage([choice('same', '17'), choice('other')], '26')); await more;
        assert.equal(model.items.value.length, 3, 'Pagination deduplicates only an identical qualified path');
        const changed = model.load(true); scenario.api.pending.shift().resolve(choicePage([choice('unrelated')], null, 'new-authority')); await changed;
        assert.equal(model.phase.value, 'error'); assert.deepEqual(model.items.value, []); assert.equal(model.nextCursor.value, null);
        const reload = model.load(); scenario.api.pending.shift().resolve(choicePage([choice('valid')], '9007199254740993')); await reload;
        const count = scenario.api.calls.length; await model.load(true);
        assert.equal(scenario.api.calls.length, count, 'An unsafe integer cursor never reaches the API'); assert.equal(model.phase.value, 'error');
    } finally { model.dispose(); }
});

test('accepted Vue shell choices discard superseded, departed and closed asynchronous reads and distinguish empty from failed', async () => {
    const scenario = await controlledChoices(), model = scenario.module.createBusinessChoices();
    try {
        const stale = model.load(), current = model.load();
        scenario.api.pending[1].resolve(choicePage([choice('current')])); await current;
        scenario.api.pending[0].resolve(choicePage([choice('late-secret')])); await stale; scenario.api.pending.length = 0;
        assert.deepEqual(model.items.value.map(item => item.name), ['current']);
        const departed = model.load(); transport.advance(); scenario.api.pending.shift().resolve(choicePage([choice('old-principal')])); await departed;
        assert.deepEqual(model.items.value, [], 'A departed ownership stamp cannot publish its metadata');
        const empty = model.load(); scenario.api.pending.shift().resolve(choicePage([])); await empty;
        assert.equal(model.phase.value, 'ready'); assert.equal(model.items.value.length, 0);
        const failed = model.load(); scenario.api.pending.shift().reject(Error('Unavailable')); await failed;
        assert.equal(model.phase.value, 'error');
        const closed = model.load(); model.clear(); scenario.api.pending.shift().resolve(choicePage([choice('closed-secret')])); await closed;
        assert.equal(model.phase.value, 'closed'); assert.deepEqual(model.items.value, []);
        const calls = scenario.api.calls.length; await model.load(); assert.equal(scenario.api.calls.length, calls);
    } finally { model.dispose(); }
});

test('accepted Vue shell switcher retains admitted selection outside the page, refreshes its name and emits an exact opaque path', async () => {
    const selected = ref(choice('current', '9007199254740993', 'Admitted current name'));
    const items = ref([choice('duplicate-A', '21', 'Shared name'), choice('duplicate-B', '22', 'Shared name')]);
    const selections = [], root = element('root'), app = renderer.createApp({ render: () => h(switcher.value, { id: 'choice-test', selected: selected.value, items: items.value, phase: 'ready', more: true, onSelect: item => selections.push(item) }) });
    app.use(await routerAt('/businesses/current')); app.mount(root);
    try {
        const select = find(root, 'data-business-switch'), options = () => all(select).filter(node => node.tag === 'option');
        assert.equal(options().length, 3); assert.match(text(options()[0]), /Admitted current name/);
        assert.notEqual(text(options()[1]), text(options()[2]), 'Same-name businesses remain distinguishable');
        items.value = [choice('current', '9007199254740993', 'Stale catalog name'), ...items.value]; await nextTick();
        assert.doesNotMatch(text(select), /Stale catalog name/); assert.match(text(select), /Admitted current name/);
        selected.value = { ...selected.value, name: 'New admitted name' }; await nextTick(); assert.match(text(select), /New admitted name/);
        const { businessChoiceKey, businessChoiceDestination } = await import(choicesUrl);
        select.value = businessChoiceKey(items.value[1]); select.props.onChange({ target: select });
        assert.equal(select.value, businessChoiceKey(selected.value), 'The control resets until destination admission succeeds');
        assert.deepEqual(selections, [items.value[1]]);
        assert.deepEqual(businessChoiceDestination(selected.value), { path: '/businesses/current', query: { access: '9007199254740993' } });
        assert.ok(find(root, 'data-business-choices-more')); assert.ok(find(root, 'data-business-create-entry'), 'The creation link delegates its qualification to the existing creation route');
    } finally { app.unmount(); }
});

test('Business picker distinguishes colliding exact access paths and organization scopes without decorating unambiguous labels', async () => {
    const items = [choice('same-business', '17', 'Shared business'), choice('same-business', '18', 'Shared business'),
        { ...choice('agency-business', '21', 'Agency client'), realm_kind: 'agency', realm_workspace_public_id: 'agency-one' },
        { ...choice('agency-business', '22', 'Agency client'), realm_kind: 'agency', realm_workspace_public_id: 'agency-two' }, choice('unique-business', '31', 'Unique business')];
    const root = element('root'), app = renderer.createApp(switcher.value, { id: 'collision-picker', items, selected: items[0], phase: 'ready', more: false }); app.use(await routerAt('/businesses/same-business')); app.mount(root);
    try {
        const options = all(find(root, 'data-business-switch')).filter(node => node.tag === 'option'); assert.equal(options.length, 5); assert.equal(new Set(options.map(text)).size, 5);
        assert.match(text(options[0]), /مسار وصول 1/); assert.match(text(options[1]), /مسار وصول 2/); assert.doesNotMatch(text(options[4]), /مسار وصول/);
        locale.value = 'en'; await nextTick(); assert.match(text(options[2]), /Access path 1/); assert.match(text(options[3]), /Access path 2/); assert.doesNotMatch(text(options[4]), /Access path/);
        const keys = items.map((item) => JSON.stringify([item.business_public_id, item.read_grant_id, item.realm_kind, item.realm_workspace_public_id])); assert.deepEqual(options.map(node => node.props.value), keys);
    } finally { app.unmount(); locale.value = 'ar'; }
});

test('accepted Vue shell selection and sidebar brand use existing router guards without optimistic identity changes', async () => {
    const scenario = await controlledChoices();
    const guardedLayout = await component('resources/js/business-foundation/AdvertiserWorkspaceLayout.vue', { './businessChoices': scenario.url, '../Components/BrandLogo.vue': brandLogo.url, './BusinessSwitcher.vue': switcher.url });
    const previousWindow = globalThis.window; globalThis.window = { matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    const router = await routerAt('/businesses/current?access=17'), attempted = [];
    router.beforeEach(to => { attempted.push(to.fullPath); return false; });
    const selected = choice('current'), root = element('root'), app = renderer.createApp(guardedLayout.value, { selection: selected, businessSelected: true }); app.use(router); app.mount(root);
    try {
        scenario.api.pending.shift().resolve(choicePage([choice('destination', '25')])); await nextTick(); await nextTick();
        const select = find(root, 'data-business-switch'), { businessChoiceKey } = scenario.module;
        select.value = businessChoiceKey(choice('destination', '25')); select.props.onChange({ target: select }); await flushFacts();
        assert.equal(router.currentRoute.value.fullPath, '/businesses/current?access=17'); assert.equal(select.value, businessChoiceKey(selected));
        const brand = all(root).find(node => node.tag === 'a' && node.props.class === 'ba-logo'); assert.equal(brand.props.href, '/businesses');
        await brand.props.onClick({ button: 0, preventDefault() {}, currentTarget: { getAttribute() { return null; } } }); await nextTick();
        assert.equal(router.currentRoute.value.fullPath, '/businesses/current?access=17');
        assert.deepEqual(attempted, ['/businesses/destination?access=25', '/businesses']);
        transport.documentStatus.value = 'closed'; await nextTick(); assert.equal(select.props.disabled, true); assert.doesNotMatch(text(select), /destination/);
    } finally { app.unmount(); transport.documentStatus.value = 'ready'; globalThis.window = previousWindow; }
});

test('mobile organization disclosures remain open and destinations dismiss the native modal with focus restoration', async () => {
    const previousWindow = globalThis.window;
    globalThis.window = { matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) };
    headerHost = element('header');
    const root = element('root'), app = renderer.createApp({ render: () => h(layout.value, { workspaceId: 'workspace-mobile', context: 'Workspace M', workspaceContext: true }) });
    app.use(await routerAt('/workspaces/workspace-mobile')); app.mount(root); await nextTick();
    try {
        const modal = find(root, 'data-advertiser-drawer'), toggle = find(headerHost, 'data-advertiser-menu-toggle');
        toggle.props.onClick(); assert.equal(modal.open, true); assert.equal(modal.modalCount, 1);
        assert.equal(all(root).filter(node => 'data-business-switch' in node.props).length, 1, 'Only the mobile selector is mounted');
        const body = all(root).find(node => node.props.class === 'advertiser-drawer-body');
        assert.equal(find(root, 'data-workspace-previous-navigation'), undefined);
        assert.equal(all(root).find(node => node.props.class === 'advertiser-organization-tools'), undefined);
        body.props.onClick({ target: all(root).find(node => node.tag === 'a' && node.props.href === '/profile') });
        assert.equal(modal.open, false); assert.equal(toggle.focusCount, 1, 'Closing restores focus to the menu trigger');
        toggle.props.onClick(); modal.props.onClick({ target: modal, clientX: 350, clientY: 50 });
        assert.equal(modal.open, false, 'Only backdrop clicks outside the dialog bounds dismiss it');
    } finally { app.unmount(); headerHost = undefined; globalThis.window = previousWindow; }
});

test('mobile destination buttons dismiss the drawer and resizing closes only the navigation dialog', async () => {
    const previousWindow = globalThis.window; let mediaChanged;
    const media = { matches: false, addEventListener(_name, fn) { mediaChanged = fn; }, removeEventListener() {} };
    globalThis.window = { matchMedia: () => media }; headerHost = element('header');
    const root = element('root'), app = renderer.createApp({ render: () => h(layout.value, {}, { navigation: () => h('nav', [h('button', { type: 'button', 'data-workspace-section': 'direction' }, [h('span', 'Markets')])]) }) });
    app.use(await routerAt('/businesses/mobile')); app.mount(root); await nextTick();
    try {
        const modal = find(root, 'data-advertiser-drawer'), toggle = find(headerHost, 'data-advertiser-menu-toggle');
        toggle.props.onClick();
        const label = all(root).find(node => node.tag === 'span' && text(node) === 'Markets');
        all(root).find(node => node.props.class === 'advertiser-drawer-body').props.onClick({ target: label });
        assert.equal(modal.open, false, 'Nested button content resolves to its destination');
        toggle.props.onClick(); const confirmation = element('dialog'); confirmation.showModal();
        media.matches = true; mediaChanged(); await nextTick();
        assert.equal(modal.open, false); assert.equal(confirmation.open, true, 'Resize leaves unrelated confirmation dialogs alone');
        assert.equal(all(root).filter(node => 'data-workspace-section' in node.props).length, 1);
        assert.equal(all(root).filter(node => 'data-business-switch' in node.props).length, 1);
        assert.ok(find(root, 'id', 'businessSelect'));
    } finally { app.unmount(); headerHost = undefined; globalThis.window = previousWindow; }
});

test('Business creation clears a localized country search without discarding selected markets or sending commands', async () => {
    const restore = withSetupDraft({}, 2); locale.value = 'ar';
    const countries = [setupCountry, { code: 'JO', name_ar: 'الأردن', name_en: 'Jordan' }];
    const initialWrites = creationApi.calls.length + creationApi.factCalls.length;
    const {root,app}=await mountSetup(countries);
    const country = code => all(root).find(node => node.tag === 'input' && node._value === code);
    try {
        country('SA').checked = true; country('SA').dispatchEvent({ type: 'change' }); await flushFacts();
        await inputFact(root, 'bf-create-market-search', 'Jordan');
        assert.equal(country('SA'), undefined); country('JO').checked = true; country('JO').dispatchEvent({ type: 'change' }); await flushFacts();
        await inputFact(root, 'bf-create-market-search', 'no-matching-country');
        assert.match(text(find(root, 'data-create-market-search-empty')), /لا توجد دول مطابقة/);
        assert.equal(text(find(root, 'data-create-market-search-clear')), 'مسح البحث');
        locale.value = 'en'; await nextTick();
        assert.equal(text(find(root, 'data-create-market-search-empty')), 'No countries match your search.');
        assert.equal(text(find(root, 'data-create-market-search-clear')), 'Clear search');
        find(root, 'data-create-market-search-clear').props.onClick(); await flushFacts();
        assert.equal(find(root, 'id', 'bf-create-market-search').value, '');
        assert.equal(find(root, 'id', 'bf-create-market-search').focusCount, 1, 'Clear returns keyboard focus to the retained search input');
        assert.equal(find(root, 'data-create-market-search-empty'), undefined); assert.equal(find(root, 'data-create-market-search-clear'), undefined);
        assert.equal(country('SA').checked, true); assert.equal(country('JO').checked, true);
        assert.deepEqual(readSetup(setupKey()).answers.codes.sort(), ['JO', 'SA']);
        assert.equal(creationApi.calls.length + creationApi.factCalls.length, initialWrites, 'Search and clear are local presentation only');
    } finally { app.unmount(); restore(); locale.value = 'ar'; }
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
        assert.match(html, /اختر الجهة التي تدير حساب/);
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
            locale.value='en';await nextTick();assert.match(text(root),/Preserved original custody/);assert.doesNotMatch(text(root),/Workspace resources/);locale.value='ar';await nextTick();
            assert.equal(dialog.open,true); assert.equal(dialog.modalCount,1); assert.equal(dialog.props['aria-label'],expected);
            app.unmount();
        }
    } finally {globalThis.window=previousWindow;globalThis.location=previousLocation;}
});

test('dismissing a provider result clears only its acknowledged return marker and retains qualified routing and opaque flows', async () => {
    const previousWindow = globalThis.window;
    try {
        for (const [hash, remaining] of [
            ['#google-result=AUTHORIZATION_NOT_COMPLETED', ''],
            ['#google-result=AUTHORIZATION_NOT_COMPLETED&x-flow=original-opaque-flow', '#x-flow=original-opaque-flow'],
            ['#google-flow=original-unknown-selection', '#google-flow=original-unknown-selection'],
        ]) {
            globalThis.window = { location: { hash } };
            const root = element('root'), router = await routerAt('/businesses/native-one?access=51&section=sources'+hash);
            const app = renderer.createApp({ render: () => h(actualAccounts.value, { businessId: 'native-one', canManage: true }) });
            app.use(router); app.mount(root); await nextTick(); await nextTick();
            try {
                const dialog = all(root).find(node => node.tag === 'dialog');
                assert.equal(dialog.open, true); assert.equal(dialog.props['aria-label'], 'Google Ads');
                all(dialog).find(node => node.tag === 'button').props.onClick();
                await new Promise(resolve => setImmediate(resolve)); await nextTick();
                assert.equal(dialog.open, false);
                assert.equal(router.currentRoute.value.path, '/businesses/native-one');
                assert.deepEqual(router.currentRoute.value.query, { access: '51', section: 'sources' });
                assert.equal(router.currentRoute.value.hash, remaining);
                assert.equal(dialog.modalCount, 1, 'Dismissing a result must not open a different provider during URL requalification');
            } finally { app.unmount(); }
        }
    } finally { globalThis.window = previousWindow; }
});


test('fresh actual data-use draft publishes pending true and false to its contextual task and reopens the original command', async () => {
    const dataUseApi = moduleUrl('export const dataUseChoices=async()=>({scopes:[],next_cursor:null});export const writeBinding=async()=>{throw Error("No command expected")};export const writeResourceRight=async()=>{throw Error("No command expected")};');
    const actualDataUse = await component('resources/js/business-foundation/BusinessDataUse.vue', {'./api':dataUseApi});
    const actualWorkspace = await component('resources/js/business-foundation/BusinessWorkspace.vue', {'./AdvertiserWorkspaceLayout.vue':layout.url,'../inquiry-evidence/InquiryEvidencePanel.vue':inquiryUrl,...childPorts,'../workspaces/OrganizationTeam.vue':plainUrl,'./DirectBusinessTeam.vue':plainUrl,'./BusinessDataUse.vue':actualDataUse.url,'./BusinessPurposes.vue':purposePanelUrl});
    const previousWindow=globalThis.window;
    globalThis.window={scrollY:0,scrollTo(){},matchMedia:()=>({matches:true,addEventListener(){},removeEventListener(){}})};
    presentation.workspacePresentation.clear();
    const selected={...businessSurface('fresh-pending-business'),collaboration:{options:[{grant_id:'1'}]},data_use:{binding_options:[{grant_id:'1'}],bindings:[],bindings_more:false}};
    const cacheKey=drafts.key(selected.business.business_public_id,selected.authority_selection)+':data-use:1';
    drafts.dataUseDrafts.delete(cacheKey);
    const root=element('root'),app=renderer.createApp({render:()=>h(actualWorkspace.value,{surface:selected,refreshing:false})});
    app.use(await routerAt('/businesses/fresh-pending-business'));app.mount(root);
    try {
        await find(root,'data-workspace-section','sources').props.onClick();await nextTick();
        const advanced=find(root,'data-business-source-advanced');
        assert.equal(advanced.style.display,'none');assert.equal(find(root,'data-business-source-pending'),undefined);
        const freshDraft=drafts.dataUseDrafts.get(cacheKey);assert.ok(freshDraft);assert.equal(freshDraft.pending,undefined);
        freshDraft.pending={kind:'binding',business:'fresh-pending-business',body:{command_id:'exact-pending-command',data_use_scope_id:'scope-one',effect:'add',expected_relation:0,authority_selection:selected.authority_selection}};
        await nextTick();
        const notice=find(root,'data-business-source-pending');assert.ok(notice);assert.equal(advanced.style.display,'none','Pending notice remains outside the inactive editor');
        assert.equal(all(advanced).includes(notice),false);
        all(notice).find(node=>node.tag==='button').props.onClick();await nextTick();assert.notEqual(advanced.style.display,'none');
        assert.ok(find(root,'data-data-use-reconcile'),'The original pending command action is reachable');
        freshDraft.pending=undefined;await nextTick();
        assert.equal(find(root,'data-business-source-pending'),undefined);assert.equal(find(root,'data-data-use-reconcile'),undefined);
        assert.notEqual(advanced.style.display,'none','Resolving does not unexpectedly close the user-opened task');
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

test('internal file custody distinguishes initial rejection from an unknown original replay after loss or remount', async t => {
    for (const mode of ['initial', 'unknown', 'remount']) for (const status of [422, 409]) await t.test(`${mode} ${status}`, async () => {
        const restoreDom = withFactDom();
        const custodyApi = moduleUrl(`export const scenario=${JSON.stringify(mode + status)},writes=[],pending=[],reads=[];export const internalTeamSurface=async(business)=>{reads.push(business);return {business_public_id:business,creator_basis:true,can_admin:true,teams:[]};};export const writeInternalTeam=(business,body)=>{writes.push({business,body:structuredClone(body)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
        const custody = await component('resources/js/business-foundation/InternalClientFileTeam.vue', { './api': custodyApi });
        const api = await import(custodyApi), { TransportError } = await import(transportUrl);
        const business = '01a1a326-5588-75c2-b5b3-392a12242d37', team = '01a1a326-5588-75c2-b5b3-392a12242d38', actor = '01a1a326-5588-75c2-b5b3-392a12242d39';
        const selected = { ...businessSurface(business), authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 51 }] }, delegation: { actor_public_id: actor }, collaboration: { workspaces: [{ public_id: team, slug: 'Qualified team' }] } };
        const cacheKey = drafts.key(business, selected.authority_selection) + ':internal-team:1'; drafts.internalTeamDrafts.delete(cacheKey);
        let root, app, departedRequest;
        const mount = async () => { root = element('root'); app = renderer.createApp({ render: () => h(custody.value, { surface: selected, active: true }) }); app.use(await routerAt(`/businesses/${business}`)); app.mount(root); await flushFacts(); };
        try {
            await mount(); const draft = drafts.internalTeamDrafts.get(cacheKey); draft.workspace = team; await nextTick(); draft.confirmed = true; await nextTick();
            all(root).find(node => node.tag === 'button' && text(node) === 'تفعيل إدارة الفريق لهذا الملف').props.onClick(); await nextTick();
            const original = structuredClone(api.writes[0]);
            if (mode === 'unknown') { api.pending.shift().reject(Error('ACK lost')); await flushFacts(); }
            if (mode === 'remount') { departedRequest = api.pending.shift(); app.unmount(); await mount(); }
            if (mode !== 'initial') {
                assert.equal(api.writes.length, 1, 'Loss or remount never automatically sends a command');
                all(root).find(node => node.tag === 'button' && text(node) === 'التحقق بالطلب الأصلي').props.onClick(); await nextTick(); assert.deepEqual(api.writes[1], original);
            }
            const rejection = new TransportError('Rejected'); rejection.status = status; rejection.body = { error: status === 409 ? 'business_relation_revision_rejected' : 'validation_rejected' };
            api.pending.shift().reject(rejection); await flushFacts();
            if (mode === 'initial' || status === 409) {
                assert.equal(draft.pending, undefined, 'A definitive first rejection releases only the rejected command'); assert.equal(draft.confirmed, false);
                assert.equal(draft.uncertain, false);
                assert.equal(api.reads.length, mode === 'remount' ? 3 : 2, 'Definitive rejection refreshes qualified state; canonical typed CAS follows receipt lookup'); assert.equal(all(root).find(node => node.tag === 'fieldset').props.disabled, false);
                draft.confirmed = true; await nextTick(); all(root).find(node => node.tag === 'button' && text(node) === 'تفعيل إدارة الفريق لهذا الملف').props.onClick(); await nextTick();
                assert.equal(api.writes.length, mode === 'initial' ? 2 : 3); assert.notEqual(api.writes.at(-1).body.command_id, original.body.command_id, 'A newly reviewed definitive rejection permits a new identity');
            } else {
                assert.deepEqual(draft.pending && JSON.parse(JSON.stringify(draft.pending)), original.body, 'A replay rejection cannot discard an earlier possibly committed original');
                assert.equal(draft.uncertain, true);
                assert.equal(all(root).find(node => node.tag === 'fieldset').props.disabled, true); assert.ok(find(root, 'data-internal-team-pending'));
                assert.equal(api.writes.length, 2); assert.equal(api.reads.length, mode === 'remount' ? 2 : 1, 'Replay failure never replaces the immutable original with a refreshed revision');
                if (departedRequest) { departedRequest.resolve({ business_public_id: business, workspace_public_id: team, recipient_public_id: actor, grant_refs: { 'business.context.read': '99' } }); await flushFacts(); assert.deepEqual(JSON.parse(JSON.stringify(draft.pending)), original.body, 'Departed receipt cannot settle current presentation'); }
                draft.workspace = '01a1a326-5588-75c2-b5b3-392a12242d40'; await nextTick();
                all(root).find(node => node.tag === 'button' && text(node) === 'التحقق بالطلب الأصلي').props.onClick(); await nextTick(); assert.deepEqual(api.writes[2], original);
                api.pending.shift().resolve({ business_public_id: business, workspace_public_id: team, recipient_public_id: actor, grant_refs: { 'business.context.read': '99' } }); await flushFacts();
                assert.equal(draft.pending, undefined); assert.ok(find(root, 'data-open-internal-team'));
                assert.equal(draft.uncertain, false, 'A matching current receipt alone settles the original unknown outcome');
            }
        } finally { app?.unmount(); drafts.internalTeamDrafts.delete(cacheKey); restoreDom(); }
    });
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
        await find(root,'data-workspace-section','settings').props.onClick();await nextTick();
        assert.equal(find(root,'data-private-history-reader'),undefined);
        await find(root,'data-workspace-settings','data').props.onClick();await nextTick();
        assert.equal(find(root,'data-test-inquiry'),undefined);assert.equal(events.length,0);assert.match(text(root),/صلاحية مستقلة/);
        await find(root,'data-workspace-section','access').props.onClick();await nextTick();
        assert.equal(find(root,'data-own-membership'),undefined,'Workspace membership has no public Business team surface');
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
async function mountTeam(reset = true) {
    const restoreDom = withFactDom();
    if (reset) {
        teamAdapter.teamDrafts.clear();
        for (const entries of [teamHttp.reads, teamHttp.writes, teamHttp.readPending, teamHttp.writePending]) entries.length = 0;
    }
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
        assert.equal(view.draft.uncertain, false);
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
        assert.equal(view.draft.uncertain, false);
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

test('organization Team replay rejection preserves an earlier unknown original through 403, 409 and 422', async t => {
    for (const status of [403, 409, 422]) await t.test(String(status), async () => {
        const view = await mountTeam();
        try {
            view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
            const original = structuredClone(teamHttp.writes[0].options.body);
            teamHttp.writePending.shift().reject(Error('Original acknowledgement lost')); await settleTeam();
            find(view.root, 'data-organization-retry').props.onClick();
            assert.deepEqual(teamHttp.writes[1].options.body, original);
            teamHttp.writePending.shift().reject(new teamTransport.TransportError(status)); await settleTeam();
            assert.deepEqual(view.draft.pending && JSON.parse(JSON.stringify(view.draft.pending)), original, 'A replay rejection cannot prove the earlier request did not commit');
            assert.equal(view.draft.uncertain, true);
            assert.equal(teamAdapter.hasPendingTeamCommand(), true);
            assert.equal(teamHttp.writes.length, 2); assert.equal(teamHttp.reads.length, 1, 'No read can replace unresolved command identity');
            assert.equal(all(view.root).find(node => node.tag === 'fieldset').props.disabled, true);
            assert.equal(find(view.root, 'data-organization-confirm').props.disabled, true);
            const retry = find(view.root, 'data-organization-retry');
            assert.equal(retry.props.disabled, status === 403, 'Denied authority still blocks explicit replay');
            view.draft.recipient = 'alternate-employee'; view.draft.confirmed = true;
            retry.props.onClick(); await settleTeam();
            if (status === 403) assert.equal(teamHttp.writes.length, 2);
            else {
                assert.deepEqual(teamHttp.writes[2].options.body, original, 'Another explicit check uses only the immutable original');
                teamHttp.writePending.shift().resolve({ data: { data: { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' } } }); await settleTeam();
                assert.equal(view.draft.pending, undefined); assert.equal(teamAdapter.hasPendingTeamCommand(), false);
                assert.equal(view.draft.uncertain, false, 'Only a matching acknowledged receipt settles an uncertain operation');
                teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 5 }) } }); await settleTeam();
            }
        } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
    });
});

test('organization Team in-flight unmount preserves uncertainty through remount and ignores its stale receipt', async () => {
    let view = await mountTeam();
    try {
        view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
        const original = structuredClone(teamHttp.writes[0].options.body), departedRequest = teamHttp.writePending.shift();
        view.app.unmount(); view = await mountTeam(false);
        assert.equal(teamHttp.writes.length, 1, 'Remount never automatically resends an in-flight command');
        find(view.root, 'data-organization-retry').props.onClick();
        assert.deepEqual(teamHttp.writes[1].options.body, original);
        teamHttp.writePending.shift().reject(new teamTransport.TransportError(422)); await settleTeam();
        assert.deepEqual(view.draft.pending && JSON.parse(JSON.stringify(view.draft.pending)), original, 'A replay rejection after remount leaves original unresolved');
        departedRequest.resolve({ data: { data: { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' } } }); await settleTeam();
        assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), original, 'A departed component receipt cannot settle the retained current view');
        find(view.root, 'data-organization-retry').props.onClick();
        assert.deepEqual(teamHttp.writes[2].options.body, original);
        teamHttp.writePending.shift().resolve({ data: { data: { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' } } }); await settleTeam();
        assert.equal(view.draft.pending, undefined); assert.equal(teamAdapter.hasPendingTeamCommand(), false);
        assert.equal(view.draft.uncertain, false);
        teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 5 }) } }); await settleTeam();
    } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); }
});

test('organization Team retained outcomes render in the current locale without changing command recovery', async t => {
    for (const outcome of [
        { status: 200, key: 'updated', ar: /تم تحديث عضوية الفريق/, en: /Team membership updated/ },
        { status: 422, key: 'invalid', ar: /رُفض الطلب دون تنفيذ/, en: /The request was rejected without execution/ },
        { status: 409, key: 'conflict', ar: /تغيّرت حالة الفريق/, en: /The team state changed/ },
        { status: 403, key: 'denied', ar: /تعذر اعتماد الطلب/, en: /The request could not be admitted/ },
        { status: 0, key: 'unknown', ar: /لم نتأكد من النتيجة/, en: /The outcome is unknown/ },
    ]) {
        await t.test(outcome.key, async () => {
            locale.value = 'ar'; const view = await mountTeam();
            try {
                view.recipient('registered-employee'); view.confirm(); await nextTick(); view.submit();
                const original = structuredClone(teamHttp.writes[0].options.body), request = teamHttp.writePending.shift();
                if (outcome.status === 200) request.resolve({ data: { data: { workspace_public_id: 'organization-one', recipient_public_id: 'registered-employee', state: 'active' } } });
                else request.reject(outcome.status ? new teamTransport.TransportError(outcome.status) : Error('Acknowledgement lost'));
                await settleTeam();
                if ([200, 409].includes(outcome.status)) { teamHttp.readPending.shift().resolve({ data: { data: teamSurface({ revision: 5 }) } }); await settleTeam(); }
                assert.equal(view.draft.notice, outcome.key); assert.match(text(view.root), outcome.ar);
                const pending = JSON.stringify(view.draft.pending), blocked = view.draft.blocked;
                locale.value = 'en'; await nextTick();
                assert.match(text(view.root), outcome.en); assert.doesNotMatch(text(view.root), outcome.ar);
                assert.equal(JSON.stringify(view.draft.pending), pending); assert.equal(view.draft.blocked, blocked);
                assert.equal(teamHttp.writes.length, 1, 'Locale changes never resend or replace commands');
                if ([403, 0].includes(outcome.status)) assert.deepEqual(JSON.parse(pending), original);
                else assert.equal(view.draft.pending, undefined);
                locale.value = 'ar'; await nextTick(); assert.match(text(view.root), outcome.ar);
            } finally { view.app.unmount(); teamAdapter.teamDrafts.clear(); locale.value = 'ar'; }
        });
    }
});

const recoveryConsumers = ['BusinessEditor', 'BusinessFacts', 'BusinessCollaboration', 'BusinessDelegation', 'BusinessDataUse', 'BoundaryEditor', 'SourceRightsEditor'];
async function recoveryView(name, scenario, configure = () => {}) {
    const business = '01a1a326-5588-75c2-b5b3-392a12242d37', workspace = '01a1a326-5588-75c2-b5b3-392a12242d38';
    const selection = { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 17 }] };
    const surface = { ...factSurface(business), authority_selection: selection, context_write_options: [{ grant_id: '17' }],
        collaboration: { options: [{ grant_id: '17', allows_agency: false }], workspaces: [{ public_id: workspace, slug: 'Qualified organization' }], relationships: [{ workspace_public_id: workspace, workspace_slug: 'Qualified organization', kind: 'collaboration', state: 'active', revision: 2, link_ref: 'link-one' }] },
        delegation: { actor_public_id: '01a1a326-5588-75c2-b5b3-392a12242d39', options: [{ grant_id: '17', action: 'business.context.read', can_issue: true }], issued: [{ grant_id: '20', parent_grant_id: '17', recipient_public_id: workspace, action: 'business.context.read', recorded_status: 'active', revision: 2, realm_kind: 'direct', realm_workspace_public_id: null }], issued_more: false },
        data_use: { binding_options: [{grant_id:'17'}], bindings: [{ binding_id: 'binding-one', scope_id: 'scope-one', state: 'active', revision: 2 }], bindings_more: false },
        boundary: { boundary_id: 'boundary-one', state: 'active', revision: 1 }, memberships: [{ membership_id: 'membership-one', business_public_id: business, branch_public_id: null, state: 'active', revision: 2 }], memberships_more: false,
        source: { source_id: 'source-one', label: 'Saved source name', revision: 2, family: 'meta', namespace: 'account', resource: 'resource-one', operation: 'read', purpose: 'review', adopted: true, scope_declared: true, status: 'ACTIVE' }, rights: [], rights_more: false, can_command: true };
    Object.assign(surface.business, { name: 'Saved business name', business_type_id: 1 }); surface.catalog.business_types = [{ id: 1, active: true, name_ar: 'خدمات', name_en: 'Services' }]; configure(surface);
    const apiUrl = moduleUrl(`export const scenario=${JSON.stringify(name + scenario)},writes=[],pending=[];const send=(kind,args)=>{writes.push({kind,args:structuredClone(args)});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};export const write=(...args)=>send('write',args),writeFact=(...args)=>send('fact',args),writeLink=(...args)=>send('link',args),writeGrant=(...args)=>send('grant',args),writeBinding=(...args)=>send('binding',args),writeResourceRight=(...args)=>send('resource',args);export const dataUseChoices=async()=>({scopes:[],next_cursor:null});export const businessChoices=async()=>({items:[],next_cursor:null});export const businessSurface=async()=>{throw Error('No target selected')};`);
    const Component = await component(`resources/js/business-foundation/${name}.vue`, { './api': apiUrl, '../Components/Business/goalSelection': goalSelectionUrl });
    const api = await import(apiUrl);
    const store = ({ BusinessEditor: drafts.drafts, BusinessFacts: drafts.factDrafts, BusinessCollaboration: drafts.linkDrafts, BusinessDelegation: drafts.delegationDrafts, BusinessDataUse: drafts.dataUseDrafts, BoundaryEditor: drafts.boundaryDrafts, SourceRightsEditor: drafts.sourceDrafts })[name]; store.clear();
    let root, app;
    const mount = async () => { root = element('root'); app = renderer.createApp(Component.value, { surface, refreshing: false }); await app.use(await routerAt('/businesses/' + business)); app.mount(root); await flushFacts(); };
    await mount();
    const getDraft = () => name === 'BusinessFacts' ? [...store.values()].find(row => row.kind === 'markets') : [...store.values()][0];
    const submit = form => form.props.onSubmit({ preventDefault() {} });
    const button = label => all(root).find(node => node.tag === 'button' && text(node) === label);
    async function start() {
        if (name === 'BusinessEditor') { await inputFact(root, 'bf-name', 'Unsent private name'); submit(all(root).find(node => node.tag === 'form')); }
        if (name === 'BusinessFacts') { await checkFact(root, 'data-fact-country', 'JO'); submit(find(root, 'data-business-fact-form', 'markets')); }
        if (name === 'BusinessCollaboration') button('End relationship').props.onClick();
        if (name === 'BusinessDelegation') button('Withdraw this right').props.onClick();
        if (name === 'BusinessDataUse') { find(root, 'data-data-use-confirm').props['onUpdate:modelValue'](true); await nextTick(); button('End use binding').props.onClick(); }
        if (name === 'BoundaryEditor') button('End this coverage').props.onClick();
        if (name === 'SourceRightsEditor') { find(root, 'data-source-confirm').props['onUpdate:modelValue'](true); await nextTick(); find(root, 'data-source-retire').props.onClick(); }
        await flushFacts();
    }
    function replay() {
        if (name === 'BusinessEditor') { const form = all(root).find(node => node.tag === 'form'); if (form) submit(form); }
        if (name === 'BusinessFacts') { const form = find(root, 'data-business-fact-form', 'markets'); if (form) submit(form); }
        if (name === 'BusinessCollaboration') { const form = find(root, 'data-collaboration-form'); if (form) submit(form); }
        if (name === 'BusinessDelegation') { const form = find(root, 'data-delegation-form'); if (form) submit(form); }
        if (name === 'BusinessDataUse') find(root, 'data-data-use-reconcile')?.props.onClick();
        if (name === 'BoundaryEditor') { const form = find(root, 'data-boundary-attach'); if (form) submit(form); }
        if (name === 'SourceRightsEditor') find(root, 'data-source-reconcile')?.props.onClick();
    }
    const receipt = () => ({ business_public_id: business, name: 'Unsent private name', business_type_id: 1, codes: ['SA', 'JO'], versions: { context: 4, binding: 3 }, workspace_public_id: workspace, kind: 'collaboration', status: 'ended', grant_id: 20, membership_id: 'membership-one', source_id: 'source-one' });
    return { api, store, get draft() { return getDraft(); }, get root() { return root; }, start, replay, receipt, remount: async () => { app.unmount(); await mount(); }, unmount: () => app.unmount() };
}

test('retired workspace agency creation retains historical end and exact pending add reconciliation', async () => {
    const restore = withFactDom(); locale.value = 'en';
    const view = await recoveryView('BusinessCollaboration', 'retired-agency', surface => {
        surface.collaboration.options[0].allows_agency = true;
        surface.collaboration.relationships[0].kind = 'agency';
    });
    try {
        assert.equal(find(view.root, 'id', 'bf-link-kind'), undefined, 'No new Workspace agency creation selector remains');
        await view.start(); assert.equal(view.api.writes[0].args[1].effect, 'end'); assert.equal(view.api.writes[0].args[1].kind, 'agency');
        view.api.pending.shift().reject(Error('Acknowledgement lost')); await flushFacts();
        const original = { ...JSON.parse(JSON.stringify(view.draft.pending)), command_id: 'historical-agency-add', effect: 'add' };
        view.draft.pending = original; view.draft.uncertain = true;
        await view.remount(); assert.equal(view.api.writes.length, 1, 'Remount never sends automatically');
        view.replay(); await flushFacts(); assert.deepEqual(view.api.writes[1].args[1], original, 'The historical add is reconciled exactly, without conversion to collaboration');
        view.api.pending.shift().resolve({ ...view.receipt(), kind: 'agency' }); await flushFacts();
        assert.equal(view.draft.pending, undefined); assert.equal(view.draft.kind, 'collaboration', 'Only acknowledged settlement resets the new resource-link editor');
    } finally { view.unmount(); view.store.clear(); restore(); locale.value = 'ar'; }
});

test('material Business writers retain unknown originals through validation rejection, remount and authority denial', async t => {
    for (const name of recoveryConsumers) for (const scenario of ['initial422', 'unknown422', 'remount422', 'unknown403', 'unknown409', 'mismatch']) await t.test(`${name} ${scenario}`, async () => {
        const restore = withFactDom(); locale.value = 'en'; const view = await recoveryView(name, scenario);
        try {
            await view.start(); assert.equal(view.api.writes.length, 1, 'Actual initial UI action sends one command');
            const original = structuredClone(view.api.writes[0]), first = view.api.pending.shift(), pending = JSON.parse(JSON.stringify(view.draft.pending));
            if (!['initial422', 'remount422'].includes(scenario)) { first.reject(Error('Committed ACK lost')); await flushFacts(); }
            if (scenario === 'remount422') await view.remount();
            if (scenario !== 'initial422') { assert.equal(view.api.writes.length, 1, 'Loss or remount never automatically sends'); view.replay(); await flushFacts(); assert.deepEqual(view.api.writes[1], original); }
            const { TransportError } = await import(transportUrl), rejection = new TransportError('Rejected'); rejection.status = scenario === 'unknown403' ? 403 : scenario === 'unknown409' ? 409 : 422; rejection.body = { error: scenario === 'unknown409' ? 'business_conflict' : 'invalid_business_command' };
            const replay = scenario === 'initial422' ? first : view.api.pending.shift();
            if (scenario === 'mismatch') {
                const mismatch = view.receipt();
                if (name === 'BusinessEditor' || name === 'BusinessFacts') mismatch.business_public_id = 'foreign-business';
                if (name === 'BusinessCollaboration') mismatch.workspace_public_id = 'foreign-organization';
                if (name === 'BusinessDelegation') { mismatch.status = 'revoked'; mismatch.grant_id = 21; }
                if (name === 'BusinessDataUse') mismatch.status = 'active';
                if (name === 'BoundaryEditor') mismatch.membership_id = 'foreign-membership';
                if (name === 'SourceRightsEditor') mismatch.source_id = 'foreign-source';
                replay.resolve(mismatch);
            } else replay.reject(rejection);
            await flushFacts();
            if (scenario === 'initial422') { assert.equal(view.draft.pending, undefined, 'A definitive first rejection releases the rejected command'); assert.equal(view.api.writes.length, 1); await view.start(); assert.equal(view.api.writes.length, 2, 'A newly reviewed initial rejection remains editable'); assert.notEqual(view.api.writes[1].args.at(-1).command_id, original.args.at(-1).command_id); }
            else {
                assert.deepEqual(view.draft?.pending && JSON.parse(JSON.stringify(view.draft.pending)), pending, 'Earlier possibly committed original must survive replay rejection');
                assert.equal(view.store.size > 0, true, 'Identity-scoped opaque original survives for the same document'); assert.equal(view.api.writes.length, 2);
                if (scenario === 'unknown403') {
                    assert.equal(view.draft.blocked, true);
                    if (name === 'BusinessEditor') { assert.equal(find(view.root, 'id', 'bf-name') !== undefined, false); assert.equal(view.draft.name, ''); assert.equal(view.draft.selected, ''); }
                    if (name === 'BusinessFacts') { assert.equal(find(view.root, 'data-business-fact-form', 'markets') !== undefined, false); assert.deepEqual([...view.draft.fields.codes], []); assert.equal(view.draft.selected, ''); }
                    if (name === 'BusinessCollaboration') { assert.equal(find(view.root, 'data-collaboration-form') !== undefined, false); assert.equal(view.draft.workspace, ''); assert.equal(view.draft.selected, ''); }
                    if (name === 'BusinessDelegation') { assert.equal(find(view.root, 'data-delegation-form') !== undefined, false); assert.equal(view.draft.recipient, ''); assert.equal(view.draft.selected, ''); }
                    if (name === 'BusinessDataUse') { assert.equal(find(view.root, 'data-data-use-details') !== undefined, false); assert.equal(find(view.root, 'id', 'bf-data-scope') !== undefined, false); assert.equal(view.draft.selected, ''); assert.equal(view.draft.manager, ''); }
                    if (name === 'BoundaryEditor') { assert.equal(find(view.root, 'data-boundary-attach') !== undefined, false); assert.equal(view.draft.choice, ''); assert.equal(view.draft.selected, ''); }
                    if (name === 'SourceRightsEditor') { assert.equal(find(view.root, 'id', 'bf-source-label') !== undefined, false); assert.equal(view.draft.label, ''); }
                    const before = view.api.writes.length; view.replay(); await flushFacts(); assert.equal(view.api.writes.length, before, 'Denied authority cannot replay or send an alternate'); await view.remount(); assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), pending); view.replay(); await flushFacts(); assert.equal(view.api.writes.length, before, 'Remount must not unblock denied original');
                }
                else {
                    if (scenario === 'remount422') { first.resolve(view.receipt()); await flushFacts(); assert.deepEqual(JSON.parse(JSON.stringify(view.draft.pending)), pending, 'Departed receipt cannot settle current presentation'); }
                    view.replay(); await flushFacts(); assert.deepEqual(view.api.writes[2], original); const receipt = view.receipt(); if (name === 'BusinessDelegation') receipt.status = 'revoked'; view.api.pending.shift().resolve(receipt); await flushFacts(); assert.equal(view.draft.pending, undefined, 'Matching current receipt settles original');
                }
            }
        } finally { view.unmount(); view.store.clear(); locale.value = 'ar'; restore(); }
    });
});

test('canonical typed relation CAS rejection remains definitive after receipt lookup for affected writers', async t => {
    for (const name of ['BusinessCollaboration', 'BusinessDataUse', 'BoundaryEditor']) for (const priorLoss of [false, true]) await t.test(`${name} priorLoss=${priorLoss}`, async () => {
        const restore = withFactDom(); locale.value = 'en'; const view = await recoveryView(name, 'typed409' + priorLoss);
        try {
            await view.start(); let request = view.api.pending.shift();
            if (priorLoss) { request.reject(Error('Transport failed without commitment')); await flushFacts(); view.replay(); await flushFacts(); request = view.api.pending.shift(); }
            const { TransportError } = await import(transportUrl), rejection = new TransportError('Canonical pre-effect CAS rejection'); rejection.status = 409; rejection.body = { error: 'business_relation_revision_rejected' };
            request.reject(rejection); await flushFacts();
            assert.equal(view.draft.pending, undefined, 'Canonical typed CAS is emitted only after exact receipt lookup found no committed original'); assert.equal(view.draft.uncertain, false);
            const previous = view.api.writes.length; await view.start(); assert.equal(view.api.writes.length, previous + 1, 'Explicit newly reviewed command remains available after authoritative no-effect proof');
        } finally { view.unmount(); view.store.clear(); locale.value = 'ar'; restore(); }
    });
});


test('all previous Workspace product paths redirect to the Business list without legacy route loaders', async () => {
    const { workspaceRoutes } = await import(await scriptModule('resources/js/workspaces/routes.ts'));
    const { sourceRoutes } = await import(await scriptModule('resources/js/sources/routes.ts'));
    const { objectPurposeRoutes } = await import(await scriptModule('resources/js/object-purpose/routes.ts'));
    assert.deepEqual(sourceRoutes, []); assert.deepEqual(objectPurposeRoutes, []);
    const router = createRouter({history:createMemoryHistory(),routes:[...workspaceRoutes,{path:'/businesses',component:{render:()=>null}},{path:'/businesses/new',component:{render:()=>null}}]});
    for(const path of ['/workspaces','/workspaces/old','/workspaces/old/commercial-history','/workspaces/old/purpose-history/id','/workspaces/old/providers/meta']) {await router.push(path);assert.equal(router.currentRoute.value.path,'/businesses',path);}
});

for (const classified of [true, false]) test(`registration keeps classified breach outage retryable and unclassified 503 uncertain (${classified})`, async () => {
    const restore = withFactDom(), previousStorage = globalThis.sessionStorage;
    const email = 'outage@example.test', attempt = '11111111-1111-4111-8111-111111111111';
    const storage = new Map([['builderad:registration-guest:v1:document-one', JSON.stringify({ answers: emptyWizardAnswers(), step: 5, finished: true, email, attemptId: attempt })]]);
    globalThis.sessionStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) };
    const runtimeUrl = moduleUrl(`import {TransportError} from ${JSON.stringify(transportUrl)};
        export const writes=[], closures=new Set(); export const close=()=>closures.add('departed');
        export const onClose=f=>{closures.add(f);return closures;};
        export const api={GET:async()=>({data:{state:'verified',email:${JSON.stringify(email)},attempt_id:${JSON.stringify(attempt)},resend_after:0}}),POST:async(path,options)=>{writes.push({path,body:options.body});const e=new TransportError();e.status=503;e.body={message:'Password safety check unavailable',...${classified ? "{code:'password_breach_unavailable'}" : '{}'}};throw e;}};`);
    const runtime = await import(runtimeUrl);
    const registration = await component('resources/js/auth-entry/RegistrationEntry.vue', {
        '../business-foundation/BusinessWizard.vue': plainUrl, '../business-foundation/businessWizard': businessWizardUrl,
        '../spa/api': runtimeUrl, './errors': await scriptModule('resources/js/auth-entry/errors.ts'),
        '../spa/transport': moduleUrl(`export {scope,owned,stamp,TransportError} from ${JSON.stringify(transportUrl)};export {close,onClose} from ${JSON.stringify(runtimeUrl)};`),
    });
    const root = element('root'), app = renderer.createApp(registration.value, { passwordPolicy: { min: 15, max: null, browserRules: '' } });
    app.use(await routerAt('/businesses')); app.mount(root);
    try {
        await flushFacts(); assert.ok(find(root, 'data-registration-stage', 'profile'));
        await inputFact(root, 'registration-name', 'Account owner');
        await inputFact(root, 'registration-password', 'a unique long passphrase 2026');
        await inputFact(root, 'registration-confirmation', 'a unique long passphrase 2026');
        await all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} }); await flushFacts();
        assert.equal(runtime.writes.length, 1); assert.equal(runtime.writes[0].path, '/register');
        assert.equal(runtime.closures.has('departed'), false, 'A rejected HTTP response never causes an identity departure');
        assert.ok(find(root, 'data-registration-stage', 'profile'));
        if (classified) {
            assert.equal(find(root, 'data-auth-recover'), undefined);
            assert.equal(find(root, 'id', 'registration-name').value, 'Account owner');
            assert.equal(find(root, 'id', 'registration-password').value, 'a unique long passphrase 2026');
            assert.equal(find(root, 'id', 'registration-confirmation').value, 'a unique long passphrase 2026');
            assert.equal(all(root).find(node => node.tag === 'button' && node.props.type === 'submit').props.disabled, false);
            assert.match(text(root), /Password safety check unavailable/);
            await all(root).find(node => node.tag === 'form').props.onSubmit({ preventDefault() {} }); await flushFacts();
            assert.equal(runtime.writes.length, 2, 'Known rejection allows an explicit retry with retained account fields');
            assert.deepEqual(runtime.writes[1].body, runtime.writes[0].body);
        } else {
            assert.ok(find(root, 'data-auth-recover'));
            assert.equal(find(root, 'id', 'registration-password'), undefined, 'Uncertain account outcome conceals the account form');
            assert.equal(runtime.writes.length, 1, 'Unknown delivery is not automatically retried');
        }
    } finally { app.unmount(); restore(); globalThis.sessionStorage = previousStorage; }
});

test('direct Business custody pending protects departure and archive through the canonical custody store', async () => {
    const previousWindow = globalThis.window, previousConfirm = globalThis.confirm; let prompts = 0;
    const listeners = new Map();
    globalThis.window = { scrollY: 0, scrollTo() {}, addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name), matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }) };
    globalThis.confirm = () => { prompts++; return false; };
    const selected = businessSurface('custody-guard-business'), root = element('root'), app = renderer.createApp({ render: () => h(RouterView) });
    const router = createRouter({ history: createMemoryHistory(), routes: routes.map(route => route.path === '/businesses/:business' ? { ...route, component: workspace.value, props: () => ({ surface: selected }) } : route) });
    await router.push('/businesses/custody-guard-business?access=17'); await router.isReady(); app.use(router); app.mount(root);
    const resource = drafts.key(selected.business.business_public_id, selected.authority_selection) + ':business-file-custody:2';
    const original = { command_id: 'retained-custody-command', workspace_public_id: null, effect: 'appoint' };
    drafts.internalTeamDrafts.set(resource, { pending: original });
    try {
        assert.equal(drafts.hasPendingBusinessDraft(selected.business.business_public_id), true, 'The existing archive guard sees custody pending');
        await router.push('/businesses/custody-guard-business?access=17&section=settings&settings=access'); assert.equal(prompts, 0);
        await router.push('/businesses/another-business?access=17'); assert.equal(prompts, 1); assert.equal(router.currentRoute.value.params.business, 'custody-guard-business');
        const event = { preventDefault() { this.prevented = true; }, returnValue: undefined }; listeners.get('beforeunload')(event); assert.equal(event.prevented, true);
        assert.equal(drafts.internalTeamDrafts.get(resource).pending, original);
    } finally { app.unmount(); drafts.internalTeamDrafts.delete(resource); globalThis.window = previousWindow; globalThis.confirm = previousConfirm; }
});
