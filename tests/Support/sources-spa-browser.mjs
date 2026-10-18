import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import Ajv from 'ajv/dist/2020.js';

export async function runSourceCases({ browser, check, login, meta, get, post, command, fixture, result, run, until, held }) {
    const stage = process.env.SOURCES_SPA_STAGE ?? 'all';
    const stages = stage.split(',');
    assert.ok(stages.every(value => ['all', 'shell', 'remaining', 'registry', 'reports', 'renew', 'disconnect', 'lifecycle', 'recovery'].includes(value)));
    result.sourcesStage = stage;
    const runCheck = check;
    check = (name, mode, execute) => {
        const group = /registry|service authorization/.test(name) ? 'registry' : /report requests|queued reports/.test(name) ? 'reports'
            : /offline grant/.test(name) ? 'recovery' : /renewal preserve/.test(name) ? 'renew' : /disconnections/.test(name) ? 'disconnect'
                : /superseded|account switch|expired-session|csrf is|revoked authority/.test(name) ? 'lifecycle' : 'shell';
        return stages.includes('all') || stages.includes(group) || (stages.includes('remaining') && group !== 'shell') ? runCheck(name, mode, execute) : Promise.resolve();
    };
    if (stage === 'all') fixture.a.workspaces[0] = command('source-fresh');
    const workspace = fixture.a.workspaces[0].public_id, other = fixture.a.workspaces[1].public_id, foreign = fixture.b.workspaces[0].public_id;
    const base = `/workspaces/${workspace}`;
    command('restore-view'); command('restore');
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.setDefaultTimeout(15000); page.on('dialog', dialog => dialog.accept());
    page.on('crash', () => result.errors.push({ mode: 'sources', message: 'Source browser page crashed' }));
    page.on('pageerror', error => result.errors.push({ mode: 'sources', message: error.message }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const validate = name => new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: `#/components/schemas/${name}` });
    const sourceSchema = validate('WorkspaceSourcesResource');
    const ready = () => page.waitForFunction(workspace => document.querySelector(`[data-sources-workspace="${workspace}"]`) && document.getElementById('application-spa')?.dataset.state === 'ready', workspace);
    const visit = async url => { await page.evaluate(url => __goalsSpa.router.push(url), url); await page.waitForURL('http://localhost:8138' + url); };
    const panel = provider => page.locator(`dialog[open] section[aria-labelledby="${provider}-connection-heading"]`);
    const open = async provider => { await page.locator(`[data-provider="${provider}"] .source-action`).click(); await panel(provider).waitFor(); };
    const close = async () => { await page.locator('.source-dialog-close').click(); await ready(); };
    const snapshots = async provider => { const response = await get(page, `${base}/providers/${provider}`, { 'X-Builderad-Passive': '1' }); assert.equal(response.status, 200, JSON.stringify(response)); return response.body; };
    const handoffs = [];
    await context.route(/^https:\/\/(www\.facebook\.com|accounts\.google\.com|business-api\.tiktok\.com|accounts\.snapchat\.com|api\.(x|twitter)\.com)\//, async route => {
        const url = new URL(route.request().url());
        const provider = url.hostname.includes('facebook') ? 'meta' : url.hostname.includes('google') ? 'google' : url.hostname.includes('tiktok') ? 'tiktok' : url.hostname.includes('snapchat') ? 'snapchat' : 'x';
        handoffs.push({ provider, queryKeys: [...url.searchParams.keys()], prompt: url.searchParams.get('prompt') });
        const query = provider === 'x' ? new URLSearchParams({ oauth_token: url.searchParams.get('oauth_token'), oauth_verifier: 'synthetic-verifier' })
            : new URLSearchParams({ state: url.searchParams.get('state'), [provider === 'tiktok' ? 'auth_code' : 'code']: 'synthetic-native-code' });
        await route.fulfill({ status: 302, headers: { Location: `http://localhost:8138/providers/${provider}/callback?${query}` }, body: '' });
    });
    async function connectAccount(provider, reconnect = false) {
        const previousIds = reconnect ? (await snapshots(provider)).connections.map(value => value.source_id) : [];

            await open(provider);
            await panel(provider).locator(`[data-source-connect="${provider}"]`).waitFor();
            const connected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === `${base}/providers/${provider}/connect`);
            await panel(provider).locator(`[data-source-connect="${provider}"]`).click();
            assert.equal((await connected).status(), 200);
            await page.waitForURL(new RegExp(`/workspaces/${workspace}#${provider}-flow=`)); await ready();
            const current = panel(provider); await current.locator('[data-source-select]').first().waitFor();
            if (provider === 'google' && await current.locator('[data-source-hierarchy]').count()) {
                const hierarchy = page.waitForResponse(response => new URL(response.url()).pathname.includes('/providers/google/hierarchy/'));
                await current.locator('li').filter({ hasText: '9999999999' }).locator('[data-source-hierarchy]').click(); const hierarchyReply = await hierarchy; assert.equal(hierarchyReply.status(), 200, await hierarchyReply.text());
                await current.locator('[data-source-select]').first().waitFor();
            }
            const selected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === `${base}/providers/${provider}/select`);
            await current.locator('[data-source-select]').first().click(); const reply = await selected; assert.equal(reply.status(), 200, await reply.text());
            if (provider === 'google') {
                const denied = await post(page, `/workspaces/${other}/providers/google/select`, reply.request().postDataJSON());
                assert.ok([403, 409, 422].includes(denied.status));
                const isolated = await get(page, `/workspaces/${other}/providers/google`, { 'X-Builderad-Passive': '1' });
                assert.equal(isolated.status, 200); assert.equal(isolated.body.connections.length, 0);
            }
            await current.locator('[data-source-disconnect]').first().waitFor();
            const snapshot = await snapshots(provider); assert.equal(snapshot.connections.length, 1);
            if (reconnect) assert.deepEqual(snapshot.connections.map(value => value.source_id), previousIds);
            assert.notEqual(snapshot.connections[0].access.effective.state, 'READY');
            const text = JSON.stringify(snapshot) + await current.innerText();
            for (const secret of ['synthetic-web-token', 'synthetic-new-access', 'synthetic-new-refresh', 'synthetic-tiktok-token', 'synthetic-snap-access', 'synthetic-snap-refresh', 'synthetic-user-secret', 'synthetic-app-secret']) assert.equal(text.includes(secret), false, secret);
            await close(); assert.equal(new URL(page.url()).hash, '');
            return { nativeCallback: true, existingWriter: true, connectedNotReady: true, syntheticOnly: true, sources: snapshot.connections.length };
    }
    try {
        if (stage !== 'all' && stage !== 'shell') { await login(page, 'application'); await visit(base); await ready(); }
        await check('canonical Source shell uses the unified router and exact private scoped resource', 'sources', async () => {
            await login(page, 'application'); const before = await meta(page);
            await page.getByRole('banner').getByRole('link', { name: 'المصادر', exact: true }).click(); await ready();
            assert.equal((await meta(page)).binding.generation, before.binding.generation);
            await visit('/workspaces'); await page.locator(`a.workspace-row[href="${base}"]`).click(); await ready();
            assert.equal((await meta(page)).binding.generation, before.binding.generation);
            const resource = await get(page, base, { 'X-Builderad-Passive': '1' }); assert.equal(resource.status, 200); assert.ok(sourceSchema(resource.body), JSON.stringify(sourceSchema.errors));
            assert.equal((await get(page, `/workspaces/${foreign}`)).status, 403);
            assert.equal((await get(page, base + '/providers/meta', { 'X-Builderad-Passive': '1' })).status, 200);
            assert.equal(await page.locator('[data-page]').count(), 0);
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'en');
            assert.equal(await page.locator('[data-sources-workspace]').count(), 1);
            await page.setViewportSize({ width: 390, height: 844 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth), 0);
            await page.locator('[data-application="locale"]').click(); await page.waitForFunction(() => document.documentElement.lang === 'ar');
            await page.screenshot({ path: path.join(run, 'sources-ar-mobile.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 });
            return { sameDocument: true, locales: ['ar', 'en'], foreignWorkspace: 403, overflow: 0 };
        });
        for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) await check(`${provider} real SPA connection, synthetic native OAuth, discovery and exact account selection`, 'sources', () => connectAccount(provider));
        await check('registry grants, registration, scope declaration, correction, recovery and revocation use real Laravel effects', 'sources', async () => {
            await page.locator('details > summary').click();
            const registry = page.locator('section[aria-labelledby="sources-heading"]');
            const grant = registry.locator('form').filter({ hasText: 'طلب صلاحية صريحة' });
            const source = await grant.getByLabel('معرّف المصدر', { exact: true }).inputValue();
            const authorize = async (action, operation = 'report.read') => {
                await grant.getByLabel('معرّف المصدر', { exact: true }).fill(source);
                await grant.getByLabel('المورد', { exact: true }).fill('synthetic-resource');
                await grant.getByLabel('العملية (رمز)', { exact: true }).fill(operation);
                await grant.getByLabel('الغرض (رمز)', { exact: true }).fill('advertising_analysis');
                await grant.locator('select').first().selectOption(action);
                const response = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/source-grants');
                await grant.getByRole('button', { name: 'تأكيد القرار الصريح' }).click(); const reply = await response; assert.equal(reply.status(), 200, await reply.text());
                await grant.getByRole('button').waitFor({ state: 'visible' }); await ready();
            };
            for (const action of ['source.register', 'source.read', 'source.manage', 'source.audit.recover']) await authorize(action);
            const registration = registry.locator('form').filter({ hasText: 'تسجيل الهوية المعلنة' });
            await registration.locator('input[list="source-families"]').fill('business');
            for (const [name, value] of [['نطاق المعرّف', 'account'], ['معرّف الحساب المعلن', 'synthetic-local-' + source], ['الاسم المعلن', 'Synthetic local Source']]) await registration.getByLabel(name, { exact: true }).fill(value);
            const registered = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/sources');
            await registration.locator(`[data-register-source="${source}"]`).click(); const reply = await registered; assert.equal(reply.status(), 200, await reply.text());
            const item = () => registry.locator('article').filter({ hasText: source }).first(); await item().waitFor();
            await authorize('source.manage', 'discovery.read'); await authorize('source.read', 'discovery.read'); await authorize('source.audit.recover', 'discovery.read');
            const declaration = item().locator('form').filter({ hasText: 'إعلان نطاق إضافي' });
            await declaration.locator('select').selectOption('0');
            const declared = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/sources');
            await declaration.getByRole('button', { name: 'تأكيد إعلان النطاق' }).click(); assert.equal((await declared).status(), 200);
            await item().getByRole('button', { name: 'إعادة تسليم الالتزام نفسه' }).first().waitFor();
            const recovered = page.waitForResponse(reply => reply.request().method() === 'POST' && /\/source-audit\/[^/]+\/recover$/.test(new URL(reply.url()).pathname));
            await item().getByRole('button', { name: 'إعادة تسليم الالتزام نفسه' }).first().click(); assert.equal((await recovered).status(), 200);
            page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.accept(dialog.type() === 'prompt' ? 'Synthetic corrected Source' : undefined));
            const corrected = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/sources');
            await item().getByRole('button', { name: 'تصحيح الاسم' }).click(); assert.equal((await corrected).status(), 200);
            await item().getByRole('heading', { name: /Synthetic corrected Source/ }).waitFor();
            const revoked = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/sources');
            await item().getByRole('button', { name: 'سحب المصدر', exact: true }).click(); assert.equal((await revoked).status(), 200);
            await item().getByText('المصدر مسحوب؛ لا يسمح بالاستخدام المحمي', { exact: false }).waitFor();
            return { register: 200, declareScope: 200, recover: 200, rename: 200, revoke: 200, providerVerificationInvented: false };
        });
        await check('existing service authorization and revocation work from the Source screen without exposing credentials', 'sources', async () => {
            const service = command('source-service');
            const section = page.locator('section').filter({ has: page.locator('form.biz-form') }).last();
            const form = section.locator('form.biz-form'); await form.locator('input').nth(0).fill(service.public_id); await form.locator('input').nth(1).fill('Synthetic explicit Source service purpose');
            const authorized = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname === base + '/services');
            await form.locator('button').click(); assert.equal((await authorized).status(), 200);
            const row = page.locator('.service-list li').filter({ hasText: service.public_id }); await row.waitFor();
            const revoked = page.waitForResponse(reply => reply.request().method() === 'DELETE' && new URL(reply.url()).pathname === base + '/services/' + service.public_id);
            await row.locator('button').click(); assert.equal((await revoked).status(), 200); await row.waitFor({ state: 'detached' });
            return { authorize: 200, revoke: 200, identityCreation: 'isolated fixture only', credentialExposed: false };
        });
        await check('report requests travel through Laravel with original unknown command retained and no automatic replay', 'sources', async () => {
            const jobsBefore = command('source-inspect').jobs;
            const admission = command('source-admit'); assert.equal(admission.admitted, 5); result.sourceAdmissionContext = admission;
            await page.evaluate(() => __goalsSpa.resume()); await ready();
            let original;
            for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) {
                if (['meta', 'tiktok', 'x'].includes(provider)) await connectAccount(provider, true);
                await open(provider); const current = panel(provider), report = current.locator(`[data-source-report="${provider}"]`);
                if (provider === 'google' || provider === 'snapchat') {
                    const renewed = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/renew'));
                    await current.locator('[data-source-renew]').click(); const reply = await renewed; assert.equal(reply.status(), 200, await reply.text());
                    await current.getByText('تم تجديد الربط والتحقق', { exact: false }).waitFor();
                }
                await current.getByRole('button', { name: /^تحديث حالة الربط$|^تحديث الحالة$/ }).click();
                const verified = (await snapshots(provider)).connections[0];
                assert.equal(verified.access.reporting.can_report, true, JSON.stringify({ provider, source: verified.source_id, access: verified.access }));
                await report.locator('form').waitFor();
                await report.locator('input[type=date]').nth(0).fill('2026-09-01'); await report.locator('input[type=date]').nth(1).fill('2026-09-02');
                if (provider === 'meta' || provider === 'x') await report.locator('select').last().selectOption('SYNC');
                if (provider === 'x') {
                    const campaigns = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/campaigns'));
                    await report.getByRole('button', { name: 'اختيار الحملات من حساب X' }).click(); assert.equal((await campaigns).status(), 200);
                    await report.locator('input[type=checkbox]').first().check();
                }
                const posts = []; let lose = provider === 'google';
                const pattern = `**${base}/providers/${provider}/*/report`;
                await page.route(pattern, async route => {
                    if (route.request().method() !== 'POST') return route.continue(); posts.push(route.request().postDataJSON());
                    if (!lose) return route.continue(); lose = false; const response = await route.fetch(); assert.equal(response.status(), 202); await route.abort('failed');
                });
                const response = provider === 'google' ? null : page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.includes(`/providers/${provider}/`) && new URL(response.url()).pathname.endsWith('/report'));
                await report.locator('button[type=submit]').click();
                if (provider === 'google') {
                    await report.getByText('تعذّر تأكيد نتيجة الطلب.', { exact: false }).waitFor();
                    assert.equal(posts.length, 1); original = posts[0];
                    await visit(base + '/goals'); await page.locator('[data-goals-workspace]').waitFor(); await visit(base); await ready(); await open('google');
                    const restored = panel('google').locator('[data-source-report="google"]'); assert.equal(await restored.locator('input[type=date]').first().inputValue(), '2026-09-01');
                    assert.equal(await restored.locator('input[type=date]').first().isDisabled(), true);
                    await restored.locator('button[type=submit]').click();
                    await restored.getByText('تم إرسال طلب السحب', { exact: false }).waitFor(); assert.deepEqual(posts[1], original);
                } else { const acknowledged = await response; assert.equal(acknowledged.status(), 202); await report.getByText('تم إرسال طلب السحب', { exact: false }).waitFor(); }
                await page.unroute(pattern); await close();
            }
            const before = command('source-inspect'); assert.equal(before.jobs - jobsBefore, 6);
            return { providers: 5, queueAcknowledgement: 202, googleDeliveries: 2, originalGeneration: original.expected_generation, automaticReplay: false, liveProviderEvidence: false };
        });
        await check('revoked authority, validation and CSRF errors remain real server failures', 'sources', async () => {
            const snapshot = await snapshots('google'), source = snapshot.connections[0].source_id;
            assert.equal((await post(page, `${base}/providers/google/select`, { selection: 'invalid' })).status, 422);
            assert.equal((await post(page, `${base}/providers/google/${source}/disconnect`, { command_id: crypto.randomUUID(), expected_version: 999 })).status, 409);
            assert.equal((await post(page, `${base}/providers/google/connect`, { intent: 'invalid' }, { 'X-CSRF-TOKEN': 'wrong' })).status, 422);
            const identity = await meta(page);
            const csrf = await context.request.post('http://localhost:8138' + base + '/providers/google/connect', { headers: { Accept: 'application/json', 'X-Builderad-Document': identity.receipt, 'X-CSRF-TOKEN': 'wrong' }, data: { intent: 'invalid' }, maxRedirects: 0 });
            assert.equal(csrf.status(), 419);
            command('deny-view'); assert.equal((await get(page, base)).status, 403);
            assert.equal((await post(page, `${base}/providers/google/${source}/disconnect`, { command_id: crypto.randomUUID(), expected_version: snapshot.connections[0].access.version })).status, 403);
            await page.evaluate(() => __goalsSpa.resume()); await page.waitForFunction(() => __goalsSpa.status.value === 'unavailable');
            assert.equal(await page.locator('[data-sources-workspace]').count(), 0);
            command('restore-view'); await page.evaluate(() => __goalsSpa.resume()); await ready();
            return { statuses: [403, 409, 419, 422], ordinaryPermissionDenialDoesNotAuthenticate: true };
        });
        await check('queued reports execute canonical workers and their real result status returns to the SPA', 'sources', async () => {
            const execution = command('source-run-reports'); assert.ok(execution.handled >= 6);
            const states = {};
            for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) {
                await open(provider); const report = panel(provider).locator(`[data-source-report="${provider}"]`);
                await report.getByRole('button', { name: 'تحديث حالة الفترة', exact: true }).click();
                await report.getByText('آخر نتيجة سحب لهذه الفترة:', { exact: false }).waitFor();
                states[provider] = await report.getByText('آخر نتيجة سحب لهذه الفترة:', { exact: false }).innerText();
                if (provider === 'google') {
                    const link = report.getByRole('link', { name: /تنزيل تقرير حملات Google/ }); await link.waitFor();
                    const response = await get(page, await link.getAttribute('href')); assert.equal(response.status, 200); assert.equal(typeof response.body, 'string');
                    assert.ok(response.body.includes('campaign')); assert.equal(response.body.includes('synthetic-new-refresh'), false);
                    const download = page.waitForEvent('download'); await link.click(); assert.equal(await (await download).failure(), null);
                }
                await close();
            }
            return { handled: execution.handled, states, nativeGoogleCsv: true, syntheticResultsOnly: true };
        });
        await check('Google and Snapchat renewal preserve success versus provider action-required semantics', 'sources', async () => {
            for (const provider of ['google', 'snapchat']) {
                await open(provider); const current = panel(provider);
                const renewed = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/renew'));
                await current.locator('[data-source-renew]').click(); const response = await renewed; assert.equal(response.status(), 200, await response.text());
                const body = await response.json(); assert.ok(body.receipt); assert.equal(body.status, undefined);
                await current.getByText('تم تجديد الربط والتحقق', { exact: false }).waitFor();
                if (provider === 'snapchat') {
                    command('source-snap-deny'); const denied = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/renew'));
                    await current.locator('[data-source-renew]').click(); const refusal = await denied; assert.equal(refusal.status(), 502);
                    const failure = await refusal.json(); assert.equal(failure.status, 'PROVIDER_ACTION_REQUIRED'); assert.equal('receipt' in failure, false);
                    await current.getByText('يحتاج تفويضًا جديدًا', { exact: false }).waitFor();
                    assert.equal(await current.getByText('تم تجديد الربط والتحقق', { exact: false }).count(), 0); command('source-snap-restore');
                }
                await close();
            }
            return { success: 200, actionRequired: 502, failurePromotedToSuccess: false };
        });
        await check('all provider disconnections are local and unknown acknowledgements require explicit original retry', 'sources', async () => {
            for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) {
                await open(provider); const current = panel(provider), original = (await snapshots(provider)).connections[0];
                await current.locator('[data-source-disconnect]').click();
                const pattern = `**${base}/providers/${provider}/*/disconnect`; const bodies = []; let lose = provider === 'google', committed = false;
                await page.route(pattern, async route => {
                    bodies.push(route.request().postDataJSON());
                    if (!lose) return route.continue(); lose = false; const reply = await route.fetch(); assert.equal(reply.status(), 200); committed = true; await route.abort('failed');
                });
                if (provider === 'google') {
                    await current.locator('[data-source-confirm-disconnect]').click(); await until(() => committed, 'actual disconnect committed before losing its acknowledgement');
                    await current.getByRole('button', { name: 'إعادة إرسال الأمر الأصلي', exact: true }).click({ trial: true });
                    await visit(base + '/goals'); await page.locator('[data-goals-workspace]').waitFor(); await visit(base); await ready(); await open(provider);
                    await page.reload(); await ready(); await open(provider);
                    assert.equal(bodies.length, 1); await panel(provider).getByRole('button', { name: 'إعادة إرسال الأمر الأصلي', exact: true }).click();
                    await panel(provider).locator('[data-source-pending]').waitFor({ state: 'detached' }); assert.deepEqual(bodies[1], bodies[0]);
                    assert.equal(bodies[1].expected_version, original.access.version);
                } else {
                    const response = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/disconnect'));
                    await current.locator('[data-source-confirm-disconnect]').click(); const reply = await response; assert.equal(reply.status(), 200, await reply.text());
                }
                await page.unroute(pattern); const after = (await snapshots(provider)).connections[0]; assert.equal(after.access.connection, 'LOCALLY_DISCONNECTED');
                await close();
            }
            return { providers: 5, googleDeliveries: 2, automaticReplay: false, remoteRevocationClaimed: false };
        });
        await check('Google missing offline grant returns through Laravel and explicit recovery alone requests consent', 'sources', async () => {
            command('source-google-offline-missing');
            await open('google');
            await panel('google').locator('[data-source-connect="google"]').click();
            await page.waitForURL(new RegExp(`/workspaces/${workspace}#google-result=OFFLINE_GRANT_REQUIRED`)); await ready();
            const recovery = panel('google').locator('[data-source-connect="recover"]'); await recovery.waitFor();
            assert.equal(handoffs.at(-1).prompt, null);
            command('source-google-offline-restore');
            const request = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/connect'));
            await recovery.click(); const response = await request; assert.equal(response.status(), 200);
            assert.equal(response.request().postDataJSON().recover_offline_grant, true);
            await page.waitForURL(new RegExp(`/workspaces/${workspace}#google-flow=`)); await ready();
            assert.equal(handoffs.at(-1).prompt, 'consent');
            const current = panel('google'); await current.locator('[data-source-hierarchy]').first().waitFor();
            await current.locator('li').filter({ hasText: '9999999999' }).locator('[data-source-hierarchy]').click();
            const selected = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/select'));
            await current.locator('[data-source-select]').first().click(); assert.equal((await selected).status(), 200);
            await current.locator('[data-source-disconnect]').waitFor(); await close();
            return { missingRefreshToken: 'OFFLINE_GRANT_REQUIRED', recoverOfflineGrant: true, normalPrompt: null, recoveryPrompt: 'consent', nativeCallback: true };
        });
        await check('superseded Source resource is aborted and cannot publish another Workspace', 'sources', async () => {
            const key = 'late-source-resource'; let once = true;
            await page.route(`**/workspaces/${other}`, async route => {
                if (once && route.request().headers()['x-builderad-witness'] !== '1') { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); }
                else await route.continue();
            });
            await page.evaluate(url => { void __goalsSpa.router.push(url); }, `/workspaces/${other}`); await until(() => held.has(key));
            await visit(base + '/goals'); await page.locator('[data-goals-workspace]').waitFor(); await visit(base); await ready(); held.get(key).deliver(); held.delete(key); await page.waitForTimeout(100);
            assert.equal(await page.locator('[data-sources-workspace]').getAttribute('data-sources-workspace'), workspace);
            await page.unroute(`**/workspaces/${other}`); return { stalePublication: false, actualLaravelRead: 200 };
        });
        await check('account switch during a real Source request closes the old document and blocks its effects', 'sources', async () => {
            const key = 'account-switch-source'; let once = true;
            await page.route(`**${base}/providers/google`, async route => {
                if (once) { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); } else await route.continue();
            });
            await open('google'); await panel('google').getByRole('button', { name: 'تحديث حالة الربط', exact: true }).click({ noWaitAfter: true }); await until(() => held.has(key));
            const sibling = await context.newPage(); await sibling.goto('http://localhost:8138' + base + '/goals'); await sibling.waitForFunction(() => __goalsSpa.status.value === 'ready');
            const switched = await post(sibling, '/identity/switch', { email: 'goals-b@example.test', password: 'DisposableGoalsProof42!' }); assert.equal(switched.status, 200);
            await sibling.goto(`http://localhost:8138/workspaces/${foreign}/goals`); await sibling.waitForFunction(() => __goalsSpa.status.value === 'ready');
            assert.equal((await meta(sibling)).binding.principal, fixture.b.user);
            await page.evaluate(() => __goalsSpa.resume()); await page.waitForFunction(() => __goalsSpa.status.value === 'closed');
            held.get(key).deliver(); held.delete(key); assert.equal(await page.locator('[data-sources-workspace]').count(), 0);
            const denied = await post(page, `${base}/providers/google/select`, { selection: 'old-context' }); assert.ok([401, 409].includes(denied.status));
            const latest = await get(sibling, `/workspaces/${foreign}/goals`); assert.equal(latest.status, 200);
            await sibling.close(); return { closed: true, oldEffect: denied.status, stalePublication: false };
        });
        for (const failure of ['expired-session', 'csrf']) await check(`Source ${failure} is a terminal real Laravel failure rather than a provider success`, 'sources', async () => {
            const isolated = await browser.newContext(), current = await isolated.newPage(); current.setDefaultTimeout(15000);
            try {
                await login(current, 'application'); await current.evaluate(url => __goalsSpa.router.push(url), base);
                await current.waitForFunction(() => !!document.querySelector('[data-sources-workspace]') && __goalsSpa.status.value === 'ready');
                if (failure === 'expired-session') { command('expire'); await current.evaluate(() => __goalsSpa.resume()); }
                else {
                    await current.route(`**${base}/providers/google/connect`, async route => {
                        const headers = Object.fromEntries(Object.entries(await route.request().allHeaders()).filter(([key]) => !['sec-fetch-site', 'x-csrf-token'].includes(key.toLowerCase())));
                        const response = await isolated.request.fetch(route.request().url(), { method: 'POST', headers: { ...headers, 'X-CSRF-TOKEN': 'invalid' }, data: route.request().postData(), maxRedirects: 0 });
                        await route.fulfill({ response });
                    });
                    await current.locator('[data-provider="google"] .source-action').click();
                    const response = current.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/connect'));
                    await current.locator('dialog[open] [data-source-connect="google"]').click(); assert.equal((await response).status(), 419);
                }
                await current.waitForFunction(() => __goalsSpa.status.value === 'closed'); assert.equal(await current.locator('[data-sources-workspace]').count(), 0);
                const denied = await get(current, base);
                if (failure === 'expired-session') assert.ok([401, 409].includes(denied.status));
                else { assert.equal(denied.status, 200); assert.equal(await current.evaluate(() => __goalsSpa.status.value), 'closed'); }
                return { closed: true, oldResource: denied.status, expectedError: failure === 'csrf' ? 419 : 401, csrfDoesNotRevokeServerSession: failure === 'csrf' };
            } finally { await isolated.close(); }
        });
        if (stage === 'all') {
            assert.deepEqual(handoffs.map(value => value.provider), ['meta', 'google', 'tiktok', 'snapchat', 'x', 'meta', 'tiktok', 'x', 'google', 'google']);
            assert.equal(handoffs.find(value => value.provider === 'google').prompt, null);
        }
    } finally { command('restore-view'); command('source-snap-restore'); command('source-google-offline-restore'); await context.close(); }
}
