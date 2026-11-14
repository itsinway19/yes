import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import Ajv from 'ajv/dist/2020.js';

export async function runSourceCases({ browser, check, login, meta, get, post, command, fixture, result, run, until, held, target }) {
    const stage = process.env.SOURCES_SPA_STAGE ?? 'all';
    const stages = stage.split(',');
    assert.ok(stages.every(value => ['all', 'shell', 'remaining', 'registry', 'reports', 'renew', 'disconnect', 'lifecycle', 'recovery'].includes(value)));
    result.sourcesStage = stage;
    const runCheck = check;
    check = (name, mode, execute) => {
        const group = /registry|service authorization/.test(name) ? 'registry' : /report requests|queued reports/.test(name) ? 'reports'
            : /offline grant/.test(name) ? 'recovery' : /renewal preserve/.test(name) ? 'renew' : /disconnections/.test(name) ? 'disconnect'
                : /superseded|account switch|expired-session|csrf is|CSRF errors|revoked authority/.test(name) ? 'lifecycle' : 'shell';
        return stages.includes('all') || stages.includes(group) || (stages.includes('remaining') && group !== 'shell') ? runCheck(name, mode, execute) : Promise.resolve();
    };
    const sourceBusiness=stage==='all'||!fixture.a.source_business?command('source-fresh'):fixture.a.source_business;
    const workspace=sourceBusiness.business_public_id,other=fixture.a.businesses[1].business_public_id,foreign=fixture.b.businesses[0].business_public_id;
    const base=`/businesses/${workspace}`,home=`${base}?access=${sourceBusiness.grant_refs['business.context.read']}&section=sources`;
    const otherHome=target('application',other),foreignHome=target('application',foreign);
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
    page.setDefaultTimeout(15000); page.on('dialog', dialog => dialog.accept());
    page.on('crash', () => result.errors.push({ mode: 'sources', message: 'Source browser page crashed' }));
    page.on('pageerror', error => result.errors.push({ mode: 'sources', message: error.message }));
    context.on('response', response => { if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 }); });
    const spec = JSON.parse(await readFile('contracts/application.openapi.json', 'utf8'));
    const validate = name => new Ajv({ strict: false, validateFormats: false }).compile({ ...spec, $ref: `#/components/schemas/${name}` });
    const sourceSchema = validate('BusinessSourceRegistryResource');
    const ready = () => page.waitForFunction(workspace => document.querySelector('[data-business-public]')?.getAttribute('data-business-public')===workspace && document.getElementById('application-spa')?.dataset.state==='ready' && !!document.querySelector('[data-business-source-inventory]'),workspace);
    const visit = async url => { await page.evaluate(url => document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(url), url); await page.waitForURL('http://localhost:8138' + url); };
    const panel = provider => page.locator(`dialog[open] section[aria-labelledby="${provider}-connection-heading"]`);
    const open = async provider => { await page.locator(`[data-business-source-inventory] [data-provider="${provider}"] button`).click(); await panel(provider).waitFor(); };
    const close = async () => { await page.locator('.advertising-dialog-heading button').click(); await ready(); };
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
            await page.waitForURL(new RegExp(`/businesses/${workspace}.*#${provider}-flow=`)); await ready();
            const current = panel(provider); await current.locator('[data-source-select]').first().waitFor();
            if (provider === 'google' && await current.locator('[data-source-hierarchy]').count()) {
                const hierarchy = page.waitForResponse(response => new URL(response.url()).pathname.includes('/providers/google/hierarchy/'));
                await current.locator('li').filter({ hasText: '9999999999' }).locator('[data-source-hierarchy]').click(); const hierarchyReply = await hierarchy; assert.equal(hierarchyReply.status(), 200, await hierarchyReply.text());
                await current.locator('[data-source-select]').first().waitFor();
            }
            const selected = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === `${base}/providers/${provider}/select`);
            await current.locator('[data-source-select]').first().click(); const reply = await selected; assert.equal(reply.status(), 200, await reply.text());
            if (provider === 'google') {
                const denied = await post(page, `/businesses/${other}/providers/google/select`, reply.request().postDataJSON());
                assert.ok([403, 409, 422].includes(denied.status));
                const isolated = await get(page, `/businesses/${other}/providers/google`, { 'X-Builderad-Passive': '1' });
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
        if (stage !== 'all' && stage !== 'shell') { await login(page, 'application'); await visit(home); await ready(); }
        await check('canonical Source shell is the current Business-native inventory','sources',async()=>{
            await login(page,'application');const before=await meta(page);await visit(home);await ready();
            assert.equal((await meta(page)).binding.generation,before.binding.generation);
            await visit('/businesses');await page.locator('[data-business-list]').waitFor();await visit(home);await ready();
            const resource=await get(page,base+'/source-registry',{'X-Builderad-Passive':'1'});assert.equal(resource.status,200);assert.ok(sourceSchema(resource.body),JSON.stringify(sourceSchema.errors));
            assert.equal((await get(page,foreignHome)).status,403);assert.equal((await get(page,base+'/providers/meta',{'X-Builderad-Passive':'1'})).status,200);
            const language=async wanted=>{const menu=page.locator('.advertiser-account-menu');if(await menu.getAttribute('open')===null)await menu.locator(':scope > summary').click();await page.locator('[data-application="locale"]').click();await page.waitForFunction(wanted=>document.documentElement.lang===wanted,wanted);await ready();if(await menu.getAttribute('open')!==null)await menu.locator(':scope > summary').click();};
            await language('en');assert.equal(await page.locator('[data-business-public]').count(),1);await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await language('ar');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await page.setViewportSize({width:1280,height:900});
            return{sameDocument:true,locales:['ar','en'],foreignBusiness:403,businessSourceContract:true,overflow:false,publicWorkspacePage:false,screenshots:false};
        });
        for (const provider of ['meta', 'google', 'tiktok', 'snapchat', 'x']) await check(`${provider} real SPA connection, synthetic native OAuth, discovery and exact account selection`, 'sources', () => connectAccount(provider));
        await check('Business registry preserves registration replay, audit recovery and versioned retirement','sources',async()=>{
            const candidates = await get(page, base+'/source-registry');
            assert.equal(candidates.status, 200);
            const source = candidates.body.data.sourceCandidateId;
            const scope={source_id:source,resource:'synthetic-resource',operation:'inventory.read',purpose:'inventory_review',reason:'synthetic_browser_fixture'};
            const registration={...scope,command_id:crypto.randomUUID(),effect:'register',expected_version:0,family:'inventory',namespace:'catalog',external_id:'synthetic-local-'+source,label:'Synthetic local Source'};
            const registered=await post(page,base+'/sources',registration);
            assert.equal(registered.status,200);
            assert.equal(registered.body.registration_state,'UNVERIFIED');
            const replay=await post(page,base+'/sources',registration);
            assert.equal(replay.status,200);
            assert.deepEqual(replay.body,registered.body);
            assert.equal((await post(page,base+'/source-audit/'+registered.body.obligation_id+'/recover',{})).status,200);
            assert.equal((await post(page,base+'/sources',{...scope,command_id:crypto.randomUUID(),effect:'rename',expected_version:1,label:'Corrected Source'})).status,200);
            assert.equal((await post(page,base+'/sources',{...scope,command_id:crypto.randomUUID(),effect:'revoke',expected_version:2})).status,200);
            const projection=await get(page,base+'/source-registry');
            assert.equal(projection.status,200);
            assert.ok(sourceSchema(projection.body),JSON.stringify(sourceSchema.errors));
            assert.equal(projection.body.data.sources.items.find(item=>item.id===source)?.capability.state,'REVOKED');
            assert.equal(JSON.stringify(projection.body).includes('synthetic-web-token'),false);
            return {exactReplay:true,registrationUnverified:true,recovered:true,retired:true};
        });
        await check('report requests travel through Laravel with original unknown command retained and no automatic replay', 'sources', async () => {
            const jobsBefore = command('source-inspect').jobs;
            const admission = command('source-admit'); assert.equal(admission.admitted, 5); result.sourceAdmissionContext = admission;
            await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))); await ready();
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
                    await visit('/businesses'); await page.locator('[data-business-list]').waitFor(); await visit(home); await ready(); await open('google');
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
        await check('validation, command conflict and CSRF errors remain real server failures', 'sources', async () => {
            const snapshot = await snapshots('google'), source = snapshot.connections[0].source_id;
            assert.equal((await post(page, `${base}/providers/google/select`, { selection: 'invalid' })).status, 422);
            assert.equal((await post(page, `${base}/providers/google/${source}/disconnect`, { command_id: crypto.randomUUID(), expected_version: 999 })).status, 409);
            assert.equal((await post(page, `${base}/providers/google/connect`, { intent: 'invalid' }, { 'X-CSRF-TOKEN': 'wrong' })).status, 422);
            const identity = await meta(page);
            const csrf = await context.request.post('http://localhost:8138' + base + '/providers/google/connect', { headers: { Accept: 'application/json', 'X-Builderad-Document': identity.receipt, 'X-CSRF-TOKEN': 'wrong' }, data: { intent: 'invalid' }, maxRedirects: 0 });
            assert.equal(csrf.status(), 419);
            return { statuses: [409, 419, 422] };
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
                    await visit('/businesses'); await page.locator('[data-business-list]').waitFor(); await visit(home); await ready(); await open(provider);
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
            await page.waitForURL(new RegExp(`/businesses/${workspace}.*#google-result=OFFLINE_GRANT_REQUIRED`)); await ready();
            const recovery = panel('google').locator('[data-source-connect="recover"]'); await recovery.waitFor();
            assert.equal(handoffs.at(-1).prompt, null);
            command('source-google-offline-restore');
            const request = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/connect'));
            await recovery.click(); const response = await request; assert.equal(response.status(), 200);
            assert.equal(response.request().postDataJSON().recover_offline_grant, true);
            await page.waitForURL(new RegExp(`/businesses/${workspace}.*#google-flow=`)); await ready();
            assert.equal(handoffs.at(-1).prompt, 'consent');
            const current = panel('google'); await current.locator('[data-source-hierarchy]').first().waitFor();
            await current.locator('li').filter({ hasText: '9999999999' }).locator('[data-source-hierarchy]').click();
            const selected = page.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/select'));
            await current.locator('[data-source-select]').first().click(); assert.equal((await selected).status(), 200);
            await current.locator('[data-source-disconnect]').waitFor(); await close();
            return { missingRefreshToken: 'OFFLINE_GRANT_REQUIRED', recoverOfflineGrant: true, normalPrompt: null, recoveryPrompt: 'consent', nativeCallback: true };
        });
        await check('superseded Source resource is aborted and cannot publish another Business', 'sources', async () => {
            const key = 'late-source-resource'; let once = true;const otherRead=url=>url.pathname===new URL(otherHome,'http://localhost:8138').pathname;
            await page.route(otherRead, async route => {
                if (once && route.request().headers()['x-builderad-witness'] !== '1') { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); }
                else await route.continue();
            });
            await page.evaluate(url => { void document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(url); }, otherHome); await until(() => held.has(key));
            await visit('/businesses'); await page.locator('[data-business-list]').waitFor(); await visit(home); await ready(); held.get(key).deliver(); held.delete(key); await page.waitForTimeout(100);
            assert.equal(await page.locator('[data-business-public]').getAttribute('data-business-public'), workspace);
            await page.unroute(otherRead); return { stalePublication: false, actualLaravelRead: 200 };
        });
        await check('account switch during a real Source request closes the old document and blocks its effects', 'sources', async () => {
            const key = 'account-switch-source'; let once = true;await open('google');
            await page.route(`**${base}/providers/google`, async route => {
                if (once) { once = false; await route.continue({ headers: { ...route.request().headers(), 'X-Proof-Hold': key } }); } else await route.continue();
            });
            await panel('google').getByRole('button', { name: 'تحديث حالة الربط', exact: true }).click({ noWaitAfter: true }); await until(() => held.has(key));
            const sibling = await context.newPage(); await sibling.goto('http://localhost:8138'+home); await sibling.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
            const switched = await post(sibling, '/identity/switch', { email: 'goals-b@example.test', password: 'DisposableGoalsProof42!' }); assert.equal(switched.status, 200);
            await sibling.goto('http://localhost:8138'+foreignHome); await sibling.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
            assert.equal((await meta(sibling)).binding.principal, fixture.b.user);
            await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))); await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed');
            held.get(key).deliver(); held.delete(key); assert.equal(await page.locator('[data-business-public]').count(), 0);
            const denied = await post(page, `${base}/providers/google/select`, { selection: 'old-context' }); assert.ok([401, 409].includes(denied.status));
            const latest = await get(sibling, foreignHome); assert.equal(latest.status, 200);
            await sibling.close(); return { closed: true, oldEffect: denied.status, stalePublication: false };
        });
        for (const failure of ['expired-session', 'csrf']) await check(`Source ${failure} is a terminal real Laravel failure rather than a provider success`, 'sources', async () => {
            const isolated = await browser.newContext(), current = await isolated.newPage(); current.setDefaultTimeout(15000);
            try {
                await login(current, 'application'); await current.evaluate(url => document.getElementById('application-spa').__vue_app__.config.globalProperties.$router.push(url), home);
                await current.waitForFunction(() => !!document.querySelector('[data-business-public]') && document.getElementById('application-spa')?.dataset.state === 'ready');
                if (failure === 'expired-session') { command('expire'); await current.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))); }
                else {
                    await current.route(`**${base}/providers/google/connect`, async route => {
                        const headers = Object.fromEntries(Object.entries(await route.request().allHeaders()).filter(([key]) => !['sec-fetch-site', 'x-csrf-token'].includes(key.toLowerCase())));
                        const response = await isolated.request.fetch(route.request().url(), { method: 'POST', headers: { ...headers, 'X-CSRF-TOKEN': 'invalid' }, data: route.request().postData(), maxRedirects: 0 });
                        await route.fulfill({ response });
                    });
                    await current.locator('[data-business-source-inventory] [data-provider="google"] button').click();
                    const response = current.waitForResponse(reply => reply.request().method() === 'POST' && new URL(reply.url()).pathname.endsWith('/google/connect'));
                    await current.locator('dialog[open] [data-source-connect="google"]').click(); assert.equal((await response).status(), 419);
                }
                await current.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'closed'); assert.equal(await current.locator('[data-business-public]').count(), 0);
                const denied = await get(current,home);
                if (failure === 'expired-session') assert.ok([401, 409].includes(denied.status));
                else { assert.equal(denied.status, 200); assert.equal(await current.evaluate(() => document.getElementById('application-spa')?.dataset.state), 'closed'); }
                return { closed: true, oldResource: denied.status, expectedError: failure === 'csrf' ? 419 : 401, csrfDoesNotRevokeServerSession: failure === 'csrf' };
            } finally { await isolated.close(); }
        });
        await check('revoked authority conceals a previously admitted Source document and denies effects','sources',async()=>{
            const isolated = await browser.newContext(), current = await isolated.newPage();
            try {
                await login(current, 'application');
                await current.goto('http://localhost:8138'+home);
                await current.locator('[data-business-source-inventory]').waitFor();
                const before = await get(current, base+'/providers/google');
                assert.equal(before.status, 200);
                const connection = before.body.connections[0];
                assert.ok(connection);
                command('source-deny-owner');
                assert.equal((await get(current, home)).status, 403);
                assert.equal((await post(current, `${base}/providers/google/${connection.source_id}/disconnect`, {
                    command_id: crypto.randomUUID(), expected_version: connection.access.version,
                })).status, 403);
                await current.evaluate(() => dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
                await current.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'unavailable');
                assert.equal(await current.locator('[data-business-public]').count(), 0);
                return { withdrawnBasis: true, effectDenied: true, staleDocumentConcealed: true };
            } finally { await isolated.close(); }
        });
        if (stage === 'all') {
            assert.deepEqual(handoffs.map(value => value.provider), ['meta', 'google', 'tiktok', 'snapchat', 'x', 'meta', 'tiktok', 'x', 'google', 'google']);
            assert.equal(handoffs.find(value => value.provider === 'google').prompt, null);
        }
    } finally { command('source-snap-restore'); command('source-google-offline-restore'); await context.close(); }
}
