import assert from 'node:assert/strict';
import { runAuthEntryCases as runExistingAuthentication } from './auth-entry-browser.mjs';

export async function runAuthEntryCases(harness) {
    if (process.env.RETIREMENT_RESTORATION_ONLY === 'enabled') {
        const { runRestorationControls } = await import('./runtime-retirement-restoration.mjs');
        return runRestorationControls(harness);
    }
    const { browser, check, get, command, fixture, result, until, held } = harness;
    const origin = 'http://localhost:8138';
    async function ready(page, selector = '[data-account-kind="profile"]') {
        await page.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
    }
    function observe(context) {
        context.on('page', page => { page.on('dialog', dialog => dialog.accept()); page.on('pageerror', error => result.errors.push({ mode: 'retirement', message: error.message, stack: error.stack })); });
        context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
    }
    async function login(page) {
        await page.goto(origin + '/profile'); await ready(page, '[data-auth-kind="login"]');
        await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!');
        await page.locator('[data-auth-entry] button[type=submit]').click(); await ready(page);
    }
    const context = await browser.newContext(); observe(context); const page = await context.newPage();
    try {
        await login(page);
        await check('retirement clean SPA document has no private HTML or legacy runtime entry', 'retirement', async () => {
            const scriptless = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
            try {
                const shell = await scriptless.newPage(), response = await shell.goto(origin + '/profile');
                assert.equal(response.status(), 200); assert.match(response.headers()['cache-control'], /no-store/);
                const html = await response.text(); assert.ok(!html.includes('goals-a@example.test')); assert.ok(!html.includes('data-page='));
                assert.equal(await shell.locator('#application-spa').innerHTML(), '');
                assert.ok(!(await page.locator('script[src]').evaluateAll(nodes => nodes.map(node => node.src))).some(url => /inertia|n1|\/app-/.test(url)));
                assert.equal(await page.evaluate(() => typeof window.__goalsSpa), 'undefined');
                return { initialHtmlProjectionFree: true, productionBundle: true, proofHookAbsent: true };
            } finally { await scriptless.close(); }
        });
        await check('retirement JS-controlled restoration stays hidden until actual current witness and fails closed offline', 'retirement', async () => {
            const key = 'retirement-current-witness'; let intercept = true;
            await page.route('**/profile', async route => {
                if (intercept && route.request().headers()['x-builderad-witness'] === '1') { intercept = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); }
                else await route.continue();
            });
            await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
            await until(() => held.has(key));
            assert.equal(await page.locator('[data-spa-private]:visible').count(), 0);
            held.get(key).deliver(); held.delete(key); await ready(page); await page.unroute('**/profile');
            await page.route('**/profile', route => route.abort('failed'));
            await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'unavailable');
            assert.equal(await page.locator('[data-spa-private]:visible').count(), 0);
            await page.unroute('**/profile'); await page.reload(); await ready(page);
            return { syntheticPersistedEventTestsJavascriptOnly: true, actualWitnessHeld: true, offlineFailsClosed: true, noNativeBfcacheClaim: true };
        });
        await check('retirement active cross-tab account transition closes old owner and suppresses held private response', 'retirement', async () => {
            const key = 'retirement-old-profile'; let first = true;
            await page.route('**/profile', async route => {
                if (first && route.request().headers().accept === 'application/json') { first = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); }
                else await route.continue();
            });
            await page.locator('[data-account-review]').click(); await until(() => held.has(key));
            const switcher = await context.newPage();
            await switcher.goto(origin + '/identity/switch'); await ready(switcher, '[data-auth-kind="login"]');
            await switcher.locator('#email').fill('goals-b@example.test'); await switcher.locator('#password').fill('DisposableGoalsProof42!');
            await switcher.locator('[data-auth-entry] button[type=submit]').click(); await ready(switcher, '[data-spa-private]:not([data-auth-entry])');
            assert.equal((await get(switcher, '/profile')).body.data.user.email, 'goals-b@example.test');
            await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
            held.get(key).deliver(); held.delete(key);
            assert.equal(await page.locator('[data-account-page]').count(), 0);
            await page.unroute('**/profile'); await switcher.close();
            return { actualNativeIdentitySwitch: true, previousPrincipal: fixture.a.user, nextPrincipal: fixture.b.user, latePublication: false };
        });
    } finally { await context.close(); }

    await check('retirement pre-JavaScript restoration observation is separate from pageshow validation', 'retirement', async () => {
        const isolated = await browser.newContext(); observe(isolated); const current = await isolated.newPage();
        const shows = [], notRestored = [];
        const cdp = await isolated.newCDPSession(current); await cdp.send('Page.enable'); await cdp.send('DOM.enable'); await cdp.send('CSS.enable');
        cdp.on('Page.backForwardCacheNotUsed', event => notRestored.push(event.notRestoredExplanations));
        await current.addInitScript(() => addEventListener('pageshow', event => console.log('RETIREMENT_SHOW ' + JSON.stringify({ persisted: event.persisted, trusted: event.isTrusted, path: location.pathname }))));
        current.on('console', message => { if (message.text().startsWith('RETIREMENT_SHOW ')) shows.push(JSON.parse(message.text().slice(16))); });
        try {
            await login(current);
            const history = await cdp.send('Page.getNavigationHistory'), entry = history.entries[history.currentIndex];
            await current.goto(origin + '/'); command('revoke');
            await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
            const mark = shows.length;
            await cdp.send('Page.navigateToHistoryEntry', { entryId: entry.id });
            await current.waitForURL(url => ['/profile', '/login'].includes(url.pathname), { waitUntil: 'commit' });
            await current.waitForLoadState('domcontentloaded');
            const snapshot = await cdp.send('DOMSnapshot.captureSnapshot', { computedStyles: ['display', 'visibility'] });
            for (const document of snapshot.documents) {
                const rendered = new Set(document.layout.nodeIndex);
                for (const [index, attributes] of document.nodes.attributes.entries()) {
                    if (attributes.some((value, offset) => offset % 2 === 0 && snapshot.strings[value] === 'data-spa-private')) {
                        assert.ok(!rendered.has(index), 'Private root must have no rendered layout while JavaScript is disabled');
                    }
                }
            }
            await cdp.send('Emulation.setScriptExecutionDisabled', { value: false });
            await current.waitForFunction(() => !document.querySelector('[data-account-page]'));
            const native = shows.slice(mark).some(event => event.persisted && event.trusted);
            if (!native) result.unverified.push({ gate: 'C pre-JavaScript native BFCache restoration', reason: 'No trusted persisted restoration; script-disabled fresh document does not prove a cached compositor frame' });
            return { result: native ? 'PASS' : 'UNVERIFIED', scriptsDisabledDuringInspection: true, renderedPrivateRootAbsentOrHidden: true, nativePersistedObserved: native, shows: shows.slice(mark), notRestored,
                boundary: 'CDP computed layout before re-enabling script; not proof for every compositor frame, browser or device' };
        } finally { await cdp.send('Emulation.setScriptExecutionDisabled', { value: false }).catch(() => {}); await isolated.close(); }
    });
    await runExistingAuthentication(harness);
}
