import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';

export async function runRestorationControls(harness) {
    const { browser, check, command, result } = harness;
    const origin = 'http://localhost:8138';
    const ready = (page, selector) => page.waitForFunction(selector => document.getElementById('application-spa')?.dataset.state === 'ready' && document.querySelector(selector), selector);
    const processInfo = spawnSync('C:/Program Files/PowerShell/7/pwsh.exe', ['-NoProfile', '-NonInteractive', '-Command', `Get-CimInstance Win32_Process | Where-Object { $_.ParentProcessId -eq ${process.pid} -and $_.Name -eq 'chrome.exe' } | Select-Object ProcessId,CommandLine | ConvertTo-Json -Compress`], { encoding: 'utf8', windowsHide: true });
    assert.equal(processInfo.status, 0, processInfo.stderr);
    const browserProcess = JSON.parse(processInfo.stdout);
    assert.ok(browserProcess, 'Actual task-owned browser command line must be recorded without changing its flags');
    result.restorationBrowserProcess = browserProcess;
    for (const mode of ['passive', 'cdp-observed', 'scripts-disabled']) {
        for (const action of ['unchanged-control', 'logout', 'identity-switch', 'session-revocation', 'offline']) {
            await check(`production restoration ${action} with ${mode}`, 'retirement-restoration', async () => {
                const context = await browser.newContext(), events = [], reasons = [], documents = [], frames = [], failedRequests = [];
                let session, browserArguments;
                let phase = 'initial-guest-navigation';
                context.on('requestfailed', request => failedRequests.push({ path: new URL(request.url()).pathname, method: request.method(), resource: request.resourceType(), error: request.failure()?.errorText, phase, at: Date.now() }));
                context.on('page', page => {
                    page.on('dialog', dialog => dialog.accept());
                    page.on('pageerror', error => result.errors.push({ mode, action, message: error.message }));
                    page.on('console', message => {
                        if (message.text().startsWith('RETIREMENT_EVENT ')) events.push(JSON.parse(message.text().slice('RETIREMENT_EVENT '.length)));
                    });
                });
                context.on('response', response => {
                    const securityHeaders = ['cache-control', 'pragma', 'expires', 'vary', 'content-type', 'cross-origin-opener-policy', 'cross-origin-resource-policy', 'referrer-policy', 'content-security-policy', 'x-frame-options', 'x-builderad-document-location'];
                    if (response.request().resourceType() === 'document') documents.push({ url: response.url(), status: response.status(), headers: Object.fromEntries(Object.entries(response.headers()).filter(([name]) => securityHeaders.includes(name))) });
                    if (response.url().includes('/build/') && response.status() === 200) result.assets.push({ url: response.url(), status: 200 });
                });
                await context.addInitScript(() => {
                    const id = globalThis.crypto?.randomUUID?.() ?? 'observer-' + Date.now() + '-' + Math.random();
                    for (const type of ['pagehide', 'pageshow']) addEventListener(type, event => {
                        const facts = { type, document: id, path: location.pathname, persisted: event.persisted, trusted: event.isTrusted };
                        console.log('RETIREMENT_EVENT ' + JSON.stringify(facts));
                        queueMicrotask(() => {
                            const root = document.querySelector('[data-spa-private]');
                            console.log('RETIREMENT_EVENT ' + JSON.stringify({ ...facts, phase: 'observer-microtask-not-a-compositor-or-all-handlers-barrier', state: document.getElementById('application-spa')?.dataset.state, privateRendered: !!root?.getClientRects().length }));
                        });
                    });
                });
                const page = await context.newPage(); page.setDefaultTimeout(15000);
                try {
                    if (mode !== 'passive') {
                        session = await context.newCDPSession(page); await session.send('Page.enable');
                        session.on('Page.backForwardCacheNotUsed', event => reasons.push(event));
                        session.on('Page.frameNavigated', event => frames.push({ type: event.type, url: event.frame.url }));
                        browserArguments = browserProcess;
                    }
                    await page.goto(origin + '/profile'); await ready(page, '[data-auth-kind="login"]');
                    phase = 'authentication';
                    await page.locator('#email').fill('goals-a@example.test'); await page.locator('#password').fill('DisposableGoalsProof42!');
                    await page.locator('[data-auth-entry] button[type=submit]').click(); await ready(page, '[data-account-kind="profile"]');
                    const protectedDocument = documents.filter(row => new URL(row.url).pathname === '/profile' && row.status === 200).at(-1);
                    assert.ok(protectedDocument); assert.match(protectedDocument.headers['cache-control'], /no-store/);
                    phase = 'leave-private-document';
                    await page.goto(origin + '/');
                    const other = await context.newPage();
                    phase = 'invalidation';
                    if (action === 'logout') {
                        await other.goto(origin + '/profile'); await ready(other, '[data-account-kind="profile"]');
                        await other.locator('[data-spa="logout"]').click(); await other.waitForURL(origin + '/');
                    } else if (action === 'identity-switch') {
                        await other.goto(origin + '/identity/switch'); await ready(other, '[data-auth-kind="login"]');
                        await other.locator('#email').fill('goals-b@example.test'); await other.locator('#password').fill('DisposableGoalsProof42!');
                        await other.locator('[data-auth-entry] button[type=submit]').click(); await ready(other, '[data-spa-private]:not([data-auth-entry])');
                    } else if (action === 'session-revocation') command('revoke');
                    await other.close();
                    if (action === 'offline') await context.setOffline(true);
                    const mark = events.length, frameMark = frames.length;
                    phase = 'history-return';
                    if (mode === 'scripts-disabled') await session.send('Emulation.setScriptExecutionDisabled', { value: true });
                    let response, navigationError;
                    try { response = await page.goBack({ waitUntil: 'domcontentloaded', timeout: 15000 }); }
                    catch (error) { navigationError = error.message; if (action !== 'offline') throw error; }
                    let snapshot;
                    if (mode === 'scripts-disabled') {
                        const captured = await session.send('DOMSnapshot.captureSnapshot', { computedStyles: ['display', 'visibility'] });
                        let privateRoots = 0, renderedPrivateRoots = 0;
                        for (const document of captured.documents) {
                            const rendered = new Set(document.layout.nodeIndex);
                            document.nodes.attributes.forEach((attributes, index) => {
                                if (attributes.some((value, offset) => offset % 2 === 0 && captured.strings[value] === 'data-spa-private')) {
                                    privateRoots++; if (rendered.has(index)) renderedPrivateRoots++;
                                }
                            });
                        }
                        snapshot = { privateRoots, renderedPrivateRoots }; assert.equal(renderedPrivateRoots, 0);
                    }
                    if (action === 'offline' && mode !== 'scripts-disabled') assert.equal(await page.locator('[data-spa-private]:visible').count(), 0, 'Offline return must not leave private presentation visible');
                    if (session && mode === 'scripts-disabled') await session.send('Emulation.setScriptExecutionDisabled', { value: false });
                    await context.setOffline(false);
                    const native = events.slice(mark).some(event => event.type === 'pageshow' && event.path === '/profile' && event.persisted && event.trusted)
                        || frames.slice(frameMark).some(event => event.type === 'BackForwardCacheRestore');
                    const performance = await page.evaluate(() => {
                        const entry = performance.getEntriesByType('navigation')[0];
                        return { type: entry?.type, notRestoredReasons: entry?.notRestoredReasons?.toJSON?.() ?? null };
                    }).catch(error => ({ unavailable: error.message }));
                    if (action !== 'offline' && mode !== 'scripts-disabled') {
                        await page.waitForFunction(() => document.getElementById('application-spa')?.dataset.state === 'ready');
                        const oldEmail = await page.locator('input[type=email]').inputValue().catch(() => '');
                        if (action !== 'unchanged-control') assert.notEqual(oldEmail, 'goals-a@example.test');
                    }
                    const unexplained = !response && !navigationError && !native;
                    if (native || unexplained) result.unverified.push({ gate: 'native BFCache Security C', action, mode, reason: native ? 'Native persisted return observed; post-handler visibility is not compositor/pre-JavaScript confidentiality proof' : 'History return has no fresh response and no trusted native pageshow evidence' });
                    return { result: native || unexplained ? 'UNVERIFIED' : 'PASS', classification: native ? 'TRUSTED_NATIVE_RESTORATION_PRE_JS_UNVERIFIED' : navigationError ? 'OFFLINE_NAVIGATION_FAILURE' : 'FRESH_NAVIGATION',
                        browser: browser.version(), browserArguments, mode, action, explicitCdpSession: !!session, scriptsDisabled: mode === 'scripts-disabled',
                        protectedDocument, freshResponse: response ? { status: response.status(), url: response.url() } : null, navigationError,
                        events, returnEvents: events.slice(mark), returnFrames: frames.slice(frameMark), reasons, performance, snapshot, failedRequests,
                        limitations: 'Playwright uses CDP underneath even in passive mode. No claim of every compositor frame, other browser/device or production hosting. Observation controls do not weaken HTTP/cache policy.' };
                } catch (error) {
                    result.restorationFailureBoundary = { mode, action, phase, failedRequests, message: error.message };
                    throw error;
                } finally {
                    if (session) await session.send('Emulation.setScriptExecutionDisabled', { value: false }).catch(() => {});
                    await context.setOffline(false).catch(() => {}); await context.close();
                }
            });
        }
    }
}
