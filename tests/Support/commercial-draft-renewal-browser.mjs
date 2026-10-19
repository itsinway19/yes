import assert from 'node:assert/strict';

export async function runDraftRenewal({ browser, page, ready, check, meta, command, fixture, result, cdp, authenticator }) {
    const base = '/internal/administration/commercial-markets';
    const draftKey = `commercial-preparation-draft:v1:internal:${fixture.a.user}`;
    const form = page.locator('[data-commercial-prepare]');
    const note = 'Review retained human note';
    let commercialWrites = 0;
    page.on('request', request => { if (request.method() !== 'GET' && new URL(request.url()).pathname.startsWith(base)) commercialWrites++; });
    async function fill(reason) {
        await form.getByLabel('الدولة', { exact: true }).selectOption('JO');
        await form.getByLabel('العملة', { exact: true }).selectOption('USD');
        await form.getByLabel('السبب / ملاحظة', { exact: true }).fill(reason);
    }
    async function renew(reason) {
        command('account-age-auth');
        await page.goto('http://localhost:8138' + base); await ready('[data-commercial-preparation]');
        await fill(reason);
        const reply = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/internal/logout');
        await page.getByRole('button', { name: 'تجديد الدخول', exact: true }).click();
        assert.equal((await reply).status(), 200); await ready('[data-auth-passkey]');
        assert.equal((await meta(page)).binding.principal, 0);
        const stored = await page.evaluate(key => JSON.parse(sessionStorage.getItem(key)), draftKey);
        assert.deepEqual(stored, { version: 1, principal: fixture.a.user, boundary: 'internal', mode: 'direct', country_code: 'JO', denomination_code: 'USD', label: '', reason });
        assert.equal(await page.locator('input').evaluateAll(inputs => inputs.some(input => input.value.includes('retained human') || input.value.includes('Previous principal'))), false);
        return stored;
    }
    await check('S7-R01 same-principal real logout UV login and admitted read restore human fields without commands', 'commercial-draft', async () => {
        const before = command('operations-commercial-inspect'), previous = await meta(page);
        await renew(note);
        await page.locator('[data-auth-passkey]').click(); await ready('[data-administration]');
        const current = await meta(page);
        assert.equal(current.binding.principal, fixture.a.user); assert.equal(current.binding.boundary, 'internal'); assert.notEqual(current.receipt, previous.receipt);
        let release, reached;
        const waiting = new Promise(resolve => { reached = resolve; }), held = new Promise(resolve => { release = resolve; });
        let heldRead = false;
        await page.route('**' + base, async route => {
            if (heldRead || route.request().headers().accept !== 'application/json') return route.continue();
            heldRead = true; const response = await route.fetch(); assert.equal(response.status(), 200); reached(); await held; await route.fulfill({ response });
        });
        try {
            await page.getByRole('link', { name: 'تهيئة الأسواق التجارية', exact: true }).click(); await waiting;
            assert.equal(await form.count(), 0); assert.equal(await page.locator('body').textContent().then(text => text.includes(note)), false);
            assert.notEqual(await page.evaluate(key => sessionStorage.getItem(key), draftKey), null);
            release(); await ready('[data-commercial-preparation]');
            await page.waitForFunction(reason => document.querySelector('[data-commercial-prepare] input')?.value === reason, note);
            assert.equal(await form.getByLabel('الدولة', { exact: true }).inputValue(), 'JO');
            assert.equal(await form.getByLabel('العملة', { exact: true }).inputValue(), 'USD');
            assert.equal(await page.evaluate(key => sessionStorage.getItem(key), draftKey), null);
            assert.equal(await page.evaluate(principal => sessionStorage.getItem(`commercial-preparation-spa:v1:${principal}`), fixture.a.user), null);
            assert.equal(commercialWrites, 0); assert.deepEqual(command('operations-commercial-inspect'), before);
            await page.reload(); await ready('[data-commercial-preparation]');
            assert.equal(await form.getByLabel('السبب / ملاحظة', { exact: true }).inputValue(), '');
            return { realLogoutStatus: 200, virtualUvLogin: true, admittedPrincipal: current.binding.principal, heldReadNoReveal: true, oneTimeConsumption: true, commercialWrites, restoredAuthority: false };
        } finally { release(); await page.unroute('**' + base); }
    });
    await check('S7-R01 same-tab different-principal real UV login cannot restore previous human draft', 'commercial-draft', async () => {
        const otherContext = await browser.newContext();
        try {
            const other = await otherContext.newPage(); other.setDefaultTimeout(20000);
            other.on('pageerror', error => result.errors.push({ mode: 'draft-other-enrollment', message: error.message, stack: error.stack }));
            otherContext.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
            const otherReady = selector => other.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
            const otherCdp = await otherContext.newCDPSession(other); await otherCdp.send('WebAuthn.enable');
            const options = { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true };
            let otherAuthenticator = (await otherCdp.send('WebAuthn.addVirtualAuthenticator', { options })).authenticatorId;
            await other.goto('http://localhost:8138/login'); await otherReady('[data-auth-kind="login"]');
            await other.locator('#email').fill('goals-b@example.test'); await other.locator('#password').fill('DisposableGoalsProof42!');
            await other.locator('[data-auth-entry] button[type=submit]').click(); await otherReady('[data-spa-private]:not([data-auth-entry])');
            await other.goto('http://localhost:8138/security/devices'); await otherReady('[data-account-kind="devices"]');
            for (const name of ['first', 'second']) {
                const keyName = `Draft B ${name} virtual UV`;
                await other.locator('#account-key-name').fill(keyName);
                const stored = other.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/security/passkeys');
                await other.locator('[data-account-passkey] button[type=submit]').click(); assert.equal((await stored).status(), 201);
                await other.locator('[data-passkey-id]').filter({ hasText: keyName }).waitFor();
                if (name === 'first') { await otherCdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: otherAuthenticator }); otherAuthenticator = (await otherCdp.send('WebAuthn.addVirtualAuthenticator', { options })).authenticatorId; }
            }
            command('account-staff', 'b'); command('operations-founder', 'b');
            const credential = (await otherCdp.send('WebAuthn.getCredentials', { authenticatorId: otherAuthenticator })).credentials[0];
            const before = command('operations-commercial-inspect');
            const retained = await renew('Previous principal private human note');
            await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authenticator });
            const replacement = (await cdp.send('WebAuthn.addVirtualAuthenticator', { options })).authenticatorId;
            await cdp.send('WebAuthn.addCredential', { authenticatorId: replacement, credential });
            await page.locator('[data-auth-passkey]').click(); await ready('[data-administration]');
            assert.equal((await meta(page)).binding.principal, fixture.b.user);
            await page.getByRole('link', { name: 'تهيئة الأسواق التجارية', exact: true }).click(); await ready('[data-commercial-preparation]');
            assert.equal(await form.getByLabel('السبب / ملاحظة', { exact: true }).inputValue(), '');
            assert.equal(await form.getByLabel('الدولة', { exact: true }).inputValue(), '');
            assert.equal(await form.getByLabel('العملة', { exact: true }).inputValue(), '');
            assert.deepEqual(await page.evaluate(key => JSON.parse(sessionStorage.getItem(key)), draftKey), retained);
            assert.equal(commercialWrites, 0); assert.deepEqual(command('operations-commercial-inspect'), before);
            return { realPrincipalSwitch: 'A to B', sameTabStoragePreserved: true, previousDraftPublished: false, commercialWrites };
        } finally { await otherContext.close(); }
    });
}
