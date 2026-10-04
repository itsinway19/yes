import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.DTM_PROOF_RUN;
assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_DATABASE, 'builderad_test_disposable');
assert.ok(process.env.BUILDERAD_TEST_ISOLATION_TOKEN);
const fixture = JSON.parse(await readFile(run + '/ui-fixture.json', 'utf8'));
const base = 'http://127.0.0.1:8017';
const url = base + '/workspaces/' + fixture.workspace + '/business';
const screenshots = 'C:/Users/stoma/.codex/visualizations/2026/10/04/01a1061d-77e6-7a21-98bc-fda1bd8dcabb';
const evidence = { browser: 'Bundled Playwright; Browser plugin not available', checks: [], errors: [], failedRequests: [], screenshots: [] };
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
async function check(name, body) { await body(); evidence.checks.push({ name, result: 'PASS' }); }
async function screen(page, name) { const path = screenshots + '/' + name + '.png'; await page.screenshot({ path, fullPage: true }); evidence.screenshots.push(path); }
try {
    const context = await browser.newContext({ viewport: { width: 320, height: 740 } });
    await context.addCookies([{ ...fixture.ownerCookie, url: base, httpOnly: true, sameSite: 'Lax' }]);
    const page = await context.newPage();
    page.on('pageerror', error => evidence.errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') evidence.errors.push(`${message.text()} [${message.location().url}]`); });
    page.on('requestfailed', request => evidence.failedRequests.push({ url: request.url(), error: request.failure()?.errorText }));
    const posts = [];
    const nameTypePosts = [];
    page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/target-markets')) posts.push(request.postDataJSON()); });
    page.on('request', request => { if (request.method() === 'POST' && request.url() === url) nameTypePosts.push(request.postDataJSON()); });
    // Retry only local server readiness, never a mutation or transaction.
    for (let attempt = 0; attempt < 30; attempt++) {
        try { await page.goto(url); break; } catch (error) { if (attempt === 29) throw error; await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    const editor = page.locator('.target-markets');
    await editor.getByRole('heading', { name: 'أين تريد الحصول على عملائك؟' }).waitFor();
    await check('Arabic identity, meaningful content and no framework overlay', async () => {
        assert.equal(page.url(), url); assert.ok((await page.title()).includes('سياق النشاط'));
        assert.equal(await page.locator('.business-shell[dir="rtl"][lang="ar"]').count(), 1);
        assert.equal(await page.locator('vite-error-overlay').count(), 0);
    });
    await check('320px Arabic no horizontal overflow', async () => {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    });
    await editor.getByRole('searchbox').fill('المملكة العربية السعودية');
    await editor.getByRole('searchbox').press('Tab');
    await page.keyboard.press('Space');
    await check('Native keyboard selection and visible removable chip', async () => {
        assert.equal(await editor.locator('input[value="SA"]').isChecked(), true);
        assert.equal(await editor.locator('.markets-selected li').count(), 1);
        assert.ok(await page.evaluate(() => document.activeElement?.getAttribute('type') === 'checkbox'));
    });
    await editor.getByRole('searchbox').fill('Kuwait');
    assert.equal(await editor.locator('.markets-selected li').count(), 1);
    await editor.locator('input[value="KW"]').check();
    await editor.getByRole('button', { name: 'إزالة المملكة العربية السعودية', exact: true }).click();
    assert.equal(await editor.locator('.markets-selected li').count(), 1);
    await editor.getByRole('searchbox').fill('SA'); await editor.locator('input[value="SA"]').check();
    await editor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await editor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    assert.deepEqual([...posts[0].country_codes].sort(), ['KW', 'SA']); assert.equal(posts[0].version, 1);
    await screen(page, 'dtm-ar-320');
    await check('Selected chips wrap within 320px after save', async () => {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    });
    const competing = await context.newPage(); await competing.goto(url);
    await competing.locator('.target-markets input[type="search"]').fill('AE');
    await competing.locator('input[value="AE"]').check();
    await competing.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await competing.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    await editor.getByRole('searchbox').fill('QA'); await editor.locator('input[value="QA"]').check();
    await editor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await editor.getByRole('alert').filter({ hasText: 'تغيّر سياق النشاط' }).waitFor();
    await check('Real stale-tab conflict retains draft without automatic repost', async () => {
        assert.equal(posts.length, 2); assert.equal(await editor.locator('.markets-selected li').count(), 3);
        assert.ok(posts[1].country_codes.includes('QA'));
    });
    await screen(page, 'dtm-ar-conflict-320');
    await editor.getByRole('button', { name: 'إعادة التحميل والمراجعة', exact: true }).click();
    await editor.getByRole('alert').filter({ hasText: 'الإمارات العربية المتحدة' }).waitFor();
    assert.equal(posts.length, 2);
    await check('Reload shows current persisted set and preserves unrelated draft', async () => {
        assert.equal(await editor.locator('.markets-selected li').count(), 3);
        assert.ok((await editor.getByRole('alert').innerText()).includes('الإمارات العربية المتحدة'));
    });
    let countBeforeSkip = posts.length;
    await editor.getByRole('link', { name: 'ليس الآن', exact: true }).click();
    await page.waitForURL(base + '/workspaces/' + fixture.workspace);
    assert.equal(posts.length, countBeforeSkip);
    await page.goto(url);
    await editor.getByRole('searchbox').waitFor();
    await check('Skip never mutates saved set', async () => { assert.equal(await editor.locator('.markets-selected li').count(), 3); });
    const nameInput = page.locator('.biz-form input[autocomplete="organization"]');
    const typeSelect = page.locator('.biz-form select');
    const originalName = await nameInput.inputValue();
    const originalType = await typeSelect.inputValue();
    const draftType = await typeSelect.locator('option').evaluateAll((options, current) => options.find(option => option.value && option.value !== current && !option.disabled)?.value, originalType);
    assert.ok(draftType, 'Fixture must expose a different valid Business Type');
    await nameInput.fill('Unsaved locale draft'); await typeSelect.selectOption(draftType);
    await editor.getByRole('searchbox').fill('SA'); await editor.locator('input[value="SA"]').uncheck();
    await editor.getByRole('searchbox').fill('JO'); await editor.locator('input[value="JO"]').check();
    await editor.getByRole('searchbox').fill('');
    const localeCompetitor = await context.newPage(); await localeCompetitor.goto(url);
    await localeCompetitor.locator('#market-search').fill('QA');
    await localeCompetitor.locator('input[value="QA"]').check();
    await localeCompetitor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await localeCompetitor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    await check('Actual locale button preserves additions, removals and independent name/type drafts', async () => {
        for (const locale of ['en', 'ar']) {
            await page.getByRole('button', { name: locale === 'en' ? /English|الإنجليزية/ : /Arabic|العربية/ }).click();
            await page.locator(`.business-shell[lang="${locale}"]`).waitFor();
            assert.deepEqual(await editor.locator('input[type="checkbox"]:checked').evaluateAll(inputs => inputs.map(input => input.value).sort()), ['AE', 'JO', 'KW']);
            assert.equal(await nameInput.inputValue(), 'Unsaved locale draft');
            assert.equal(await typeSelect.inputValue(), draftType);
            assert.equal(posts.length, countBeforeSkip); assert.equal(nameTypePosts.length, 0);
        }
    });
    await editor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    assert.equal(posts.at(-1).version, posts[0].version + 2, 'Locale navigation must not rebase a stale draft');
    await editor.getByRole('alert').filter({ hasText: 'تغيّر سياق النشاط' }).waitFor();
    await check('Competing save BEFORE locale switch still conflicts without rebasing or automatic repost', async () => {
        assert.equal(posts.length, countBeforeSkip + 1);
        assert.deepEqual([...posts.at(-1).country_codes].sort(), ['AE', 'JO', 'KW']);
        assert.equal(posts.at(-1).version, posts[0].version + 2);
        assert.deepEqual(await editor.locator('input[type="checkbox"]:checked').evaluateAll(inputs => inputs.map(input => input.value).sort()), ['AE', 'JO', 'KW']);
        assert.equal(await nameInput.inputValue(), 'Unsaved locale draft'); assert.equal(await typeSelect.inputValue(), draftType);
        assert.equal(nameTypePosts.length, 0);
    });
    const staleNameResponse = page.waitForResponse(response => response.url() === url && response.request().method() === 'GET' && response.headers()['x-inertia'] === 'true');
    await page.locator('.biz-form button[type="submit"], .biz-form button:not([type])').click();
    const staleNamePage = await (await staleNameResponse).json();
    assert.equal(nameTypePosts.at(-1).version, posts[0].version + 2);
    assert.ok(staleNamePage.props.errors.business_type_id, 'Stale name/type submission must return the existing conflict error');
    await page.locator('.biz-form label:has(select) [role="alert"]').filter({ hasText: 'تعذّر حفظ سياق النشاط' }).waitFor();
    await check('Name/type retained before locale keeps original shared revision and conflicts independently', async () => {
        assert.equal(nameTypePosts.length, 1); assert.equal(nameTypePosts[0].version, posts[0].version + 2);
        assert.equal(nameTypePosts[0].name, 'Unsaved locale draft');
        assert.equal('country_codes' in nameTypePosts[0], false); assert.equal(posts.length, countBeforeSkip + 1);
        assert.ok(staleNamePage.props.errors.business_type_id.includes('أعد تحميل الصفحة'));
        assert.equal(staleNamePage.props.workspace.name, originalName);
        assert.equal(String(staleNamePage.props.workspace.business_type_id), originalType);
        assert.equal(staleNamePage.props.business.version, posts[0].version + 3);
        assert.deepEqual(staleNamePage.props.business.targetMarkets.map(country => country.code), ['AE', 'KW', 'QA', 'SA']);
    });
    const reviewedResponse = page.waitForResponse(response => response.url() === url && response.request().method() === 'GET' && response.headers()['x-inertia'] === 'true');
    await editor.getByRole('button', { name: 'إعادة التحميل والمراجعة', exact: true }).click();
    const reviewedPage = await (await reviewedResponse).json();
    assert.equal(reviewedPage.props.business.version, posts[0].version + 3);
    assert.ok((await editor.getByRole('alert').innerText()).includes('قطر'));
    assert.equal(posts.length, countBeforeSkip + 1);
    await editor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await editor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    await check('Explicit review rebases the retained draft even when locale already fetched that revision', async () => {
        assert.equal(posts.length, countBeforeSkip + 2); assert.equal(posts.at(-1).version, posts[0].version + 3);
        assert.deepEqual([...posts.at(-1).country_codes].sort(), ['AE', 'JO', 'KW']);
        assert.equal(await nameInput.inputValue(), 'Unsaved locale draft'); assert.equal(nameTypePosts.length, 1);
    });
    await localeCompetitor.goto(url);
    await localeCompetitor.locator('#market-search').fill('');
    await localeCompetitor.locator('input[value="JO"]').uncheck();
    await localeCompetitor.locator('input[value="SA"]').check();
    await localeCompetitor.locator('input[value="QA"]').uncheck();
    const restoredResponse = localeCompetitor.waitForResponse(response => response.url() === url && response.request().method() === 'GET' && response.headers()['x-inertia'] === 'true');
    await localeCompetitor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    const restoredPage = await (await restoredResponse).json();
    assert.equal(restoredPage.props.business.version, posts[0].version + 5);
    assert.deepEqual(restoredPage.props.business.targetMarkets.map(country => country.code), ['AE', 'KW', 'SA']);
    await localeCompetitor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    const redirectRace = await context.newPage(); await redirectRace.goto(url);
    const redirectEditor = redirectRace.locator('.target-markets');
    const redirectPosts = [];
    redirectRace.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/target-markets')) redirectPosts.push(request.postDataJSON()); });
    await redirectEditor.getByRole('searchbox').fill('JO'); await redirectEditor.locator('input[value="JO"]').check();
    let interceptRedirect = true;
    let redirectFailure;
    await redirectRace.route(url + '/target-markets', async intercepted => {
        if (!interceptRedirect || intercepted.request().method() !== 'POST') return intercepted.continue();
        interceptRedirect = false;
        // Execute A's real POST once, but withhold its redirect response until B commits.
        const committedResponse = await intercepted.fetch({ maxRedirects: 0 });
        try {
            assert.equal(committedResponse.status(), 302);
            assert.equal(new URL(committedResponse.headers().location, base).href, url);
            await localeCompetitor.goto(url); await localeCompetitor.locator('#market-search').fill('');
            assert.equal(await localeCompetitor.locator('input[value="JO"]').isChecked(), true);
            await localeCompetitor.locator('input[value="JO"]').uncheck();
            await localeCompetitor.locator('input[value="QA"]').check();
            await localeCompetitor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
            await localeCompetitor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
        } catch (error) { redirectFailure = error; }
        await intercepted.fulfill({ response: committedResponse });
    });
    const racedResponse = redirectRace.waitForResponse(response => response.url() === url && response.request().method() === 'GET' && response.headers()['x-inertia'] === 'true');
    await redirectEditor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    const racedPage = await (await racedResponse).json();
    await redirectEditor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    if (redirectFailure) throw redirectFailure;
    await redirectEditor.getByRole('searchbox').fill('');
    await check('Successful save reconciles values and revision after a real post-commit pre-redirect competing write', async () => {
        assert.equal(interceptRedirect, false);
        assert.equal(racedPage.props.business.version, restoredPage.props.business.version + 2);
        assert.deepEqual(racedPage.props.business.targetMarkets.map(country => country.code), ['AE', 'KW', 'QA', 'SA']);
        assert.deepEqual(await redirectEditor.locator('input[type="checkbox"]:checked').evaluateAll(inputs => inputs.map(input => input.value).sort()), ['AE', 'KW', 'QA', 'SA']);
    });
    const replayResponse = redirectRace.waitForResponse(response => response.url() === url && response.request().method() === 'GET' && response.headers()['x-inertia'] === 'true');
    await redirectEditor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    const replayPage = await (await replayResponse).json();
    assert.equal(redirectPosts.length, 2);
    assert.equal(redirectPosts[1].version, racedPage.props.business.version);
    assert.deepEqual(redirectPosts[1].country_codes, ['AE', 'KW', 'QA', 'SA']);
    assert.equal(replayPage.props.business.version, racedPage.props.business.version);
    assert.deepEqual(replayPage.props.business.targetMarkets, racedPage.props.business.targetMarkets);
    await localeCompetitor.locator('input[value="QA"]').uncheck();
    await localeCompetitor.getByRole('button', { name: 'حفظ الأسواق', exact: true }).click();
    await localeCompetitor.getByRole('status').filter({ hasText: 'تم حفظ التغييرات.' }).waitFor();
    countBeforeSkip = posts.length;
    await page.goto(url); await editor.getByRole('searchbox').waitFor();
    await check('Locale switch leaves canonical set and name/type unchanged without any Business POST', async () => {
        assert.deepEqual(await editor.locator('input[type="checkbox"]:checked').evaluateAll(inputs => inputs.map(input => input.value).sort()), ['AE', 'KW', 'SA']);
        assert.equal(await nameInput.inputValue(), originalName); assert.equal(await typeSelect.inputValue(), originalType);
        assert.equal(posts.length, countBeforeSkip); assert.equal(nameTypePosts.length, 1);
    });
    await page.getByRole('button', { name: /English|الإنجليزية/ }).click();
    await editor.getByRole('heading', { name: 'Where do you want to get your customers?' }).waitFor();
    await check('English switch keeps canonical saved membership', async () => {
        assert.equal(await page.locator('.business-shell[dir="ltr"][lang="en"]').count(), 1);
        assert.equal(await editor.locator('.markets-selected li').count(), 3);
        assert.equal(posts.length, countBeforeSkip);
    });
    await page.setViewportSize({ width: 1280, height: 900 }); await screen(page, 'dtm-en-desktop');
    const reader = await browser.newContext({ viewport: { width: 320, height: 740 } });
    await reader.addCookies([{ ...fixture.readerCookie, url: base, httpOnly: true, sameSite: 'Lax' }]);
    const readPage = await reader.newPage(); await readPage.goto(url);
    await readPage.locator('.markets-summary').waitFor();
    await check('Read-only member sees saved markets without mutation controls', async () => {
        assert.equal(await readPage.locator('.target-markets form').count(), 0);
        assert.equal(await readPage.locator('.markets-summary li').count(), 3);
        assert.equal(await readPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    });
    await screen(readPage, 'dtm-reader-ar-320');
    await check('Console and runtime health', async () => { assert.deepEqual(evidence.errors, []); });
    evidence.result = 'PASS';
} catch (error) {
    evidence.result = 'FAIL'; evidence.failure = error.stack; throw error;
} finally {
    await browser.close();
    await writeFile(run + '/ui-evidence.json', JSON.stringify(evidence, null, 2));
    console.log(JSON.stringify({ result: evidence.result, checks: evidence.checks, errors: evidence.errors, screenshots: evidence.screenshots }));
}
