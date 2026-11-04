import assert from 'node:assert/strict';

// Real authenticated HTTP refusal, followed by the independently admitted
// organization and historical resources. No retired editor or writer fixture.
export async function runWorkspaceRetirementCases({ browser, check, login, get, post, fixture, result }) {
    const context = await browser.newContext(), page = await context.newPage();
    page.on('dialog', dialog => dialog.accept());
    const workspace = fixture.a.workspaces[0].public_id;
    try {
        await login(page, 'application');
        await check('retired commercial HTTP entrances reject reads and writes while original history and organizational sources remain available', 'retirement', async () => {
            const base = `/workspaces/${workspace}`;
            const denied = [];
            for (const path of ['/business', '/goals', '/object-purposes']) {
                const read = await get(page, base + path), write = await post(page, base + path, {});
                assert.equal(read.status, 410, path); assert.equal(write.status, 410, path);
                denied.push({ path, read: read.status, write: write.status });
            }
            for (const path of ['/business/target-markets', '/business/current-intent']) {
                const write = await post(page, base + path, {}); assert.equal(write.status, 410, path);
                denied.push({ path, write: write.status });
            }
            const history = await get(page, base + '/commercial-history');
            assert.equal(history.status, 200); assert.equal(history.body.data.read_only, true);
            assert.equal(history.body.data.current_business_inferred, false);
            assert.equal(await page.locator('[data-commercial-history] form').count(), 0);
            const sources = await get(page, base); assert.equal(sources.status, 200);
            assert.equal(sources.body.data.business, undefined);
            assert.equal(sources.body.data.guidance, undefined);
            result.retirement = { denied, originalHistory: 200, organizationalSources: 200, inferredBusiness: false };
            return result.retirement;
        });
    } finally { await context.close(); }
}
