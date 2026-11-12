import http from 'node:http';
import assert from 'node:assert/strict';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

if (process.env.GOALS_SPA_PROOF !== 'enabled' ||
    process.env.DB_DATABASE !== 'builderad_test_disposable' ||
    !/^(6397|1639[7-9]|1640[0-4])$/.test(process.env.REDIS_PORT ?? '')) {
    throw Error('Guarded disposable environment required');
}

const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN;
const output = path.join(run, 'content-browser');
await mkdir(output, { recursive: true });

const fixture = spawnSync('C:/tools/php85/php.exe', ['tests/Support/AdminUiBrowserFixture.php'], {
    env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8',
});
assert.equal(fixture.status, 0, fixture.stderr);
assert.equal(JSON.parse(fixture.stdout).database.db, 'builderad_test_disposable');
const cookies = JSON.parse(await readFile(path.join(run, 'admin-ui-fixture.json'), 'utf8')).founder;

const proxy = http.createServer((request, response) => {
    const outgoing = http.request({
        hostname: 'localhost', port: 8136, path: request.url, method: request.method, headers: request.headers,
    }, incoming => {
        response.writeHead(incoming.statusCode, incoming.headers);
        incoming.pipe(response);
    });
    outgoing.on('error', error => {
        response.writeHead(502);
        response.end(error.message);
    });
    request.pipe(outgoing);
});
await new Promise(resolve => proxy.listen(8138, 'localhost', resolve));

let browser;
let page;
const results = [];
const errors = [];
const assets = new Set();
const base = 'http://localhost:8138';
const check = async (name, action) => {
    await action();
    results.push({ name, status: 'PASS' });
    console.log('PASS '+name);
};
const ready = () => page.waitForFunction(() =>
    document.querySelector('#application-spa')?.dataset.state === 'ready');
const visit = async (route, selector) => {
    await page.goto(base+route);
    await ready();
    await page.locator(selector).waitFor();
};
const clickAndWait = async (selector, requestPath) => {
    const acknowledgement = page.waitForResponse(response =>
        (requestPath instanceof RegExp
            ? requestPath.test(new URL(response.url()).pathname)
            : new URL(response.url()).pathname === requestPath) &&
        response.request().method() === 'POST');
    await page.locator(selector).click();
    const response = await acknowledgement;
    assert.equal(response.status(), 200, requestPath);
};
const fillBody = async text => {
    await page.locator('.content-editor-surface [contenteditable="true"]').first().fill(text);
};
const close = async () => {
    await browser?.close();
    await new Promise(resolve => proxy.close(resolve));
};

