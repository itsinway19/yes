import assert from 'node:assert/strict';

// Browser tests only: use the installed Vue application and real HTTP owners.
export async function runFocusedAcceptance(harness) {
    const { browser, check, login, fixture, result, until, held, command } = harness;
    const wire = result.wire;
    const origin = 'http://localhost:8138';
    const a = fixture.a.workspaces[0].public_id, b = fixture.a.workspaces[1].public_id;
    const path = workspace => `/workspaces/${workspace}/goals`;
    const ready = (page, workspace = a) => page.waitForFunction(workspace =>
        document.getElementById('application-spa')?.dataset.state === 'ready'
        && document.querySelector(`[data-goals-workspace="${workspace}"] [data-goals="form"]`), workspace);
    const push = (page, destination) => page.evaluate(async destination => {
        try {
            const outcome = await document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(destination);
            return { rejected: false, navigationFailure: outcome?.type ?? null };
        } catch (error) { return { rejected: true, message: error.message }; }
    }, destination);
    async function open() {
        const context = await browser.newContext();
        context.on('page', page => {
            page.on('dialog', dialog => dialog.accept());
            page.on('pageerror', error => result.errors.push({ mode: 'focused', message: error.message }));
        });
        context.on('response', response => {
            if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 });
        });
        const page = await context.newPage();
        await login(page, 'spa'); await ready(page);
        assert.equal(await page.evaluate(() => typeof window.__goalsSpa), 'undefined');
        return { context, page };
    }
    const release = key => { if (held.has(key)) { held.get(key).deliver(); held.delete(key); } };

    if (process.env.FOCUSED_SPA_ACCEPTANCE_PART !== 'lease') {
    await check('focused rapid A to B to A cancels the actual competing private read', 'focused', async () => {
        const { context, page } = await open(), key = 'focused-rapid-b';
        let intercepted = 0;
        try {
            await page.route(`**/workspaces/${b}/goals/context`, async route => {
                if (!intercepted++) await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } });
                else await route.continue();
            });
            const pending = push(page, path(b)); await until(() => held.has(key));
            assert.equal(new URL(page.url()).pathname, path(a));
            await push(page, path(a)); await ready(page, a);
            release(key); await pending;
            assert.equal(new URL(page.url()).pathname, path(a));
            assert.equal(await page.locator(`[data-goals-workspace="${b}"]`).count(), 0);
            await ready(page, a);
            return { realHeldRead: true, actualDuplicatedReturnToA: true, lateBPublication: false, requestCount: intercepted };
        } finally { release(key); await context.close(); }
    });

    for (const superseded of [false, true]) {
        await check(`focused ${superseded ? 'superseded' : 'failed'} actual lazy module retains the admitted destination`, 'focused', async () => {
            const { context, page } = await open();
            let chunk, attempts = 0;
            try {
                await page.route(/\/build\/goals-spa\/assets\/GoalsReview-[^/]+\.js/, async route => {
                    if (route.request().resourceType() !== 'script') return route.continue();
                    attempts++; chunk = route;
                    if (!superseded) await route.abort('failed');
                });
                const navigation = push(page, path(a) + '/review');
                await until(() => chunk, 'The real lazy module must be requested');
                if (superseded) { await push(page, path(b)); await ready(page, b); await chunk.abort('failed'); }
                const failure = await navigation;
                assert.equal(failure.rejected, true);
                const admitted = superseded ? b : a;
                await ready(page, admitted);
                assert.equal(new URL(page.url()).pathname, path(admitted));
                assert.equal(attempts, 1, 'A failed lazy import must not automatically replay');
                assert.equal(await page.locator('[data-goals="form"]').isVisible(), true);
                return { actualNetworkModuleFailure: failure.message, superseded, admittedWorkspace: admitted, attempts, indefiniteChecking: false };
            } finally { if (chunk) await chunk.abort('failed').catch(() => {}); await context.close(); }
        });
    }

    await check('focused overlapping restoration events share one actual witness and retain a human draft', 'focused', async () => {
        const { context, page } = await open(), key = 'focused-overlapping-witness';
        let witnesses = 0;
        try {
            const choice = page.locator('[data-goals^="select-"]').first(), selector = await choice.getAttribute('data-goals');
            await choice.check();
            await page.route(`**/workspaces/${a}/goals/context`, async route => {
                if (route.request().headers()['x-goals-witness'] === '1') {
                    witnesses++;
                    await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } });
                } else await route.continue();
            });
            await page.evaluate(() => {
                dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
                document.dispatchEvent(new Event('visibilitychange'));
                dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            });
            await until(() => held.has(key));
            assert.equal(await page.locator('[data-spa-private]:visible').count(), 0);
            assert.equal(witnesses, 1);
            release(key); await ready(page);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(), true);
            assert.equal(witnesses, 1);
            return { syntheticLifecycleEvents: 3, actualHttpWitnesses: witnesses, hiddenUntilAdmitted: true, humanDraftRetained: true, nativeBFCacheClaim: false };
        } finally { release(key); await context.close(); }
    });

    }
    // Real wall time and the existing server lease: no virtual clock or product timer override.
    const { context, page } = await open(), readKey = 'focused-lease-route', renewKey = 'focused-lease-renew';
    let renewals = 0;
    try {
        await page.route('**/identity/renew', async route => {
            renewals++;
            await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': renewKey } });
        });
        const clock = await page.evaluate(() => ({ lease: JSON.parse(document.getElementById('application-bootstrap').textContent).identity.leaseMilliseconds, elapsed: performance.now() }));
        const wait = Math.max(0, clock.lease * 0.7 - clock.elapsed - 5000);
        console.log('FOCUSED_REAL_LEASE_WAIT ' + JSON.stringify({ lease: clock.lease, wait }));
        await page.waitForTimeout(wait);
        await page.route(`**/workspaces/${b}/goals/context`, route => route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': readKey } }));
        const pending = push(page, path(b));
        await until(() => held.has(readKey));
        await check('focused real document renewal proceeds while a route remains pending', 'focused', async () => {
            await until(() => held.has(renewKey), 'The original document lease must renew independently of the pending route');
            assert.equal(held.get(renewKey).row.status, 200);
            assert.equal(new URL(page.url()).pathname, path(a));
            assert.equal(renewals, 1);
            assert.ok(held.has(readKey));
            assert.equal(await page.locator('[data-spa="navigation"]').isVisible(), true);
            return { actualServerRenewal: 200, pendingRouteUncommitted: true, renewalCount: renewals, originalLeaseMilliseconds: clock.lease, realWaitMilliseconds: wait, clockOrTimeoutOverride: false };
        });
        await check('focused terminal closure suppresses the late renewal and overlapping restoration events', 'focused', async () => {
            command('revoke');
            await push(page, '/profile');
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
            release(renewKey); release(readKey); await pending;
            const mark = wire.length;
            await page.evaluate(() => {
                dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
                document.dispatchEvent(new Event('visibilitychange'));
                dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            });
            await page.waitForTimeout(150);
            assert.equal(await page.locator('#application-spa').getAttribute('data-state'), 'closed');
            assert.equal(await page.locator('[data-spa-private]:visible').count(), 0);
            assert.equal(renewals, 1);
            assert.equal(wire.slice(mark).filter(row => !row.path.startsWith('/build/')).length, 0);
            const sessions = command('account-inspect', 'a').sessions;
            assert.ok(sessions.length > 0 && sessions.every(session => session.revoked_at));
            return { canonicalRevocation: true, lateCommittedRenewalNotPublished: true, closedAfterOverlappingEvents: true, furtherRequests: 0, automaticReplay: false };
        });
    } finally { release(renewKey); release(readKey); await context.close(); }
}
