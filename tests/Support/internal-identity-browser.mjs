import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

export async function runAuthEntryCases({ browser, check, meta, get, post, command, fixture, result, run }) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('dialog', dialog => dialog.accept());
    context.on('page', current => current.on('pageerror', error => result.errors.push({ mode: 'internal', message: error.message, stack: error.stack, url: current.url() })));
    page.on('pageerror', error => result.errors.push({ mode: 'internal', message: error.message, stack: error.stack, url: page.url() }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() }); });
    const ready = selector => page.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
    const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
    let authenticator = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
    async function advertiser(who) {
        await page.goto('http://localhost:8138/login'); await ready('[data-auth-kind="login"]');
        await page.locator('#email').fill(`goals-${who}@example.test`); await page.locator('#password').fill('DisposableGoalsProof42!');
        await page.locator('[data-auth-entry] button[type=submit]').click(); await ready('[data-spa-private]:not([data-auth-entry])');
        await page.goto('http://localhost:8138/security/devices'); await ready('[data-account-kind="devices"]');
    }
    async function addDevice(name) {
        const stored = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/security/passkeys'));
        await page.locator('#account-key-name').fill(name); await page.locator('[data-account-passkey] button[type=submit]').click();
        assert.equal((await stored).status(), 201); await page.locator('[data-passkey-id]').filter({ hasText: name }).waitFor();
    }
    async function internalLogin(destination = '/internal/login') {
        await page.goto('http://localhost:8138' + destination); await ready('[data-auth-kind="login"]');
        const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/passkey'));
        await page.locator('[data-auth-passkey]').click(); assert.equal((await response).status(), 200);
        await page.waitForURL('http://localhost:8138/internal/security/devices'); await ready('[data-account-kind="devices"]');
    }
    async function raw(url, body, method = 'POST') {
        return page.evaluate(async ({ url, body, method }) => {
            const identity = JSON.parse(document.getElementById('application-bootstrap').textContent).identity;
            const response = await fetch(url, { method, redirect: 'manual', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': identity.csrf, 'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition }, body: body ? JSON.stringify(body) : undefined });
            return { status: response.status };
        }, { url, body, method });
    }
    try {
        await check('A internal guest has explicit boundary and no advertiser password form or private bootstrap', 'internal', async () => {
            await page.goto('http://localhost:8138/internal/login'); await ready('[data-auth-kind="login"]');
            const identity = await meta(page); assert.equal(identity.binding.boundary, 'internal'); assert.equal(identity.binding.principal, 0);
            assert.equal(await page.locator('#password,#email,[data-page]').count(), 0);
            assert.equal((await get(page, '/internal/login')).body.visibility.protected, false);
            await page.screenshot({ path: path.join(run, 'internal-login.png'), fullPage: true });
            return { guestBoundary: 'internal', passwordLoginAbsent: true, noPrivateBootstrap: true };
        });
        await check('A real internal passkey login uses a new document and does not reuse advertiser guard', 'internal', async () => {
            await advertiser('a'); await addDevice('Internal primary real key'); command('account-staff');
            await internalLogin(); assert.equal((await meta(page)).binding.principal, fixture.a.user);
            assert.equal((await meta(page)).binding.boundary, 'internal');
            assert.equal(await page.locator('[data-account-password]').count(), 0);
            const devices = await get(page, '/internal/security/devices'); assert.equal(devices.status, 200); assert.equal(devices.body.data.internalContext, true);
            for (const secret of ['credential', 'session_key_hash', 'password']) assert.equal(JSON.stringify(devices.body.data).includes(`"${secret}"`), false);
            assert.ok([403, 409].includes((await get(page, '/security/devices')).status));
            await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.screenshot({ path: path.join(run, 'internal-devices-mobile.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
            return { signedUV: true, boundary: 'internal', advertiserGuardNotReused: true, providerSecretsAbsent: true };
        });
        await check('A browser cancellation does not submit an internal identity switch', 'internal', async () => {
            await page.goto('http://localhost:8138/internal/identity/switch'); await ready('[data-auth-kind="login"]');
            await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId: authenticator, enabled: false });
            let deliveries = 0; const watch = request => { if (request.method() === 'POST' && request.url().endsWith('/internal/identity/passkey')) deliveries++; }; page.on('request', watch);
            await page.locator('[data-auth-passkey]').click(); await page.locator('[data-auth-cancel]').waitFor(); await page.locator('[data-auth-cancel]').click();
            await page.waitForFunction(() => !document.querySelector('[data-auth-cancel]'));
            page.off('request', watch); assert.equal(deliveries, 0);
            await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId: authenticator, enabled: true });
            await internalLogin('/internal/identity/switch');
            return { cancelledBeforeSubmission: true, deliveries: 0, explicitFreshCeremonySettles: true };
        });
        await check('A actual account switch drops an older internal resource and revokes only the selected owned session', 'internal', async () => {
            const original = (await cdp.send('WebAuthn.getCredentials', { authenticatorId: authenticator })).credentials[0];
            const otherContext = await browser.newContext(), otherPage = await otherContext.newPage(); otherPage.setDefaultTimeout(20000);
            otherPage.on('pageerror', error => result.errors.push({ mode: 'internal-switch', message: error.message }));
            const otherCdp = await otherContext.newCDPSession(otherPage); await otherCdp.send('WebAuthn.enable');
            const otherAuthenticator = (await otherCdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
            const otherReady = selector => otherPage.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
            let release, intercepted;
            const held = new Promise(resolve => { release = resolve; }), reached = new Promise(resolve => { intercepted = resolve; });
            try {
                await otherPage.goto('http://localhost:8138/login'); await otherReady('[data-auth-kind="login"]');
                await otherPage.locator('#email').fill('goals-b@example.test'); await otherPage.locator('#password').fill('DisposableGoalsProof42!');
                await otherPage.locator('[data-auth-entry] button[type=submit]').click(); await otherReady('[data-spa-private]:not([data-auth-entry])');
                await otherPage.goto('http://localhost:8138/security/devices'); await otherReady('[data-account-kind="devices"]');
                await otherPage.locator('#account-key-name').fill('Other internal identity key'); await otherPage.locator('[data-account-passkey] button[type=submit]').click();
                await otherPage.locator('[data-passkey-id]').filter({ hasText: 'Other internal identity key' }).waitFor(); command('account-staff', 'b');
                await otherPage.goto('http://localhost:8138/internal/login'); await otherReady('[data-auth-passkey]');
                await otherPage.locator('[data-auth-passkey]').click(); await otherReady('[data-account-kind="devices"]');
                const otherCredential = (await otherCdp.send('WebAuthn.getCredentials', { authenticatorId: otherAuthenticator })).credentials[0];
                const previous = await meta(page);
                await page.route('**/internal/security/devices', async route => {
                    if (route.request().headers().accept !== 'application/json') return route.continue();
                    const response = await route.fetch(); assert.equal(response.status(), 200); intercepted(); await held;
                    await route.fulfill({ response }).catch(() => {});
                });
                await page.locator('[data-account-review]').click(); await reached;
                await page.getByRole('banner').getByRole('link', { name: 'تبديل الهوية الداخلية', exact: true }).click(); await ready('[data-auth-passkey]');
                await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authenticator });
                authenticator = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
                await cdp.send('WebAuthn.addCredential', { authenticatorId: authenticator, credential: otherCredential });
                const switched = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/internal/identity/passkey'));
                await page.locator('[data-auth-passkey]').click(); assert.equal((await switched).status(), 200);
                await page.unroute('**/internal/security/devices'); await ready('[data-account-kind="devices"]'); release();
                const current = await meta(page); assert.equal(current.binding.principal, fixture.b.user); assert.equal(current.binding.epoch, previous.binding.epoch + 1);
                assert.notEqual(current.binding.generation, previous.binding.generation); assert.equal(await page.getByText('Internal primary real key', { exact: false }).count(), 0);
                await page.goBack(); await page.waitForFunction(() => ['ready', 'closed', 'unavailable'].includes(document.getElementById('application-spa')?.dataset.state));
                const backState = await page.locator('#application-spa').getAttribute('data-state');
                if (backState === 'ready') assert.equal((await meta(page)).binding.principal, fixture.b.user);
                assert.equal(await page.getByText('Internal primary real key', { exact: false }).isVisible(), false);
                await page.goForward(); await page.waitForFunction(() => ['ready', 'closed', 'unavailable'].includes(document.getElementById('application-spa')?.dataset.state));
                if (await page.locator('#application-spa').getAttribute('data-state') !== 'ready') await page.reload();
                await ready('[data-account-kind="devices"]'); assert.equal((await meta(page)).binding.principal, fixture.b.user);
                let deliveries = 0;
                await page.route('**/internal/security/sessions/*', async route => { if (route.request().method() !== 'DELETE') return route.continue(); deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
                await page.locator('[data-account-revoke]').click(); await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor();
                assert.equal(deliveries, 1); assert.equal(command('account-inspect', 'b').sessions.filter(session => session.auth_method === 'internal_passkey' && !session.revoked_at).length, 1);
                assert.ok([401, 409].includes((await get(otherPage, '/internal/security/devices')).status));
                await page.unroute('**/internal/security/sessions/*'); await page.locator('[data-account-review]').click(); await ready('[data-account-kind="devices"]');
                await page.waitForFunction(() => !document.querySelector('[data-account-revoke]')); assert.equal(deliveries, 1);
                await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authenticator });
                authenticator = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
                await cdp.send('WebAuthn.addCredential', { authenticatorId: authenticator, credential: original }); await internalLogin('/internal/identity/switch');
                assert.equal((await meta(page)).binding.principal, fixture.a.user);
                return { actualSwitch: 'A to B to A', oldReadNotPublished: true, selectedSessionRevoked: true, unknownDeleteDeliveredOnce: deliveries, foreignPrincipalAbsent: true };
            } finally { release(); await page.unroute('**/internal/security/devices'); await page.unroute('**/internal/security/sessions/*'); await otherContext.close(); }
        });
        await check('C protected employee factor, freshness, locale and committed unknown registration retain server policy', 'internal', async () => {
            const firstKey = command('account-inspect').keys[0].id;
            assert.equal((await raw('/internal/security/passkeys/' + firstKey, null, 'DELETE')).status, 422);
            command('account-age-auth'); assert.equal((await get(page, '/internal/security/passkeys/options')).status, 403);
            await internalLogin('/internal/identity/switch');
            await page.locator('[data-application=locale]').click(); await page.waitForFunction(() => document.documentElement.lang === 'en');
            await page.locator('[data-application=locale]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar');
            await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authenticator });
            authenticator = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
            let deliveries = 0;
            await page.route('**/internal/security/passkeys', async route => { if (route.request().method() !== 'POST') return route.continue(); deliveries++; const response = await route.fetch(); assert.equal(response.status(), 201); await route.abort('failed'); });
            await page.locator('#account-key-name').fill('Unknown committed internal backup'); await page.locator('[data-account-passkey] button[type=submit]').click();
            await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor(); assert.equal(deliveries, 1); assert.equal(command('account-inspect').keys.length, 2);
            assert.equal(await page.locator('[data-account-passkey] button[type=submit]').isDisabled(), true);
            await page.unroute('**/internal/security/passkeys'); await page.locator('[data-account-review]').click(); await page.locator('[data-passkey-id]').filter({ hasText: 'Unknown committed internal backup' }).waitFor(); assert.equal(deliveries, 1);
            command('internal-founder-actor');
            assert.equal((await raw('/internal/security/passkeys/' + firstKey, null, 'DELETE')).status, 422);
            return { employeeMinimum: 422, founderMinimum: 422, staleFreshness: 403, actualLocale: true, committedOnce: deliveries, noAutomaticReplay: true, founderIndependenceFixture: 'synthetic operator attestation; not physical-factor acceptance' };
        });
        for (const who of ['a', 'b']) await check(`B pending ${who === 'a' ? 'employee' : 'founder'} email and enrollment do not grant internal authority`, 'internal', async () => {
            const pending = command('internal-pending', who);
            const enrollmentContext = await browser.newContext(), current = await enrollmentContext.newPage(); current.setDefaultTimeout(20000);
            current.on('pageerror', error => result.errors.push({ mode: 'enrollment', message: error.message }));
            enrollmentContext.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
            const enrolled = () => current.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-staff-enrollment]'));
            try {
                await current.goto('http://localhost:8138/login'); await current.waitForFunction(() => document.querySelector('[data-auth-kind="login"]'));
                await current.locator('#email').fill(pending.email); await current.locator('#password').fill('DisposableGoalsProof42!'); await current.locator('[data-auth-entry] button[type=submit]').click(); await enrolled();
                assert.equal((await meta(current)).binding.boundary, 'advertiser'); assert.equal(command('internal-status', who).sessions.at(-1).auth_method, 'staff_enrollment');
                assert.equal((await get(current, '/staff/onboarding/passkeys/options')).status, 403);
                await current.goto('http://localhost:8138/verify-email'); await current.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-resend]')); await current.locator('[data-auth-resend]').click(); await current.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-resend]'));
                const mailbox = (await readFile(path.join(run, 'mailbox.jsonl'), 'utf8')).trim().split('\n').map(row => JSON.parse(row));
                const message = mailbox.filter(message => message.to.includes(pending.email) && message.html.includes('/verify-email/')).at(-1); assert.ok(message);
                const link = message.html.match(/href="([^"]*\/verify-email\/[^\"]+)"/)[1].replaceAll('&amp;', '&'); await current.goto(link); await enrolled();
                const client = await enrollmentContext.newCDPSession(current); await client.send('WebAuthn.enable'); const enrollmentAuthenticator = (await client.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
                await client.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId: enrollmentAuthenticator, enabled: false });
                await current.locator('#enrollment-key-name').fill('Cancelled enrollment');
                await current.locator('[data-staff-enrollment] button[type=submit]').click(); await current.locator('[data-enrollment-cancel]').click();
                await current.waitForFunction(() => !document.querySelector('[data-enrollment-cancel]'));
                assert.equal(command('internal-status', who).keys.length, 0);
                await client.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId: enrollmentAuthenticator, enabled: true });
                const stored = current.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/staff/onboarding/passkeys');
                await current.locator('#enrollment-key-name').fill('Pending real UV key'); await current.locator('[data-staff-enrollment] button[type=submit]').click(); assert.equal((await stored).status(), 201); await current.locator('[data-enrollment-key]').waitFor();
                if (who === 'b') {
                    await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: enrollmentAuthenticator });
                    await client.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
                    const second = current.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/staff/onboarding/passkeys');
                    await current.locator('#enrollment-key-name').fill('Pending founder second UV key'); await current.locator('[data-staff-enrollment] button[type=submit]').click(); assert.equal((await second).status(), 201);
                    await current.locator('[data-enrollment-key]').filter({ hasText: 'Pending founder second UV key' }).waitFor();
                    assert.equal(command('internal-status', who).keys.length, 2); assert.equal(command('internal-status', who).personnel.factor_independence_attested_at, null);
                }
                assert.equal(command('internal-status', who).lifecycle.status, 'pending');
                const beforeResume = command('internal-status', who).sessions.at(-1);
                const passive = current.waitForResponse(response => new URL(response.url()).pathname === '/staff/onboarding' && response.request().headers()['x-builderad-passive'] === '1');
                await current.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); assert.equal((await passive).status(), 200); await enrolled();
                const afterResume = command('internal-status', who).sessions.at(-1);
                assert.equal(afterResume.idle_expires_at, beforeResume.idle_expires_at); assert.equal(afterResume.last_seen_at, beforeResume.last_seen_at);
                await current.screenshot({ path: path.join(run, `staff-${who === 'a' ? 'employee' : 'founder'}-enrollment.png`), fullPage: true });
                const target = '/internal/staff/' + pending.public_id;
                assert.equal((await raw(target + '/activate')).status, 403);
                if (who === 'a') {
                    assert.equal((await raw(target + '/attest')).status, 0); assert.equal((await raw(target + '/activate')).status, 0);
                    assert.equal(command('internal-status', who).lifecycle.status, 'current');
                    await current.goto('http://localhost:8138/staff/onboarding'); assert.ok(!current.url().endsWith('/internal/security/devices'));
                } else {
                    assert.equal((await raw(target + '/attest')).status, 403); assert.equal(command('internal-status', who).lifecycle.status, 'pending');
                }
                assert.ok([401, 409].includes((await get(current, '/internal/security/devices')).status));
                return { realMailSignature: true, realSignedUVEnrollment: true, boundary: 'advertiser', thirdGuard: false, pendingDoesNotGrantInternalSession: true, employeeActivation: who === 'a' ? 'actual existing attest/activate HTTP command' : 'founder not eligible for ordinary employee activation' };
            } finally { await enrollmentContext.close(); }
        });
        await check('C CSRF expiry closes presentation and expired internal sessions cannot republish private state', 'internal', async () => {
            await page.goto('http://localhost:8138/internal/security/devices'); await ready('[data-account-kind="devices"]');
            await page.route('**/internal/security/passkeys/*', async route => {
                const headers = Object.fromEntries(Object.entries(await route.request().allHeaders()).filter(([key]) => !['sec-fetch-site', 'x-csrf-token'].includes(key.toLowerCase())));
                const response = await route.fetch({ headers }); assert.equal(response.status(), 419); await route.fulfill({ response });
            });
            await page.locator('[data-account-delete-key]').first().click();
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
            assert.equal(await page.locator('[data-account-page]').isVisible(), false);
            await page.unroute('**/internal/security/passkeys/*');
            await page.goto('http://localhost:8138/internal/security/devices'); await ready('[data-account-kind="devices"]');
            command('expire'); await page.locator('[data-account-review]').click();
            await page.waitForFunction(() => ['closed', 'unavailable'].includes(document.getElementById('application-spa')?.dataset.state));
            assert.equal(await page.locator('[data-account-page]').isVisible(), false);
            await page.goto('http://localhost:8138/internal/login'); await ready('[data-auth-passkey]');
            await page.locator('[data-auth-passkey]').click(); await page.waitForURL('http://localhost:8138/internal/administration');
            await page.goto('http://localhost:8138/internal/security/devices'); await ready('[data-account-kind="devices"]');
            return { csrfRejected: 419, expiredInternalSessionClosed: true, explicitReauthentication: true };
        });
        await check('C internal logout performs the original terminal transition to a new internal guest document', 'internal', async () => {
            await page.goto('http://localhost:8138/internal/security/devices'); await ready('[data-account-kind="devices"]');
            await page.locator('[data-spa=logout]').click(); await page.waitForURL('http://localhost:8138/internal/login'); await ready('[data-auth-kind="login"]');
            assert.equal((await meta(page)).binding.principal, 0); assert.equal((await meta(page)).binding.boundary, 'internal');
            return { terminalDestination: '/internal/login', newGuestDocument: true };
        });
    } finally { await page.unrouteAll({ behavior: 'ignoreErrors' }); await context.close(); }
}
