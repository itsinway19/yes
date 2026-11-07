import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, h, markRaw, nextTick, ref } from 'vue';

// Compile and mount the actual Vue component and its actual command/store module.
// Only the document transport is replaced, so tests inspect real HTTP payloads.
const moduleUrl = text => 'data:text/javascript;base64,' + Buffer.from(text).toString('base64');
const vue = import.meta.resolve('vue');
const compile = text => ts.transpileModule(text, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
const transportUrl = moduleUrl(`export const scope='identity-one';export let generation=0;export const stamp=()=>generation;export const owned=value=>value===generation;export const callbacks=[];export const onClose=fn=>callbacks.push(fn);export const close=()=>{generation++;callbacks.forEach(fn=>fn());};export class TransportError extends Error{constructor(status,body={}){super('rejected');this.status=status;this.body=body;}}export const calls=[],pending=[];export const request=(url,options={})=>{calls.push({url,method:options.method??'GET',body:options.body?JSON.parse(options.body):undefined});return new Promise((resolve,reject)=>pending.push({resolve,reject}));};`);
const languageUrl = moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref('en');`);
const apiUrl = moduleUrl(`import {request} from ${JSON.stringify(transportUrl)};const url=(path,options)=>path.replace('{business}',encodeURIComponent(options?.params?.path?.business??''))+(options?.params?.query?'?'+new URLSearchParams(options.params.query):'');export const api={GET:async(path,options)=>({data:await request(url(path,options))}),POST:async(path,options)=>({data:await request(url(path,options),{method:'POST',body:JSON.stringify(options.body)})})};`);
const transport = await import(transportUrl), { locale } = await import(languageUrl);
function ports(source, extra = {}) { for (const [key, value] of Object.entries({ vue, '../spa/transport': transportUrl, '../spa/locale': languageUrl, '../spa/api': apiUrl, ...extra })) source = source.replaceAll(`from '${key}'`, `from ${JSON.stringify(value)}`).replaceAll(`from "${key}"`, `from ${JSON.stringify(value)}`); return source; }
const storeUrl = moduleUrl(ports(compile(await readFile('resources/js/business-foundation/dataLicenseJourney.ts', 'utf8'))));
const store = await import(storeUrl);
const draftsUrl = moduleUrl(ports(compile(await readFile('resources/js/business-foundation/drafts.ts', 'utf8')), { './dataLicenseJourney': storeUrl }));
const filename = 'resources/js/business-foundation/BusinessDataLicenses.vue';
const { descriptor, errors } = parse(await readFile(filename, 'utf8'), { filename });
assert.deepEqual(errors, []);
assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename, id: filename, compilerOptions: { expressionPlugins: ['typescript'] } }).errors, []);
const Component = (await import(moduleUrl(ports(compile(compileScript(descriptor, { id: filename, inlineTemplate: true }).content), { './dataLicenseJourney': storeUrl, './drafts': draftsUrl })))).default;
globalThis.HTMLElement ??= class HTMLElement {};
globalThis.Document ??= class Document {};
globalThis.ShadowRoot ??= class ShadowRoot {};
const focusListeners = new Set();
globalThis.window = { addEventListener(event, fn) { if (event === 'focus') focusListeners.add(fn); }, removeEventListener(event, fn) { if (event === 'focus') focusListeners.delete(fn); } };
function element(tag, text = '') {
    const listeners = new Map();
    return markRaw({ tag, tagName: tag.toUpperCase(), text, props: {}, children: [], parent: null, value: '', selected: false, getRootNode() { return {}; },
        get options() { return this.children.filter(row => row.tag === 'option'); },
        addEventListener(name, fn) { const set = listeners.get(name) ?? new Set(); set.add(fn); listeners.set(name, set); }, removeEventListener(name, fn) { listeners.get(name)?.delete(fn); },
        dispatchEvent(event) { if (!event.target) event.target = this; for (const fn of listeners.get(event.type) ?? []) fn(event); },
    });
}
const renderer = createRenderer({ createElement: tag => element(tag), createText: text => element('#text', text), createComment: text => element('#comment', text), setText(node, text) { node.text = text; }, setElementText(node, text) { node.text = text; node.children = []; }, parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] ?? null,
    insert(node, parent, anchor = null) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; at < 0 ? parent.children.push(node) : parent.children.splice(at, 0, node); }, remove(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; },
    patchProp(node, key, old, value) { node.props[key] = value; if (['type', 'checked', 'multiple', 'selected'].includes(key)) node[key] = value; if (key === 'value') { node.value = value ?? ''; node._value = value; } },
});
const all = node => [node, ...node.children.flatMap(all)];
const find = (root, attribute) => all(root).find(node => attribute in node.props);
const text = node => node.text + node.children.map(text).join('');
const flush = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick(); };
const clone = value => JSON.parse(JSON.stringify(value));
const selection = { native_namespaces: ['meta.insights'], native_keys: ['report-one'], field_keys: ['spend'], partition_ref: null };
const material_refs = [{ namespace: 'meta.insights', key: 'report-one', version: 'v1' }];
const record_metadata = [{ ref: material_refs[0], campaign_id: 'campaign-first', start: '2026-10-01', end: '2026-10-31', boundary: 'INCLUSIVE_DATES', grain: 'TOTAL', timezone: 'UTC' }];
const nativeMoney = (value, patches = {}) => ({ native_value: value, currency: 'SAR', native_unit: 'meta.account_currency_major', decimal_scale: 0, scale_basis: 'documented-account-currency-scale', major_units: value, ...patches });
const source = id => ({ source_id: id, label: `Private ${id}`, family: 'meta', resource: 'ad_account:one', manager_grant_id: '201', read_grant_id: '202', datasets_completeness: 'COMPLETE', datasets: [{ dataset_id: 'report-one', coverage: 'PARTIAL', records_completeness: 'COMPLETE', record_count: 1, selection, material_refs, record_metadata }] });
const licence = { license_id: 'licence-one', boundary_id: 'boundary-one', boundary_grant_id: '301', use_grant_id: '302', source_id: 'source-one', resource: 'ad_account:one', operation: 'display', purpose: 'advertising_performance', valid_until: '2030-01-01T00:00:00Z', status: 'active', is_declarant: true, selection, recipient_public_id: 'actor-one', material_refs, record_metadata, coverage_revision: 0, binding_revision: 0, covered: false, bound: false };
const inventory = (business = 'business-one', patches = {}) => clone({ business_public_id: business, actor_public_id: 'actor-one', sources: [source('source-one'), source('source-two')], sources_more: false, licenses_more: false, licenses: [licence], business_use_options: [{ grant_id: '401' }], binding_options: [{ grant_id: '402' }], beneficiaries: [], assignments: [], assigned_licenses: [], operating_mandate: { available: true, previous_grant: '0' }, ...patches });
const surface = business => ({ business: { business_public_id: business }, authority_selection: { realm_kind: 'direct', realm_workspace_public_id: null, requirements: [{ requirement_kind: 'business', leaf_grant_id: 101 }] } });
function mount() {
    transport.calls.length = 0; transport.pending.length = 0; store.journeyDrafts.clear(); store.journeyClosed.value = false; locale.value = 'en';
    const current = ref(surface('business-one')), active = ref(true), root = element('root');
    const app = renderer.createApp({ render: () => h(Component, { surface: current.value, active: active.value }) }); app.mount(root);
    return { app, root, current, active, draft: () => store.journeyDrafts.get(store.journeyKey(current.value)) };
}
async function reply(value) { const entry = transport.pending.shift(); assert.ok(entry, 'Expected an actual transport delivery'); entry.resolve({ data: clone(value) }); await flush(); }
async function reject(status, body = {}) { const entry = transport.pending.shift(); assert.ok(entry); entry.reject(new transport.TransportError(status, body)); await flush(); }
async function input(root, name, value) { const node = find(root, name); assert.ok(node, name); node.props['onUpdate:modelValue'](value); await nextTick(); }
async function click(root, name) { const node = find(root, name); assert.ok(node, name); assert.ok(!node.props.disabled, `${name} is enabled`); node.props.onClick(); await nextTick(); }
async function chooseSource(root, id = 'source-one') { await input(root, 'data-license-source', id); }
async function issueForm(root) { await chooseSource(root); await input(root, 'data-license-dataset', 'report-one'); await input(root, 'data-license-purpose', 'advertising_performance'); await input(root, 'data-license-holder', 'Holder A'); await input(root, 'data-license-reference', 'Mandate A'); await input(root, 'data-license-expiry', '2030-01-01T10:00'); await input(root, 'data-license-confirm', true); }
async function issue(root) { const form = all(root).find(node => node.tag === 'form'); form.props.onSubmit({ preventDefault() {} }); await nextTick(); }
const licenseReceipt = (command, status = 'active') => ({ license_id: command.license_id, boundary_id: 'boundary-one', boundary_grant_id: '301', use_grant_id: '302', status, assurance: 'DECLARED_AUTHORITY', versions: { license: status === 'active' ? 1 : 2 } });
const beneficiaries = [{ public_id: 'beneficiary-one', name: 'Qualified colleague', realm_kind: 'direct', realm_workspace_public_id: null }, { public_id: 'agency-colleague', name: 'Different agency colleague', realm_kind: 'agency', realm_workspace_public_id: 'other-agency' }];
const assigned = { license_id: 'licence-one', recipient_public_id: 'beneficiary-one', grant_id: '601', status: 'active', revision: 1, valid_until: '2029-12-31T00:00:00Z' };
const beneficiaryReceipt = (command, grant = '601') => ({ license_id: command.license_id, recipient_public_id: command.recipient_public_id, grant_id: grant, status: command.effect === 'assign' ? 'active' : 'revoked', versions: { grant: command.expected_revision + 1 } });
async function assignmentForm(root) { await chooseSource(root); await input(root, 'data-license-current', 'licence-one'); await input(root, 'data-assignment-authority', '401'); await input(root, 'data-assignment-beneficiary', 'beneficiary-one'); await input(root, 'data-assignment-expiry', '2029-12-30T10:00'); await input(root, 'data-assignment-confirm', true); }

test('bounded report selection distinguishes acquisition from record and dataset completeness in both languages', async () => {
    const m = mount();
    try {
        const limited = source('source-one');
        limited.datasets_completeness = 'PARTIAL';
        limited.datasets[0] = { ...limited.datasets[0], coverage: 'COMPLETE', record_count: 32, records_completeness: 'PARTIAL' };
        await reply(inventory('business-one', { sources: [limited] }));
        await chooseSource(m.root); await input(m.root, 'data-license-dataset', 'report-one');
        assert.match(text(find(m.root, 'data-datasets-completeness')), /More qualified reports exist/);
        assert.match(text(find(m.root, 'data-records-completeness')), /More qualified records exist/);
        assert.match(text(find(m.root, 'data-report-acquisition')), /Report collection.*Complete/);
        assert.match(text(find(m.root, 'data-report-acquisition')), /Displayed records.*32/);
        locale.value = 'ar'; await nextTick();
        assert.match(text(find(m.root, 'data-datasets-completeness')), /تقارير مؤهلة أخرى/);
        assert.match(text(find(m.root, 'data-records-completeness')), /سجلات مؤهلة أخرى/);
        assert.match(text(find(m.root, 'data-report-acquisition')), /جمع التقرير.*مكتملة/);
        // Unknown is explicit; it is not treated as a complete or empty list.
        await click(m.root, 'data-license-refresh');
        limited.datasets_completeness = 'UNKNOWN'; limited.datasets[0].records_completeness = 'UNKNOWN';
        limited.datasets[0].coverage = 'UNKNOWN'; limited.datasets[0].record_count = 0;
        await reply(inventory('business-one', { sources: [limited] }));
        assert.match(text(find(m.root, 'data-datasets-completeness')), /اكتمال قائمة التقارير غير معروف/);
        assert.match(text(find(m.root, 'data-records-completeness')), /اكتمال قائمة السجلات غير معروف/);
        assert.match(text(find(m.root, 'data-report-acquisition')), /غير معروفة/);
    } finally { m.app.unmount(); }
});

for (const lang of ['ar','en']) for (const [records,datasets] of [[32,8],[33,8],[32,9],[33,9]]) {
    test(`${lang} renders bounded32/${records} records and8/${datasets} datasets independently of complete acquisition`, async () => {
        const m=mount();
        try {
            const currentSource=source('source-one');
            currentSource.datasets_completeness=datasets>8?'PARTIAL':'COMPLETE';
            const report={...currentSource.datasets[0],coverage:'COMPLETE',record_count:32,records_completeness:records>32?'PARTIAL':'COMPLETE'};
            currentSource.datasets=Array.from({length:8},(_,i)=>({...report,dataset_id:i===0?'report-one':`report-${i+1}`}));
            await reply(inventory('business-one',{sources:[currentSource]}));locale.value=lang;await nextTick();
            await chooseSource(m.root);await input(m.root,'data-license-dataset','report-one');
            assert.equal(find(m.root,'data-license-dataset').children.filter(row=>row.tag==='option').length,9);
            assert.match(text(find(m.root,'data-license-dataset')),lang==='ar'?/جمع التقرير/:/Report collection/);
            assert.match(text(find(m.root,'data-report-acquisition')),/32/);
            const reportText=text(find(m.root,'data-datasets-completeness')),recordText=text(find(m.root,'data-records-completeness'));
            assert.match(reportText,datasets>8?(lang==='ar'?/تقارير مؤهلة أخرى/:/More qualified reports exist/):(lang==='ar'?/مكتملة حاليًا/:/currently complete/));
            assert.match(recordText,records>32?(lang==='ar'?/سجلات مؤهلة أخرى/:/More qualified records exist/):(lang==='ar'?/مكتملة حاليًا/:/currently complete/));
            assert.match(text(find(m.root,'data-report-acquisition')),lang==='ar'?/جمع التقرير.*مكتملة/:/Report collection.*Complete/);
        } finally {m.app.unmount();}
    });
}

test('record changes cannot reuse previous processing confirmation and loss of Source authority conceals record review', async () => {
    const m=mount();
    const second={namespace:'meta.insights',key:'second-choice',version:'second-version'};
    const processLicence={...licence,operation:'process',covered:true,bound:true,material_refs:[material_refs[0],second],record_metadata:[record_metadata[0],{...record_metadata[0],ref:second,campaign_id:'other-campaign'}]};
    try {
        await reply(inventory('business-one',{licenses:[processLicence]}));await chooseSource(m.root);await input(m.root,'data-license-current','licence-one');
        await input(m.root,'data-license-use','401');await input(m.root,'data-license-binding','402');await input(m.root,'data-summary-record','0');
        await click(m.root,'data-summary-process');const processed=clone(transport.calls.at(-1).body);
        await reply({business_public_id:'business-one',operation:'process',purpose:licence.purpose,recipient_ref:'actor-one',materials:[{ref:material_refs[0],fields:{spend:nativeMoney('0')},coverage:'PARTIAL',data_use_scope_id:'licence-one'}]});
        await reply(inventory('business-one',{licenses:[processLicence]}));assert.equal(m.draft().summaryRead.command,processed.command_id);
        await input(m.root,'data-summary-expiry','2029-12-30T10:00');await input(m.root,'data-summary-confirm',true);
        await input(m.root,'data-summary-record','1');assert.equal(m.draft().summaryRead,undefined);assert.equal(m.draft().summaryConfirmed,false);
        await input(m.root,'data-summary-record','0');assert.equal(m.draft().summaryRead,undefined);assert.equal(find(m.root,'data-summary-prepare').props.disabled,true);
        await click(m.root,'data-license-refresh');
        await reply(inventory('business-one',{sources:[{...source('source-one'),read_grant_id:null,datasets:[],datasets_completeness:'UNKNOWN'}],licenses:[{...processLicence,material_refs:[],record_metadata:[]}]}));
        assert.equal(m.draft().summaryRead,undefined);assert.equal(m.draft().summaryConfirmed,false);
        assert.equal(find(m.root,'data-summary-process').props.disabled,true);assert.equal(find(m.root,'data-summary-record-review'),undefined);
    } finally {m.app.unmount();}
});

test('a beneficiary reads only a registered summary through its explicit Business and output paths, concealing a late response after a switch', async () => {
    const m = mount();
    const artifact_ref = { namespace: 'business.expenditure_summary', key: 'summary-one', version: 'a'.repeat(64) };
    try {
        await reply(inventory('business-one', { sources: [], licenses: [], actor_public_id: 'beneficiary-one', assigned_licenses: [{license_id:'summary-one',operation:'display',purpose:'advertising_performance',valid_until:'2030-01-01T00:00:00Z',artifact_ref,use_grant_id:'603'}] }));
        await input(m.root, 'data-assigned-business-use', '401'); await click(m.root, 'data-assigned-summary-read');
        const command = transport.calls.at(-1).body;
        assert.equal(command.recipient_ref, 'beneficiary-one'); assert.deepEqual(command.material_refs, [artifact_ref]);
        assert.deepEqual(command.authority_selection.requirements,[{requirement_kind:'business',leaf_grant_id:401},{requirement_kind:'data_use',leaf_grant_id:603}]);
        const exactMajor='0.000000000000000000123456789012345678901234567891';
        await reply({business_public_id:'business-one',operation:'display',purpose:'advertising_performance',recipient_ref:'beneficiary-one',materials:[{ref:artifact_ref,fields:{spend:nativeMoney('0.123456789012345678901234567891',{major_units:exactMajor,decimal_scale:18,native_unit:'SCALED_CURRENCY_UNIT',scale_basis:'canonical_native_amount_scale:1'})},coverage:'PARTIAL',data_use_scope_id:'summary-one'}]});
        assert.equal(text(find(m.root,'data-native-amount')),exactMajor,'A lawful48-decimal amount stays exact in the actual Vue');
        await click(m.root,'data-assigned-summary-read');
        const delivery = transport.pending.shift(); m.current.value = surface('business-two'); await flush();
        delivery.resolve({data:{business_public_id:'business-one',operation:'display',purpose:'advertising_performance',recipient_ref:'beneficiary-one',materials:[{ref:artifact_ref,fields:{spend:nativeMoney('123')},coverage:'PARTIAL',data_use_scope_id:'summary-one'}]}}); await flush();
        assert.equal(find(m.root,'data-license-materials'),undefined);
    } finally { m.app.unmount(); }
});

test('summary processing, explicit consent and registered publication remain separate commands; a lost prepare reply reconciles the same summary', async () => {
    const m=mount();
    const secondRef={namespace:'meta.insights',key:'report-two',version:'v2'};
    const secondMetadata={...record_metadata[0],ref:secondRef,campaign_id:'campaign-second',start:'2026-11-01',end:'2026-11-07'};
    const processLicence={...licence,operation:'process',covered:true,bound:true,material_refs:[...material_refs,secondRef],record_metadata:[...record_metadata,secondMetadata]};
    try {
        await reply(inventory('business-one',{licenses:[processLicence]}));await chooseSource(m.root);await input(m.root,'data-license-current','licence-one');
        await input(m.root,'data-license-use','401');await input(m.root,'data-license-binding','402');await input(m.root,'data-summary-record','0');
        assert.match(text(find(m.root,'data-summary-record-review')),/campaign-first.*2026-10-01/);
        await input(m.root,'data-summary-confirm',true);await input(m.root,'data-summary-record','1');
        assert.equal(m.draft().summaryConfirmed,false);assert.match(text(find(m.root,'data-summary-record-review')),/campaign-second.*2026-11-01/);
        await click(m.root,'data-summary-process');const processed=clone(transport.calls.at(-1).body);
        assert.equal(processed.operation,'process');assert.deepEqual(processed.material_refs,[secondRef]);
        await reply({business_public_id:'business-one',operation:'process',purpose:licence.purpose,recipient_ref:'actor-one',materials:[{ref:secondRef,fields:{spend:nativeMoney('12')},coverage:'PARTIAL',data_use_scope_id:'licence-one'}]});
        await reply(inventory('business-one',{licenses:[processLicence]}));assert.equal(m.draft().summaryRead.command,processed.command_id);
        await input(m.root,'data-summary-expiry','2029-12-30T10:00');await input(m.root,'data-summary-confirm',true);await click(m.root,'data-summary-prepare');
        const prepared=clone(transport.calls.at(-1).body);
        assert.equal(prepared.read_command_id,processed.command_id);assert.deepEqual(prepared.input_ref,secondRef);assert.deepEqual(prepared.consent,{derive:true,deliver_summary:true,retain_payload:false});assert.equal(prepared.authority_selection.requirements.length,5);
        await reject(500);assert.equal(m.draft().pending.body.command_id,prepared.command_id);assert.equal(find(m.root,'data-summary-prepare').props.disabled,true);
        await click(m.root,'data-license-reconcile');assert.deepEqual(transport.calls.at(-1).body,prepared);
        const artifact={namespace:'business.expenditure_summary',key:prepared.summary_id,version:'a'.repeat(64)};
        const dependencies=[{input:secondRef,data_use_scope_id:'licence-one',policy_id:'declared_data_license_v1',policy_version:1}];
        const output={...licence,license_id:prepared.summary_id,use_grant_id:'603',covered:true,bound:true,material_refs:[artifact],resource:'business.expenditure_summary',summary:{state:'prepared',input_license_id:'licence-one',process_grant_id:'302',dependencies}};
        await reply({business_public_id:'business-one',artifact_ref:artifact,license_id:prepared.summary_id,use_grant_id:'603',status:'prepared',versions:{summary:1}});
        await reply(inventory('business-one',{licenses:[processLicence,output]}));
        assert.equal(m.draft().license,prepared.summary_id);assert.ok(find(m.root,'data-summary-publish'));assert.ok(!transport.calls.some(row=>row.url.endsWith('/materials/publish')),'No automatic publication');
        await click(m.root,'data-summary-publish');const published=transport.calls.at(-1).body;
        assert.deepEqual(published.artifact_ref,artifact);assert.deepEqual(published.dependencies,dependencies);
        assert.deepEqual(published.authority_selection.requirements.map(row=>row.leaf_grant_id),[401,302,603,202]);
        await reply({business_public_id:'business-one',artifact_ref:artifact,manifest_id:crypto.randomUUID(),closure_digest:'b'.repeat(64),versions:{manifest:1}});
        await reply(inventory('business-one',{licenses:[processLicence,{...output,summary:{...output.summary,state:'published'}}]}));
        assert.equal(find(m.root,'data-summary-publish'),undefined);
    } finally {m.app.unmount();}
});

test('actual Vue issues only the explicit actor/report/spend/display licence and withdraws it after a receipt and current read', async () => {
    const m = mount();
    try {
        await reply(inventory()); assert.equal(m.draft().source, '', 'No source is selected on entry'); assert.equal(transport.calls.length, 1);
        await issueForm(m.root); await issue(m.root); const issued = clone(transport.calls.at(-1));
        assert.match(issued.url, /data-use-licenses$/); assert.equal(issued.body.effect, 'issue'); assert.equal(issued.body.declaration.recipient_public_id, 'actor-one'); assert.deepEqual(issued.body.declaration.selection, selection); assert.equal(issued.body.declaration.operation, 'display'); assert.equal(issued.body.declaration.requires_review, false);
        assert.match(issued.body.license_id, /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        const issuedAt = Number.parseInt(issued.body.license_id.slice(0, 13).replace('-', ''), 16);
        assert.ok(Math.abs(Date.now() - issuedAt) < 5000);
        await issue(m.root); assert.equal(transport.calls.length, 2, 'Double submission never duplicates delivery'); assert.equal(m.draft().outcome, '');
        await reply(licenseReceipt(issued.body)); assert.equal(m.draft().pending, undefined); assert.match(transport.calls.at(-1).url, /data-use-journey/); await reply(inventory());
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-withdraw-confirm', true); await click(m.root, 'data-license-withdraw'); const withdrawal = transport.calls.at(-1).body;
        assert.equal(withdrawal.effect, 'withdraw'); assert.equal(withdrawal.expected_revision, 1); assert.deepEqual(withdrawal.declaration, {});
        await reply(licenseReceipt(withdrawal, 'withdrawn')); await reply(inventory('business-one', { licenses: [{ ...licence, status: 'withdrawn' }] }));
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); assert.equal(find(m.root, 'data-license-read').props.disabled, true); assert.match(text(m.root), /Withdrawn/);
        assert.doesNotMatch(text(m.root), /recipient_public_id|actor-one/); assert.equal(all(m.root).some(node => /beneficiary|recipient/i.test(node.props.id ?? '')), false, 'No fabricated recipient selector');
    } finally { m.app.unmount(); }
});

test('initial 422 is editable; generic 409 freezes and reconciles the exact original command, including a later 422', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); await issue(m.root); const first = clone(transport.calls.at(-1).body); await reject(422);
        assert.equal(m.draft().pending, undefined); assert.equal(m.draft().outcome, 'rejected'); assert.equal(find(m.root, 'data-license-holder').value, 'Holder A');
        await input(m.root, 'data-license-reference', 'Corrected mandate'); await input(m.root, 'data-license-confirm', true); await issue(m.root); const second = clone(transport.calls.at(-1).body); assert.notEqual(second.command_id, first.command_id);
        await reject(409, { error: 'business_conflict' }); assert.equal(m.draft().outcome, 'unknown'); assert.equal(find(m.root, 'data-license-issue').props.disabled, true);
        const calls = transport.calls.length; await issue(m.root); assert.equal(transport.calls.length, calls);
        await click(m.root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, second); await reject(422); assert.deepEqual(clone(m.draft().pending.body), second); assert.equal(m.draft().outcome, 'unknown');
    } finally { m.app.unmount(); }
});

test('the declarant can still withdraw exact historical consent when the source and its data are no longer visible', async () => {
    const m = mount();
    try {
        const historical = { ...licence, boundary_grant_id: null, use_grant_id: null, material_refs: [] };
        await reply(inventory('business-one', { sources: [], licenses: [historical] }));
        assert.ok(find(m.root, 'data-license-own-history'));
        assert.equal(find(m.root, 'data-license-read'), undefined);
        assert.doesNotMatch(text(m.root), /Private source|ad_account:one/);
        await input(m.root, 'data-license-history', historical.license_id);
        await input(m.root, 'data-license-withdraw-confirm', true);
        await click(m.root, 'data-license-withdraw');
        const withdrawal = transport.calls.at(-1).body;
        assert.equal(withdrawal.license_id, historical.license_id);
        assert.equal(withdrawal.expected_revision, 1);
        assert.deepEqual(withdrawal.declaration, {});
        await reply(licenseReceipt(withdrawal, 'withdrawn'));
        await reply(inventory('business-one', { sources: [], licenses: [{ ...historical, status: 'withdrawn' }] }));
        await input(m.root, 'data-license-history', historical.license_id);
        assert.equal(find(m.root, 'data-license-withdraw').props.disabled, true);
    } finally { m.app.unmount(); }
});

test('Arabic licence review explains the purpose and permitted use without exposing contract codes or ISO dates', async () => {
    const m = mount();
    try {
        locale.value = 'ar';
        await reply(inventory());
        await chooseSource(m.root);
        await input(m.root, 'data-license-current', 'licence-one');
        assert.match(text(m.root), /مراجعة أداء الإعلانات/);
        assert.match(text(m.root), /عرض البيانات داخل النشاط/);
        assert.match(text(find(m.root, 'data-license-selected-fields')), /الحقول المشمولة.*الإنفاق/);
        assert.match(text(find(m.root, 'data-license-selected-fields')), /عدد السجلات المشمولة.*1/);
        assert.doesNotMatch(text(m.root), /advertising_performance|\bdisplay\b|2030-01-01T00:00/);
    } finally { m.app.unmount(); }
});

test('coverage, binding and consumption each require an explicit step and their exact independently selected proofs', async () => {
    const m = mount();
    try {
        await reply(inventory()); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-binding', '402');
        await click(m.root, 'data-license-cover'); const cover = transport.calls.at(-1).body; assert.equal(cover.target_ref, 'boundary-one'); assert.equal(cover.effect, 'attach_subject'); assert.equal(cover.expected_revision, 0); assert.deepEqual(cover.authority_selection.requirements, [{ requirement_kind: 'business', leaf_grant_id: 402 }, { requirement_kind: 'client_data', leaf_grant_id: 301 }]);
        assert.equal(transport.calls.filter(row => /data-use-bindings$/.test(row.url)).length, 0);
        await reply({ membership_id: 'membership-one', status: 'active', versions: { membership: 1 } }); await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, coverage_revision: 1 }] }));
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-binding', '402'); await click(m.root, 'data-license-bind'); assert.equal(transport.calls.at(-1).body.data_use_scope_id, 'licence-one');
        await reply({ status: 'active', versions: { binding: 1 } }); await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, bound: true }] }));
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401'); await click(m.root, 'data-license-read'); const body = transport.calls.at(-1).body;
        assert.deepEqual(body.material_refs, material_refs); assert.equal(body.recipient_ref, 'actor-one'); assert.deepEqual(body.authority_selection.requirements, [{ requirement_kind: 'business', leaf_grant_id: 401 }, { requirement_kind: 'data_use', leaf_grant_id: 302 }, { requirement_kind: 'native_source', leaf_grant_id: 202 }]);
        await reply({ business_public_id: 'business-one', operation: 'display', purpose: licence.purpose, recipient_ref: 'actor-one', materials: [{ ref: material_refs[0], fields: { spend: nativeMoney('0'), revenue: 999999 }, coverage: 'PARTIAL', data_use_scope_id: 'licence-one' }] });
        assert.match(text(find(m.root, 'data-license-materials')), /Expenditure: 0/); assert.doesNotMatch(text(m.root), /999999/); assert.match(text(m.root), /Source-asserted values/);
    } finally { m.app.unmount(); }
});

test('refreshing the same Business authority does not lose an in-flight coverage acknowledgement', async () => {
    const m = mount();
    try {
        await reply(inventory());
        await chooseSource(m.root);
        await input(m.root, 'data-license-current', 'licence-one');
        await input(m.root, 'data-license-binding', '402');
        await click(m.root, 'data-license-cover');
        m.current.value = { ...surface('business-one'), business: { business_public_id: 'business-one', name: 'Refreshed snapshot' } };
        await nextTick();
        await reply({ membership_id: 'covered', status: 'active', versions: { membership: 1 } });
        assert.equal(m.draft().pending, undefined);
        await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, coverage_revision: 1 }] }));
        await chooseSource(m.root);
        await input(m.root, 'data-license-current', 'licence-one');
        await input(m.root, 'data-license-binding', '402');
        assert.equal(find(m.root, 'data-license-bind').props.disabled, false);
        assert.equal(transport.calls.filter(call => call.method === 'POST').length, 1);
    } finally { m.app.unmount(); }
});

test('late source and Business consumption replies never disclose the earlier private result', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, bound: true }] })); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401'); await click(m.root, 'data-license-read');
        const lateSource = transport.pending.shift(); await chooseSource(m.root, 'source-two'); lateSource.resolve({ data: { business_public_id: 'business-one', operation: 'display', purpose: licence.purpose, recipient_ref: 'actor-one', materials: [{ ref: material_refs[0], fields: { spend: 777 }, coverage: 'PARTIAL', data_use_scope_id: 'licence-one' }] } }); await flush(); assert.equal(find(m.root, 'data-license-materials'), undefined);
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401'); await click(m.root, 'data-license-read'); const lateBusiness = transport.pending.shift();
        m.current.value = surface('business-two'); await nextTick(); assert.doesNotMatch(text(m.root), /Private source-one/); lateBusiness.resolve({ data: { business_public_id: 'business-one', materials: [{ fields: { spend: 888 } }] } }); await flush(); assert.equal(find(m.root, 'data-license-materials'), undefined); await reply(inventory('business-two'));
        assert.equal(m.draft().holder, ''); assert.equal(m.draft().source, '');
    } finally { m.app.unmount(); }
});

test('403 conceals private form and readout immediately, retains only the original pending command, and focus never redelivers', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); await issue(m.root); const original = clone(transport.calls.at(-1).body); await reject(403);
        assert.equal(find(m.root, 'data-license-holder'), undefined); assert.doesNotMatch(text(m.root), /Holder A|Mandate A|Private source/); assert.equal(m.draft().holder, ''); assert.equal(m.draft().reference, ''); assert.equal(m.draft().outcome, 'denied'); assert.deepEqual(clone(m.draft().pending.body), original);
        for (const fn of focusListeners) fn(); await nextTick(); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 1); await reply(inventory());
        await click(m.root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, original); await reject(409, { error: 'business_conflict' });
        m.active.value = false; await nextTick(); assert.equal(find(m.root, 'data-license-holder'), undefined); m.active.value = true; await nextTick(); await reply(inventory()); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 2);
        transport.close(); await nextTick(); assert.equal(store.journeyDrafts.size, 0); assert.equal(find(m.root, 'data-license-holder'), undefined); assert.equal(find(m.root, 'data-license-reconcile'), undefined);
    } finally { m.app.unmount(); }
});

test('late inventory and issue receipts cannot cross a Business context; an unresolved original remains scoped to its Business', async () => {
    const m = mount();
    try {
        const initial = transport.pending.shift(); m.current.value = surface('business-two'); await nextTick(); initial.resolve({ data: inventory() }); await flush(); assert.doesNotMatch(text(m.root), /Private source/); await reply(inventory('business-two'));
        await issueForm(m.root); await issue(m.root); const original = transport.calls.at(-1).body, late = transport.pending.shift(); m.current.value = surface('business-three'); await nextTick();
        late.resolve({ data: licenseReceipt(original) }); await flush(); await reply(inventory('business-three')); assert.equal(m.draft().pending, undefined); assert.equal(m.draft().outcome, ''); assert.equal(store.hasPendingDataLicense('business-two'), true);
        const old = [...store.journeyDrafts.values()].find(row => row.pending?.business === 'business-two'); assert.equal(old.holder, ''); assert.equal(old.reference, ''); assert.deepEqual(clone(old.pending.body), original);
    } finally { m.app.unmount(); }
});

test('failed inventory stays unavailable rather than empty and real AR/EN state follows locale', async () => {
    const m = mount();
    try {
        await reject(503); assert.ok(find(m.root, 'data-license-status')); assert.equal(find(m.root, 'data-license-empty'), undefined); assert.match(text(m.root), /does not mean sources or licences are absent/);
        locale.value = 'ar'; await nextTick(); assert.equal(find(m.root, 'data-business-data-licenses').props.dir, 'rtl'); assert.match(text(m.root), /لا يعني ذلك عدم وجود/);
        locale.value = 'en'; await nextTick(); assert.equal(find(m.root, 'data-business-data-licenses').props.dir, 'ltr');
    } finally { m.app.unmount(); }
});

test('independent mandate works without choosing or possessing a source and does not activate on entry', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { sources: [], licenses: [], business_use_options: [], binding_options: [] })); assert.equal(transport.calls.length, 1);
        await input(m.root, 'data-mandate-reference', 'Client mandate A'); await input(m.root, 'data-mandate-expiry', '2030-01-01T10:00'); await input(m.root, 'data-mandate-confirm', true); await click(m.root, 'data-license-qualify');
        const command = clone(transport.calls.at(-1).body); assert.match(transport.calls.at(-1).url, /operating-mandate$/); assert.equal(command.capacity, 'responsible_business'); assert.equal(command.previous_grant, '0'); assert.equal(command.confirmed, true); assert.equal('source_id' in command, false);
        assert.equal(m.draft().outcome, ''); await reply({ business_public_id: 'business-one', grant_refs: { 'business.data.use': '501', 'business.link.manage': '502' }, assurance: 'DECLARED_AUTHORITY', qualification_ref: 'qualification-one', versions: { qualification: 1 } }); await reply(inventory('business-one', { sources: [], licenses: [] })); assert.equal(m.draft().pending, undefined); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 1);
    } finally { m.app.unmount(); }
});

test('unqualified mandate stays unavailable and a partial inventory never claims complete absence', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { sources: [], licenses: [], sources_more: true, business_use_options: [], operating_mandate: { available: false, previous_grant: '0' } }));
        assert.ok(find(m.root, 'data-license-partial')); assert.ok(find(m.root, 'data-license-mandate-unavailable')); assert.equal(find(m.root, 'data-license-qualify'), undefined); assert.equal(transport.calls.length, 1);
    } finally { m.app.unmount(); }
});

test('in-flight unmount purges private draft, freezes delivery across remount and retains an exact explicitly reconciled original', async () => {
    const m = mount(); let second;
    try {
        await reply(inventory()); await issueForm(m.root); await issue(m.root); const original = clone(transport.calls.at(-1).body), late = transport.pending.shift();
        m.app.unmount(); assert.equal(m.draft().holder, ''); assert.equal(m.draft().reference, ''); assert.ok(m.draft().pending);
        const root = element('root'); second = renderer.createApp({ render: () => h(Component, { surface: m.current.value, active: true }) }); second.mount(root); await reply(inventory());
        assert.equal(find(root, 'data-license-reconcile').props.disabled, true); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 1);
        late.resolve({ data: licenseReceipt(original) }); await flush(); assert.ok(m.draft().pending, 'An unmounted receipt cannot acknowledge the new component'); assert.equal(find(root, 'data-license-reconcile').props.disabled, false);
        await click(root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, original); await reject(422); assert.equal(m.draft().outcome, 'unknown'); assert.deepEqual(clone(m.draft().pending.body), original);
    } finally { if (second) second.unmount(); else m.app.unmount(); }
});

test('mismatched successful licence receipt remains unresolved without a replacement or a silent state refresh', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); await issue(m.root); const command = clone(transport.calls.at(-1).body); await reply({ ...licenseReceipt(command), license_id: 'sibling-licence' });
        assert.equal(m.draft().outcome, 'unknown'); assert.deepEqual(clone(m.draft().pending.body), command); assert.equal(transport.calls.length, 2); assert.equal(find(m.root, 'data-license-issue').props.disabled, true);
    } finally { m.app.unmount(); }
});

test('current consumer denial clears readout and private form with no automatic second consumption', async () => {
    const m = mount();
    try {
        const page = inventory('business-one', { licenses: [{ ...licence, covered: true, bound: true }] }); await reply(page); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401'); await click(m.root, 'data-license-read');
        await reply({ business_public_id: 'business-one', operation: 'display', purpose: licence.purpose, recipient_ref: 'actor-one', materials: [{ ref: material_refs[0], fields: {}, coverage: 'UNKNOWN', data_use_scope_id: 'licence-one' }] }); assert.match(text(find(m.root, 'data-license-materials')), /Expenditure unit unknown/);
        await input(m.root, 'data-license-holder', 'Private holder'); await click(m.root, 'data-license-read'); await reject(403);
        assert.equal(find(m.root, 'data-license-materials'), undefined); assert.equal(find(m.root, 'data-license-holder'), undefined); assert.equal(m.draft().holder, ''); assert.equal(m.draft().source, ''); assert.equal(transport.calls.filter(row => /materials\/read$/.test(row.url)).length, 2);
    } finally { m.app.unmount(); }
});

test('source licence confirmation cannot substitute for the separate Business mandate declaration and edits renew confirmation', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); assert.equal(find(m.root, 'data-license-issue').props.disabled, false); assert.equal(m.draft().mandateConfirmed, false); assert.equal(find(m.root, 'data-license-qualify').props.disabled, true);
        await input(m.root, 'data-license-holder', 'Revised holder'); assert.equal(m.draft().confirmed, false); assert.equal(find(m.root, 'data-license-issue').props.disabled, true);
        await input(m.root, 'data-mandate-reference', 'Separate Business mandate'); await input(m.root, 'data-mandate-expiry', '2030-01-01T10:00'); await input(m.root, 'data-mandate-confirm', true); assert.equal(m.draft().confirmed, false); assert.equal(find(m.root, 'data-license-qualify').props.disabled, false);
        await input(m.root, 'data-mandate-reference', 'Revised Business mandate'); assert.equal(m.draft().mandateConfirmed, false); assert.equal(find(m.root, 'data-license-qualify').props.disabled, true); assert.equal(transport.calls.length, 1);
    } finally { m.app.unmount(); }
});

test('focus requalification requires renewed confirmation and an in-place authority change synchronously conceals the prior private form', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); const originalDraft = m.draft(); assert.equal(originalDraft.confirmed, true);
        for (const fn of focusListeners) fn(); await nextTick(); assert.equal(originalDraft.confirmed, false); assert.equal(find(m.root, 'data-license-holder'), undefined);
        const changed = inventory(); changed.sources[0].datasets[0].material_refs[0].version = 'v2'; await reply(changed); assert.equal(find(m.root, 'data-license-issue').props.disabled, true);
        m.current.value.authority_selection.requirements[0].leaf_grant_id = 999; await nextTick(); assert.equal(find(m.root, 'data-license-holder'), undefined); assert.equal(originalDraft.holder, ''); assert.match(transport.calls.at(-1).url, /access=999$/); await reply(inventory()); assert.equal(m.draft().source, ''); assert.equal(m.draft().reference, '');
    } finally { m.app.unmount(); }
});

test('actual named-beneficiary chooser assigns, revokes and creates a fresh bounded assignment without issuing Business or Source grants', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { beneficiaries })); await assignmentForm(m.root); const chooser = find(m.root, 'data-assignment-beneficiary');
        assert.match(text(chooser), /Qualified colleague/); assert.doesNotMatch(text(chooser), /Different agency colleague/); assert.equal(m.draft().assignmentConfirmed, true); assert.equal(m.draft().confirmed, false); assert.equal(m.draft().mandateConfirmed, false);
        await click(m.root, 'data-assignment-assign'); const command = clone(transport.calls.at(-1).body); assert.match(transport.calls.at(-1).url, /data-use-beneficiaries$/);
        assert.equal(command.license_id, 'licence-one'); assert.equal(command.recipient_public_id, 'beneficiary-one'); assert.equal(command.expected_revision, 0); assert.equal(command.effect, 'assign'); assert.deepEqual(command.authority_selection.requirements, [{ requirement_kind: 'business', leaf_grant_id: 401 }, { requirement_kind: 'client_data', leaf_grant_id: 301 }]);
        assert.deepEqual(Object.keys(command).sort(), ['authority_selection', 'command_id', 'effect', 'expected_revision', 'license_id', 'recipient_public_id', 'valid_until']); assert.equal(transport.calls.some(row => /\/(grants|materials\/read|data-rights\/commands)$/.test(row.url)), false);
        assert.equal(m.draft().pending.kind, 'beneficiary'); await reply(beneficiaryReceipt(command)); await reply(inventory('business-one', { beneficiaries, assignments: [assigned] }));
        await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); assert.match(text(find(m.root, 'data-assignment-history')), /Licensed use assigned/); assert.match(text(find(m.root, 'data-assignment-limits')), /grants neither account management nor original-data reading/);
        await input(m.root, 'data-assignment-authority', '401'); await input(m.root, 'data-assignment-confirm', true); await click(m.root, 'data-assignment-revoke'); const revoke = clone(transport.calls.at(-1).body);
        assert.equal(revoke.expected_revision, 1); assert.equal(revoke.effect, 'revoke'); assert.equal(revoke.recipient_public_id, assigned.recipient_public_id); assert.equal(revoke.valid_until, assigned.valid_until); assert.equal(m.draft().pending.expectedGrant, '601');
        await reply(beneficiaryReceipt(revoke)); await reply(inventory('business-one', { beneficiaries, assignments: [{ ...assigned, status: 'revoked', revision: 2 }] }));
        await assignmentForm(m.root); assert.match(text(find(m.root, 'data-assignment-history')), /Assignment revoked/); await click(m.root, 'data-assignment-assign'); const reassign = clone(transport.calls.at(-1).body); assert.equal(reassign.expected_revision, 2); assert.notEqual(reassign.command_id, command.command_id); await reply(beneficiaryReceipt(reassign, '602')); await reply(inventory('business-one', { beneficiaries, assignments: [{ ...assigned, grant_id: '602', revision: 3 }] }));
        assert.equal(transport.calls.filter(row => row.method === 'POST').length, 3); assert.equal(transport.calls.filter(row => /materials\/read$/.test(row.url)).length, 0);
    } finally { m.app.unmount(); }
});

test('beneficiary assignment first validation rejection permits correction while generic conflict and later validation rejection retain the original exact command', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { beneficiaries })); await assignmentForm(m.root); await click(m.root, 'data-assignment-assign'); const first = clone(transport.calls.at(-1).body); await reject(422);
        assert.equal(m.draft().pending, undefined); assert.equal(m.draft().beneficiary, 'beneficiary-one'); await input(m.root, 'data-assignment-expiry', '2029-12-29T10:00'); await input(m.root, 'data-assignment-confirm', true); await click(m.root, 'data-assignment-assign'); const original = clone(transport.calls.at(-1).body); assert.notEqual(original.command_id, first.command_id);
        await reject(409, { error: 'business_conflict' }); assert.equal(m.draft().outcome, 'unknown'); assert.equal(find(m.root, 'data-assignment-assign').props.disabled, true); const posts = transport.calls.filter(row => row.method === 'POST').length;
        for (const fn of focusListeners) fn(); await nextTick(); await reply(inventory('business-one', { beneficiaries })); assert.equal(transport.calls.filter(row => row.method === 'POST').length, posts);
        await click(m.root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, original); await reject(422); assert.equal(m.draft().outcome, 'unknown'); assert.deepEqual(clone(m.draft().pending.body), original);
    } finally { m.app.unmount(); }
});

test('beneficiary assignment requires a real qualified list, bounded expiry and its own renewed confirmation', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { beneficiaries })); await assignmentForm(m.root); await input(m.root, 'data-assignment-expiry', '2031-01-01T10:00'); await input(m.root, 'data-assignment-confirm', true); assert.equal(find(m.root, 'data-assignment-assign').props.disabled, true);
        await input(m.root, 'data-assignment-expiry', '2029-12-30T10:00'); assert.equal(m.draft().assignmentConfirmed, false); await input(m.root, 'data-assignment-beneficiary', 'unlisted-user'); await input(m.root, 'data-assignment-confirm', true); assert.equal(find(m.root, 'data-assignment-assign').props.disabled, true); assert.equal(transport.calls.length, 1);
        await input(m.root, 'data-assignment-beneficiary', 'beneficiary-one'); await input(m.root, 'data-assignment-confirm', true); await chooseSource(m.root, 'source-two'); assert.equal(m.draft().beneficiary, ''); assert.equal(m.draft().assignmentExpires, ''); assert.equal(m.draft().assignmentConfirmed, false);
        await click(m.root, 'data-license-refresh'); await reply(inventory()); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); assert.equal(find(m.root, 'data-assignment-beneficiary'), undefined); assert.ok(find(m.root, 'data-assignment-unavailable')); assert.equal(find(m.root, 'data-assignment-assign').props.disabled, true);
    } finally { m.app.unmount(); }
});

test('beneficiary revoke rejects a mismatched grant receipt and authoritative denial conceals every private assignment field', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { beneficiaries, assignments: [assigned] })); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-assignment-authority', '401'); await input(m.root, 'data-assignment-confirm', true); await click(m.root, 'data-assignment-revoke'); const original = clone(transport.calls.at(-1).body);
        await reply(beneficiaryReceipt(original, 'sibling-grant')); assert.equal(m.draft().outcome, 'unknown'); assert.deepEqual(clone(m.draft().pending.body), original);
        await click(m.root, 'data-license-reconcile'); await reject(403); assert.equal(m.draft().outcome, 'denied'); assert.equal(m.draft().beneficiary, ''); assert.equal(m.draft().assignmentAuthority, ''); assert.equal(m.draft().assignmentExpires, ''); assert.equal(find(m.root, 'data-assignment-history'), undefined); assert.doesNotMatch(text(m.root), /Qualified colleague/); assert.deepEqual(clone(m.draft().pending.body), original); assert.equal(m.draft().pending.expectedGrant, '601');
    } finally { m.app.unmount(); }
});

test('typed beneficiary revision rejection after exact reconciliation refreshes the baseline and requires a newly confirmed command', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { beneficiaries })); await assignmentForm(m.root); await click(m.root, 'data-assignment-assign'); const first = clone(transport.calls.at(-1).body); await reject(409, { error: 'business_conflict' });
        await click(m.root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, first); await reject(409, { error: 'business_relation_revision_rejected' }); assert.equal(m.draft().pending, undefined); assert.equal(m.draft().outcome, 'revision');
        await reply(inventory('business-one', { beneficiaries, assignments: [{ ...assigned, status: 'revoked', revision: 2 }] })); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 2); assert.equal(find(m.root, 'data-assignment-assign').props.disabled, true); assert.match(text(m.root), /revision changed before this request took effect/);
        await input(m.root, 'data-assignment-confirm', true); await click(m.root, 'data-assignment-assign'); const replacement = clone(transport.calls.at(-1).body); assert.notEqual(replacement.command_id, first.command_id); assert.equal(replacement.expected_revision, 2);
        await reply(beneficiaryReceipt(replacement, '602')); await reply(inventory('business-one', { beneficiaries, assignments: [{ ...assigned, grant_id: '602', revision: 3 }] }));
    } finally { m.app.unmount(); }
});

test('an active beneficiary assignment never substitutes for independent original Source reading permission', async () => {
    const m = mount();
    try {
        const page = inventory('business-one', { beneficiaries, assignments: [assigned], licenses: [{ ...licence, covered: true, bound: true }] }); page.sources[0].read_grant_id = null;
        await reply(page); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401');
        assert.match(text(find(m.root, 'data-assignment-history')), /Licensed use assigned/); assert.equal(find(m.root, 'data-license-read').props.disabled, true); assert.equal(transport.calls.length, 1); assert.match(text(find(m.root, 'data-assignment-limits')), /reading conditions are checked again/);
    } finally { m.app.unmount(); }
});

test('a qualified assigned recipient sees licence metadata but never issuer withdrawal or beneficiary assignment controls', async () => {
    const m = mount();
    try {
        const page = inventory('business-one', { beneficiaries, assignments: [assigned], licenses: [{ ...licence, is_declarant: false, boundary_grant_id: null, covered: true, bound: true }] }); page.sources[0].manager_grant_id = null; page.sources[0].read_grant_id = null;
        await reply(page); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401');
        assert.match(text(m.root), /Review advertising performance/); assert.doesNotMatch(text(m.root), /advertising_performance/); assert.equal(find(m.root, 'data-license-withdraw'), undefined); assert.equal(find(m.root, 'data-license-beneficiary-journey'), undefined); assert.equal(find(m.root, 'data-assignment-assign'), undefined); assert.equal(find(m.root, 'data-license-holder'), undefined); assert.ok(find(m.root, 'data-license-issue-unavailable')); assert.equal(find(m.root, 'data-license-read').props.disabled, true); assert.equal(transport.calls.length, 1);
    } finally { m.app.unmount(); }
});

test('a qualified beneficiary without Source access sees only the assignment summary, and loses it on refresh after withdrawal', async () => {
    const m = mount();
    try {
        const restricted = { sources: [], licenses: [], assigned_licenses: [{ license_id: licence.license_id,
            operation: licence.operation, purpose: licence.purpose, valid_until: licence.valid_until }] };
        await reply(inventory('business-one', restricted));
        assert.match(text(find(m.root, 'data-assigned-licenses')), /Review advertising performance/);
        assert.equal(find(m.root, 'data-license-read'), undefined);
        assert.equal(find(m.root, 'data-assignment-assign'), undefined);
        assert.doesNotMatch(text(m.root), /licence-one|report-one|source-one/);
        await click(m.root, 'data-license-refresh');
        await reply(inventory('business-one', { ...restricted, assigned_licenses: [] }));
        assert.equal(find(m.root, 'data-assigned-licenses'), undefined);
    } finally { m.app.unmount(); }
});

test('actual expenditure readout preserves exact major-unit strings and currency with bidi isolation while concealing native internals and provenance', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, bound: true }] })); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401'); await click(m.root, 'data-license-read');
        const exactMajor = '123456789012345678901234.56789';
        await reply({ business_public_id: 'business-one', operation: 'display', purpose: licence.purpose, recipient_ref: 'actor-one', materials: [{ ref: material_refs[0], fields: { spend: nativeMoney('123456789012345678901234567890', { native_unit: 'google.currency_micros', decimal_scale: 6, major_units: exactMajor, format_exponent: 'DO_NOT_SHOW_FORMAT_EXPONENT' }) }, coverage: 'PARTIAL', data_use_scope_id: 'licence-one', provenance: { private_trace: 'DO_NOT_SHOW_PROVENANCE' } }] });
        const amount = find(m.root, 'data-native-amount'), currency = find(m.root, 'data-native-currency'); assert.equal(amount.tag, 'bdi'); assert.equal(text(amount), exactMajor); assert.equal(currency.tag, 'bdi'); assert.equal(text(currency), 'SAR'); assert.equal(text(find(m.root, 'data-native-coverage')), 'Partial');
        assert.doesNotMatch(text(find(m.root, 'data-license-materials')), /native_value|major_units|decimal_scale|scale_basis|format_exponent|provenance|DO_NOT_SHOW|123456789012345678901234567890/);
        locale.value = 'ar'; await nextTick(); assert.equal(text(find(m.root, 'data-native-amount')), exactMajor); assert.equal(text(find(m.root, 'data-native-coverage')), 'جزئية'); assert.doesNotMatch(text(find(m.root, 'data-license-dataset')), /PARTIAL/); assert.match(text(find(m.root, 'data-license-dataset')), /جزئية/);
    } finally { m.app.unmount(); }
});

test('unknown scale, missing major units and unsupported scalar or legacy shapes never become invented currency amounts', async () => {
    const m = mount();
    try {
        await reply(inventory('business-one', { licenses: [{ ...licence, covered: true, bound: true }] })); await chooseSource(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-use', '401');
        for (const value of [nativeMoney('12000000', { decimal_scale: null, scale_basis: null, major_units: null }), nativeMoney('12000000', { decimal_scale: 6, major_units: null }), { amount: '17', currency: 'SAR' }, 0]) {
            await click(m.root, 'data-license-read'); await reply({ business_public_id: 'business-one', operation: 'display', purpose: licence.purpose, recipient_ref: 'actor-one', materials: [{ ref: material_refs[0], fields: { spend: value }, coverage: 'UNKNOWN', data_use_scope_id: 'licence-one' }] });
            assert.equal(find(m.root, 'data-native-amount'), undefined); assert.equal(find(m.root, 'data-native-currency'), undefined); assert.match(text(find(m.root, 'data-native-amount-unknown')), /Expenditure unit unknown/); assert.equal(text(find(m.root, 'data-native-coverage')), 'Unknown'); assert.doesNotMatch(text(find(m.root, 'data-license-materials')), /12000000|SAR|amount|native_value/);
        }
    } finally { m.app.unmount(); }
});

test('uncertain eligibility blocks licence approval locally and never pretends to submit a legal review request', async () => {
    const m = mount();
    try {
        await reply(inventory()); await issueForm(m.root); await input(m.root, 'data-license-review-needed', true); assert.equal(m.draft().confirmed, false); assert.equal(find(m.root, 'data-license-confirm').props.disabled, true); assert.equal(find(m.root, 'data-license-issue').props.disabled, true); assert.match(text(find(m.root, 'data-license-review-state')), /no automatic legal review or recorded review request/);
        await issue(m.root); assert.equal(transport.calls.length, 1); locale.value = 'ar'; await nextTick(); assert.match(text(find(m.root, 'data-license-review-state')), /تحقق من سندك أو احصل على التفويض الصحيح/);
        await input(m.root, 'data-license-review-needed', false); assert.equal(m.draft().confirmed, false); assert.equal(find(m.root, 'data-license-issue').props.disabled, true); await input(m.root, 'data-license-confirm', true); assert.equal(find(m.root, 'data-license-issue').props.disabled, false);
    } finally { m.app.unmount(); }
});

test('issuers independently confirm withdrawal of active or expired licences while unsure about new issuance and reconcile only that original withdrawal', async () => {
    for (const status of ['active', 'expired']) {
        const m = mount();
        try {
            await reply(inventory('business-one', { licenses: [{ ...licence, status }] })); await issueForm(m.root); await input(m.root, 'data-license-current', 'licence-one'); await input(m.root, 'data-license-review-needed', true);
            assert.equal(m.draft().reviewNeeded, true); assert.equal(find(m.root, 'data-license-issue').props.disabled, true); assert.equal(find(m.root, 'data-license-confirm').props.disabled, true); assert.equal(find(m.root, 'data-license-withdraw').props.disabled, true); assert.equal(find(m.root, 'data-license-withdraw-confirm').props.disabled, false);
            await input(m.root, 'data-license-withdraw-confirm', true); assert.equal(m.draft().confirmed, false); assert.equal(m.draft().reviewNeeded, true); await click(m.root, 'data-license-withdraw');
            const original = clone(transport.calls.at(-1).body); assert.equal(original.license_id, 'licence-one'); assert.equal(original.effect, 'withdraw'); assert.equal(original.expected_revision, 1); assert.deepEqual(original.declaration, {}); await reject(409, { error: 'business_conflict' });
            assert.equal(m.draft().reviewNeeded, true); assert.equal(m.draft().outcome, 'unknown'); await click(m.root, 'data-license-reconcile'); assert.deepEqual(transport.calls.at(-1).body, original);
            await reply(licenseReceipt(original, 'withdrawn')); await reply(inventory('business-one', { licenses: [{ ...licence, status: 'withdrawn' }] })); assert.equal(m.draft().pending, undefined); assert.equal(transport.calls.filter(row => row.method === 'POST').length, 2);
        } finally { m.app.unmount(); }
    }
});
