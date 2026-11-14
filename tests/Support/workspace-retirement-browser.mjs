import assert from 'node:assert/strict';

// Real authenticated HTTP refusals from a current Business document.
export async function runWorkspaceRetirementCases({ browser, check, login, get, post, fixture, result }) {
    const context = await browser.newContext(), page = await context.newPage();
    page.on('dialog', dialog => dialog.accept());
    const workspace = fixture.a.businesses[0].business_public_id;
    try {
        await login(page, 'application');
        await check('Retired commercial, source and purpose routes reject reads and writes', 'retirement', async () => {
            const base = `/workspaces/${workspace}`;
            const denied = [];
            for (const path of ['', '/business', '/goals', '/object-purposes', '/commercial-history', '/source-registry']) {
                const read = await get(page, base + path);
                const write = await post(page, base + path, {});
                assert.equal(read.status, 404, path);
                assert.equal(write.status, 404, path);
                denied.push({ path, read: read.status, write: write.status });
            }
            assert.equal(await page.locator('[data-business-public]').count(), 1);
            result.retirement = { denied, businessDocumentPreserved: true };
            return result.retirement;
        });
    } finally { await context.close(); }
}
