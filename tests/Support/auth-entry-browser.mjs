import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

export async function runAuthEntryCases({ browser, check, meta, get, post, command, fixture, result, run, until, held }) {
    const onlyInternal = process.env.AUTH_ENTRY_ONLY === 'internal';
    const completion = process.env.AUTH_ENTRY_ONLY === 'completion';
    const passkeyExpiry = process.env.AUTH_ENTRY_ONLY === 'passkey-expiry';
    const cleanupPasskeys = process.env.AUTH_ENTRY_ONLY === 'cleanup-passkeys';
    const runCheck = check;
    check = (name, mode, action) => (cleanupPasskeys && !['A actual virtual', 'A cancelled and expired', 'A known passkey', 'A committed passkey'].some(prefix => name.startsWith(prefix))) || (onlyInternal && !name.startsWith('C internal')) || (completion && !name.startsWith('C ') && !name.startsWith('B pending staff')) || (passkeyExpiry && !name.startsWith('A actual virtual') && !name.startsWith('A cancelled and expired')) ? Promise.resolve() : runCheck(name, mode, action);
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.setDefaultTimeout(20000); page.on('dialog', dialog => dialog.accept());
    context.on('page', current => current.on('pageerror', error => result.errors.push({ mode: 'auth', message: error.message, stack: error.stack, url: current.url(), case: result.cases.at(-1)?.name })));
    page.on('pageerror', error => result.errors.push({ mode: 'auth', message: error.message, stack: error.stack, url: page.url(), case: result.cases.at(-1)?.name }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: response.status() }); });
    const ready = kind => page.waitForFunction(kind => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(`[data-auth-kind="${kind}"]`), kind);
    const privateReady = () => page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-spa-private]:not([data-auth-entry])'));
    let authenticatorId, authenticatorClient;
    async function login(who = 'a', destination = '/profile') {
        await page.goto('http://localhost:8138' + destination); await ready('login');
        await page.locator('#email').fill(`goals-${who}@example.test`); await page.locator('#password').fill('DisposableGoalsProof42!');
        await page.locator('[data-auth-entry] button[type=submit]').click(); await page.waitForURL('http://localhost:8138' + destination); await privateReady();
    }
    async function logout() { await page.locator('[data-spa=logout]').click(); await page.waitForURL('http://localhost:8138/'); }
    async function mailLinks(email, segment) {
        const messages = (await readFile(path.join(run, 'mailbox.jsonl'), 'utf8')).trim().split('\n').map(line => JSON.parse(line));
        return messages.filter(message => message.to.includes(email) && message.html.includes(segment))
            .map(message => message.html.match(new RegExp(`href="([^"]*${segment}[^\\"]+)"`))[1].replaceAll('&amp;', '&'));
    }
    async function register(email) {
        await page.goto('http://localhost:8138/register'); await ready('register');
        await page.locator('#name').fill('Isolated acceptance advertiser'); await page.locator('#email').fill(email);
        await page.locator('#password').fill('BrowserRegistrationPassword42!'); await page.locator('#password_confirmation').fill('BrowserRegistrationPassword42!');
        await page.locator('[data-auth-entry] button[type=submit]').click();
    }
    async function loseNativeAcknowledgement(action, submit) {
        let deliveries = 0;
        await page.route('**' + action, async route => {
            if (route.request().method() !== 'POST') return route.continue();
            deliveries++; const response = await route.fetch({ maxRedirects: 0 });
            assert.ok([302, 303].includes(response.status()), String(response.status()));
            await route.abort('failed');
        });
        await submit();
        await page.waitForURL(url => url.href.startsWith('chrome-error:') || url.pathname === action);
        await page.unroute('**' + action);
        assert.equal(deliveries, 1);
        return () => deliveries;
    }
    try {
        await check('A public native entry and explicit guest bootstrap use no principal/workspace or authenticated renewal', 'auth', async () => {
            await page.goto('http://localhost:8138/'); assert.equal(await page.locator('[data-page],#application-spa').count(), 0);
            const response = await page.goto('http://localhost:8138/login'); await ready('login');
            assert.equal((await meta(page)).binding.principal, 0); assert.equal(response.headers()['referrer-policy'], 'no-referrer');
            assert.equal(await page.locator('[data-page]').count(), 0); assert.equal(await page.locator('[data-spa=logout]').count(), 0);
            const resource = await get(page, '/login'); assert.equal(resource.status, 200); assert.equal(resource.body.visibility.protected, false);
            assert.deepEqual(Object.keys(resource.body.data.old).sort(), ['email', 'name']);
            await page.setViewportSize({ width: 390, height: 844 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({ path: path.join(run, 'auth-entry-mobile.png'), fullPage: true });
            await page.setViewportSize({ width: 1280, height: 900 });
            return { guest: true, privateBootstrap: false, noWorkspaceRequired: true, mobileRtl: true };
        });
        await check('A native rejected password returns safe validation; valid login honors actual intended destination and replaces document', 'auth', async () => {
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('incorrect-password');
            await page.locator('[data-auth-entry] button[type=submit]').click();
            await page.locator('[data-auth-recover]').waitFor();
            assert.equal((await meta(page)).binding.principal, 0); assert.equal(await page.locator('#password').inputValue(), '');
            await page.locator('[data-auth-recover]').click(); await ready('login');
            await page.evaluate(() => { window.authOldDocument = 'must-not-survive'; });
            await login('a', '/security/devices');
            assert.equal((await meta(page)).binding.principal, fixture.a.user);
            assert.equal(await page.evaluate(() => window.authOldDocument), undefined);
            await logout(); await page.goto('http://localhost:8138/login'); await ready('login'); assert.equal((await meta(page)).binding.principal, 0);
            return { rejected: true, secretsCleared: true, intended: '/security/devices', fullNativeDocument: true, logoutToGuest: true };
        });
        await check('A native identity switch replaces the old account document and server-selected destination', 'auth', async () => {
            await login(); const before = await meta(page);
            await page.goto('http://localhost:8138/identity/switch'); await ready('login');
            assert.equal((await meta(page)).binding.principal, fixture.a.user);
            await page.locator('#email').fill('goals-b@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await privateReady();
            const after = await meta(page); assert.equal(after.binding.principal, fixture.b.user); assert.ok(after.binding.epoch > before.binding.epoch);
            assert.equal((await get(page, '/profile')).body.data.user.email, 'goals-b@example.test');
            await logout(); return { prior: fixture.a.user, current: fixture.b.user, epochAdvanced: true, serverDestination: true };
        });
        await check('A native SPA retains source route and mounted draft while a destination is pending or cancelled', 'auth', async () => {
            await login('a', '/profile');
            const originalUrl = page.url();
            await page.locator('#account-name').fill('Unsaved retained profile');
            await page.evaluate(() => {
                dispatchEvent(new PageTransitionEvent('pagehide'));
                if (getComputedStyle(document.querySelector('[data-spa-private]')).display !== 'none') throw Error('Private content was not concealed synchronously');
                dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
            });
            await privateReady();
            assert.equal(await page.locator('#account-name').inputValue(), 'Unsaved retained profile');
            let heldOnce = false;
            await page.route('**/security/devices', async route => {
                if (route.request().isNavigationRequest() || !route.request().headers()['x-builderad-witness'] || heldOnce) return route.continue();
                heldOnce = true;
                await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': 'native-candidate' } });
            });
            const mark = result.wire.length;
            await page.locator('header a[href="/security/devices"]').click();
            await until(() => held.has('native-candidate'));
            assert.equal(page.url(), originalUrl);
            assert.equal(await page.locator('#account-name').inputValue(), 'Unsaved retained profile');
            assert.equal(await page.locator('[data-spa-view]').getAttribute('inert'), '');
            await page.locator('header a[href="/profile"]').click();
            held.get('native-candidate').deliver(); held.delete('native-candidate');
            await page.waitForFunction(() => !document.querySelector('[data-spa="navigation"]'));
            assert.equal(page.url(), originalUrl);
            assert.equal(await page.locator('#account-name').inputValue(), 'Unsaved retained profile');
            await page.unroute('**/security/devices');
            const requests = result.wire.slice(mark).filter(row => row.path === '/security/devices' && row.method === 'GET');
            assert.equal(requests.length, 2);
            await page.locator('header a[href="/security/devices"]').click();
            await page.waitForURL('**/security/devices'); await privateReady();
            assert.equal(await page.locator('[data-account-kind="devices"]').count(), 1);
            await logout();
            return { sourceUrlRetained: true, mountedDraftRetained: true, sourceInert: true, cancelledTargetCannotPublish: true,
                resourceAndWitnessRequests: requests.map(row => ({ status: row.status, ms: row.finished - row.start, bytes: row.bytes })), beforeAfterSpeedup: 'NOT_MEASURED' };
        });
        await check('A production Workspace navigation and actual Back/Forward preserve scoped human drafts without writes', 'auth', async () => {
            const first = fixture.a.workspaces[0].public_id, second = fixture.a.workspaces[1].public_id;
            const goalsPath = id => `/workspaces/${id}/goals`;
            const goalsReady = id => page.waitForFunction(id => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(`[data-goals-workspace="${id}"]`), id);
            await login('a', goalsPath(first)); await goalsReady(first);
            const mark = result.wire.length;
            const goal = page.locator('[data-goals^="select-"]').first(), selector = await goal.getAttribute('data-goals');
            await goal.check();
            await page.locator(`header a[href="/workspaces/${first}/business"]`).click();
            await page.waitForURL(`**/workspaces/${first}/business`); await privateReady();
            await page.goBack(); await goalsReady(first);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(), true);
            await page.goForward(); await page.waitForURL(`**/workspaces/${first}/business`); await privateReady();
            await page.locator('header a[href="/workspaces"]').click(); await page.waitForURL('**/workspaces'); await privateReady();
            await page.locator(`a.workspace-row[href="/workspaces/${second}"]`).click();
            await page.waitForURL(`**/workspaces/${second}`); await privateReady();
            await page.locator(`header a[href="${goalsPath(second)}"]`).click(); await goalsReady(second);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(), false);
            await page.locator('header a[href="/workspaces"]').click(); await page.waitForURL('**/workspaces'); await privateReady();
            await page.locator(`a.workspace-row[href="/workspaces/${first}"]`).click(); await privateReady();
            await page.locator(`header a[href="${goalsPath(first)}"]`).click(); await goalsReady(first);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(), true);
            assert.equal((await get(page, goalsPath(first) + '/context')).body.preferences.version, 0);
            assert.equal(result.wire.slice(mark).filter(row => row.method === 'POST' && row.path.startsWith('/workspaces/')).length, 0);
            await logout();
            return { productionAssets: true, realBackForward: true, crossWorkspace: true, humanDraftRetained: true, draftIsolation: true, navigationWrites: 0 };
        });
        await check('A same-record Workspace updates honor actual editor guards and passive history conceals old data', 'auth', async () => {
            const first=fixture.a.workspaces[0].public_id,second=fixture.a.workspaces[1].public_id;
            const target=id=>`/workspaces/${id}/goals`;
            const goalsReady=id=>page.waitForFunction(id=>document.getElementById('application-spa')?.dataset.state==='ready'&&document.querySelector(`[data-goals-workspace="${id}"]`),id);
            await login('a',target(first));await goalsReady(first);
            const choice=page.locator('[data-goals^="select-"]').first(),selector=await choice.getAttribute('data-goals');await choice.check();
            page.removeAllListeners('dialog');page.on('dialog',dialog=>dialog.dismiss());
            await page.evaluate(path=>document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(path),target(first)+'?review=1');
            assert.equal(new URL(page.url()).search,'','Unsaved guard rejects same-record query updates too');
            await page.evaluate(path=>document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(path),target(second));
            assert.equal(new URL(page.url()).pathname,target(first));assert.equal(await choice.isChecked(),true);
            page.removeAllListeners('dialog');page.on('dialog',dialog=>dialog.accept());
            await page.evaluate(path=>document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(path),target(second));await goalsReady(second);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(),false);
            let once=true;await page.route(`**${target(first)}/context`,async route=>{
                if(once){once=false;await route.continue({headers:{...route.request().headers(),'X-Proof-Hold':'passive-history-source'}});}else await route.continue();
            });
            await page.evaluate(()=>history.back());await until(()=>held.has('passive-history-source'));
            assert.equal(new URL(page.url()).pathname,target(first));
            assert.equal(await page.locator('[data-spa-private]:visible').count(),0);
            assert.equal(await page.locator(`[data-goals-workspace="${second}"]`).count(),1,'Source editor remains mounted while hidden');
            held.get('passive-history-source').deliver();held.delete('passive-history-source');await goalsReady(first);
            assert.equal(await page.locator(`[data-goals="${selector}"]`).isChecked(),true);
            await page.unroute(`**${target(first)}/context`);await logout();
            return {sameRecordDeniedUpdate:true,acceptedUpdate:true,scopedDraft:true,actualHistory:true,hiddenBeforeTargetAdmission:true,sourceLifetimeRetained:true};
        });
        await check('A actual virtual resident UV passkey enrollment and native passkey login retain server verification and consumed-document denial', 'auth', async () => {
            const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
            authenticatorClient = cdp;
            authenticatorId = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
            await login('a', '/security/devices');
            assert.equal(await page.locator('[data-passkey-id]').count(), 0, 'Enrollment case requires a fresh account fixture');
            await page.locator('#account-key-name').fill('Authentication real key'); await page.locator('[data-account-passkey] button[type=submit]').click();
            await page.locator('[data-passkey-id]').waitFor(); assert.equal(command('account-inspect').keys.length, 1);
            await logout(); await page.goto('http://localhost:8138/login'); await ready('login'); const captured = await meta(page);
            let releaseDocument, documentStarted = false;
            const handoff = []; result.handoffObservations = handoff;
            const observeHandoff = message => { if (message.text().startsWith('NATIVE_HANDOFF ')) handoff.push(JSON.parse(message.text().slice(15))); };
            page.on('console', observeHandoff);
            await page.evaluate(() => {
                new MutationObserver(() => {
                    const root = document.getElementById('application-spa'), status = document.querySelector('[data-spa="status"]');
                    if (root?.dataset.state === 'closed') {
                        console.log('NATIVE_HANDOFF ' + JSON.stringify({state:root.dataset.state,text:status?.textContent ?? '',authViews:document.querySelectorAll('[data-auth-entry]').length}));
                    }
                }).observe(document.getElementById('application-spa'), {attributes:true,childList:true,subtree:true});
            });
            const documentGate = new Promise(resolve => { releaseDocument = resolve; });
            await page.route('**/*', async route => {
                if (route.request().isNavigationRequest() && route.request().frame() === page.mainFrame()) {
                    documentStarted = true; await documentGate;
                }
                await route.continue();
            });
            try {
                await page.locator('[data-auth-passkey]').click({noWaitAfter:true});
                await until(() => documentStarted && handoff.some(row => row.authViews === 0 && row.text), 'old document must render neutral handoff before destination delivery');
                assert.equal(handoff.at(-1).authViews, 0);
                assert.match(handoff.at(-1).text, /Opening a new document|الانتقال إلى مستند جديد/);
                assert.doesNotMatch(handoff.at(-1).text, /could not be verified|تعذر التحقق/);
            } finally { releaseDocument(); page.off('console', observeHandoff); }
            await privateReady(); await page.unroute('**/*');
            assert.equal((await meta(page)).binding.principal, fixture.a.user);
            const old = await page.evaluate(async identity => {
                const response = await fetch('/auth/passkey', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': identity.csrf, 'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition }, body: JSON.stringify({ credential: {} }) }); return response.status;
            }, captured);
            assert.ok([409, 419].includes(old)); assert.ok(command('account-inspect').audit.includes('passkey_login_verified'));
            const freshOptions = await get(page, '/identity/passkey/options'); assert.equal(freshOptions.status, 200);
            const unusedAssertion = await page.evaluate(async options => (await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options) })).toJSON(), freshOptions.body.options);
            assert.equal((await post(page, '/identity/passkey', { credential: unusedAssertion }, { 'X-Builderad-Transition': 'invalid-transition' })).status, 409);
            assert.equal((await get(page, '/profile')).body.data.user.email, 'goals-a@example.test');
            await logout();
            const samples = [];
            for (let sample = 0; sample < 3; sample++) {
                await page.goto('http://localhost:8138/login'); await ready('login');
                const phases = [], requests = [];
                const observe = message => {
                    if (!message.text().startsWith('builderad_webauthn ')) return;
                    const value = JSON.parse(message.text().slice('builderad_webauthn '.length));
                    phases.push({ operation: value.operation, phase: value.phase, elapsedMs: value.elapsedMs, requestElapsedMs: value.requestElapsedMs, errorName: value.errorName });
                };
                const network = response => { const parsed = new URL(response.url()); if (['/auth/passkey/options', '/auth/passkey'].includes(parsed.pathname)) requests.push({ request: response.request(), path: parsed.pathname, operation: parsed.searchParams.get('webauthn_probe'), status: response.status() }); };
                page.on('console', observe); page.on('response', network);
                const started = performance.now();
                await page.locator('[data-auth-passkey]').click(); await privateReady();
                const usableMs = performance.now() - started;
                page.off('console', observe); page.off('response', network);
                assert.equal(requests.filter(row => row.path === '/auth/passkey').length, 1);
                assert.equal(requests.length, 2);
                assert.ok(requests.every(row => /^[0-9a-f]{16}$/.test(row.operation)));
                assert.equal(requests[0].operation, requests[1].operation);
                assert.ok(phases.some(row => row.phase === 'credential_request_completed'));
                assert.ok(phases.some(row => row.phase === 'authentication_acknowledgement_admitted'));
                const server = (await readFile(path.join(run, 'laravel/logs/laravel.log'), 'utf8')).split('\n').filter(line => line.includes('builderad_webauthn') && line.includes(requests[0].operation)).map(line => JSON.parse(line.match(/\{.*\}/)[0]));
                assert.ok(server.some(row => row.phase === 'login_assertion_accepted'));
                samples.push({ sample, usableMs, phases, server, requests: requests.map(({ request, ...row }) => ({ ...row, httpMs: request.timing().responseEnd })) });
                await logout();
            }
            result.passkeyReliability = { samples, environment: 'Headless Chromium virtual CTAP2 resident UV authenticator; physical phone NOT OBSERVED', clockRule: 'Client phase elapsed, HTTP request duration and server phase elapsed have independent monotonic origins; no cross-clock subtraction' };
            return { virtualAuthenticator: true, realUvAssertion: true, nativeSignIn: true, consumedInput: old, audit: true, measuredSamples: samples.length };
        });
        await check('A cancelled and expired real WebAuthn assertions never authenticate or replay automatically', 'auth', async () => {
            await page.goto('http://localhost:8138/login'); await ready('login');
            await authenticatorClient.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: false });
            const before = result.wire.filter(row => new URL(row.path, 'http://localhost').pathname === '/auth/passkey' && row.method === 'POST').length;
            await page.locator('[data-auth-passkey]').click(); await page.locator('[data-auth-cancel]').waitFor(); await page.locator('[data-auth-cancel]').click();
            await page.waitForFunction(() => !document.querySelector('[data-auth-cancel]'));
            assert.equal(result.wire.filter(row => new URL(row.path, 'http://localhost').pathname === '/auth/passkey' && row.method === 'POST').length, before);
            await authenticatorClient.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: true });
            const options = await get(page, '/auth/passkey/options'); assert.equal(options.status, 200);
            const credential = await page.evaluate(async options => (await navigator.credentials.get({ publicKey: PublicKeyCredential.parseRequestOptionsFromJSON(options) })).toJSON(), options.body.options);
            await new Promise(resolve => setTimeout(resolve, 61000));
            assert.equal((await post(page, '/auth/passkey', { credential })).status, 422);
            await page.goto('http://localhost:8138/login'); await ready('login');
            const fresh = await get(page, '/auth/passkey/options'); assert.equal(fresh.status, 200);
            const invalid = structuredClone(credential); invalid.response.signature = 'invalid';
            assert.equal((await post(page, '/auth/passkey', { credential: invalid })).status, 422);
            await page.goto('http://localhost:8138/login'); await ready('login');
            return { cancelledSubmitCount: 0, realWaitMs: 61000, expired: 422, invalidSignature: 422, automaticReplay: false };
        });
        await check('A known passkey rejection shows server rejection and requires a fresh document without replay', 'auth', async () => {
            await page.goto('http://localhost:8138/login'); await ready('login');
            let deliveries = 0;
            await page.route(/\/auth\/passkey(?:\?.*)?$/, async route => {
                deliveries++;
                const body = route.request().postDataJSON(); body.credential.response.signature = 'invalid';
                await route.continue({ postData: JSON.stringify(body) });
            });
            await page.locator('[data-auth-passkey]').click();
            await page.locator('[data-auth-recover]').waitFor();
            assert.equal(await page.locator('[data-auth-passkey]').isDisabled(), true);
            assert.equal((await meta(page)).binding.principal, 0);
            assert.equal(result.wire.filter(row => new URL(row.path, 'http://localhost').pathname === '/auth/passkey').at(-1).status, 422);
            assert.doesNotMatch(await page.locator('[data-auth-entry]').innerText(), /نتيجة طلب مفتاح المرور غير مؤكدة|Passkey sign-in is uncertain/);
            assert.equal(deliveries, 1); await page.unroute(/\/auth\/passkey(?:\?.*)?$/);
            await page.locator('[data-auth-recover]').click(); await ready('login');
            return { rejected: 422, deliveries, freshDocumentRequired: true, automaticReplay: false };
        });
        await check('A committed passkey assertion with lost ACK stays unknown and is never replayed', 'auth', async () => {
            let deliveries = 0;
            await page.route(/\/auth\/passkey(?:\?.*)?$/, async route => {
                deliveries++; const response = await route.fetch({ maxRedirects: 0 });
                assert.equal(response.status(), 200); await route.abort('failed');
            });
            await page.locator('[data-auth-passkey]').click();
            await page.locator('[data-auth-recover]').waitFor();
            assert.equal(await page.locator('[data-auth-passkey]').isDisabled(), true);
            assert.match(await page.locator('[data-auth-entry]').innerText(), /نتيجة طلب مفتاح المرور غير مؤكدة|Passkey sign-in is uncertain/);
            assert.equal(deliveries, 1); await page.unroute(/\/auth\/passkey(?:\?.*)?$/);
            await page.locator('[data-auth-recover]').click(); await privateReady();
            assert.equal((await meta(page)).binding.principal, fixture.a.user); assert.equal(deliveries, 1);
            await logout(); await page.goto('http://localhost:8138/login'); await ready('login');
            return { committed: true, acknowledgement: 'unknown', deliveries, automaticReplay: false };
        });
        await check('A committed password login with lost acknowledgement is unknown, disabled and delivered exactly once', 'auth', async () => {
            const before = command('account-inspect').sessions.length; let deliveries = 0;
            await page.route('**/login', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                deliveries++; const response = await route.fetch({ maxRedirects: 0 }); assert.equal(response.status(), 200); await route.abort('failed');
            });
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!'); await page.locator('[data-auth-entry] button[type=submit]').click();
            await page.locator('[data-auth-recover]').waitFor(); assert.equal(await page.locator('[data-auth-entry] button[type=submit]').isDisabled(), true);
            assert.equal(command('account-inspect').sessions.length, before + 1); assert.equal(deliveries, 1);
            await page.unroute('**/login'); await page.locator('[data-auth-recover]').click(); await privateReady(); assert.equal(deliveries, 1);
            assert.equal((await meta(page)).binding.principal, fixture.a.user); await logout();
            return { committed: true, acknowledgement: 'unknown', deliveries, automaticReplay: false, explicitRecovery: 'native server-owned authenticated document' };
        });
        await check('A native registration creates a pending advertiser and dispatches actual isolated verification mail', 'auth', async () => {
            await page.goto('http://localhost:8138/register'); await ready('register');
            await page.locator('#name').fill('Browser pending advertiser'); await page.locator('#email').fill('auth-register@example.test');
            await page.locator('#password').fill('BrowserRegistrationPassword42!'); await page.locator('#password_confirmation').fill('BrowserRegistrationPassword42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await page.waitForURL('http://localhost:8138/verify-email');
            assert.ok((await meta(page)).binding.principal > 0);
            const messages = (await readFile(path.join(run, 'mailbox.jsonl'), 'utf8')).trim().split('\n').map(line => JSON.parse(line));
            assert.ok(messages.some(message => message.to.includes('auth-register@example.test') && message.html.includes('/verify-email/')));
            return { realRegistration: true, destination: '/verify-email', verificationMail: true, externalMail: false };
        });
        await check('B real resend and signed email verification preserve Laravel lifecycle and native new document', 'auth', async () => {
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="verify-email"]'));
            await page.locator('[data-auth-resend]').click();
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="verify-email"]'));
            const messages = (await readFile(path.join(run, 'mailbox.jsonl'), 'utf8')).trim().split('\n').map(line => JSON.parse(line)).filter(message => message.to.includes('auth-register@example.test') && message.html.includes('/verify-email/'));
            assert.ok(messages.length >= 2);
            const link = messages.at(-1).html.match(/href="([^"]*\/verify-email\/[^\"]+)"/)[1].replaceAll('&amp;', '&');
            const invalid = new URL(link); invalid.searchParams.set('signature', 'invalid');
            assert.equal((await context.request.get(invalid.href, { headers: { Accept: 'text/html' } })).status(), 403);
            const expired = command('auth-expired-verification').url;
            assert.equal((await context.request.get(expired, { headers: { Accept: 'text/html' } })).status(), 403);
            assert.equal(command('auth-inspect-email').verified, false);
            const prior = await meta(page); await page.goto(link); await privateReady();
            assert.equal(command('auth-inspect-email').verified, true); assert.equal(command('auth-inspect-email').lifecycle, 'current');
            assert.ok((await meta(page)).binding.lifecycle > prior.binding.lifecycle);
            await logout(); return { isolatedMail: messages.length, invalidSignature: 403, expiredSignature: 403, verified: true, lifecycleCurrent: true, nativeTransition: true };
        });
        await check('B actual broker recovery mail and native reset consume token, revoke sessions and reject replay', 'auth', async () => {
            await page.goto('http://localhost:8138/forgot-password');
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="forgot-password"]'));
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('[data-auth-entry] button[type=submit]').click();
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="forgot-password"]'));
            const messages = (await readFile(path.join(run, 'mailbox.jsonl'), 'utf8')).trim().split('\n').map(line => JSON.parse(line));
            const message = messages.filter(message => message.to.includes('goals-a@example.test') && message.html.includes('/reset-password/')).at(-1); assert.ok(message);
            const link = message.html.match(/href="([^"]*\/reset-password\/[^\"]+)"/)[1].replaceAll('&amp;', '&');
            const response = await page.goto(link); await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="reset-password"]'));
            const token = new URL(link).pathname.split('/').at(-1);
            assert.equal(response.headers()['referrer-policy'], 'no-referrer'); assert.ok(response.headers()['cache-control'].includes('no-store'));
            assert.equal(await page.locator('#application-bootstrap').textContent().then(text => text.includes(token)), false);
            assert.equal(await page.locator('#email').inputValue(), 'goals-a@example.test');
            await page.locator('#password').fill('RecoveredAdvertiserPassword42!'); await page.locator('#password_confirmation').fill('RecoveredAdvertiserPassword42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await ready('login');
            assert.ok(command('account-inspect').sessions.every(session => session.revoked_at !== null));
            const replay = await post(page, '/reset-password', { token, email: 'goals-a@example.test', password: 'SecondRecoveryPassword42!', password_confirmation: 'SecondRecoveryPassword42!' });
            assert.equal(replay.status, 422);
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('RecoveredAdvertiserPassword42!'); await page.locator('[data-auth-entry] button[type=submit]').click(); await privateReady(); await logout();
            return { realBrokerToken: true, secretsOutsideBootstrap: true, consumedReplay: 422, sessionsRevoked: true, passwordActuallyChanged: true };
        });
        await check('B lost registration and resend acknowledgements never replay committed operations', 'auth', async () => {
            let registrations = 0;
            await page.route('**/register', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                registrations++; assert.equal((await route.fetch({ maxRedirects: 0 })).status(), 200); await route.abort('failed');
            });
            await register('auth-unknown@example.test'); await page.locator('[data-auth-recover]').waitFor();
            assert.equal(await page.locator('[data-auth-entry] button[type=submit]').isDisabled(), true);
            assert.equal(command('auth-inspect-unknown').count, 1); assert.equal(registrations, 1);
            await page.unroute('**/register'); await page.locator('[data-auth-recover]').click(); await ready('verify-email');
            const before = (await mailLinks('auth-unknown@example.test', '/verify-email/')).length;
            const deliveries = await loseNativeAcknowledgement('/email/verification-notification', () => page.locator('[data-auth-resend]').click());
            await page.goto('http://localhost:8138/verify-email'); await ready('verify-email');
            assert.equal((await mailLinks('auth-unknown@example.test', '/verify-email/')).length, before + 1);
            assert.equal(deliveries(), 1); assert.equal(registrations, 1); await logout();
            return { registrationCommitted: 1, registrationReplay: 0, resendDelivered: 1, resendReplay: 0, explicitNativeRecovery: true };
        });
        await check('B expired recovery token is rejected and lost recovery/reset acknowledgements are not replayed', 'auth', async () => {
            await page.goto('http://localhost:8138/forgot-password'); await ready('forgot-password');
            await page.locator('#email').fill('goals-a@example.test');
            const forgotDeliveries = await loseNativeAcknowledgement('/forgot-password', () => page.locator('[data-auth-entry] button[type=submit]').click());
            const expiredLink = (await mailLinks('goals-a@example.test', '/reset-password/')).at(-1);
            command('auth-expire-token'); await page.goto(expiredLink); await ready('reset-password');
            await page.locator('#password').fill('ExpiredMustNotApplyPassword42!'); await page.locator('#password_confirmation').fill('ExpiredMustNotApplyPassword42!');
            await page.locator('[data-auth-entry] button[type=submit]').click();
            await page.waitForFunction(() => document.querySelector('[data-auth-kind="reset-password"] [role=alert]')?.textContent.trim());
            assert.equal(await page.locator('#password').inputValue(), '');
            await page.goto('http://localhost:8138/forgot-password'); await ready('forgot-password');
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('[data-auth-entry] button[type=submit]').click(); await ready('forgot-password');
            const freshLink = (await mailLinks('goals-a@example.test', '/reset-password/')).at(-1);
            assert.notEqual(freshLink, expiredLink); await page.goto(freshLink); await ready('reset-password');
            await page.locator('#password').fill('UnknownResetCommittedPassword42!'); await page.locator('#password_confirmation').fill('UnknownResetCommittedPassword42!');
            const resetDeliveries = await loseNativeAcknowledgement('/reset-password', () => page.locator('[data-auth-entry] button[type=submit]').click());
            await page.goto('http://localhost:8138/login'); await ready('login');
            await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('UnknownResetCommittedPassword42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await privateReady();
            assert.equal(forgotDeliveries(), 1); assert.equal(resetDeliveries(), 1); await logout();
            return { expiredRejected: true, expiredSecretCleared: true, forgotDelivered: 1, resetDelivered: 1, passwordActuallyChanged: true, automaticReplay: false };
        });
        await check('B pending staff signed verification returns to existing onboarding without activating staff', 'auth', async () => {
            await register('auth-staff@example.test'); await ready('verify-email'); command('auth-pending-staff');
            await context.clearCookies(); await page.goto('http://localhost:8138/login'); await ready('login');
            await page.locator('#email').fill('auth-staff@example.test'); await page.locator('#password').fill('BrowserRegistrationPassword42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await page.waitForURL('http://localhost:8138/staff/onboarding');
            await page.goto('http://localhost:8138/verify-email'); await ready('verify-email');
            const link = (await mailLinks('auth-staff@example.test', '/verify-email/')).at(-1);
            await page.goto(link); await page.waitForURL('http://localhost:8138/staff/onboarding');
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-staff-enrollment]'));
            assert.equal(await page.locator('[data-page]').count(), 0);
            assert.equal((await get(page, '/dashboard')).status, 403);
            await page.goto('http://localhost:8138/identity/switch'); await ready('login');
            await page.locator('#email').fill('goals-b@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!');
            await page.locator('[data-auth-entry] button[type=submit]').click(); await privateReady(); await logout();
            return { signedVerification: true, onboardingUnifiedSpa: true, staffActivation: false, dashboardDenied: 403 };
        });
        await check('C real confirmation rejects wrong password and restores access to a server fresh-authentication gate', 'auth', async () => {
            await login('b', '/security/devices'); command('account-age-auth', 'b');
            assert.equal((await get(page, '/security/passkeys/options')).status, 403);
            await page.goto('http://localhost:8138/confirm-password');
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="confirm-password"]'));
            await page.locator('#password').fill('incorrect-password'); await page.locator('[data-auth-entry] button[type=submit]').click();
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-kind="confirm-password"] [role=alert]')?.textContent.trim());
            assert.equal(await page.locator('#password').inputValue(), ''); assert.equal((await get(page, '/security/passkeys/options')).status, 403);
            await page.locator('#password').fill('DisposableGoalsProof42!'); await page.locator('[data-auth-entry] button[type=submit]').click(); await privateReady();
            assert.equal((await get(page, '/security/passkeys/options')).status, 200);
            return { wrongPasswordRejected: true, freshGateBefore: 403, freshGateAfter: 200, canonicalSessionOwner: true };
        });
        await check('C internal passkey login, guard separation and lifecycle denial remain independent', 'auth', async () => {
            if (onlyInternal) await login('b', '/security/devices');
            if (authenticatorClient) await authenticatorClient.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
            const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
            await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
            await page.goto('http://localhost:8138/security/devices'); await privateReady();
            await page.locator('#account-key-name').fill('Independent internal key'); await page.locator('[data-account-passkey] button[type=submit]').click(); await page.locator('[data-passkey-id]').waitFor();
            command('account-staff', 'b'); await page.goto('http://localhost:8138/internal/login');
            await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector('[data-auth-passkey]'));
            const verified = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === '/internal/auth/passkey');
            await page.locator('[data-auth-passkey]').click();
            assert.equal((await verified).status(), 200);
            await page.waitForURL('http://localhost:8138/internal/security/devices'); await privateReady();
            assert.equal(await page.locator('[data-page]').count(), 0); assert.equal((await meta(page)).binding.boundary, 'internal');
            const rejected = page.waitForResponse(reply => reply.request().method() === 'DELETE' && new URL(reply.url()).pathname.includes('/internal/security/passkeys/'));
            await page.locator('[data-account-delete-key]').click(); assert.equal((await rejected).status(), 422);
            await page.getByText('راجع المدخلات والحالة الحالية؛ لم نؤكد تنفيذ الطلب.', { exact: true }).waitFor();
            assert.equal(command('account-inspect', 'b').keys.length, 1);
            command('account-age-auth', 'b'); assert.equal((await get(page, '/internal/security/passkeys/options')).status, 403);
            return { internalUnifiedSpa: true, realSignedAssertion: true, boundary: 'internal', employeeMinimum: true, freshDenial: 403 };
        });
        await check('C guest navigation cancels an older real Laravel read before Back and Forward restoration', 'auth', async () => {
            await context.clearCookies(); await page.goto('http://localhost:8138/login'); await ready('login');
            let release, reached;
            const held = new Promise(resolve => { release = resolve; });
            const captured = new Promise(resolve => { reached = resolve; });
            await page.route('**/forgot-password', async route => {
                if (route.request().method() !== 'GET' || !route.request().headers().accept?.includes('application/json')) return route.continue();
                const response = await route.fetch(); reached(); await held;
                await route.fulfill({ response }).catch(error => { assert.match(error.message, /cancel|abort|closed|handled/i); });
            });
            try {
                await page.evaluate(() => { void document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push('/forgot-password'); }); await captured;
                await page.evaluate(() => { void document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push('/register'); }); await ready('register');
                release(); await page.unrouteAll({ behavior: 'wait' });
                assert.equal(await page.locator('[data-auth-kind="forgot-password"]').count(), 0);
                assert.equal(await page.locator('#password').inputValue(), '');
                // The cancelled candidate was never admitted and must not enter history.
                await page.goBack(); await ready('login'); await page.goForward(); await ready('register');
                assert.equal((await meta(page)).binding.principal, 0);
                return { realLateLaravelRead: true, cancelledCandidateNotPublished: true, localBackForward: true, nativeBFCache: 'NOT_PROVEN' };
            } finally { release(); }
        });
    } finally { await page.unrouteAll({ behavior: 'ignoreErrors' }); await context.close(); }
}
