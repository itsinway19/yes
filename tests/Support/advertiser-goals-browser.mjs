import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_DATABASE, 'builderad_test_disposable');
assert.equal(process.env.DB_PORT, '5433');
assert.ok(process.env.BUILDERAD_TEST_ISOLATION_TOKEN);
const run = process.env.CURRENT_INTENT_PROOF_RUN;
const fixture = JSON.parse(await readFile(run + '/ui-fixture.json', 'utf8'));
const base = 'http://localhost:8027';
const url = base + '/workspaces/' + fixture.workspace + '/goals';
const artifacts = 'C:/Users/stoma/.codex/visualizations/2026/10/09/01a12164-ee02-72c3-9949-61c679dd16cd';
const evidence = { checks: [], errors: [], screenshots: [], saved: [] };
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const control = (page, key) => page.locator('[data-goals="' + key + '"]');
async function check(name, action) { await action(); evidence.checks.push({ name, result: 'PASS' }); console.log('PASS ' + name); }
async function screen(page, name) { const path = artifacts + '/' + 'remediation-' + name + '.png'; await page.screenshot({ path, fullPage: true }); evidence.screenshots.push(path); }
async function open(page) {
    await page.goto(url);
    try { await control(page, 'form').waitFor(); }
    catch (error) { console.log('OPEN_URL=' + page.url()); console.log('OPEN_VIEW=' + (await page.locator('body').innerText()).slice(0, 500)); throw error; }
}
async function saved(page) { return page.evaluate(async address => { const response = await fetch(address, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('Read failed: ' + response.status); return await response.json(); }, url); }
async function save(page) {
    const reply = page.waitForResponse(response => response.url() === url && response.request().method() === 'POST');
    await control(page, 'continue').click();
    const response = await reply;
    assert.equal(response.status(), 200);
    evidence.saved.push(await response.json());
    try { await page.waitForURL(base + '/workspaces/' + fixture.workspace); }
    catch (error) { console.log('POST_SAVE_URL=' + page.url()); console.log('POST_SAVE_VIEW=' + (await page.locator('body').innerText()).slice(0, 1400)); throw error; }
    await open(page);
}
try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await context.addCookies([{ ...fixture.ownerCookie, url: base, httpOnly: true, sameSite: 'Lax' }]);
    let page = await context.newPage();
    page.on('pageerror', error => evidence.errors.push(error.message));
    await open(page);
    await check('empty choice, disabled continue, RTL and desktop without overflow', async () => {
        assert.equal(await control(page, 'continue').isDisabled(), true);
        assert.equal(await page.locator('.goals-screen[dir="rtl"]').count(), 1);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    });
    await screen(page, 'goals-ar-desktop-empty');
    await page.locator('.goals-business a').click();
    await page.waitForURL(base + '/workspaces/' + fixture.workspace + '/business');
    await page.locator('nav a[href$="/workspaces/' + fixture.workspace + '/goals"]').click();
    await control(page, 'form').waitFor();
    await control(page, 'select-sales').focus(); await page.keyboard.press('Space');
    await check('keyboard selects first goal and assigns its priority', async () => {
        assert.equal(await control(page, 'select-sales').isChecked(), true);
        assert.equal(await control(page, 'result-actual_sales').count(), 1);
    });
    await control(page, 'select-customers').check(); await control(page, 'select-growth').check();
    await control(page, 'result-actual_sales').check();
    await check('compact desktop omits summary and optional copy while preserving selected cards', async () => {
        assert.equal(await page.locator('.goals-summary').count(), 0);
        assert.equal(await page.getByText('اختياري؛ يمكنك اختيار عدة نتائج', { exact: true }).count(), 0);
        assert.equal(await page.locator('.goal-card.is-selected').count(), 3);
        const box = await page.locator('.business-main').boundingBox(); assert.ok(box.width <= 1040);
    });
    await screen(page, 'goals-ar-desktop-multiple');
    await check('real browser Back and Forward retain all unsaved selections, outcomes and original revision', async () => {
        const prior = await saved(page);
        await page.goBack(); await page.waitForURL(base + '/workspaces/' + fixture.workspace + '/business');
        await page.goForward(); await control(page, 'form').waitFor();
        assert.equal(await control(page, 'select-customers').isChecked(), true);
        assert.equal(await control(page, 'select-growth').isChecked(), true);
        assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
        assert.deepEqual(await saved(page), prior);
        await page.goBack(); await page.goForward(); await control(page, 'form').waitFor();
        assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
    });
    await save(page);
    const first = await saved(page);
    await check('three goals and optional sales result persist after successful real HTTP save and refresh', async () => {
        assert.deepEqual(first.value.goals, ['customers', 'growth', 'sales']);
        assert.equal(first.value.priority, 'sales');
        assert.deepEqual(first.value.results.sales, ['actual_sales']);
        await page.reload(); await control(page, 'form').waitFor();
        assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
    });
    await page.close(); page = await context.newPage(); await open(page);
    await control(page, 'priority-customers').click(); await control(page, 'result-serious_inquiries').check();
    await control(page, 'priority-sales').click();
    await check('switching priority preserves each goal result in the live draft', async () => assert.equal(await control(page, 'result-actual_sales').isChecked(), true));
    await control(page, 'priority-customers').click(); await save(page);
    const second = await saved(page);
    await check('changed priority and both result sets persist after reopen', async () => {
        assert.equal(second.version, first.version + 1);
        assert.equal(second.value.priority, 'customers');
        assert.deepEqual(second.value.results.sales, ['actual_sales']);
        assert.deepEqual(second.value.results.customers, ['serious_inquiries']);
        await page.close(); page = await context.newPage(); await open(page);
        assert.equal(await control(page, 'result-serious_inquiries').isChecked(), true);
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await check('mobile has one card per row, no horizontal overflow and usable card targets', async () => {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('.goals-grid').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length), 1);
        const box = await control(page, 'select-growth').locator('..').boundingBox(); assert.ok(box.width >= 44 && box.height >= 44);
    });
    await screen(page, 'goals-ar-mobile-saved');
    await page.setViewportSize({ width: 768, height: 1024 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await screen(page, 'goals-ar-tablet-saved');
    await page.setViewportSize({ width: 320, height: 740 });
    await check('small mobile remains within viewport', async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true));
    await control(page, 'select-growth').uncheck();
    await control(page, 'select-customers').uncheck();
    await check('removing priority with one goal left auto-prioritizes it without losing its results', async () => {
        assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
        assert.equal(await control(page, 'continue').isEnabled(), true);
    });
    await control(page, 'select-growth').check(); await control(page, 'select-efficiency').check();
    await control(page, 'select-sales').uncheck();
    await check('removing priority with multiple goals left requires explicit priority', async () => {
        assert.equal(await control(page, 'continue').isDisabled(), true);
        assert.equal(await page.locator('.goals-outcomes').count(), 0);
    });
    // Deliberately abandon this tested draft without submitting it.
    page.on('dialog', dialog => dialog.accept()); await page.goto(base + '/workspaces'); await open(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.getByRole('button', { name: /English|الإنجليزية/ }).click();
    await page.locator('.goals-screen[dir="ltr"]').waitFor();
    await check('English uses the same goal/result IDs and saved values', async () => {
        assert.deepEqual(await saved(page), second);
        assert.match(await page.locator('.goals-screen').innerText(), /Serious inquiries|serious inquiries/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    });
    await screen(page, 'goals-en-desktop-saved');
    const readerContext = await browser.newContext();
    await readerContext.addCookies([{ ...fixture.readerCookie, url: base, httpOnly: true, sameSite: 'Lax' }]);
    const reader = await readerContext.newPage(); await open(reader);
    await check('read-only member sees saved goals with no mutation controls', async () => {
        assert.equal(await reader.locator('[data-goals] input, button[data-goals="continue"], button[data-goals^="priority-"]').count(), 0);
        assert.equal(await reader.locator('.goal-card.is-selected').count(), 3);
    });
    await check('reader inspects secondary outcomes with keyboard without changing persisted priority or saving', async () => {
        const before = await saved(reader);
        const savedPriorityLabel = await reader.locator('.goal-card.is-priority .goal-choice strong').innerText();
        const salesLabel = await reader.locator('.goal-card').filter({ has: control(reader, 'view-sales') }).locator('.goal-choice strong').innerText();
        const posts = []; reader.on('request', request => { if (request.method() === 'POST' && request.url() === url) posts.push(request.url()); });
        await control(reader, 'view-sales').focus(); await reader.keyboard.press('Enter');
        assert.equal(await control(reader, 'view-sales').getAttribute('aria-pressed'), 'true');
        assert.ok((await reader.locator('.goals-outcomes').getAttribute('aria-label')).includes(salesLabel));
        assert.equal(await reader.locator('.goals-outcome-list .is-selected').count(), 1);
        assert.equal(await reader.locator('.goal-card.is-priority .goal-choice strong').innerText(), savedPriorityLabel);
        await reader.setViewportSize({ width: 390, height: 844 });
        await screen(reader, 'goals-reader-mobile-secondary');
        await reader.setViewportSize({ width: 1280, height: 900 });
        await screen(reader, 'goals-reader-desktop-secondary');
        await control(reader, 'view-customers').click();
        assert.deepEqual(await saved(reader), before); assert.deepEqual(posts, []);
        assert.equal(await reader.locator('.goals-summary').count(), 0);
    });
    await readerContext.close();
    const loginContext = await browser.newContext(); const login = await loginContext.newPage();
    await login.goto(url); await login.waitForURL(base + '/login');
    await login.locator('#email').fill('goals-proof@example.test');
    await login.locator('#password').fill('DisposableGoalsProof42!');
    await login.locator('form').getByRole('button').click();
    await login.waitForURL(url); await control(login, 'form').waitFor();
    await check('fresh password login automatically returns to intended goals and restores durable preferences', async () => assert.deepEqual(await saved(login), second));
    // The runner removes this run's isolated session directory, including fresh login sessions.
    await loginContext.close(); await context.close();
    assert.deepEqual(evidence.errors, []);
    console.log('ADVERTISER_GOALS_BROWSER=PASS');
} finally {
    await browser.close();
    await writeFile(run + '/goals-browser-evidence.json', JSON.stringify(evidence, null, 2));
    await writeFile(artifacts + '/goals-remediation-browser-evidence.json', JSON.stringify(evidence, null, 2));
}
