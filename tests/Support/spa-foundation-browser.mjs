import assert from 'node:assert/strict';
import path from 'node:path';

export async function runFoundationCases(harness) {
    const { browser, check, login, ready, visit, target, until, result, workspace, other, command, run } = harness;
    command('restore');
    command('restore-view');
    for (const destination of ['newer-workspace', 'current-route']) {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        context.on('response', response => {
            if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() });
        });
        const page = await context.newPage();
        page.on('dialog', dialog => dialog.accept());
        try {
            await login(page, 'spa');
            await check('shared shell restores an aborted navigation', 'spa', async () => {
                const state = await page.evaluate(async url => {
                    const remove = __goalsSpa.router.beforeResolve(route => route.query.block === '1' ? false : undefined);
                    try { await __goalsSpa.router.push(url + '?block=1'); }
                    finally { remove(); }
                    return __goalsSpa.router.currentRoute.value.fullPath;
                }, target('spa', workspace));
                assert.equal(state, target('spa', workspace));
                await ready(page, 'spa');
                return { currentRoute: state, boundary: 'Real Vue Router guard cancellation and authoritative data reload' };
            });
            await check('superseded network component failure preserves ' + destination, 'spa', async () => {
                let heldChunk;
                await page.route(/\/build\/goals-spa\/assets\/GoalsReview-[^/]+\.js/, route => { heldChunk = route; });
                await page.evaluate(url => {
                    window.__foundationNavigation = __goalsSpa.router.push(url + '/review')
                        .then(() => ({ ok: true }), error => ({ error: error.message }));
                }, target('spa', workspace));
                await until(() => heldChunk, 'Actual review module request must be held');
                const retained = destination === 'newer-workspace' ? other : workspace;
                await visit(page, 'spa', target('spa', retained));
                await heldChunk.abort('failed');
                const rejected = await page.evaluate(() => window.__foundationNavigation);
                assert.ok(rejected.error, 'The old module request must actually fail');
                await ready(page, 'spa');
                assert.equal(await page.evaluate(() => __goalsSpa.current.value.workspace.public_id), retained);
                assert.equal(await page.locator('[data-goals="form"]').isVisible(), true);
                return { retainedWorkspace: retained, actualModuleFailure: rejected.error, boundary: 'Browser network interception of the built component asset; no server or library replacement' };
            });
            await check('shared shell deep link, RTL and narrow viewport', 'spa', async () => {
                await page.reload();
                await ready(page, 'spa');
                await page.setViewportSize({ width: 390, height: 844 });
                assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                assert.equal(overflow, 0);
                await page.screenshot({ path: path.join(run, 'foundation-' + destination + '-mobile.png'), fullPage: true });
                return { viewport: { width: 390, height: 844 }, overflow, device: 'Desktop Chromium viewport; not real mobile evidence' };
            });
        } finally {
            await context.close();
        }
    }
}
