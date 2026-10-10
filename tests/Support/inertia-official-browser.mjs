import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
assert.equal(process.env.APP_ENV, 'testing');
assert.equal(process.env.DB_DATABASE, 'builderad_test_disposable');
assert.equal(process.env.DB_PORT, '5433');
assert.ok(process.env.BUILDERAD_TEST_ISOLATION_TOKEN);
const run = process.env.CURRENT_INTENT_PROOF_RUN;
const fixture = JSON.parse(await readFile(run + '/client-state-fixture.json', 'utf8'));
const base = 'http://localhost:8027';
const goalUrl = id => base + '/workspaces/' + id + '/goals';
const aName = 'نشاط اختبار أهداف المعلن';
const evidence = { environment: { base, database: process.env.DB_DATABASE, port: process.env.DB_PORT }, mechanism: 'Official Inertia encryption and clearHistory ONLY', checks: [], errors: [], leaks: [], responses: [], pageshow: [] };
const browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--disable-back-forward-cache'], executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
const pendingRequests = new WeakMap();
const control = (page, key) => page.locator('[data-goals="' + key + '"]');
const mounted = page => page.waitForFunction(() => !!document.getElementById('app')?.__vue_app__);
const fixtureCommand = mode => {
    const result = spawnSync('C:/tools/php85/php.exe', ['tests/Support/ProtectedClientStateFixture.php', mode], { encoding: 'utf8', env: process.env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout.trim());
};
async function check(name, category, action) {
    try { const details = await action(); evidence.checks.push({ name, category, result: details?.result ?? 'PASS', details, source: 'Real Laravel / real Chromium; synthetic isolated users' }); console.log((details?.result ?? 'PASS') + ' ' + name); }
    catch (error) { evidence.checks.push({ name, category, result: 'FAIL', message: error.message }); console.log('FAIL ' + name + ': ' + error.message); }
}
async function context() {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    ctx.setDefaultTimeout(15000);
    await ctx.addInitScript(aId => {
        window.__officialLeaks = [];
        window.__officialShows = [];
        addEventListener('pageshow', event => {
            const show = { persisted: event.persisted, url: location.href };
            window.__officialShows.push(show); console.log('OFFICIAL_PAGESHOW ' + JSON.stringify(show));
        });
        const sample = () => {
            let forbidden = false;
            try { forbidden = sessionStorage.getItem('official-proof-forbid-a') === '1'; } catch {}
            const app = document.getElementById('app');
            const restoredIdentity = app?.__vue_app__?.config.globalProperties.$page?.props.auth?.user?.id;
            if (forbidden && app && getComputedStyle(app).visibility !== 'hidden' && (restoredIdentity === aId || app.textContent.includes('نشاط اختبار أهداف المعلن'))) {
                if (!window.__officialLeaks.length) console.log('OFFICIAL_LEAK ' + location.href);
                window.__officialLeaks.push(location.href);
            }
            requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
    }, fixture.a);
    ctx.on('page', page => {
        const pending = new Set(); pendingRequests.set(page, pending);
        page.on('request', request => {
            if (request.url().startsWith(base) && ['document', 'fetch', 'xhr'].includes(request.resourceType())) pending.add(request);
        });
        page.on('requestfinished', request => pending.delete(request));
        page.on('requestfailed', request => pending.delete(request));
        page.on('dialog', dialog => dialog.accept());
        page.on('pageerror', error => evidence.errors.push(error.message));
        page.on('console', message => {
            if (message.text().startsWith('OFFICIAL_LEAK ')) evidence.leaks.push(message.text());
            if (message.text().startsWith('OFFICIAL_PAGESHOW ')) evidence.pageshow.push(JSON.parse(message.text().slice('OFFICIAL_PAGESHOW '.length)));
        });
        page.on('response', response => { if (response.url().startsWith(base) && response.request().resourceType() !== 'script' && !response.url().includes('/build/')) evidence.responses.push({ url: response.url(), status: response.status(), inertia: response.request().headers()['x-inertia'] === 'true' }); });
    });
    return ctx;
}
async function login(page, account = 'a') {
    await page.goto(base + '/login'); await mounted(page);
    await page.locator('#email').fill(account === 'a' ? 'goals-proof@example.test' : 'boundary-b@example.test');
    await page.locator('#password').fill('DisposableGoalsProof42!');
    await page.locator('form button').click();
    await page.waitForURL(url => url.pathname !== '/login'); await mounted(page);
}
async function visit(page, url) {
    await page.evaluate(url => document.getElementById('app').__vue_app__.config.globalProperties.$inertia.visit(url), url);
    await page.waitForURL(url); await mounted(page);
}
async function draft(page, workspace = fixture.first) {
    await visit(page, goalUrl(workspace));
    await control(page, 'select-sales').check(); await control(page, 'select-customers').check(); await control(page, 'result-actual_sales').check();
}
async function logout(page) {
    await page.locator('.business-account summary').click(); await page.locator('.business-account-menu button').click();
    await page.waitForURL(base + '/'); await mounted(page);
}
async function assertDraft(page) {
    assert.equal(await control(page, 'select-sales').isChecked(), true);
    assert.equal(await control(page, 'select-customers').isChecked(), true);
    assert.equal(await control(page, 'result-actual_sales').isChecked(), true);
}
async function historyStep(page, direction) {
    if (direction === 'back') await page.goBack({ waitUntil: 'commit' }); else await page.goForward({ waitUntil: 'commit' });
    // BFCache does not emit another load event. Missing encrypted history can
    // also trigger a second real navigation. Observe a quiet, stable document
    // after both transitions; rAF leak monitoring remains active throughout.
    const deadline = Date.now() + 15000;
    let previous = '', quietSince = 0;
    while (Date.now() < deadline) {
        try {
            const snapshot = await page.evaluate(() => ({ url: location.href,
                identity: document.getElementById('app')?.__vue_app__?.config.globalProperties.$page?.props.auth?.user?.id ?? null,
                text: document.getElementById('app')?.textContent ?? '', ready: document.readyState }));
            const signature = JSON.stringify(snapshot);
            if (signature !== previous || pendingRequests.get(page)?.size) { previous = signature; quietSince = Date.now(); }
            else if (Date.now() - quietSince >= 500 && snapshot.ready !== 'loading') return snapshot;
        } catch (error) {
            if (!/context was destroyed|Cannot find context/.test(error.message)) throw error;
            quietSince = Date.now();
        }
        await page.waitForTimeout(50);
    }
    throw new Error('Real browser document did not settle within the existing 15s test timeout');
}
async function deniedWrite(page, url) {
    const csrf = (await page.context().cookies(base)).find(cookie => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post(url, { data: { value: { goals: ['sales'], priority: 'sales', results: {} }, version: 0 }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) } });
    assert.equal(response.status(), 403);
    return response.status();
}
try {
    await check('Same session: two Back/Forward cycles preserve goals, results and original revision', 'OFFICIAL_PROTECTION', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await visit(page, base + '/workspaces/' + fixture.first + '/business'); await draft(page);
            assert.equal(await page.evaluate(() => history.state.page instanceof ArrayBuffer && !!crypto.subtle), true);
            const before = await page.evaluate(id => { const g = document.getElementById('app').__vue_app__.config.globalProperties; return g.$inertia.restore(`advertiser-goals:${g.$page.props.auth.user.id}:${g.$page.props.auth.sessionContextId}:${id}`); }, fixture.first);
            assert.equal(before.baseline.version, 0);
            for (let cycle = 0; cycle < 2; cycle++) { await historyStep(page, 'back'); await historyStep(page, 'forward'); await assertDraft(page); }
            const after = await page.evaluate(id => { const g = document.getElementById('app').__vue_app__.config.globalProperties; return g.$inertia.restore(`advertiser-goals:${g.$page.props.auth.user.id}:${g.$page.props.auth.sessionContextId}:${id}`); }, fixture.first);
            assert.deepEqual(after, before);
            return { cycles: 2, encrypted: true, persistedRows: fixtureCommand('inspect').goals };
        } finally { await ctx.close(); }
    });
    for (let attempt = 1; attempt <= 3; attempt++) await check('A logout / B login / same tab Back-Forward attempt ' + attempt, 'OFFICIAL_PROTECTION', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page);
            const oldKey = await page.evaluate(() => sessionStorage.getItem('historyKey')); assert.ok(oldKey);
            await visit(page, base + '/workspaces/' + fixture.first + '/business'); await logout(page);
            const logoutKey = await page.evaluate(() => sessionStorage.getItem('historyKey')); assert.notEqual(logoutKey, oldKey);
            await login(page, 'b'); await visit(page, goalUrl(fixture.other));
            const bKey = await page.evaluate(() => sessionStorage.getItem('historyKey')); assert.notEqual(bKey, oldKey);
            await page.evaluate(() => sessionStorage.setItem('official-proof-forbid-a', '1'));
            const leakStart = evidence.leaks.length;
            for (let index = 0; index < 5; index++) { const state = await historyStep(page, 'back'); assert.equal(state.text.includes(aName), false); assert.notEqual(state.identity, fixture.a); }
            for (let index = 0; index < 3; index++) { const state = await historyStep(page, 'forward'); assert.equal(state.text.includes(aName), false); assert.notEqual(state.identity, fixture.a); }
            assert.equal(evidence.leaks.length, leakStart);
            await deniedWrite(page, goalUrl(fixture.first));
            return { nativeKeyRotatedAtLogout: true, nativeKeyRotatedAtLogin: logoutKey !== bKey, backSteps: 5, forwardSteps: 3, sampledLeaks: 0, unauthorizedWrite: 403 };
        } finally { await ctx.close(); }
    });
    await check('Workspace switching preserves separate authorized drafts', 'OFFICIAL_PROTECTION', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page); await visit(page, goalUrl(fixture.second));
            assert.equal(await control(page, 'select-sales').isChecked(), false); await control(page, 'select-growth').check();
            await historyStep(page, 'back'); await assertDraft(page); await historyStep(page, 'forward');
            assert.equal(await control(page, 'select-growth').isChecked(), true); assert.equal(await control(page, 'select-sales').isChecked(), false);
            return { isolatedByWorkspace: true };
        } finally { await ctx.close(); }
    });
    await check('Same account after real logout and new login cannot recover old session draft', 'OFFICIAL_PROTECTION', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page); await visit(page, base + '/workspaces/' + fixture.first + '/business');
            await logout(page); await login(page); await visit(page, goalUrl(fixture.first));
            assert.equal(await control(page, 'select-sales').isChecked(), false);
            await historyStep(page, 'back'); await historyStep(page, 'back'); await visit(page, goalUrl(fixture.first));
            assert.equal(await control(page, 'select-customers').isChecked(), false); return { oldDraftAbsent: true };
        } finally { await ctx.close(); }
    });
    await check('Idle expiry: history still restores old page; real server denies current access without renewing expiry', 'REQUIRES_FRESH_SERVER_AUTHORITY', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page); await visit(page, base + '/profile');
            const before = fixtureCommand('expire'); const requestStart = evidence.responses.length;
            await historyStep(page, 'back'); await assertDraft(page);
            assert.deepEqual(fixtureCommand('inspect').sessions, before.sessions);
            const historyRequests = evidence.responses.slice(requestStart).filter(r => r.url === goalUrl(fixture.first));
            assert.equal(historyRequests.length, 0);
            await page.goto(goalUrl(fixture.first)); await page.waitForURL(base + '/login');
            assert.deepEqual(fixtureCommand('inspect').sessions, before.sessions);
            return { result: 'PROVEN_LIMIT', oldDraftDisplayedOnHistory: true, historyServerRequests: 0, freshServerRedirect: '/login', idleDeadlineUnchanged: true };
        } finally { await ctx.close(); }
    });
    await check('Workspace grant revocation: history still restores old page; real server writes remain forbidden', 'REQUIRES_FRESH_SERVER_AUTHORITY', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page); await visit(page, base + '/profile'); fixtureCommand('revoke');
            await historyStep(page, 'back'); await assertDraft(page); await deniedWrite(page, goalUrl(fixture.first));
            const response = await page.request.get(goalUrl(fixture.first)); assert.equal(response.status(), 403);
            return { result: 'PROVEN_LIMIT', oldDraftDisplayedOnHistory: true, freshServerRead: 403, freshServerWrite: 403 };
        } finally { fixtureCommand('restore'); await ctx.close(); }
    });
    await check('Cross-tab identity change does not invalidate another tab\'s sessionStorage encryption key', 'REQUIRES_FRESH_SERVER_AUTHORITY', async () => {
        const ctx = await context(); try {
            const first = await ctx.newPage(); await login(first); await draft(first); const key = await first.evaluate(() => sessionStorage.getItem('historyKey'));
            await visit(first, base + '/workspaces/' + fixture.first + '/business');
            const second = await ctx.newPage(); await second.goto(goalUrl(fixture.first)); await mounted(second); await logout(second); await login(second, 'b');
            await historyStep(first, 'back'); await assertDraft(first);
            assert.equal(await first.evaluate(() => sessionStorage.getItem('historyKey')), key); await deniedWrite(first, goalUrl(fixture.first));
            return { result: 'PROVEN_LIMIT', otherTabKeyUnchanged: true, oldDraftDisplayedOnHistory: true, freshServerWrite: 403 };
        } finally { await ctx.close(); }
    });
    await check('Legacy plaintext history is not retroactively encrypted or invalidated by enabling encryption and clearHistory', 'LEGACY_HISTORY_LIMIT', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page);
            await ctx.setExtraHTTPHeaders({ 'X-Proof-Legacy-History': '1' }); await page.goto(goalUrl(fixture.first)); await mounted(page);
            await control(page, 'select-sales').check(); await control(page, 'result-actual_sales').check();
            await page.waitForFunction(() => history.state.page && !(history.state.page instanceof ArrayBuffer));
            assert.notEqual(await page.evaluate(() => history.state.page.encryptHistory), true);
            await ctx.setExtraHTTPHeaders({}); await visit(page, base + '/workspaces/' + fixture.first + '/business');
            assert.equal(await page.evaluate(() => history.state.page instanceof ArrayBuffer), true);
            await logout(page); await login(page, 'b'); await visit(page, goalUrl(fixture.other));
            let restored = false;
            for (let index = 0; index < 7; index++) {
                await historyStep(page, 'back');
                if (await page.evaluate(name => (document.getElementById('app')?.textContent ?? '').includes(name), aName)) { restored = true; break; }
            }
            assert.equal(restored, true);
            assert.equal(await control(page, 'select-sales').isChecked(), true);
            await deniedWrite(page, goalUrl(fixture.first));
            return { result: 'PROVEN_LIMIT', realApplicationWithEncryptionDisabledForLegacyEntry: true, encryptedSubsequentEntry: true, oldPlaintextAContentRestoredAfterBLogin: true, freshServerWrite: 403 };
        } finally { await ctx.close(); }
    });
    await check('Actual BFCache restoration with expired authority', 'BFCACHE', async () => {
        const ctx = await context(); try {
            const page = await ctx.newPage(); await login(page); await draft(page); await page.goto(base + '/'); await mounted(page); fixtureCommand('expire');
            const showStart = evidence.pageshow.length; await historyStep(page, 'back');
            const persisted = evidence.pageshow.slice(showStart).some(show => show.persisted);
            if (!persisted) return { result: 'NOT VERIFIED', reason: 'No actual pageshow.persisted=true recorded in this Chromium run; no simulated event substituted.' };
            const oldVisible = await page.evaluate(name => (document.getElementById('app')?.textContent ?? '').includes(name), aName);
            return { result: 'PROVEN_LIMIT', actualPersistedPageshow: true, oldContentVisible: oldVisible };
        } finally { await ctx.close(); }
    });
} finally {
    await writeFile(run + '/inertia-official-browser-evidence.json', JSON.stringify(evidence, null, 2));
    await browser.close();
}
if (evidence.checks.some(check => check.result === 'FAIL')) process.exitCode = 1;
