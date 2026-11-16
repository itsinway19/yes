import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { appearanceReliability } from './appearance-reliability-browser.mjs';

if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.DB_DATABASE !== 'builderad_test_disposable' || !/^(6397|1639[7-9]|1640[0-4])$/.test(process.env.REDIS_PORT ?? '')) throw Error('Dedicated disposable proof environment required');
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const run = process.env.GOALS_SPA_RUN;
const fixture = spawnSync('C:/tools/php85/php.exe', ['tests/Support/AppearanceBrowserFixture.php'], { env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr);
assert.equal(JSON.parse(fixture.stdout).database.db, 'builderad_test_disposable');
const cookies = JSON.parse(await readFile(path.join(run, 'appearance-fixture.json'), 'utf8'));
const unexpectedResponses = [];
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const contexts = [], results = [], errors = [], requests = [], assets = new Map();
// Appearance requires a single application origin and uses Playwright to
// exercise individual request failures. The guarded PHP server already
// serves both Laravel and the built assets at that origin.
const url = 'http://localhost:8136';
assert.equal(process.env.APP_URL, url, 'Appearance proof must use its direct application origin');
const appearancePath = '/internal/administration/appearance';
const capture = (page, options) => process.env.GOALS_SPA_CAPTURE_IMAGES === '1' ? page.screenshot(options) : Promise.resolve();
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const check = async (name, action) => { await action(); results.push({ name, status: 'PASS' }); console.log('PASS '+name); };
async function session(kind) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }); contexts.push(context);
    // Fetch public build assets from the proof server for byte provenance.
    // Routing passes requests through unchanged and disables Chromium's body cache.
    await context.route('**/build/**', route => route.continue());
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
const secondaryColor = page => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ba-brand-secondary').trim());
async function publish(page, expected) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === appearancePath && response.request().method() === 'POST');
    const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
    await page.locator('[data-appearance-publish]').click(); assert.equal((await response).status(), 200);
    await navigation;
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
        await advertiser.goto(url+'/businesses'); await ready(advertiser); assert.equal(await rootColor(advertiser), '#155f53');
    });
    await check('Isolated interactive preview, validation, contrast and focus', async () => {
        await page.locator('#appearance-primary').fill('#ffff00'); await page.locator('#appearance-secondary').fill('#7c3aed');
        assert.equal(await rootColor(page), '#155f53');
        const button = page.locator('[data-appearance-preview] .ba-brand-button:not(:disabled)');
        assert.deepEqual(await button.evaluate(el => ({ bg: getComputedStyle(el).backgroundColor, text: getComputedStyle(el).color })), { bg: 'rgb(255, 255, 0)', text: 'rgb(0, 0, 0)' });
        await button.focus(); assert.notEqual(await button.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
        await page.locator('#appearance-primary').fill('#bad'); assert.equal(await page.locator('[data-appearance-publish]').isDisabled(), true);
        await page.locator('#appearance-primary').fill('#ffff00');
        await capture(page, { path: path.join(run, 'appearance-desktop.png'), fullPage: true });
    });
    await check('Authorized publication updates fresh advertiser documents only', async () => {
        await publish(page, '#ffff00');
        assert.equal(await rootColor(advertiser), '#155f53');
        assert.equal(await secondaryColor(advertiser), '#334b73');
        await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#ffff00');
        assert.equal(await secondaryColor(advertiser), '#7c3aed');
        const second = await session('advertiser'); await second.goto(url+'/businesses'); await ready(second); assert.equal(await rootColor(second), '#ffff00');
        assert.equal(await secondaryColor(second), '#7c3aed');
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
        await capture(page, { path: path.join(run, 'appearance-mobile-rtl.png'), fullPage: true });
        assert.equal(await page.locator('[data-appearance-preview] [data-preview-status]').count(), 3);
    });
    await check('Restore defaults uses the same explicit publication path', async () => {
        await page.locator('[data-appearance-defaults]').click();
        await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#008866');
        await publish(page, '#155f53'); await advertiser.reload(); await ready(advertiser); assert.equal(await rootColor(advertiser), '#155f53');
    });
    await check('Founder previews and publishes logo and favicon atomically with public immutable bytes and explicit defaults', async () => {
        const images = JSON.parse(await readFile('tests/Fixtures/brand-images.json','utf8'));
        await page.setViewportSize({width:1280,height:900});
        const count = () => requests.filter(row=>row.path===appearancePath && row.method==='POST').length;
        const before = count();
        await page.locator('[data-appearance-image="logo"]').setInputFiles({name:'rejected.svg',mimeType:'image/svg+xml',buffer:Buffer.from('<svg/>')});
        await page.waitForFunction(()=>document.querySelector('[data-appearance-image="logo"]')?.getAttribute('aria-invalid')==='true');
        assert.equal(await page.locator('[data-appearance-publish]').isDisabled(),true);
        await page.locator('[data-appearance-image-default="logo"]').click();
        assert.equal(await page.locator('[data-appearance-image="logo"]').getAttribute('aria-invalid'),'false','An invalid selection can be cleared without choosing another file');
        const original = Buffer.from(images.logo.split(',')[1],'base64');
        const damaged = Buffer.concat([original.subarray(0,33),original.subarray(original.length-12)]);
        await page.locator('[data-appearance-image="logo"]').setInputFiles({name:'damaged.png',mimeType:'image/png',buffer:damaged});
        await page.waitForFunction(()=>document.querySelector('[data-appearance-image="logo"]')?.getAttribute('aria-invalid')==='true');
        assert.equal(await page.locator('[data-appearance-publish]').isDisabled(),true,'A PNG that cannot decode never becomes publishable');
        for (const key of ['logo','favicon']) {
            await page.locator(`[data-appearance-image="${key}"]`).setInputFiles({name:`isolated-${key}.png`,mimeType:'image/png',buffer:Buffer.from(images[key].split(',')[1],'base64')});
            await page.waitForFunction(({key,value})=>document.querySelector(`[data-appearance-image-preview="${key}"]`)?.getAttribute('src')===value,{key,value:images[key]});
        }
        assert.equal(count(),before,'Selecting files never publishes');
        assert.equal(await page.locator('meta[name="builderad-logo"]').getAttribute('content'),'');
        await publish(page,'#155f53');
        const logo = await page.locator('meta[name="builderad-logo"]').getAttribute('content');
        const favicon = await page.locator('link[rel="icon"]').getAttribute('href');
        assert.match(logo,/^\/brand\/assets\/[a-f0-9]{64}\.png$/); assert.notEqual(logo,favicon);
        await page.waitForFunction(()=>[...document.querySelectorAll('[data-published-brand-logo]')].some(image=>image.complete && image.naturalWidth===80));
        await advertiser.reload(); await ready(advertiser);
        for (const width of [1280,390]) {
            await advertiser.setViewportSize({width,height:844});
            assert.equal(await advertiser.locator('meta[name="builderad-logo"]').getAttribute('content'),logo);
            assert.equal(await advertiser.locator('link[rel="icon"]').getAttribute('href'),favicon);
            assert.equal(await advertiser.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        }
        const guest = await browser.newContext(); contexts.push(guest);
        for (const [key,asset] of [['logo',logo],['favicon',favicon]]) {
            const response = await guest.request.get(url+asset);
            assert.equal(response.status(),200); assert.equal(response.headers()['content-type'],'image/png');
            assert.equal(response.headers()['x-builderad-identity'],undefined); assert.equal(response.headers()['set-cookie'],undefined);
            assert.equal(hash(await response.body()),hash(Buffer.from(images[key].split(',')[1],'base64')));
        }
        const homePage = await guest.newPage();
        const home = await homePage.goto(url+'/'); assert.equal(home.status(),200);
        assert.ok((await homePage.content()).includes('src="'+logo+'"'));
        for (const key of ['logo','favicon']) await page.locator(`[data-appearance-image-default="${key}"]`).click();
        assert.equal(await page.locator('meta[name="builderad-logo"]').getAttribute('content'),logo,'Defaults remain a local draft');
        await publish(page,'#155f53');
        assert.equal(await page.locator('meta[name="builderad-logo"]').getAttribute('content'),'');
        assert.equal(await page.locator('link[rel="icon"]').getAttribute('href'),'/brand/builderad-mark.svg');
        assert.equal((await guest.request.get(url+logo)).status(),200,'An older open document keeps its immutable image');
    });
    }
    await appearanceReliability({ session, ready, check, run, url, appearancePath });
    for (const [asset, pendingHash] of assets) {
        const actualHash = await pendingHash;
        assert.equal(typeof actualHash, 'string', `${asset}: ${JSON.stringify(actualHash)}`);
        assert.equal(actualHash, hash(await readFile(path.join(process.cwd(), 'public', asset))), asset);
    }
    assert.equal(errors.length, 0, errors.join('\n'));
    assert.equal(unexpectedResponses.length, 0, JSON.stringify(unexpectedResponses));
    await writeFile(path.join(run, 'appearance-browser-results.json'), JSON.stringify({ results, errors, unexpectedResponses, origin: url, assets: [...assets.keys()], headlessChromium: true, syntheticSessions: true, productionBuild: true }, null, 2));
} catch (error) {
    for (const [index, context] of contexts.entries()) for (const page of context.pages()) {
        console.error('FAILED_PAGE '+JSON.stringify({ index, url: page.url(), state: await page.locator('#application-spa').getAttribute('data-state').catch(() => null), text: await page.locator('body').innerText().catch(() => '') }));
        await capture(page, { path: path.join(run, `appearance-failure-${index}.png`), fullPage: true }).catch(() => {});
    }
    await writeFile(path.join(run, 'appearance-browser-results.json'), JSON.stringify({ results, errors, unexpectedResponses, origin: url, failure: error.stack }, null, 2));
    throw error;
} finally {
    await Promise.all(contexts.map(context => context.close())); await browser.close();
}
