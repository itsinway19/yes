import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import path from 'node:path';

export async function appearanceReliability({ session, ready, check, run, url, appearancePath }) {
    const command = action => {
        const result = spawnSync('C:/tools/php85/php.exe', ['tests/Support/AppearanceBrowserFixture.php', action], { env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        const receipt = JSON.parse(result.stdout);
        assert.equal(receipt.database.db, 'builderad_test_disposable');
        assert.equal(receipt.database.port, 5433);
    };
    const page = await session('founder');
    const samples = [];
    for (let sample = 0; sample < 3; sample++) {
        await page.goto(url + appearancePath); await ready(page);
        const requests = [];
        const observe = response => { const request = response.request(); if (request.resourceType() === 'fetch') requests.push({ path: new URL(response.url()).pathname, method: request.method(), status: response.status(), witness: request.headers()['x-builderad-witness'] === '1' }); };
        page.on('response', observe);
        await page.evaluate(() => { window.__reliabilityStates = []; new MutationObserver(() => window.__reliabilityStates.push({ ms: performance.now(), state: document.getElementById('application-spa')?.dataset.state })).observe(document.getElementById('application-spa'), { attributes: true, attributeFilter: ['data-state'] }); });
        const started = performance.now();
        await page.locator('[data-admin-sidebar] a[href="/internal/administration"]').click();
        await ready(page); await page.locator('[data-administration]').waitFor();
        samples.push({ sample, direction: 'appearance-to-administration', elapsedMs: performance.now() - started, requests, states: await page.evaluate(() => window.__reliabilityStates), fullConcealments: await page.evaluate(() => window.__reliabilityStates.filter(row => row.state !== 'ready').length) });
        page.off('response', observe);
    }
    await page.goto(url + appearancePath); await ready(page);
    await page.locator('#appearance-primary').fill('#123456');
    const restorationReads = [];
    const restorationResponse = response => { if (response.request().resourceType() === 'fetch') restorationReads.push({ path: new URL(response.url()).pathname, status: response.status() }); };
    page.on('response', restorationResponse);
    const restorationStart = performance.now();
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
    page.off('response', restorationResponse);
    const restoration = { stimulus: 'JavaScript visibility restoration signal on a visible Chromium document; not physical tab/BFCache proof', elapsedMs: performance.now() - restorationStart, requests: restorationReads, draft: await page.locator('#appearance-primary').inputValue() };
    assert.equal(restoration.draft, '#123456'); assert.equal(restorationReads.length, 2);
    command('expire-fresh');
    let posts = 0;
    page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === appearancePath) posts++; });
    const pending = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === appearancePath);
    await page.locator('[data-appearance-publish]').click();
    const response = await pending;
    assert.equal(response.status(), 403);
    // Wait for the actual client decision, rather than treating the HTTP receipt as UI acceptance.
    await page.waitForFunction(() => document.querySelector('[data-appearance-outcome="reauthentication"]') || document.querySelector('[data-application-spa]')?.getAttribute('data-state') === 'closed');
    const state = await page.evaluate(() => ({ state: document.querySelector('[data-application-spa]')?.getAttribute('data-state'), draft: document.querySelector('#appearance-primary')?.value ?? null, text: document.body.innerText, recovery: document.querySelector('[data-appearance-reauthenticate]')?.getAttribute('href') ?? null }));
    const evidence = { samples, restoration, freshAuth: { status: response.status(), code: (await response.json()).code ?? null, state, posts }, environment: { browser: await page.context().browser().version(), productionBuild: true, syntheticSessions: true, physicalPhone: 'NOT OBSERVED' } };
    await writeFile(path.join(run, 'appearance-reliability.json'), JSON.stringify(evidence, null, 2));
    await page.screenshot({ path: path.join(run, 'appearance-fresh-auth.png'), fullPage: true });
    if (process.env.RELIABILITY_BASELINE === '1') {
        assert.equal(state.state, 'closed');
        assert.equal(state.draft, null);
        console.log('BASELINE CONFIRMED: actual Laravel 403 closes Vue document and removes draft');
        return;
    }
    await check('Expired fresh authentication is an admitted known rejection preserving the draft, not terminal authority loss', async () => {
        assert.equal(evidence.freshAuth.code, 'fresh_authentication_required');
        assert.equal(state.state, 'ready'); assert.equal(state.draft, '#123456');
        assert.equal(state.recovery, '/internal/identity/switch'); assert.equal(posts, 1);
        assert.match(state.text, /مصادقة|authentication/i);
        assert.equal(await page.locator('[data-appearance-publish]').isDisabled(), true);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(run, 'appearance-fresh-auth-mobile-rtl.png'), fullPage: true });
        await page.locator('[data-appearance-reauthenticate]').click(); await ready(page);
        assert.equal(new URL(page.url()).pathname, '/internal/identity/switch');
        assert.equal(await page.locator('[data-auth-passkey]').count(), 1);
        assert.equal(posts, 1);
    });
    command('restore-fresh');
    const stale = await session('founder');
    await stale.goto(url + appearancePath); await ready(stale);
    await stale.locator('#appearance-primary').fill('#abcdef');
    let stalePosts = 0;
    await stale.route('**'+appearancePath, route => {
        if (route.request().method() !== 'POST') return route.continue();
        stalePosts++;
        return route.continue({ headers: { ...route.request().headers(), 'x-builderad-document': 'retired-document-receipt' } });
    });
    const staleResponse = stale.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === appearancePath);
    await stale.locator('[data-appearance-publish]').click(); assert.equal((await staleResponse).status(), 409);
    await stale.locator('[data-application-spa][data-state="closed"]').waitFor();
    await check('Invalid document receipt is rejected by Laravel and closes real Vue presentation without replay', async () => {
        assert.equal(await stale.locator('#appearance-primary').count(), 0); assert.equal(stalePosts, 1);
    });
    await stale.close();
    await page.goto(url + appearancePath); await ready(page);
    await page.locator('#appearance-primary').fill('#abcdef');
    command('revoke-grant');
    const denied = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === appearancePath);
    await page.locator('[data-appearance-publish]').click(); assert.equal((await denied).status(), 403);
    await page.locator('[data-application-spa][data-state="closed"]').waitFor();
    await check('Actual grant loss closes the document and clears the protected appearance draft without replay', async () => {
        assert.equal(await page.locator('#appearance-primary').count(), 0);
        assert.equal(posts, 2);
    });
    command('restore-grant');
    const revoked = await session('founder');
    await revoked.goto(url + appearancePath); await ready(revoked);
    await revoked.locator('#appearance-primary').fill('#abcdef');
    command('revoke-session');
    const revokedResponse = revoked.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === appearancePath);
    await revoked.locator('[data-appearance-publish]').click();
    const rejection = await revokedResponse;
    assert.ok([401, 403, 409, 419].includes(rejection.status()));
    assert.notEqual((await rejection.json()).code, 'fresh_authentication_required');
    await revoked.locator('[data-application-spa][data-state="closed"]').waitFor();
    await check('Revoked real session cannot be treated as recoverable freshness or retain protected draft', async () => {
        assert.equal(await revoked.locator('#appearance-primary').count(), 0);
    });
}
