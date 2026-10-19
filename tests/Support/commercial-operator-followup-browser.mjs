import assert from 'node:assert/strict';

export async function runOperatorFollowup({ browser, page, ready, check, get, post, command, result }) {
    const base = '/internal/administration/commercial-markets';
    const contexts = [];
    await check('P2 founder administrator operator lost ACK consumed approval 409 explicit archive and reload', 'commercial', async () => {
        try {
            const actors = command('operations-commercial-actors');
            async function enroll(who) {
                const context = await browser.newContext(); contexts.push(context);
                const actor = await context.newPage(); actor.setDefaultTimeout(20000);
                actor.on('dialog', dialog => dialog.accept());
                actor.on('pageerror', error => result.errors.push({ mode: 'operator-followup', message: error.message, stack: error.stack }));
                context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
                const actorReady = selector => actor.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
                const cdp = await context.newCDPSession(actor); await cdp.send('WebAuthn.enable');
                const addKey = async () => (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })).authenticatorId;
                let authenticator = await addKey();
                await actor.goto('http://localhost:8138/security/devices'); await actorReady('[data-auth-kind="login"]');
                await actor.locator('#email').fill(`goals-${who}@example.test`); await actor.locator('#password').fill('DisposableGoalsProof42!');
                await actor.locator('[data-auth-entry] button[type=submit]').click(); await actorReady('[data-spa-private]:not([data-auth-entry])');
                await actor.goto('http://localhost:8138/security/devices'); await actorReady('[data-account-kind="devices"]');
                for (const name of ['first', 'second']) {
                    await actor.locator('#account-key-name').fill(`Commercial ${who} ${name} virtual UV`);
                    const stored = actor.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/security/passkeys'));
                    await actor.locator('[data-account-passkey] button[type=submit]').click(); assert.equal((await stored).status(), 201);
                    await actor.locator('[data-passkey-id]').filter({ hasText: `Commercial ${who} ${name} virtual UV` }).waitFor();
                    if (name === 'first') { await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: authenticator }); authenticator = await addKey(); }
                }
                command('account-staff', who);
                return { page: actor, ready: actorReady };
            }
            const administrator = await enroll('b'), operator = await enroll('c');
            await page.getByRole('link', { name: 'تهيئة الأسواق التجارية', exact: true }).click(); await ready('[data-commercial-preparation]');
            const form = page.locator('[data-commercial-prepare]');
            await form.locator('select').first().selectOption('delegated');
            await form.locator('input[maxlength="120"]').first().fill('Synthetic operator follow-up market');
            await form.locator('select').nth(1).selectOption(actors.administrator);
            const expiry = await page.evaluate(() => new Date(Date.now() + 3600000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16));
            await form.locator('input[type="datetime-local"]').fill(expiry);
            await form.locator('input[maxlength="120"]').last().fill('Explicit isolated delegated approval');
            const approvalAck = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === base);
            await form.locator('button[type="submit"]').click(); const created = await approvalAck;
            assert.ok(Date.parse(created.request().postDataJSON().expires_at) > Date.now());
            assert.equal(created.status(), 200, await created.text());
            const approved = await created.json();
            const founderState = await get(page, base); const approval = founderState.body.data.approvals.find(item => item.target_id === approved.target_id); assert.ok(approval);
            async function internalLogin(actor, destination, selector) {
                await actor.page.goto('http://localhost:8138' + destination); await actor.ready('[data-auth-passkey]');
                await actor.page.locator('[data-auth-passkey]').click(); await actor.ready('[data-account-kind="devices"]');
                await actor.page.goto('http://localhost:8138' + destination); await actor.ready(selector);
            }
            await internalLogin(administrator, base, '[data-commercial-preparation]');
            const admin = administrator.page, operatorPath = `${base}/${approval.id}/operator`;
            const authorize = admin.locator('form').filter({ has: admin.getByRole('button', { name: 'تفويض', exact: true }) });
            await authorize.locator('select').nth(0).selectOption(approval.id); await authorize.locator('select').nth(1).selectOption(actors.operator);
            await authorize.locator('input').fill('Original operator request with lost ACK');
            let attempts = 0, original;
            await admin.route('**' + operatorPath, async route => {
                attempts++; original = route.request().postDataJSON();
                const committed = await route.fetch(); assert.equal(committed.status(), 200); await route.abort('failed');
            });
            await authorize.getByRole('button', { name: 'تفويض', exact: true }).click();
            await admin.getByText('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor(); assert.equal(attempts, 1);
            await admin.unroute('**' + operatorPath);
            const activeKey = await admin.evaluate(() => Object.keys(sessionStorage).find(key => /^commercial-preparation-spa:v1:[^:]+$/.test(key)));
            const frozen = await admin.evaluate(key => JSON.parse(sessionStorage.getItem(key)), activeKey);
            assert.deepEqual(frozen, { kind: 'operator', approval: approval.id, body: original });
            await internalLogin(operator, '/internal/commercial', '[data-commercial-operations]');
            const registration = await post(operator.page, '/internal/commercial/markets', { command_id: crypto.randomUUID(), reason_code: 'approved_market', market_id: approved.target_id, label: approval.label });
            assert.equal(registration.status, 200, JSON.stringify(registration));
            const rejected = admin.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === operatorPath);
            await admin.getByRole('button', { name: 'إعادة المحاولة صراحة بالطلب نفسه', exact: true }).click();
            const conflict = await rejected; assert.equal(conflict.status(), 409); assert.deepEqual(conflict.request().postDataJSON(), original);
            await admin.getByText('النتيجة غير مؤكدة؛ لا إعادة تلقائية.', { exact: true }).waitFor();
            const archive = admin.getByRole('button', { name: 'إنهاء متابعة المشغّل — إبقاء النتيجة الأصلية غير محسومة', exact: true }); assert.equal(await archive.count(), 0);
            await admin.getByRole('button', { name: 'مراجعة الحالة الحالية — ليست إقرارًا', exact: true }).click(); await archive.waitFor();
            const before = command('operations-commercial-inspect');
            const operatorRequests = () => result.wire.filter(row => row.method === 'POST' && row.path === operatorPath);
            assert.deepEqual(operatorRequests().map(row => row.status), [200, 409]);
            await archive.click();
            assert.equal(await admin.getByRole('button', { name: 'إعادة المحاولة صراحة بالطلب نفسه', exact: true }).count(), 0);
            const history = await admin.evaluate(key => ({ active: sessionStorage.getItem(key), rows: Object.keys(sessionStorage).filter(item => item.startsWith(key + ':reviewed:operator:')).map(item => JSON.parse(sessionStorage.getItem(item))) }), activeKey);
            assert.equal(history.active, null); assert.deepEqual(history.rows, [{ command: frozen, outcome: 'unknown' }]);
            await admin.reload(); await administrator.ready('[data-commercial-preparation]');
            assert.equal(await admin.getByRole('button', { name: 'إعادة المحاولة صراحة بالطلب نفسه', exact: true }).count(), 0);
            await admin.locator('[data-operator-review-history] summary').click();
            assert.ok((await admin.locator('[data-operator-review-history]').textContent()).includes(original.reason));
            assert.equal(await admin.locator('fieldset:disabled').count(), 0);
            assert.equal(await admin.getByText('وصل إقرار التنفيذ.', { exact: true }).count(), 0);
            assert.deepEqual(command('operations-commercial-inspect'), before);
            assert.deepEqual(operatorRequests().map(row => row.status), [200, 409]);
            return { realFounderAdministratorOperator: true, virtualUv: true, operatorAuthorizationCommittedAckLost: true,
                consumedOriginalApproval: approval.id, explicitRetryStatus: 409, archivedOutcome: 'unknown', reloadRestoresHistoryNotCommand: true, noArchiveAuthorityEffects: true };
        } finally { for (const context of contexts) await context.close(); }
    });
}
