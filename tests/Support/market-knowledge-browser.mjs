import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.DB_DATABASE !== 'builderad_test_disposable') throw Error('Disposable proof required');
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN, output = path.join(run, 'market-knowledge');
await mkdir(output, { recursive: true });
const endpoint = '/internal/administration/market-knowledge', base = 'http://localhost:8136';
function fixture(action = 'setup') {
 const result = spawnSync('C:/tools/php85/php.exe', ['tests/Support/MarketKnowledgeBrowserFixture.php', action], { env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
 assert.equal(result.status, 0, result.stderr || result.stdout);
 assert.equal(JSON.parse(result.stdout).database, 'builderad_test_disposable');
}
fixture();
const initialCookies = JSON.parse(await readFile(path.join(run, 'admin-ui-fixture.json'), 'utf8'));
const sourceFixture = JSON.parse(await readFile(path.join(run, 'market-knowledge-fixture.json'), 'utf8'));
fixture('employee');
const employeeCookies = JSON.parse(await readFile(path.join(run, 'admin-ui-fixture.json'), 'utf8')).employee;
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const contexts = [], results = [], screenshots = [], pageErrors = [];
async function context(cookies, viewport = { width: 1365, height: 900 }) {
 const ctx = await browser.newContext({ viewport }); contexts.push(ctx); await ctx.addCookies(cookies);
 ctx.on('page', page => page.on('pageerror', error => pageErrors.push(error.message)));
 return ctx;
}
async function capture(page, name) { const file = path.join(output, name + '.png'); await page.screenshot({ path: file, fullPage: true }); screenshots.push(file); }
async function check(name, callback) { try { await callback(); results.push({ name, status: 'PASS' }); console.log('PASS ' + name); } catch (error) { results.push({ name, status: 'FAIL' }); throw error; } }
const ready = page => page.locator('[data-market-operations]').waitFor({ state: 'visible' });
const qualifiedResponse = page => page.waitForResponse(response => new URL(response.url()).pathname === endpoint && response.request().headers().accept?.includes('application/json') && !response.request().headers()['x-builderad-witness'] && response.status() === 200);
function directHeaders(response) { const headers = response.request().headers(); return Object.fromEntries(Object.entries(headers).filter(([name]) => ['accept', 'x-csrf-token', 'x-builderad-document', 'x-builderad-transition'].includes(name))); }
async function refresh(page) { const response = qualifiedResponse(page); await page.locator('[data-knowledge-refresh]').click(); const result = await response; await page.waitForFunction(() => !document.querySelector('[data-market-operations]')?.getAttribute('aria-busy')?.includes('true')); return result; }
async function headerGeometry(page) {
 const result = await page.locator('.ba-admin-topbar').evaluate(header => {
  const nodes = [...header.querySelectorAll('.ba-admin-menu-toggle,.ba-admin-header-controls > *')].filter(e => getComputedStyle(e).visibility !== 'hidden' && e.getClientRects().length);
  const boxes = nodes.map(e => { const r = e.getBoundingClientRect(); return { text: e.textContent, x: r.x, y: r.y, right: r.right, bottom: r.bottom }; });
  const overlaps = []; for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) { const a = boxes[i], b = boxes[j]; if (Math.min(a.right, b.right) - Math.max(a.x, b.x) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.y, b.y) > 1) overlaps.push([a.text, b.text]); }
  const escaped = []; for (const e of nodes) { const r = e.getBoundingClientRect(), walker = document.createTreeWalker(e, NodeFilter.SHOW_TEXT); let n; while (n = walker.nextNode()) { if (!n.textContent.trim()) continue; const range = document.createRange(); range.selectNodeContents(n); for (const t of range.getClientRects()) if (t.left < r.left - 1 || t.right > r.right + 1 || t.top < r.top - 1 || t.bottom > r.bottom + 1) escaped.push(e.textContent); } }
  return { overlaps, escaped, inside: boxes.every(r => r.x >= 0 && r.right <= innerWidth), overflow: document.documentElement.scrollWidth > innerWidth };
 });
 assert.deepEqual(result.overlaps, []); assert.deepEqual(result.escaped, []); assert.equal(result.inside, true); assert.equal(result.overflow, false);
}
try {
 const observerContext = await context(employeeCookies), page = await observerContext.newPage();
 let headers;
 await check('Qualified employee receives only permitted source metadata and real local-method status', async () => {
  const received = qualifiedResponse(page); await page.goto(base + endpoint); await ready(page); const response = await received; headers = directHeaders(response);
  const envelope = await response.json(); assert.match(response.headers()['cache-control'], /no-store/);
  assert.equal(envelope.data.connection_status, 'not_observed'); assert.equal(envelope.data.private_processing, 'business_scoped'); assert.equal(envelope.data.methods.length, 5);
  assert.equal(envelope.data.shared_knowledge_window, 100); assert.deepEqual(envelope.data.shared_knowledge, []);
  for (const source of envelope.data.sources) assert.deepEqual(Object.keys(source).sort(), ['custody_revision', 'rights_window', 'source_ref', 'state', 'valid_until']);
  for (const forbidden of ['PRIVATE_SOURCE_LABEL_MUST_NOT_BE_DISCLOSED', 'PRIVATE_LICENCE_REF_MUST_NOT_BE_DISCLOSED', 'finding', 'business_id', 'actor_user_id', 'allowed_fields']) assert.equal(JSON.stringify(envelope.data).includes(forbidden), false);
  await page.getByRole('heading', { name: 'المعرفة السوقية', exact: true }).waitFor();
  assert.ok(await page.locator('[data-admin-sidebar] a[href="' + endpoint + '"]').isVisible());
  const direct = await page.request.get(base + endpoint, { headers }); assert.equal(direct.status(), 200);
  await capture(page, 'operations-ar-desktop-light');
 });
 await check('Unqualified Founder has no knowledge link and direct access is denied', async () => {
  const ctx = await context(initialCookies.founder), unqualified = await ctx.newPage(); await unqualified.goto(base + '/internal/administration');
  await unqualified.locator('[data-administration]').waitFor({ state: 'visible' }); assert.equal(await unqualified.locator('[data-admin-sidebar] a[href="' + endpoint + '"]').count(), 0);
  const denied = await unqualified.goto(base + endpoint); assert.equal(denied.status(), 403);
  assert.equal((await denied.text()).includes('PRIVATE_LICENCE_REF_MUST_NOT_BE_DISCLOSED'), false);
  const advertiser = await context(initialCookies.advertiser); const wrongBoundary = await advertiser.request.get(base + endpoint, { headers: { Accept: 'application/json' } }); assert.ok([401, 403, 409].includes(wrongBoundary.status()));
 });
 await check('An empty source page explains limited evidence without a zero-demand claim', async () => {
  const empty = await observerContext.newPage(); await empty.goto(base + endpoint + '?cursor=ffffffff-ffff-7fff-bfff-ffffffffffff'); await ready(empty);
  await empty.getByText('لم تظهر مصادر سوقية في هذه القراءة. لا يعني ذلك انعدام معلومات السوق أو الطلب.', { exact: true }).waitFor();
  assert.equal(await empty.locator('.knowledge-source').count(), 0); await capture(empty, 'operations-empty-ar'); await empty.close();
 });
 await check('Actual shared capture/publication pipeline produces bounded prepared and current operational summaries', async () => {
  fixture('g3-seed'); const response = await refresh(page), envelope = await response.json();
  assert.equal(envelope.data.shared_knowledge_window, 100); assert.ok(envelope.data.shared_knowledge.length > 0);
  for (const item of envelope.data.shared_knowledge) {
   assert.deepEqual(Object.keys(item).sort(), ['count', 'method_ref', 'processing_outcome', 'publication_state', 'reason_category', 'validity']);
   assert.ok(Number.isInteger(item.count) && item.count > 0);
   assert.equal(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i.test(JSON.stringify(item)), false, 'Shared operational rows must not expose raw IDs');
  }
  const section = page.locator('[data-shared-knowledge]'); assert.ok(await section.isVisible());
  assert.ok(await section.locator('[data-shared-state="prepared"]').count() > 0);
  assert.ok(await section.locator('[data-shared-state="published"][data-shared-validity="current"]').count() > 0);
  await section.getByText('لقطة محفوظة لم تُنشر نتيجتها', { exact: true }).first().waitFor();
  await section.getByText('صلاحيتها حالية', { exact: true }).first().waitFor();
  assert.match(await section.textContent(), /أحدث 100 لقطة مشتركة/);
  assert.match(await section.textContent(), /وجود اللقطة لا يثبت جاهزيتها الآن/);
  assert.equal(await section.locator('button,a[href],input,select,textarea').count(), 0, 'Operational summaries do not grant processing/publication actions');
  const interest = envelope.data.shared_knowledge.filter(item => item.method_ref === 'qualified_interest_change:1');
  assert.equal(interest.length, 2, 'Completed and abstained findings remain distinct for the same method');
  assert.deepEqual(interest.map(item => ({outcome:item.processing_outcome,reason:item.reason_category,count:item.count})).sort((a,b)=>a.outcome.localeCompare(b.outcome)), [
   {outcome:'abstained',reason:'comparison_basis_insufficient',count:1}, {outcome:'completed',reason:'none',count:1},
  ]);
  assert.ok(interest.every(item => item.publication_state === 'published' && item.validity === 'current'));
  assert.equal(envelope.data.shared_knowledge.filter(item => item.processing_outcome === 'abstained' && item.reason_category === 'required_information_missing').length, 2);
  for (const item of envelope.data.shared_knowledge) {
   assert.ok(['completed','abstained','unknown','unavailable'].includes(item.processing_outcome));
   assert.ok(['none','comparison_basis_insufficient','required_information_missing','generic_abstention','unknown','unavailable'].includes(item.reason_category));
  }
  for (const secret of ['ZERO_BASELINE','baseline','reason_codes','PRIVATE_SOURCE_LABEL_MUST_NOT_BE_DISCLOSED','PRIVATE_LICENCE_REF_MUST_NOT_BE_DISCLOSED']) assert.equal(JSON.stringify(envelope.data).includes(secret), false);
  await page.locator('[data-knowledge-abstention-attention]').waitFor({state:'visible'});
  const abstained = section.locator('[data-shared-state="published"][data-shared-validity="current"][data-shared-outcome="abstained"][data-shared-reason="comparison_basis_insufficient"]');
  assert.equal(await abstained.count(),1);
  await abstained.getByText('لم تتوصل المعالجة إلى استنتاج مؤهل',{exact:true}).waitFor();
  assert.match(await abstained.textContent(),/أساس المقارنة غير كافٍ/);
  assert.equal((await section.textContent()).includes('صفر'),false);
  await capture(page, 'knowledge-shared-current-and-prepared');
 });
 await check('Real D1 price correction marks dependent shared knowledge for review', async () => {
  fixture('g3-correct-price'); await refresh(page);
  const section = page.locator('[data-shared-knowledge]');
  assert.ok(await section.locator('[data-shared-state="published"][data-shared-validity="review_required"]').count() > 0);
  await section.getByText('تحتاج مراجعة قبل الاعتماد', { exact: true }).first().waitFor();
  assert.match(await section.textContent(), /لا تعتمد على هذه النتيجة باعتبارها حالية/);
  await capture(page, 'knowledge-shared-review-required');
 });
 await check('Real retain withdrawal and payload removal preserve only truthful shared retention status', async () => {
  fixture('g3-retention-withdraw'); await refresh(page);
  const section = page.locator('[data-shared-knowledge]');
  assert.ok(await section.locator('[data-shared-state="retention_removed"]').count() > 0);
  await section.getByText('أُزيل محتوى المخرج المحفوظ', { exact: true }).first().waitFor();
  assert.match(await section.textContent(), /هذا لا يعني حذف بيانات المصدر كله/);
  assert.ok(await section.locator('[data-shared-state="published"][data-shared-validity="current"]').count() > 0, 'Independent current shared knowledge remains available');
  await capture(page, 'knowledge-shared-retention-removed');
 });
 await check('Real forward qualification withdrawal makes only affected shared publication unavailable', async () => {
  fixture('g3-forward-withdraw'); const response = await refresh(page), envelope = await response.json();
  const section = page.locator('[data-shared-knowledge]');
  assert.ok(await section.locator('[data-shared-state="published"][data-shared-validity="unavailable"]').count() > 0);
  await section.getByText('تعذر التحقق من صلاحيتها', { exact: true }).first().waitFor();
  assert.ok(await section.locator('[data-shared-state="published"][data-shared-validity="current"]').count() > 0);
  for (const item of envelope.data.shared_knowledge) assert.deepEqual(Object.keys(item).sort(), ['count', 'method_ref', 'processing_outcome', 'publication_state', 'reason_category', 'validity']);
  await capture(page, 'knowledge-shared-unavailable-and-independent');
 });
 await check('Actual restriction and expired source rights are shown separately from connectivity', async () => {
  fixture('restrict-current'); await refresh(page);
  const current = page.locator('.knowledge-source').filter({ has: page.locator('bdi', { hasText: sourceFixture.sources.current }) });
  assert.equal(await current.getAttribute('data-source-state'), 'restricted');
  const expired = page.locator('.knowledge-source').filter({ has: page.locator('bdi', { hasText: sourceFixture.sources.expired }) });
  await expired.getByText('انتهت نافذة الحق', { exact: true }).waitFor();
  await page.getByText('لم يُتحقق من اتصال المصادر المباشر.', { exact: false }).waitFor();
  await capture(page, 'operations-review-needed');
 });
 await check('Failed readback is a previous snapshot and never triggers a write or a false refresh', async () => {
  let failed = true, writes = 0;
  await page.route('**/internal/administration/market-knowledge*', async route => {
   if (route.request().method() !== 'GET') writes++;
   if (failed) await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'SYNTHETIC_UNAVAILABLE' }) }); else await route.continue();
  });
  try {
   await page.locator('[data-knowledge-refresh]').click(); await page.locator('[data-knowledge-stale]').waitFor({ state: 'visible' });
   assert.match(await page.locator('[data-knowledge-stale]').textContent(), /لقطة سابقة/); assert.equal(writes, 0);
   assert.equal(await page.getByText('نافذة الحق المسجلة سارية', { exact: true }).count(), 0);
   const shared = page.locator('[data-shared-knowledge]');
   assert.ok(await shared.locator('[data-shared-validity="previous_snapshot"]').count() > 0);
   assert.equal(await shared.locator('[data-shared-validity="current"],[data-shared-validity="review_required"],[data-shared-validity="unavailable"]').count(), 0);
   assert.equal(await shared.getByText('صلاحيتها حالية', { exact: true }).count(), 0);
   assert.match(await shared.textContent(), /ملخص سابق — يلزم تحديث التحقق/);
   assert.equal(await shared.locator('[data-shared-state="published"],[data-shared-outcome],[data-shared-reason]').count(), 0);
   assert.equal(await page.locator('[data-knowledge-abstention-attention]').count(),0);
   assert.equal(await shared.getByText('لم تتوصل المعالجة إلى استنتاج مؤهل',{exact:true}).count(),0);

   failed = false; await refresh(page); await page.locator('[data-knowledge-stale]').waitFor({ state: 'detached' }); assert.equal(writes, 0);
  } finally { await page.unroute('**/internal/administration/market-knowledge*'); }
 });
 await check('Keyboard refresh, source details and navigation/back use the real application', async () => {
  await page.locator('[data-knowledge-refresh]').focus(); const response = qualifiedResponse(page); await page.keyboard.press('Enter'); await response;
  const details = page.locator('.knowledge-reference').first(); await details.locator('summary').focus(); await page.keyboard.press('Enter'); assert.equal(await details.getAttribute('open'), ''); await page.keyboard.press('Enter'); assert.equal(await details.getAttribute('open'), null);
  await page.locator('[data-admin-sidebar] a[href="/internal/administration"]').click(); await page.locator('[data-administration]').waitFor({ state: 'visible' }); await page.goBack(); await ready(page);
 });
 await check('Arabic/English mobile 320/390 and both themes keep readable nonoverlapping header controls and keyboard drawer', async () => {
  for (const language of ['ar', 'en']) {
   if (await page.locator('html').getAttribute('lang') !== language) { await page.locator('[data-application="locale"]').click(); await page.waitForFunction(lang => document.documentElement.lang === lang, language); await ready(page); }
   assert.equal(await page.locator('html').getAttribute('dir'), language === 'ar' ? 'rtl' : 'ltr');
   for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    for (const theme of ['light', 'dark']) {
     if (await page.locator('html').getAttribute('data-theme') !== theme) await page.locator('[data-theme-toggle]').click();
     await headerGeometry(page); assert.ok(await page.locator('[data-knowledge-refresh]').isVisible());
     const shared = page.locator('[data-shared-knowledge]'); assert.ok(await shared.isVisible());
     assert.ok(await shared.locator('[data-shared-state="retention_removed"]').count() > 0); assert.ok(await shared.locator('[data-shared-state="published"][data-shared-validity="unavailable"]').count() > 0); assert.ok(await shared.locator('[data-shared-state="published"][data-shared-validity="current"]').count() > 0);
     assert.ok((await shared.textContent()).includes(language === 'ar' ? 'المعرفة المشتركة المحفوظة' : 'Retained shared knowledge'));
     assert.match(await shared.textContent(), /100/);
     const comparable = shared.locator('[data-shared-outcome="completed"][data-shared-validity="current"]');
     const abstention = shared.locator('[data-shared-outcome="abstained"][data-shared-reason="comparison_basis_insufficient"][data-shared-validity="current"]');
     assert.ok(await comparable.count() > 0); assert.equal(await abstention.count(),1);
     await abstention.getByText(language === 'ar' ? 'لم تتوصل المعالجة إلى استنتاج مؤهل' : 'No qualified conclusion was established',{exact:true}).waitFor();
     assert.ok((await abstention.textContent()).includes(language === 'ar' ? 'أساس المقارنة غير كافٍ' : 'Comparison basis is insufficient'));
     for (const label of language === 'ar' ? ['حالة النشر','صلاحية المعرفة','نتيجة المعالجة'] : ['Publication state','Knowledge validity','Processing outcome']) assert.ok((await abstention.textContent()).includes(label));
     assert.equal((await shared.textContent()).includes('ZERO_BASELINE'),false); assert.equal((await shared.textContent()).includes('baseline value is zero'),false);
     assert.equal(await shared.locator('[data-shared-state="retention_removed"][data-shared-outcome="completed"],[data-shared-state="prepared"][data-shared-outcome]').count(),0);

     await capture(page, `operations-${language}-${width}-${theme}`);
    }
    await page.getByRole('button', { name: language === 'ar' ? 'فتح قائمة الإدارة' : 'Open administration menu', exact: true }).click(); await page.locator('[data-admin-drawer][open]').waitFor(); await page.keyboard.press('Escape'); await page.locator('[data-admin-drawer][open]').waitFor({ state: 'detached' });
   }
  }
  await page.setViewportSize({ width: 1365, height: 900 }); await headerGeometry(page); await capture(page, 'operations-en-desktop-dark');
 });
 await check('Revoked and expired observer authority closes the visible projection and denies direct API requests', async () => {
  for (const action of ['revoke-observer', 'expire-observer']) {
   headers = directHeaders(await refresh(page));
   fixture(action); await page.locator('[data-knowledge-refresh]').click(); await page.locator('[data-market-operations]').waitFor({ state: 'detached' });
   const denied = await page.request.get(base + endpoint, { headers }); assert.equal(denied.status(), 403);
   fixture('restore-observer'); const received = qualifiedResponse(page); await page.reload(); await ready(page); headers = directHeaders(await received);
  }
 });
 assert.deepEqual(pageErrors, [], 'No unhandled browser application errors');
} catch (error) {
 for (let i = 0; i < contexts.length; i++) for (const page of contexts[i].pages()) await page.screenshot({ path: path.join(output, 'failure-' + i + '.png'), fullPage: true }).catch(() => {});
 await writeFile(path.join(output, 'failure.txt'), error.stack || String(error)); throw error;
} finally {
 await Promise.all(contexts.map(ctx => ctx.close())); await browser.close();
 await writeFile(path.join(run, 'browser-results.json'), JSON.stringify({ results, screenshots, pageErrors, scope: 'synthetic qualified G1 operations and actual D3 shared publication/correction/retention/rights; no real market/provider or authentication claims' }, null, 2));
}
