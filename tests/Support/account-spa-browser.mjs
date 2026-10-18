import assert from 'node:assert/strict';
import path from 'node:path';
import { readFile } from 'node:fs/promises';
import Ajv from 'ajv/dist/2020.js';

export async function runAccountCases({ browser, check, login, meta, get, post, command, fixture, result, run, until, held }) {
    const stages = (process.env.ACCOUNT_SPA_STAGE ?? 'all').split(',');
    assert.ok(stages.every(stage => ['all', 'core', 'email', 'internal'].includes(stage)));
    const runCheck = check;
    check = (name, mode, execute) => {
        const group = name.startsWith('internal legacy') ? 'internal' : name.includes('email') ? 'email' : 'core';
        return stages.includes('all') || stages.includes(group) ? runCheck(name, mode, execute) : Promise.resolve();
    };
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.setDefaultTimeout(15000); page.on('dialog', dialog => dialog.accept());
    page.on('pageerror', error => result.errors.push({ mode: 'account', message: error.message }));
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const schema = name => new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: `#/components/schemas/${name}` });
    const profileSchema = schema('AccountProfileResource'), devicesSchema = schema('AccountDevicesResource');
    const ready = async (current = page, kind) => current.waitForFunction(kind => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(`[data-account-kind="${kind}"]`), kind);
    const visit = async (url, kind, current = page) => { await current.evaluate(url => __goalsSpa.router.push(url), url); await ready(current, kind); };
    async function raw(current, url, body, method = 'POST') {
        return current.evaluate(async ({ url, body, method }) => {
            const identity = JSON.parse((document.getElementById('application-bootstrap')?.textContent ?? document.querySelector('meta[name="identity-context"]').content)).identity;
            const actual = identity ?? JSON.parse(document.querySelector('meta[name="identity-context"]').content);
            const response = await fetch(url, { method, redirect: 'manual', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': actual.csrf, 'X-Builderad-Document': actual.receipt, 'X-Builderad-Transition': actual.transition }, body: body ? JSON.stringify(body) : undefined });
            const text = await response.text(); let value; try { value = JSON.parse(text); } catch { value = text; }
            return { status: response.status, body: value };
        }, { url, body, method });
    }
    const client = await context.newCDPSession(page); await client.send('WebAuthn.enable');
    async function authenticator(cdp) { return (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId; }
    let authenticatorId = await authenticator(client);
    let registration;
    async function observeClosure(current) {
        let closed = false;
        await current.exposeFunction('accountCloseProof', value => { closed = value; });
        await current.evaluate(() => {
            const root = document.getElementById('application-spa');
            new MutationObserver(() => {
                if (root.dataset.state === 'closed') window.accountCloseProof([...document.querySelectorAll('[data-spa-private]')].every(node => getComputedStyle(node).display === 'none' || node.getClientRects().length === 0));
            }).observe(root, { attributes: true, attributeFilter: ['data-state'] });
        });
        return () => closed;
    }
    const original = 'DisposableGoalsProof42!', changed = 'ChangedDisposableAccount42!';
    try {
        await login(page, 'application');
        await check('account canonical shell, shared navigation, locale, private shapes and mobile RTL', 'account', async () => {
            const identity = await meta(page); await page.getByRole('banner').getByRole('link', { name: 'الحساب', exact: true }).click(); await ready(page, 'profile');
            assert.equal((await meta(page)).binding.generation, identity.binding.generation);
            const profile = await get(page, '/profile'); assert.equal(profile.status, 200); assert.ok(profileSchema(profile.body), JSON.stringify(profileSchema.errors));
            assert.equal(await page.locator('[data-page]').count(), 0);
            await page.locator('[data-application=locale]').click(); await page.waitForFunction(() => document.documentElement.lang === 'en');
            await page.getByRole('banner').getByRole('link', { name: 'Security', exact: true }).click(); await ready(page, 'devices');
            const devices = await get(page, '/security/devices'); assert.ok(devicesSchema(devices.body), JSON.stringify(devicesSchema.errors));
            for (const key of ['credential', 'session_key_hash', 'password']) assert.equal(JSON.stringify(devices.body.data).includes(`"${key}"`), false);
            await page.locator('[data-application=locale]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar');
            await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.screenshot({ path: path.join(run, 'account-security-mobile.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
            return { sameDocument: true, schemas: true, credentialsAbsent: true, mobileOverflow: 0 };
        });
        await check('profile name validation and committed lost acknowledgement never repeat a write', 'account', async () => {
            await visit('/profile', 'profile'); assert.equal((await raw(page, '/profile', { name: '', email: 'goals-a@example.test' }, 'PATCH')).status, 422);
            let count = 0;
            await page.route('**/profile', async route => {
                if (route.request().method() !== 'PATCH') return route.continue(); count++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed');
            });
            await page.locator('#account-name').fill('Account proof renamed'); await page.locator('[data-account-profile] button').click();
            await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor(); assert.equal(count, 1);
            await page.locator('[data-account-review]').click(); await page.getByText('هذه آخر حالة معتمدة', { exact: false }).waitFor();
            assert.equal((await get(page, '/profile')).body.data.user.name, 'Account proof renamed'); assert.equal(count, 1); await page.unroute('**/profile');
            return { actualCommit: true, deliveries: count, automaticReplay: false };
        });
        await check('actual Chromium UV resident passkey registration, server storage and consumed challenge rejection', 'account', async () => {
            await visit('/security/devices', 'devices');
            const stored = page.waitForResponse(reply => new URL(reply.url()).pathname === '/security/passkeys' && reply.request().method() === 'POST');
            await page.locator('#account-key-name').fill('Virtual account key'); await page.locator('[data-account-passkey] button[type=submit]').click();
            const response = await stored; assert.equal(response.status(), 201, await response.text()); registration = response.request().postDataJSON();
            await page.locator('[data-passkey-id]').waitFor(); assert.equal((await raw(page, '/security/passkeys', registration)).status, 422);
            const state = command('account-inspect'); assert.equal(state.keys.length, 1); assert.ok(state.audit.includes('passkey_registered'));
            return { virtualAuthenticator: true, userVerification: true, realVerifier: true, replay: 422, audit: true };
        });
        await check('passkey ownership and freshness are enforced by Laravel, not the client', 'account', async () => {
            const foreign = await browser.newContext(), outsider = await foreign.newPage();
            try {
                await login(outsider, 'application', 'b'); const id = command('account-inspect').keys[0].id;
                assert.equal((await raw(outsider, '/security/passkeys/' + id, null, 'DELETE')).status, 404);
            } finally { await foreign.close(); }
            command('account-age-auth'); await page.bringToFront();
            assert.equal((await get(page, '/security/passkeys/options')).status, 403);
            assert.equal((await raw(page, '/security/passkeys/' + command('account-inspect').keys[0].id, null, 'DELETE')).status, 403);
            assert.equal((await raw(page, '/security/sessions/revoke-others')).status, 403);
            await page.goto('http://localhost:8138/confirm-password');
            const confirmed = await raw(page, '/confirm-password', { password: original }); assert.ok([0, 302].includes(confirmed.status));
            await page.goto('http://localhost:8138/security/devices'); await ready(page, 'devices'); assert.equal((await get(page, '/security/passkeys/options')).status, 200);
            return { foreignKey: 404, staleFreshness: 403, nativePasswordReconfirmation: true };
        });
        await check('user cancellation before WebAuthn completion does not submit or start another ceremony', 'account', async () => {
            await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }); authenticatorId = await authenticator(client);
            await client.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: false }); let submitted = 0;
            const observe = request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/security/passkeys') submitted++; }; page.on('request', observe);
            await page.locator('#account-key-name').fill('Cancelled key'); await page.locator('[data-account-passkey] button[type=submit]').click();
            await page.locator('[data-account-cancel]').click(); await page.waitForFunction(() => !document.querySelector('[data-account-cancel]'));
            assert.equal(submitted, 0); assert.equal(command('account-inspect').keys.length, 1); page.off('request', observe);
            await client.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: true }); return { submitted: 0, noAutomaticRestart: true };
        });
        await check('committed registration with lost HTTP 201 is reconciled without a second registration', 'account', async () => {
            let count = 0;
            await page.route('**/security/passkeys', async route => { count++; const response = await route.fetch(); assert.equal(response.status(), 201); await route.abort('failed'); });
            await page.locator('#account-key-name').fill('Unknown acknowledgement key'); await page.locator('[data-account-passkey] button[type=submit]').click();
            await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor(); assert.equal(count, 1);
            await page.locator('[data-account-review]').click(); await page.locator('[data-passkey-id]').nth(1).waitFor(); assert.equal(count, 1);
            await page.unroute('**/security/passkeys');
            const removed = page.waitForResponse(reply => reply.request().method() === 'DELETE' && new URL(reply.url()).pathname.startsWith('/security/passkeys/'));
            await page.locator('[data-account-delete-key]').first().click(); assert.equal((await removed).status(), 200); await page.waitForFunction(() => document.querySelectorAll('[data-passkey-id]').length === 1);
            return { deliveries: 1, registrationReplayed: false, authoritativeReconciliation: true, delete: 200 };
        });
        await check('a genuine signed browser credential with an expired server challenge is rejected', 'account', async () => {
            await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }); authenticatorId = await authenticator(client);
            const options = await get(page, '/security/passkeys/options'); assert.equal(options.status, 200);
            const credential = await page.evaluate(async options => (await navigator.credentials.create({ publicKey: PublicKeyCredential.parseCreationOptionsFromJSON(options) })).toJSON(), options.body.options);
            await new Promise(resolve => setTimeout(resolve, 61000));
            const response = await raw(page, '/security/passkeys', { name: 'Expired real challenge', credential }); assert.equal(response.status, 422);
            assert.equal((await get(page, '/security/passkeys/options')).status, 200);
            const invalid = structuredClone(registration); invalid.credential.response.clientDataJSON = 'invalid'; assert.equal((await raw(page, '/security/passkeys', invalid)).status, 422);
            assert.equal(command('account-inspect').keys.length, 1); return { realWaitMs: 61000, expired: 422, invalid: 422, freshChallengeForInvalid: true, noStoredKey: true };
        });
        await check('selected-session and other-session revocation close sibling documents without ending the current session', 'account', async () => {
            const siblingContext = await browser.newContext(), sibling = await siblingContext.newPage();
            try {
                await login(sibling, 'application'); await visit('/security/devices', 'devices', sibling);
                const siblingId = (await get(sibling, '/security/devices')).body.data.sessions.find(value => value.current).id;
                await page.bringToFront(); await page.locator('[data-account-review]').click(); let deliveries = 0;
                await page.route(`**/security/sessions/${siblingId}`, async route => { deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
                await page.locator(`[data-session-id="${siblingId}"] [data-account-revoke]`).click();
                await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor(); assert.equal(deliveries, 1);
                await page.locator('[data-account-review]').click(); await page.unroute(`**/security/sessions/${siblingId}`);
                await page.waitForFunction(id => !document.querySelector(`[data-session-id="${id}"]`), siblingId);
                await sibling.evaluate(() => __goalsSpa.resume()); await sibling.waitForFunction(() => __goalsSpa.status.value === 'closed');
                await page.locator('[data-account-revoke-others]').click(); await page.waitForFunction(() => document.querySelectorAll('[data-session-id]').length === 1);
                assert.equal((await get(page, '/security/devices')).status, 200);
                return { selectedRevoked: true, siblingClosed: true, currentRetained: true, committedLostAckDeliveries: deliveries };
            } finally { await siblingContext.close(); }
        });
        await check('password validation and committed unknown acknowledgement preserve the current session and never replay secrets', 'account', async () => {
            const passwordForm = page.locator('[data-account-password]');
            await page.locator('#account-current_password').fill('incorrect'); await page.locator('#account-password').fill(changed); await page.locator('#account-password_confirmation').fill(changed); await passwordForm.locator('button').click();
            await page.locator('[role=alert]').first().waitFor(); let count = 0;
            await page.route('**/password', async route => { if (route.request().method() !== 'PUT') return route.continue(); count++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
            await page.locator('#account-current_password').fill(original); await page.locator('#account-password').fill(changed); await page.locator('#account-password_confirmation').fill(changed); await passwordForm.locator('button').click();
            await page.getByText('نتيجة الطلب غير مؤكدة.', { exact: false }).waitFor(); assert.equal(count, 1);
            for (const input of await passwordForm.locator('input').all()) assert.equal(await input.inputValue(), '');
            assert.equal((await get(page, '/security/devices')).status, 200); await page.unroute('**/password');
            await page.goto('http://localhost:8138/confirm-password'); assert.ok([0, 302].includes((await raw(page, '/confirm-password', { password: changed })).status));
            await page.goto('http://localhost:8138/security/devices'); await ready(page, 'devices');
            await page.locator('#account-current_password').fill(changed); await page.locator('#account-password').fill(original); await page.locator('#account-password_confirmation').fill(original);
            const saved = page.waitForResponse(reply => reply.request().method() === 'PUT' && new URL(reply.url()).pathname === '/password'); await page.locator('[data-account-password] button').click(); assert.equal((await saved).status(), 200);
            return { currentRetained: true, unknownDeliveries: count, secretsCleared: true, newPasswordConfirmedByLaravel: true };
        });
        for (const mode of ['expire', 'revoke']) await check(`account ${mode} hides the identity and blocks old effects`, 'account', async () => {
            const isolated = await browser.newContext(), current = await isolated.newPage();
            try {
                await login(current, 'application'); await visit('/profile', 'profile', current); command(mode);
                await current.evaluate(() => __goalsSpa.resume()); await current.waitForFunction(() => __goalsSpa.status.value === 'closed');
                assert.equal(await current.locator('[data-account-page]').count(), 0);
                assert.ok([401, 409].includes((await raw(current, '/profile', { name: 'Old identity', email: 'goals-a@example.test' }, 'PATCH')).status));
                return { closed: true, oldEffectsDenied: true };
            } finally { await isolated.close(); }
        });
        if (stages.includes('all') || stages.includes('core')) { await login(page, 'application'); await visit('/profile', 'profile'); }
        await check('account real fallback CSRF 419 closes the SPA without changing server authority', 'account', async () => {
            const isolated = await browser.newContext(), current = await isolated.newPage();
            try {
                await login(current, 'application'); await visit('/profile', 'profile', current);
                await current.route('**/profile', async route => {
                    if (route.request().method() !== 'PATCH') return route.continue();
                    const headers = Object.fromEntries(Object.entries(await route.request().allHeaders()).filter(([key]) => !['sec-fetch-site', 'x-csrf-token'].includes(key.toLowerCase())));
                    const response = await isolated.request.fetch(route.request().url(), { method: 'PATCH', headers: { ...headers, 'X-CSRF-TOKEN': 'invalid' }, data: route.request().postData(), maxRedirects: 0 });
                    assert.equal(response.status(), 419); await route.fulfill({ response });
                });
                await current.locator('#account-name').fill('CSRF denied'); await current.locator('[data-account-profile] button').click();
                await current.waitForFunction(() => __goalsSpa.status.value === 'closed'); assert.equal(await current.locator('[data-account-page]').count(), 0);
                return { realLaravelStatus: 419, closed: true };
            } finally { await isolated.close(); }
        });
        await check('lost current-device logout acknowledgement seals only its own document without replay', 'account', async () => {
            const isolated = await browser.newContext(), current = await isolated.newPage();
            try {
                await login(current, 'application'); await visit('/security/devices', 'devices', current); let deliveries = 0;
                await current.route('**/security/sessions/sign-out-this-device', async route => { deliveries++; const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
                await current.locator('[data-account-logout]').click(); await current.waitForFunction(() => __goalsSpa.status.value === 'closed');
                assert.equal(await current.locator('[data-account-page]').count(), 0); assert.equal(deliveries, 1);
                assert.equal((await get(page, '/profile')).status, 200);
                return { closed: true, deliveries: 1, otherDocumentRetained: true, automaticReplay: false };
            } finally { await isolated.close(); }
        });
        await check('late account response after cross-tab identity switch cannot republish the old profile', 'account', async () => {
            await visit('/profile', 'profile'); const key = 'account-late-switch'; let first = true;
            await page.route('**/profile', async route => {
                if (first && route.request().headers().accept === 'application/json') { first = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); } else await route.continue();
            });
            await page.locator('[data-account-review]').click({ noWaitAfter: true }); await until(() => held.has(key));
            const switcher = await context.newPage(); await switcher.goto('http://localhost:8138/profile'); await ready(switcher, 'profile');
            assert.equal((await post(switcher, '/identity/switch', { email: 'goals-b@example.test', password: original })).status, 200);
            await page.evaluate(() => __goalsSpa.resume()); await page.waitForFunction(() => __goalsSpa.status.value === 'closed'); held.get(key).deliver(); held.delete(key);
            assert.equal(await page.locator('[data-account-page]').count(), 0); await switcher.close(); await page.unroute('**/profile');
            return { oldClosed: true, latePublication: false };
        });
        await check('current-device logout uses the existing terminal endpoint and a full native departure', 'account', async () => {
            await page.goto('http://localhost:8138/security/devices'); await ready(page, 'devices');
            const response = page.waitForResponse(reply => new URL(reply.url()).pathname === '/security/sessions/sign-out-this-device');
            await page.locator('[data-account-logout]').click(); assert.equal((await response).status(), 200); await page.waitForURL('http://localhost:8138/');
            assert.equal(await page.locator('[data-account-page]').count(), 0); return { endpoint: true, terminal: true, nativeDeparture: true };
        });
        await check('native email rejection retains validation; successful email revokes identity before departure and Back cannot restore it', 'account', async () => {
            if ((await meta(page)).binding.principal !== fixture.a.user) await login(page, 'application'); await visit('/profile', 'profile');
            await page.locator('#account-email').fill('goals-b@example.test'); await page.locator('[data-account-profile] button').click();
            await ready(page, 'profile'); await page.locator('[data-account-profile] [role=alert]').waitFor(); assert.equal(command('account-inspect').email, 'goals-a@example.test');
            await page.locator('#account-email').fill('changed-account@example.test'); const closedBeforeDispatch = await observeClosure(page);
            await page.route('**/profile', async route => {
                if (route.request().method() === 'POST') await until(closedBeforeDispatch, 'native email must close private display before dispatch');
                await route.continue();
            });
            await page.locator('[data-account-profile] button').click(); await page.waitForURL('http://localhost:8138/login'); assert.equal(closedBeforeDispatch(), true);
            await page.unroute('**/profile');
            const state = command('account-inspect'); assert.equal(state.email, 'changed-account@example.test'); assert.ok(state.sessions.every(value => value.revoked_at !== null));
            await page.goBack(); await page.waitForFunction(() => location.pathname === '/login' || document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await page.locator('[data-account-page]').count(), 0);
            await page.goForward(); await page.waitForFunction(() => location.pathname === '/login' || document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await page.locator('[data-account-page]').count(), 0);
            return { serverRevocation: true, closedBeforeDispatch: closedBeforeDispatch(), nativeLogin: true, backFreshDocumentDenied: true, preJavaScriptFrameConfidentiality: 'UNVERIFIED' };
        });
        await check('email committed with lost native acknowledgement stays closed and never automatically repeats', 'account', async () => {
            command('account-email-actor'); const isolated = await browser.newContext(), current = await isolated.newPage();
            try {
                await current.goto('http://localhost:8138/login'); assert.equal((await post(current, '/login', { email: 'email-ack@example.test', password: original })).status, 200);
                await current.goto('http://localhost:8138/profile'); await ready(current, 'profile'); let deliveries = 0; const closed = await observeClosure(current);
                await current.route('**/profile', async route => {
                    if (route.request().method() !== 'POST') return route.continue(); deliveries++;
                    await until(closed, 'unknown native email must close private display before dispatch');
                    const response = await route.fetch({ maxRedirects: 0 }); assert.equal(response.status(), 302); await route.abort('failed');
                });
                await current.locator('#account-email').fill('email-ack-changed@example.test'); await current.locator('[data-account-profile] button').click();
                await current.waitForFunction(() => !document.getElementById('application-spa'));
                const state = command('account-inspect', 'email'); assert.equal(state.email, 'email-ack-changed@example.test'); assert.ok(state.sessions.every(value => value.revoked_at !== null));
                await current.goto('http://localhost:8138/profile'); await current.waitForURL('http://localhost:8138/login'); assert.equal(deliveries, 1);
                return { actualCommit: true, deliveries: 1, nativeReauthentication: true, automaticReplay: false };
            } finally { await isolated.close(); }
        });
        await check('internal legacy devices, real passkey login/enrollment, factor minimum and freshness remain independent', 'account', async () => {
            const staffContext = await browser.newContext(), staff = await staffContext.newPage(); staff.setDefaultTimeout(15000);
            const cdp = await staffContext.newCDPSession(staff); await cdp.send('WebAuthn.enable'); let key = await authenticator(cdp);
            try {
                await login(staff, 'application', 'b'); await visit('/security/devices', 'devices', staff);
                await staff.locator('#account-key-name').fill('Internal original key');
                const enrolled = staff.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === '/security/passkeys');
                await staff.locator('[data-account-passkey] button[type=submit]').click(); assert.equal((await enrolled).status(), 201);
                command('account-staff', 'b'); await staff.goto('http://localhost:8138/internal/login');
                const options = await get(staff, '/internal/auth/passkey/options'); assert.equal(options.status, 200);
                const credential = await staff.evaluate(async options => (await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options) })).toJSON(), options.body.options);
                assert.equal((await raw(staff, '/internal/auth/passkey', { credential })).status, 200);
                await staff.goto('http://localhost:8138/internal/security/devices');
                await staff.waitForFunction(() => document.querySelector('[data-page]') && !document.getElementById('application-spa'));
                await staff.waitForFunction(() => document.documentElement.dataset.visibility === 'ready');
                const initial = command('account-inspect', 'b'); assert.equal(initial.keys.length, 1);
                await staff.getByRole('button', { name: 'حذف المفتاح', exact: true }).click();
                try { await staff.getByText('تعذّر حذف مفتاح المرور.', { exact: true }).waitFor(); }
                catch (error) { result.internalFailure = await staff.evaluate(() => ({ visibility: document.documentElement.dataset.visibility, text: document.body.innerText, page: document.querySelector('[data-page]')?.getAttribute('data-page') })); throw error; }
                assert.equal(command('account-inspect', 'b').keys.length, 1);
                await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: key }); key = await authenticator(cdp);
                const inputs = staff.locator('input[type=text]'); await inputs.last().fill('Internal second real key');
                const added = staff.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === '/internal/security/passkeys');
                await staff.getByRole('button', { name: 'إضافة مفتاح مرور', exact: true }).click(); assert.equal((await added).status(), 201);
                await staff.getByText('Internal second real key', { exact: true }).waitFor(); assert.equal(command('account-inspect', 'b').keys.length, 2);
                await staff.getByRole('button', { name: 'حذف المفتاح', exact: true }).first().click();
                await staff.waitForFunction(() => [...document.querySelectorAll('button')].filter(button => button.textContent.trim() === 'حذف المفتاح').length === 1);
                command('account-age-auth', 'b');
                assert.equal((await get(staff, '/internal/security/passkeys/options')).status, 403);
                assert.equal((await raw(staff, '/internal/security/passkeys/' + command('account-inspect', 'b').keys[0].id, null, 'DELETE')).status, 403);
                assert.equal((await raw(staff, '/internal/security/sessions/revoke-others')).status, 403);
                return { legacyInertia: true, realSignedLogin: true, realRegistration: 201, employeeMinimum: true, freshAuthentication: 403, noAdvertiserGuardReuse: true };
            } finally { await staffContext.close(); }
        });
    } finally { await page.unrouteAll({ behavior: 'ignoreErrors' }); await context.close(); }
}