try {
    browser = await chromium.launch({
        headless: true,
        executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe',
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addCookies(cookies);
    page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => errors.push(error.stack ?? error.message));
    page.on('response', response => {
        const url = new URL(response.url());
        if (url.pathname.includes('/build/') && response.status() === 200) assets.add(url.pathname);
    });

    await check('Arabic CMS section, page and public navigation journey', async () => {
        await visit('/internal/administration/content/pages', '[data-content-pages]');
        await page.locator('[data-content-add-page]').click();
        await page.locator('#page-title-ar').fill('الشركة');
        await page.locator('#page-title-en').fill('Company');
        await page.locator('#page-slug-ar').fill('company');
        await page.locator('#page-slug-en').fill('company');
        await clickAndWait('[data-content-page-create] button[type="submit"]',
            '/internal/administration/content/pages');
        await page.locator('[data-content-save]').waitFor();
        const pageId = new URL(page.url()).pathname.split('/').at(-1);
        await fillBody('المحتوى المنشور الأول');
        await clickAndWait('[data-content-save]', `/internal/administration/content/pages/${pageId}`);
        await page.locator('#page-edit-slug-ar').fill('invalid slug');
        const invalid = page.waitForResponse(response =>
            new URL(response.url()).pathname === `/internal/administration/content/pages/${pageId}` &&
            response.request().method() === 'POST');
        await page.locator('[data-content-save]').click();
        assert.equal((await invalid).status(), 422);
        await page.getByRole('alert').getByText('الرابط العربي', { exact: false }).waitFor();
        assert.equal(await page.locator('#page-edit-slug-ar').inputValue(), 'invalid slug');
        await page.locator('#page-edit-slug-ar').fill('company');

        await visit('/internal/administration/content/pages', '[data-content-pages]');
        const companyRow = page.locator('[data-content-pages] tbody tr').filter({ hasText: 'الشركة' });
        await companyRow.getByRole('link', { name: 'فتح', exact: true }).click();
        await page.locator('[data-content-save]').waitFor();
        assert.match(await page.locator('.content-editor-surface [contenteditable="true"]').first().innerText(),
            /المحتوى المنشور الأول/);
        await clickAndWait('[data-content-publish]',
            `/internal/administration/content/pages/${pageId}/publish`);

        let publicResponse = await page.goto(base+'/p/company');
        assert.equal(publicResponse.status(), 200);
        await page.getByText('المحتوى المنشور الأول', { exact: true }).waitFor();

        await visit(`/internal/administration/content/pages/${pageId}`, '[data-content-save]');
        await fillBody('نسخة مسودة جديدة');
        await clickAndWait('[data-content-save]', `/internal/administration/content/pages/${pageId}`);
        publicResponse = await page.goto(base+'/p/company');
        assert.equal(publicResponse.status(), 200);
        await page.getByText('المحتوى المنشور الأول', { exact: true }).waitFor();
        assert.equal(await page.getByText('نسخة مسودة جديدة', { exact: true }).count(), 0);

        await visit('/internal/administration/content/sections', '[data-content-sections]');
        await page.locator('[data-content-add-section]').click();
        await page.locator('#section-name-ar').fill('الشركة');
        await page.locator('#section-name-en').fill('Company');
        await clickAndWait('[data-content-section-form] button[type="submit"]',
            '/internal/administration/content/sections');
        const companySection = page.locator('[data-content-sections] tbody tr').filter({ hasText: 'الشركة' });
        const companyPublicId = (await companySection.getByRole('link', { name: 'إدارة الروابط', exact: true })
            .getAttribute('href')).split('/').at(-2);
        await companySection.getByRole('link', { name: 'إدارة الروابط', exact: true }).click();
        await page.locator('[data-content-add-link]').click();
        await page.locator('#link-target').selectOption({ label: 'الشركة' });
        await page.locator('#link-label-ar').fill('الشركة');
        await page.locator('#link-label-en').fill('Company');
        await clickAndWait('[data-content-link-form] button[type="submit"]',
            `/internal/administration/content/sections/${new URL(page.url()).pathname.split('/').at(-2)}/links`);
        await page.locator('[data-content-links] tbody tr').filter({ hasText: 'الشركة' }).waitFor();
        publicResponse = await page.goto(base+'/');
        assert.equal(publicResponse.status(), 200);
        await page.locator('header a[href="/p/company"]').getByText('الشركة', { exact: true }).waitFor();

        await visit('/internal/administration/content/sections', '[data-content-sections]');
        await page.locator('[data-content-add-section]').click();
        await page.locator('#section-name-ar').fill('الأخبار');
        await page.locator('#section-name-en').fill('News');
        await page.locator('#section-position').fill('20');
        await clickAndWait('[data-content-section-form] button[type="submit"]',
            '/internal/administration/content/sections');
        const news = page.locator('[data-content-sections] tbody tr').filter({ hasText: 'الأخبار' });
        await news.getByRole('button', { name: '↑', exact: true }).click();
        await page.waitForFunction(() =>
            document.querySelector('[data-content-sections] tbody tr td:first-child')?.textContent?.includes('الأخبار'));
        const orderedNames = await page.locator('[data-content-sections] tbody tr td:first-child').allInnerTexts();
        assert.equal(orderedNames[0].includes('الأخبار'), true);

        const companySectionAfterReorder = page.locator('[data-content-sections] tbody tr').filter({ hasText: 'الشركة' });
        await companySectionAfterReorder.getByRole('button', { name: 'تعديل', exact: true }).click();
        await page.locator('[data-content-section-form] input[type="checkbox"]').uncheck();
        await clickAndWait('[data-content-section-form] button[type="submit"]',
            `/internal/administration/content/sections/${companyPublicId}`);
        publicResponse = await page.goto(base+'/');
        assert.equal(publicResponse.status(), 200);
        assert.equal(await page.locator('header a[href="/p/company"]').count(), 0);

        await visit('/internal/administration/content/sections', '[data-content-sections]');
        await clickAndWait(
            '[data-content-sections] tbody tr:has-text("الشركة") button.content-action--danger',
            `/internal/administration/content/sections/${companyPublicId}/delete`,
        );
        await page.locator('[data-content-sections] tbody tr').filter({ hasText: 'الشركة' }).waitFor({ state: 'detached' });
        publicResponse = await page.goto(base+'/p/company');
        assert.equal(publicResponse.status(), 200, 'Deleting a navigation section must not delete its page.');
    });

    await check('Category and article create, reopen, edit and draft-safe publish journey', async () => {
        await visit('/internal/administration/content/categories', '[data-content-categories]');
        await page.locator('[data-content-add-category]').click();
        await page.locator('#category-name-ar').fill('الأخبار');
        await page.locator('#category-name-en').fill('News');
        await page.locator('#category-slug-ar').fill('news');
        await page.locator('#category-slug-en').fill('news');
        await clickAndWait('[data-content-category-form] button[type="submit"]',
            '/internal/administration/content/categories');
        const categoryRow = page.locator('[data-content-categories] tbody tr').filter({ hasText: 'الأخبار' });
        await categoryRow.getByRole('button', { name: 'تعديل', exact: true }).click();
        await page.locator('#category-name-ar').fill('أخبار الشركة');
        await clickAndWait('[data-content-category-form] button[type="submit"]',
            /^\/internal\/administration\/content\/categories\/[0-9a-f-]+$/);
        await page.locator('[data-content-categories] tbody tr').filter({ hasText: 'أخبار الشركة' }).waitFor();

        await visit('/internal/administration/content/articles', '[data-content-articles]');
        await page.locator('[data-content-add-article]').click();
        await page.locator('#article-title-ar').fill('خبر الشركة');
        await page.locator('#article-title-en').fill('Company article');
        await page.locator('#article-slug-ar').fill('company-article');
        await page.locator('#article-slug-en').fill('company-article');
        await clickAndWait('[data-content-article-create] button[type="submit"]',
            '/internal/administration/content/articles');
        await page.locator('[data-content-save]').waitFor();
        const articleId = new URL(page.url()).pathname.split('/').at(-1);
        await page.locator('#article-edit-category').selectOption({ label: 'أخبار الشركة' });
        await fillBody('نسخة المقالة الأولى');
        await clickAndWait('[data-content-save]', `/internal/administration/content/articles/${articleId}`);

        await visit('/internal/administration/content/articles', '[data-content-articles]');
        const articleRow = page.locator('[data-content-articles] tbody tr').filter({ hasText: 'خبر الشركة' });
        await articleRow.getByRole('link', { name: 'فتح', exact: true }).click();
        await page.locator('[data-content-save]').waitFor();
        assert.match(await page.locator('.content-editor-surface [contenteditable="true"]').first().innerText(),
            /نسخة المقالة الأولى/);
        await clickAndWait('[data-content-publish]', `/internal/administration/content/articles/${articleId}/publish`);

        let publicResponse = await page.goto(base+'/articles/company-article');
        assert.equal(publicResponse.status(), 200);
        await page.getByText('نسخة المقالة الأولى', { exact: true }).waitFor();
        await page.getByText('أخبار الشركة', { exact: true }).waitFor();

        await visit(`/internal/administration/content/articles/${articleId}`, '[data-content-save]');
        await fillBody('مسودة المقالة الثانية');
        await clickAndWait('[data-content-save]', `/internal/administration/content/articles/${articleId}`);
        publicResponse = await page.goto(base+'/articles/company-article');
        assert.equal(publicResponse.status(), 200);
        await page.getByText('نسخة المقالة الأولى', { exact: true }).waitFor();
        assert.equal(await page.getByText('مسودة المقالة الثانية', { exact: true }).count(), 0);

        await visit(`/internal/administration/content/articles/${articleId}`, '[data-content-publish]');
        await clickAndWait('[data-content-publish]', `/internal/administration/content/articles/${articleId}/publish`);
        publicResponse = await page.goto(base+'/articles/company-article');
        assert.equal(publicResponse.status(), 200);
        await page.getByText('مسودة المقالة الثانية', { exact: true }).waitFor();
        assert.equal(await page.getByText('نسخة المقالة الأولى', { exact: true }).count(), 0);
        await page.goto(base+'/articles/category/news');
        assert.equal(page.url().includes('/articles/category/news'), true);
        await page.locator('a.public-card[href="/articles/company-article"]').waitFor();
    });

    await check('Published appearance agrees across Arabic and English landing and content pages', async () => {
        for (const language of ['ar', 'en']) {
            await page.goto(base+'/');
            if (await page.locator('html').getAttribute('lang') !== language) {
                await page.locator('.public-language button').click();
                await page.waitForLoadState('domcontentloaded');
            }
            assert.equal(await page.locator('html').getAttribute('lang'), language);
            for (const theme of ['light', 'dark']) {
                await page.evaluate(value => {
                    localStorage.setItem('builderad.theme', value);
                    window.dispatchEvent(new StorageEvent('storage', { key: 'builderad.theme', newValue: value }));
                }, theme);
                const colors = [];
                for (const route of ['/', '/p/company', '/articles/company-article']) {
                    await page.goto(base+route);
                    assert.equal(await page.locator('html').getAttribute('data-theme'), theme);
                    assert.equal(await page.locator('html').getAttribute('lang'), language);
                    colors.push(await page.locator('body').evaluate(element => getComputedStyle(element).backgroundColor));
                    if (route === '/') {
                        const contrast = await page.locator('.ba-stat strong').first().evaluate(element => {
                            const rgb = color => color.match(/\d+/g).slice(0, 3).map(Number).map(value => {
                                const channel = value / 255;
                                return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
                            });
                            const luminance = color => rgb(color).reduce((sum, channel, index) =>
                                sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
                            const text = luminance(getComputedStyle(element).color);
                            const surface = luminance(getComputedStyle(element.closest('.ba-stat')).backgroundColor);
                            return (Math.max(text, surface) + 0.05) / (Math.min(text, surface) + 0.05);
                        });
                        assert.ok(contrast >= 4.5, `${language}/${theme} dashboard text contrast ${contrast}`);
                    }
                }
                assert.equal(new Set(colors).size, 1, `${language}/${theme} body backgrounds differ: ${colors.join(', ')}`);
            }
        }
    });

    assert.equal(errors.length, 0, errors.join('\n'));
    assert.ok([...assets].some(asset => asset.endsWith('.js')), 'Built JavaScript asset must load.');
    assert.ok([...assets].some(asset => asset.endsWith('.css')), 'Built CSS asset must load.');
    const evidence = { results, errors, assets: [...assets] };
    await writeFile(path.join(output, 'results.json'), JSON.stringify(evidence, null, 2));
    console.log('CONTENT_BROWSER_RESULTS '+JSON.stringify({ passed: results.length, assets: assets.size }));
} catch (error) {
    errors.push(error.stack ?? error.message);
    await writeFile(path.join(output, 'results.json'), JSON.stringify({ results, errors, assets: [...assets] }, null, 2));
    console.error('CONTENT_BROWSER_FAILURE '+(error.stack ?? error.message));
    process.exitCode = 1;
} finally {
    await close();
}
