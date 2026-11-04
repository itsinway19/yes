import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, access } from 'node:fs/promises';

const read = path => readFile(new URL('../' + path, import.meta.url), 'utf8');

test('retired Workspace business onboarding cannot be reintroduced through routes, contracts or the creation controller', async () => {
    const [routes, contract, organization, page] = await Promise.all([
        read('routes/web.php'), read('contracts/application.openapi.json'),
        read('app/Http/Controllers/OrganizationController.php'), read('resources/js/workspaces/OrganizationCreate.vue'),
    ]);
    assert.match(routes, /Route::post\('\/business\/start',[^\n]+410/);
    await assert.rejects(access(new URL('../app/Http/Controllers/BusinessContextController.php', import.meta.url)), { code: 'ENOENT' });
    assert.equal(JSON.parse(contract).paths['/business/start'], undefined);
    assert.doesNotMatch(organization, /BusinessContext|->(?:write|replaceCurrentIntent|replaceTargetMarkets)\(/);
    assert.doesNotMatch(page, /business_type_id|\/goals/);
    assert.ok(JSON.parse(contract).paths['/organizations'].post);
});

test('retired commercial contracts and editors cannot return alongside Business owners', async () => {
    const contract = JSON.parse(await read('contracts/application.openapi.json'));
    for (const path of Object.keys(contract.paths)) assert.doesNotMatch(path, /^\/workspaces\/\{workspace\}\/(?:business|goals|object-purposes)(?:\/|$)/);
    assert.deepEqual(JSON.parse(await read('contracts/goals.openapi.json')).paths, {});
    for (const path of ['resources/js/business-context/BusinessContext.vue', 'resources/js/business-context/ContextForm.vue', 'resources/js/goals-spa/GoalsEditor.vue', 'resources/js/goals-spa/GoalsReview.vue', 'resources/js/Components/Business/TargetMarketsEditor.vue', 'resources/js/Components/Business/CurrentIntentEditor.vue']) {
        await assert.rejects(access(new URL('../' + path, import.meta.url)), { code: 'ENOENT' });
    }
    const main = await read('resources/js/spa/main.ts');
    assert.doesNotMatch(main, /businessContextRoutes|goalsRoutes/);
    const facts = await read('database/sql/business_scope/commercial_retirement.sql');
    for (const name of ['builderad_business_context_write', 'builderad_business_target_markets_write', 'builderad_current_intent_write', 'builderad_advertiser_goals_write', 'builderad_business_context_begin', 'builderad_business_context_require_prepared']) assert.ok(facts.includes('DROP FUNCTION public.' + name + '('));
    assert.match(facts, /WORKSPACE_COMMERCIAL_WRITER_RETIRED/);
    const purpose = await read('database/sql/business_scope/purpose_retirement.sql');
    assert.match(purpose, /w IS NOT NULL AND coalesce\(c->>'operation',''\) NOT IN\('correct','withdraw'\)/);
    assert.doesNotMatch(purpose, /IF b IS NOT NULL THEN PERFORM builderad_private\.permit/);
});

test('source background work and organizational consumers use metadata rather than commercial legacy entry', async () => {
    for (const path of ['app/Providers/Ads/PersistObservationJob.php', ...['Meta', 'Google', 'TikTok', 'Snapchat', 'X'].map(provider => `app/Providers/Ads/${provider}/${provider}ReadJob.php`)]) {
        const source = await read(path);
        assert.doesNotMatch(source, /builderad_v2_legacy_enter|table\('workspaces'\)/);
        assert.match(source, /organization_workspaces/);
    }
    assert.doesNotMatch(await read('database/sql/business_scope/organization_resources.sql'), /business_type_id|business_context_version|current_intent|LEGACY_ACTIVE|legacy_admitted/);
});

test('organizational source administration cannot consume legacy commercial facts or require their setup gate', async () => {
    const [controller, source, routes, contract] = await Promise.all([
        read('app/Http/Controllers/WorkspaceController.php'), read('resources/js/sources/Sources.vue'),
        read('routes/web.php'), read('contracts/application.openapi.json'),
    ]);
    assert.doesNotMatch(controller, /BusinessContext|->guidance\(/);
    assert.doesNotMatch(source, /props\.guidance|guidance\.priors|business\.type|business\.targetMarkets/);
    assert.doesNotMatch(routes, /EnsureBusinessContext/);
    const schema = JSON.parse(contract).components.schemas.WorkspaceSourcesData;
    assert.equal(schema.properties.business, undefined);
    assert.equal(schema.properties.guidance, undefined);
});

test('source-management inventory explicitly establishes no Business data-use right', async () => {
    const contract = JSON.parse(await read('contracts/application.openapi.json'));
    const fields = contract.components.schemas.BusinessSources.properties;
    assert.equal(fields.inventory_kind.const, 'manageable_sources');
    assert.equal(fields.establishes_business_data_use.const, false);
    const sql = await read('database/sql/business_scope/business_source_visibility.sql');
    assert.match(sql, /'establishes_business_data_use',false/);
});

test('the mandatory JavaScript CI selection retains the retirement guards', async () => {
    const pkg = JSON.parse(await read('package.json'));
    assert.ok(pkg.scripts.test.split(' ').includes('tests/workspace-commercial-retirement.test.mjs'));
    assert.match(await read('.github/workflows/foundation.yml'), /npm test/);
});
