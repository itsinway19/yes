import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { ref, createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { parse, compileScript } from '@vue/compiler-sfc';
import { withPresentationPorts } from './Support/presentation-ports.mjs';
const moduleUrl = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const path = '/internal/administration/market-knowledge';
const data = (sources = []) => ({ sources, next_cursor: null, methods: [{ method_ref: 'offer_equivalence:1', availability: 'available' }], private_processing: 'business_scoped', connection_status: 'not_observed', shared_knowledge: [], shared_knowledge_window: 100 });
const visibility = suffix => ({ protected: true, path: path + suffix, context: 'qualified-current-staff' });
const resource = (value = data(), suffix = '') => ({ data: { data: value, visibility: visibility(suffix) } });
async function fixture(reader) {
 const key = 'marketOperations' + crypto.randomUUID().replaceAll('-', '');
 const runtime = { revision: 0, closed: false, status: ref('ready'), callbacks: [], reader };
 globalThis[key] = runtime;
 const transport = moduleUrl(`const r=globalThis.${key};export const status=r.status,stamp=()=>r.revision,owned=value=>!r.closed&&value===r.revision,onClose=fn=>r.callbacks.push(fn),close=()=>{r.closed=true;r.status.value='closed';r.callbacks.forEach(fn=>fn());};export class TransportError extends Error{constructor(status,body={}){super();this.status=status;this.body=body;}}`);
 const ports = withPresentationPorts({ vue: import.meta.resolve('vue'), 'openapi-fetch': import.meta.resolve('openapi-fetch'), '../spa/api': moduleUrl(`export const api={GET:(...args)=>globalThis.${key}.reader(...args)};`), '../spa/transport': transport });
 let code = ts.transpileModule(await readFile(new URL('../resources/js/market-knowledge/state.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
 for (const [name, target] of Object.entries(ports)) code = code.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target)).replaceAll(`from "${name}"`, 'from ' + JSON.stringify(target));
 const state = await import(moduleUrl(code));
 return { state, runtime, transport: await import(transport), dispose() { state.clearOperations(); delete globalThis[key]; } };
}

test('actual operations read binds the cursor and passive data to the exact current qualified witness', async () => {
 const cursor = '00000000-0000-7000-8000-000000000001', calls = [];
 const page = data([{ source_ref: cursor, custody_revision: 1, state: 'active', rights_window: 'current', valid_until: null }]);
 const f = await fixture(async (url, options) => { calls.push({ url, query: options.params.query, headers: options.headers }); return options.headers['X-Builderad-Witness'] ? { data: { visibility: visibility('?cursor=' + cursor) } } : resource(page, '?cursor=' + cursor); });
 try {
  const result = await f.state.readOperations({ cursor }, true);
  assert.deepEqual(result, page); assert.equal(f.state.operations.value, page); assert.equal(f.state.refreshFailed.value, false);
  assert.deepEqual(calls, [{ url: path, query: { cursor }, headers: { 'X-Builderad-Passive': '1' } }, { url: path, query: { cursor }, headers: { 'X-Builderad-Witness': '1' } }]);
 } finally { f.dispose(); }
});

test('a changed witness closes the operational projection rather than publishing stale source metadata', async () => {
 const f = await fixture(async (_url, options) => options.headers['X-Builderad-Witness'] ? { data: { visibility: { ...visibility(''), context: 'different-current-staff' } } } : resource());
 try { await f.state.readOperations(); assert.equal(f.runtime.closed, true); assert.equal(f.state.operations.value, undefined); } finally { f.dispose(); }
});

test('loss of current authority clears an already displayed projection and fails direct refresh closed', async () => {
 let deny = false;
 const f = await fixture(async (_url, options) => { if (deny) throw new f.transport.TransportError(403); return options.headers['X-Builderad-Witness'] ? { data: { visibility: visibility('') } } : resource(); });
 try { await f.state.readOperations(); assert.ok(f.state.operations.value); deny = true; await f.state.readOperations({}, false, true); assert.equal(f.runtime.closed, true); assert.equal(f.state.operations.value, undefined); } finally { f.dispose(); }
});

test('failed refresh preserves only a marked previous snapshot and never claims that it was verified again', async () => {
 let fail = false;
 const snapshot = data();
 const f = await fixture(async (_url, options) => { if (fail) throw Error('unavailable'); return options.headers['X-Builderad-Witness'] ? { data: { visibility: visibility('') } } : resource(snapshot); });
 try {
  await f.state.readOperations(); fail = true; assert.equal(await f.state.readOperations({}, false, true), undefined);
  assert.equal(f.state.operations.value, snapshot); assert.equal(f.state.refreshFailed.value, true); assert.equal(f.state.reading.value, false); assert.equal(f.runtime.status.value, 'ready');
  fail = false; await f.state.readOperations({}, false, true); assert.equal(f.state.refreshFailed.value, false);
 } finally { f.dispose(); }
});

test('superseded and cleared reads cannot repopulate a different presentation or page', async () => {
 let release; const latest = data(); let calls = 0;
 const f = await fixture(async () => { calls++; return calls === 1 ? new Promise(resolve => release = resolve) : calls === 2 ? resource(latest) : { data: { visibility: visibility('') } }; });
 try {
  const old = f.state.readOperations(); await f.state.readOperations(); assert.equal(f.state.operations.value, latest);
  release(resource(data([{ source_ref: 'OLD_PRIVATE_SENTINEL' }]))); await old; assert.equal(f.state.operations.value, latest);
  f.runtime.revision++; f.state.clearOperations(); assert.equal(f.state.operations.value, undefined); assert.equal(f.state.reading.value, false);
 } finally { f.dispose(); }
});

test('candidate admission owns publication and aborted admission cannot commit operational metadata', async () => {
 const f = await fixture(async (_url, options) => options.headers['X-Builderad-Witness'] ? { data: { visibility: visibility('') } } : resource());
 try {
  let commit; const cancellation = new AbortController();
  await f.state.readOperations({}, false, false, { signal: cancellation.signal, publish: value => { commit = value; } });
  assert.equal(f.state.operations.value, undefined); assert.equal(typeof commit, 'function'); commit(); assert.ok(f.state.operations.value);
  f.state.clearOperations(); cancellation.abort(); await f.state.readOperations({}, false, false, { signal: cancellation.signal, publish() { assert.fail('Aborted admission must not publish'); } }); assert.equal(f.state.operations.value, undefined);
 } finally { f.dispose(); }
});

async function renderOperations({ value = data(), language = 'ar', stale = false } = {}) {
 const key = 'marketView' + crypto.randomUUID().replaceAll('-', '');
 globalThis[key] = { value, language, stale };
 const previousDocument = globalThis.document; globalThis.document = { title: '' };
 const vue = import.meta.resolve('vue');
 const ports = {
  vue,
  'vue-router': moduleUrl('export const useRoute=()=>({query:{}}),useRouter=()=>({push:()=>{throw Error("Render cannot issue an effect");}});'),
  '../internal-operations/OperationsShell.vue': moduleUrl(`import {h} from ${JSON.stringify(vue)};export default {setup(_,{slots}){return()=>h('main',[slots.header?.(),slots.default?.()]);}};`),
  '../spa/locale': moduleUrl(`import {ref} from ${JSON.stringify(vue)};export const locale=ref(globalThis.${key}.language);`),
  './state': moduleUrl(`import {ref,shallowRef} from ${JSON.stringify(vue)};const r=globalThis.${key};export const operations=shallowRef(r.value),reading=ref(false),refreshFailed=ref(r.stale),readOperations=()=>{throw Error('Render cannot fetch');};`),
 };
 try {
  const { descriptor } = parse(await readFile(new URL('../resources/js/market-knowledge/OperationsView.vue', import.meta.url), 'utf8'));
  let code = ts.transpileModule(compileScript(descriptor, { id: key, inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
  for (const [name, target] of Object.entries(ports)) code = code.replaceAll(`from '${name}'`, 'from ' + JSON.stringify(target)).replaceAll(`from "${name}"`, 'from ' + JSON.stringify(target));
  const component = (await import(moduleUrl(code))).default;
  return await renderToString(createSSRApp(component));
 } finally { delete globalThis[key]; if (previousDocument === undefined) delete globalThis.document; else globalThis.document = previousDocument; }
}

test('actual operations view explains licence and connection limits in both languages and does not render private extras', async () => {
 const value = data([{ source_ref: '00000000-0000-7000-8000-000000000001', custody_revision: 2, state: 'restricted', rights_window: 'expired', valid_until: null, private_provenance: 'PRIVATE_SOURCE_SENTINEL' }]);
 value.private_results = [{ business_name: 'PRIVATE_BUSINESS_SENTINEL', finding: 'PRIVATE_FINDING_SENTINEL' }];
 for (const language of ['ar', 'en']) {
  const html = await renderOperations({ value, language });
  assert.ok(html.includes(language === 'ar' ? 'المعرفة السوقية' : 'Market knowledge'));
  assert.ok(html.includes(language === 'ar' ? 'المصدر مقيّد' : 'Source restricted'));
  assert.ok(html.includes(language === 'ar' ? 'انتهت نافذة الحق' : 'Rights window has expired'));
  assert.ok(html.includes(language === 'ar' ? 'لم يُتحقق من اتصال المصادر المباشر' : 'Live source connectivity has not been verified'));
  for (const secret of ['PRIVATE_SOURCE_SENTINEL', 'PRIVATE_BUSINESS_SENTINEL', 'PRIVATE_FINDING_SENTINEL']) assert.equal(html.includes(secret), false);
  assert.equal(html.includes('method_ref'), false); assert.equal(html.includes('offer_equivalence:1'), false);
 }
});

test('retained snapshot is explicit after refresh failure and an empty read makes no zero-demand claim', async () => {
 const value = data([{ source_ref: '00000000-0000-7000-8000-000000000001', custody_revision: 1, state: 'active', rights_window: 'current', valid_until: null }]);
 const stale = await renderOperations({ value, language: 'en', stale: true });
 assert.ok(stale.includes('This is a previous snapshot')); assert.ok(stale.includes('Saved status — verification needed'));
 assert.equal(stale.includes('Recorded rights window is current'), false); assert.equal(stale.includes('Available locally'), false);
 const empty = await renderOperations({ language: 'en' });
 assert.ok(empty.includes('This does not mean that market information or demand is absent'));
 assert.ok(empty.includes('This page does not create sources or issue rights'));
});


test('shared publication summaries distinguish preparation, currentness, review and removed payload in Arabic and English', async () => {
 const value = data();
 value.shared_knowledge = [
  { method_ref: 'qualified_price_observation:1', publication_state: 'prepared', validity: 'not_published', processing_outcome: 'unknown', reason_category: 'none', count: 2 },
  { method_ref: 'qualified_geography_observation:1', publication_state: 'published', validity: 'current', processing_outcome: 'completed', reason_category: 'none', count: 3 },
  { method_ref: 'qualified_interest_change:1', publication_state: 'published', validity: 'review_required', processing_outcome: 'unknown', reason_category: 'unknown', count: 4 },
  { method_ref: 'offer_equivalence:1', publication_state: 'published', validity: 'unknown', processing_outcome: 'unknown', reason_category: 'unknown', count: 5 },
  { method_ref: 'qualified_price_observation:1', publication_state: 'published', validity: 'unavailable', processing_outcome: 'unavailable', reason_category: 'unavailable', count: 6 },
  { method_ref: 'qualified_geography_observation:1', publication_state: 'retention_removed', validity: 'unavailable', processing_outcome: 'unavailable', reason_category: 'unavailable', count: 7 },
 ];
 value.methods.push({ method_ref: 'qualified_price_observation:1', availability: 'available' }, { method_ref: 'qualified_geography_observation:1', availability: 'available' });
 for (const language of ['ar', 'en']) {
  const html = await renderOperations({ value, language });
  const expected = language === 'ar' ? ['المعرفة المشتركة المحفوظة', 'لقطة محفوظة لم تُنشر نتيجتها', 'صلاحيتها حالية', 'تحتاج مراجعة قبل الاعتماد', 'لم يُتحقق من صلاحيتها', 'تعذر التحقق من صلاحيتها', 'أُزيل محتوى المخرج المحفوظ', 'رصد السعر المؤهل', 'رصد النطاق الجغرافي المؤهل', 'لا يعني حذف بيانات المصدر كله'] : ['Retained shared knowledge', 'Saved capture without a published result', 'Currently valid', 'Review before relying on this knowledge', 'Validity has not been verified', 'Validity could not be checked', 'Retained output content was removed', 'Qualified price observation', 'Qualified geography observation', 'does not mean all source data was deleted'];
  for (const text of expected) assert.ok(html.includes(text), text);
  assert.ok(html.includes('data-shared-knowledge'));
  assert.ok(html.includes(language === 'ar' ? 'وجود اللقطة لا يثبت جاهزيتها الآن' : 'a saved capture does not establish current readiness'));
  assert.equal(html.includes('qualified_price_observation:1'), false);
  assert.equal(html.includes('publication_state'), false);
  assert.equal((html.match(/<button/g) ?? []).length, 1, 'Only read-only refresh is exposed; publication/removal are not UI actions');
 }
});

test('shared summaries include no private extra payload or identifiers and explain rights independently from validity', async () => {
 const value = data();
 value.shared_knowledge = [{ method_ref: 'qualified_price_observation:1', publication_state: 'published', validity: 'current', processing_outcome: 'completed', reason_category: 'none', count: 2, business_id: 'PRIVATE_SUMMARY_BUSINESS', payload: 'PRIVATE_SUMMARY_PAYLOAD', actor: 'PRIVATE_SUMMARY_ACTOR', evidence: 'PRIVATE_SUMMARY_EVIDENCE' }];
 const html = await renderOperations({ value, language: 'en' });
 for (const privateValue of ['PRIVATE_SUMMARY_BUSINESS', 'PRIVATE_SUMMARY_PAYLOAD', 'PRIVATE_SUMMARY_ACTOR', 'PRIVATE_SUMMARY_EVIDENCE']) assert.equal(html.includes(privateValue), false);
 assert.ok(html.includes('Each consumer still needs its own current rights, purpose and qualified use.'));
 assert.ok(html.includes('These counts cover shared platform knowledge only, never private business processing.'));
 assert.ok(html.includes('within the latest 100 saved shared captures; these are not all-time totals.'));
 const empty = await renderOperations({ language: 'en' });
 assert.ok(empty.includes('No shared knowledge summaries were returned in this read.'));
 assert.ok(empty.includes('This is not a count of business analyses or evidence that market knowledge is absent.'));
});

test('failed refresh marks every shared summary as a prior snapshot and does not repeat live validity claims', async () => {
 const value = data();
 value.shared_knowledge = [{ method_ref: 'qualified_price_observation:1', publication_state: 'published', validity: 'current', processing_outcome: 'completed', reason_category: 'none', count: 2 }];
 for (const language of ['ar', 'en']) {
  const html = await renderOperations({ value, language, stale: true });
  assert.ok(html.includes(language === 'ar' ? 'ملخص سابق — يلزم تحديث التحقق' : 'Previous summary — refresh verification'));
  assert.equal(html.includes(language === 'ar' ? 'صلاحيتها حالية' : 'Currently valid'), false);
  assert.ok(html.includes(language === 'ar' ? 'حدّث القراءة قبل تقييم حالة المعرفة أو اتخاذ خطوة جديدة.' : 'Refresh this read before assessing knowledge status or taking a new step.'));
 }
});


test('shared rows distinguish publication, validity and processing outcome without exposing evidence reasons', async () => {
 const value = data();
 const row = (processing_outcome, reason_category, extra = {}) => ({ method_ref: 'qualified_interest_change:1', publication_state: 'published', validity: 'current', processing_outcome, reason_category, count: 1, ...extra });
 value.shared_knowledge = [row('completed', 'none', { status: 'different' }), row('abstained', 'comparison_basis_insufficient', { status: 'not_comparable', reason_codes: ['ZERO_BASELINE'], value: 0, message: 'ARBITRARY_REASON_SENTINEL' }), row('abstained', 'required_information_missing', { status: 'requires_information' }), row('abstained', 'generic_abstention', { status: 'unresolved' }), row('unknown', 'unknown'), row('unavailable', 'unavailable'), row('abstained', 'unknown', { validity: 'review_required' }), row('completed', 'none', { publication_state: 'prepared', validity: 'not_published' }), row('abstained', 'generic_abstention', { publication_state: 'retention_removed', validity: 'unavailable' })];
 for (const language of ['ar', 'en']) {
  const html = await renderOperations({ value, language });
  const expected = language === 'ar' ? ['حالة النشر', 'صلاحية المعرفة', 'نتيجة المعالجة', 'اكتملت المعالجة', 'لم تتوصل المعالجة إلى استنتاج مؤهل', 'أساس المقارنة غير كافٍ', 'معلومات لازمة غير مكتملة', 'لم تسمح الأدلة باستنتاج مؤهل', 'لم يُتحقق من نتيجة المعالجة', 'تعذر التحقق من نتيجة المعالجة', 'لا يثبت منفعة تجارية', 'لا يعني عطلًا في النظام'] : ['Publication state', 'Knowledge validity', 'Processing outcome', 'Processing completed', 'No qualified conclusion was established', 'Comparison basis is insufficient', 'Required information is incomplete', 'The evidence did not support a qualified conclusion', 'Processing outcome has not been verified', 'Processing outcome could not be checked', 'does not establish commercial benefit', 'does not mean an infrastructure failure'];
  for (const text of expected) assert.ok(html.includes(text), text);
  assert.equal((html.match(/data-shared-outcome="completed"/g) ?? []).length, 1, 'Prepared output does not expose a past outcome');
  assert.equal((html.match(/data-shared-outcome="abstained"/g) ?? []).length, 4, 'Removed output does not expose a past outcome');
  assert.ok(html.includes(language === 'ar' ? 'توجد معرفة حالية لم تنتج استنتاجًا مؤهلًا' : 'Some currently valid knowledge did not yield a qualified conclusion'));
  for (const secret of ['ZERO_BASELINE', 'reason_codes', 'different', 'not_comparable', 'requires_information', 'unresolved', 'ARBITRARY_REASON_SENTINEL', 'value: 0', 'baseline is zero', 'خط الأساس يساوي صفرًا']) assert.equal(html.includes(secret), false, secret);
  assert.equal((html.match(/data-shared-state=/g) ?? []).length, 9, 'Same-method groups with distinct outcomes and reasons remain distinct');
  assert.equal((html.match(/<button/g) ?? []).length, 1);
 }
});

test('stale shared rows suppress all live publication, validity, outcome and reason claims', async () => {
 const value = data();
 value.shared_knowledge = [{ method_ref: 'qualified_interest_change:1', publication_state: 'published', validity: 'current', processing_outcome: 'abstained', reason_category: 'comparison_basis_insufficient', count: 1 }];
 for (const language of ['ar', 'en']) {
  const html = await renderOperations({ value, language, stale: true });
  for (const attribute of ['data-shared-state="published"', 'data-shared-validity="current"', 'data-shared-outcome=', 'data-shared-reason=']) assert.equal(html.includes(attribute), false, attribute);
  for (const text of ['No qualified conclusion was established', 'Comparison basis is insufficient', 'Some currently valid knowledge', 'لم تتوصل المعالجة إلى استنتاج مؤهل', 'أساس المقارنة غير كافٍ', 'توجد معرفة حالية']) assert.equal(html.includes(text), false, text);
  assert.ok(html.includes(language === 'ar' ? 'ملخص سابق — يلزم تحديث التحقق' : 'Previous summary — refresh verification'));
 }
});
