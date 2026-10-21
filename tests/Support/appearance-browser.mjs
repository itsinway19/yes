import http from 'node:http';
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { appearanceReliability } from './appearance-reliability-browser.mjs';

if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.DB_DATABASE !== 'builderad_test_disposable' || process.env.REDIS_PORT !== '6397') throw Error('Dedicated disposable proof environment required');
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN;
const fixture = spawnSync('C:/tools/php85/php.exe', ['tests/Support/AppearanceBrowserFixture.php'], { env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr);
assert.equal(JSON.parse(fixture.stdout).database.db, 'builderad_test_disposable');
const cookies = JSON.parse(await readFile(path.join(run, 'appearance-fixture.json'), 'utf8'));
const proxyFailures = [], unexpectedResponses = [];
// Same-origin proxy matches the repository browser runner; no runtime route overrides.
const proxy = http.createServer((req, res) => {
    const upstream = http.request({ hostname: 'localhost', port: 8136, path: req.url, method: req.method, headers: req.headers }, source => {
        res.writeHead(source.statusCode, source.headers); source.pipe(res);
    });
    upstream.on('error', error => { proxyFailures.push({ path: new URL(req.url, 'http://localhost').pathname, method: req.method, code: error.code, clientGone: res.destroyed }); if (!res.destroyed) { res.writeHead(502); res.end(error.message); } });
    req.pipe(upstream);
});
await new Promise(resolve => proxy.listen(8138, 'localhost', resolve));
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const contexts = [], results = [], errors = [], requests = [], assets = new Map();
const url = 'http://localhost:8138';
const appearancePath = '/internal/administration/appearance';
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const check = async (name, action) => { await action(); results.push({ name, status: 'PASS' }); console.log('PASS '+name); };
async function session(kind) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }); contexts.push(context);
    context.setDefaultTimeout(30000);
    await context.addCookies(cookies[kind]);
    const page = await context.newPage();
    page.on('pageerror', error => { errors.push(error.message); console.error('BROWSER_ERROR '+error.message); });
    page.on('console', message => { if (message.type() === 'error') console.error('BROWSER_CONSOLE '+message.text()); });
    page.on('dialog', dialog => dialog.accept());
    page.on('request', request => requests.push({ page: kind, method: request.method(), path: new URL(request.url()).pathname }));
    page.on('response', response => {
        if (response.status() >= 500) unexpectedResponses.push({ path: new URL(response.url()).pathname, method: response.request().method(), status: response.status() });
        if (new URL(response.url()).pathname.includes('/build/') && response.status() === 200) {
            assets.set(new URL(response.url()).pathname, response.body().then(hash).catch(error => ({ error: error.message })));
        }
    });
    return page;
}
const ready = page => page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready', undefined, { timeout: 30000 });
const editor = async page => { await page.goto(url+appearancePath); await ready(page); await page.locator('[data-appearance-settings]').waitFor(); };
const rootColor = page => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ba-brand-primary').trim());
async function publish(page, expected) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === appearancePath && response.request().method() === 'POST');
    await page.locator('[data-appearance-publish]').click(); assert.equal((await response).status(), 200);
    await page.waitForFunction(color => getComputedStyle(document.documentElement).getPropertyValue('--ba-brand-primary').trim() === color, expected);
    await ready(page); await page.locator('[data-appearance-settings]').waitFor();
}
try {
    if (process.env.APPEARANCE_RELIABILITY_ONLY !== '1') {
    const page = await session('founder'), stale = await session('founder'), advertiser = await session('advertiser');
    await check('Default first document and RTL editor', async () => {
        await editor(page); await editor(stale);
        assert.equal(await rootColor(page), '#155f53');
        assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
        assert.equal(await page.locator('#appearance-primary').inputValue(), '#155f53');
        const receipt = await page.evaluate(() => JSON.parse(document.querySelector('#application-bootstrap').textContent).identity.receipt);
        // Laravel accepts browser same-origin Fetch Metadata independently of the
        // token. Exercise its token fallback with an HTTP client lacking that header.
        const csrfRejection = await page.context().request.post(url+appearancePath, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': 'invalid', 'X-Builderad-Document': receipt }, data: { revision: 0, primary: '#ffffff', secondary: '#000000' } });
        assert.equal(csrfRejection.status(), 419, 'Real runtime token fallback must enforce CSRF');
        await advertiser.goto(url+'/workspaces'); await ready(advertiser); assert.equal(await rootColor(advertiser), '#155f53');
    });
    await check('Isolated interactive preview, validation, contrast and focus', async () => {
        await page.locator('#appearance-primary').fill('#ffff00'); await page.locator('#appearance-secondary').fill('#7c3aed');
        assert.equal(await rootColor(page), '#155f53');
        const button = page.locator('[data-appearance-preview] .ba-brand-button:not(:disabled)');
        assert.deepEqual(await button.evaluate(el => ({ bg: getComputedStyle(el).backgroundColor, text: getComputedStyle(el).color })), { bg: 'rgb(255, 255, 0)', text: 'rgb(0, 0, 0)' });
        await button.focus(); assert.notEqual(await button.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
        await page.locator('#appearance-primary').fill('#bad'); assert.equal(await page.locator('[data-appearance-publish]').isDisabled(), true);
        await page.locator('#appearance-primary').fill('#ffff00');
        await page.screenshot({ path: path.join(run, 'appearance-desktop.png'), fullPage: true });
    });
    await check('Authorized publication updates fresh advertiser documents only', async () => {
        await publish(page, '#ffff00');
        assert.equal(await rootColor(advertiser), '#155f53');
        await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#ffff00');
        const second = await session('advertiser'); await second.goto(url+'/workspaces'); await ready(second); assert.equal(await rootColor(second), '#ffff00');
        assert.equal(await second.locator('#published-appearance').count(), 1);
    });
    await check('Concurrent stale editor cannot silently overwrite publication', async () => {
        await stale.locator('#appearance-primary').fill('#123456');
        const response = stale.waitForResponse(response => new URL(response.url()).pathname === appearancePath && response.request().method() === 'POST');
        await stale.locator('[data-appearance-publish]').click(); assert.equal((await response).status(), 409);
        await stale.waitForSelector('[data-appearance-outcome="stale"]');
        assert.equal(await stale.locator('#appearance-primary').inputValue(), '#123456');
        await stale.locator('[data-appearance-review]').click(); await stale.locator('[data-appearance-replace]').waitFor();
        assert.equal(await stale.locator('[data-appearance-publish]').isDisabled(), true);
        await stale.locator('[data-appearance-replace]').click(); assert.equal(await stale.locator('#appearance-primary').inputValue(), '#ffff00');
    });
    await check('Abandoning local draft and ordinary navigation never applies or fetches theme', async () => {
        await page.locator('#appearance-primary').fill('#112233');
        const initial = requests.length;
        await page.locator('[data-admin-sidebar] a[href="/internal/administration"]').click();
        await ready(page); await page.locator('[data-administration]').waitFor();
        assert.equal(requests.slice(initial).filter(request => request.path === appearancePath).length, 0);
        assert.equal(await rootColor(page), '#ffff00');
        await page.locator('[data-admin-sidebar] a[href="'+appearancePath+'"]').click(); await ready(page); await page.locator('#appearance-primary').waitFor();
        assert.equal(await page.locator('#appearance-primary').inputValue(), '#ffff00');
    });
    await check('Lost acknowledgement remains unknown without replay or false success', async () => {
        await page.locator('#appearance-primary').fill('#008866');
        await page.route('**'+appearancePath, async route => {
            if (route.request().method() !== 'POST') return route.continue();
            await route.fetch(); await route.abort('failed');
        });
        const initial = requests.filter(request => request.method === 'POST' && request.path === appearancePath).length;
        await page.locator('[data-appearance-publish]').click(); await page.waitForSelector('[data-appearance-outcome="unknown"]');
        assert.equal(await page.locator('[data-appearance-publish]').isDisabled(), true);
        await page.unroute('**'+appearancePath);
        await page.locator('[data-appearance-review]').click(); await page.locator('[data-appearance-replace]').waitFor();
        assert.equal(await page.locator('[data-appearance-settings]').getAttribute('data-appearance-outcome'), 'unknown');
        assert.equal(requests.filter(request => request.method === 'POST' && request.path === appearancePath).length, initial+1);
        assert.equal(await rootColor(page), '#ffff00');
        await page.locator('[data-appearance-replace]').click(); assert.equal(await page.locator('#appearance-primary').inputValue(), '#008866');
    });
    await check('Responsive RTL and semantic states retain fixed meaning', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'appearance-mobile-rtl.png'), fullPage: true });
        assert.equal(await page.locator('[data-appearance-preview] [data-preview-status]').count(), 3);
    });
    await check('Restore defaults uses the same explicit publication path', async () => {
        await page.locator('[data-appearance-defaults]').click();
        await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#008866');
        await publish(page, '#155f53'); await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#155f53');
    });
    }
    await appearanceReliability({ session, ready, check, run, url, appearancePath });
    for (const [asset, pendingHash] of assets) {
        const actualHash = await pendingHash;
        assert.equal(typeof actualHash, 'string', `${asset}: ${JSON.stringify(actualHash)}`);
        assert.equal(actualHash, hash(await readFile(path.join(process.cwd(), 'public', asset))), asset);
    }
    assert.equal(errors.length, 0, errors.join('\n'));
    assert.equal(unexpectedResponses.length, 0, JSON.stringify({ unexpectedResponses, proxyFailures }));
    await writeFile(path.join(run, 'appearance-browser-results.json'), JSON.stringify({ results, errors, unexpectedResponses, proxyFailures, assets: [...assets.keys()], headlessChromium: true, syntheticSessions: true, productionBuild: true }, null, 2));
} catch (error) {
    for (const [index, context] of contexts.entries()) for (const page of context.pages()) {
        console.error('FAILED_PAGE '+JSON.stringify({ index, url: page.url(), state: await page.locator('#application-spa').getAttribute('data-state').catch(() => null), text: await page.locator('body').innerText().catch(() => '') }));
        await page.screenshot({ path: path.join(run, `appearance-failure-${index}.png`), fullPage: true }).catch(() => {});
    }
    await writeFile(path.join(run, 'appearance-browser-results.json'), JSON.stringify({ results, errors, unexpectedResponses, proxyFailures, failure: error.stack }, null, 2));
    throw error;
} finally {
    await Promise.all(contexts.map(context => context.close())); await browser.close(); await new Promise(resolve => proxy.close(resolve));
}
