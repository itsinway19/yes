import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';

if (process.env.GOALS_SPA_PROOF !== 'enabled' || process.env.GOALS_SPA_ONLY !== 'data-licenses' || process.env.DB_DATABASE !== 'builderad_test_disposable') {
    throw Error('Guarded data licence browser entry required');
}
const require = createRequire('C:/Users/stoma/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const { chromium } = require('playwright');
const origin = 'http://localhost:8136';
const run = process.env.GOALS_SPA_RUN;
const evidence = { cases: [], pageErrors: [], externalProvider: 'synthetic responses; real application OAuth and report writers' };
function fixture(mode, ...args) {
    const processResult = spawnSync('C:/tools/php85/php.exe', ['tests/Support/DataLicenseBrowserFixture.php', mode, ...args], { encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
    assert.equal(processResult.status, 0, processResult.stderr);
    const value = JSON.parse(processResult.stdout);
    assert.equal(value.status, 'completed');
    return value;
}
async function send(page, url, body) {
    return page.evaluate(async ({ url, body }) => {
        const identity = JSON.parse(document.getElementById('application-bootstrap').textContent).identity;
        const response = await fetch(url, {
            method: body ? 'POST' : 'GET', credentials: 'same-origin', redirect: 'manual',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': identity.csrf, 'X-Builderad-Document': identity.receipt, 'X-Builderad-Transition': identity.transition },
            body: body ? JSON.stringify(body) : undefined,
        });
        return { status: response.status, body: response.type === 'opaqueredirect' ? null : await response.json() };
    }, { url, body });
}
const ready = (page, selector) => page.waitForFunction(selector => document.querySelector('[data-application-spa]')?.dataset.state === 'ready' && !!document.querySelector(selector), selector);
const choice = async locator => {
    await locator.locator('option').nth(1).waitFor({ state: 'attached' });
    await locator.selectOption({ index: 1 });
};
const expiry = new Date(Date.now() + 2 * 3600_000).toISOString().slice(0, 16);
const command = () => crypto.randomUUID();
const direct = (...requirements) => ({ realm_kind: 'direct', realm_workspace_public_id: null, requirements });
const path = (kind, id) => ({ requirement_kind: kind, leaf_grant_id: Number(id) });
const urlFor = (business, access) => origin + '/businesses/' + business + '?access=' + access + '&section=sources';
const replyFor = (page, pathname) => page.waitForResponse(r => new URL(r.url()).pathname === pathname && r.request().method() === 'POST');
async function login(page, user) {
    page.setDefaultTimeout(30000);
    await page.goto(origin + '/login');
    assert.equal((await send(page, '/login', { email: user.email, password: 'DisposableGoalsProof42!' })).status, 200);
    await page.goto(origin + '/businesses');
    await ready(page, '[data-business-surface]');
}
async function qualifiedGrant(page, base, action, parent, recipient, expires_at = null, realm = null) {
    const body = { command_id: command(), effect: 'issue', action, grantee_public_id: recipient, parent_grant_id: Number(parent),
        grant_id: null, expected_grant: 0, expires_at, delegable: false, realm_workspace_public_id: realm?.realm_workspace_public_id ?? null, authority_selection: realm ? {...realm,requirements:[path('business',parent)]} : direct(path('business', parent)) };
    const reply = await send(page, base + '/grants', body);
    assert.equal(reply.status, 200, JSON.stringify(reply.body));
    return { body, id: reply.body.data.grant_id };
}
async function exerciseBeneficiary(browser, page, business, access, originalRead) {
    const beneficiary = fixture('setup');
    const stranger = fixture('setup');
    const base = '/api/businesses/' + business;
    const inventoryPath = base + '/data-use-journey?access=';
    const journey = (await send(page, inventoryPath + access)).body.data;
    const licence = journey.licenses[0];
    const parent = journey.business_use_options[0].grant_id;
    const enrolled = await send(page, base + '/direct-team', { command_id: command(), authority_selection: direct(path('business', access)) });
    assert.equal(enrolled.status, 200);
    const read = await qualifiedGrant(page, base, 'business.context.read', enrolled.body.data.grant_refs['business.context.read'], beneficiary.public_id);
    const childExpiry = new Date(Date.now() + 45 * 60000).toISOString();
    const child = await qualifiedGrant(page, base, 'business.data.use', parent, beneficiary.public_id, childExpiry);
    const other = await send(page, '/api/businesses', { command_id: command(), name: 'نشاط مستقل لا يشارك الترخيص' });
    assert.equal(other.status, 201);
    const otherBusiness = other.body.data.business_public_id;
    const endpoint = base + '/data-use-beneficiaries';
    const bounded = { command_id: command(), license_id: licence.license_id, effect: 'assign', recipient_public_id: beneficiary.public_id,
        valid_until: new Date(Date.now() + 20 * 60000).toISOString(), expected_revision: 0,
        authority_selection: direct(path('business', parent), path('client_data', licence.boundary_grant_id)) };
    assert.equal((await send(page, endpoint, { ...bounded, command_id: command(), recipient_public_id: stranger.public_id })).status, 403);
    assert.equal((await send(page, endpoint, { ...bounded, command_id: command(), valid_until: new Date(Date.now() + 55 * 60000).toISOString() })).status, 403);
    assert.equal((await send(page, endpoint, { ...bounded, command_id: command(), operation: 'export' })).status, 422);
    await page.goto(urlFor(business, access));
    const chooseAssignment = async () => {
        await page.locator('[data-license-source]').selectOption(licence.source_id);
        await page.locator('[data-license-current]').selectOption(licence.license_id);
        await page.locator('[data-license-selected-fields]').filter({ hasText: /الإنفاق/ }).waitFor();
        const panel = page.locator('[data-license-beneficiary-journey]');
        if (!await panel.evaluate(element => element.open)) await panel.locator('summary').click();
        await page.locator('[data-assignment-authority]').selectOption(String(parent));
    };
    await chooseAssignment();
    const recipients = page.locator('[data-assignment-beneficiary]');
    assert.equal(await recipients.locator('option').count(), 2);
    await recipients.selectOption(beneficiary.public_id);
    await page.locator('[data-assignment-expiry]').fill(bounded.valid_until.slice(0, 16));
    await page.locator('[data-assignment-confirm]').check();
    let release;
    const held = new Promise(resolve => { release = resolve; });
    let committed;
    const first = new Promise(resolve => { committed = resolve; });
    const deliveries = [];
    await page.route('**' + endpoint, async route => {
        const request = route.request().postDataJSON();
        deliveries.push(request);
        if (deliveries.length !== 1) return route.continue();
        const response = await route.fetch();
        committed({ status: response.status(), body: await response.json() });
        await held;
        await route.abort('failed').catch(() => {});
    });
    await page.locator('[data-assignment-assign]').click();
    const assignment = await first;
    assert.equal(assignment.status, 200, JSON.stringify(assignment.body));
    const switchTo = async (id, readGrant) => {
        const select = page.locator('#businessSelect');
        await select.selectOption(JSON.stringify([id, String(readGrant), 'direct', null]));
        await page.locator('[data-business-public="' + id + '"]').waitFor();
    };
    page.once('dialog', dialog => dialog.accept());
    await switchTo(otherBusiness, other.body.data.grant_refs['business.context.read']);
    release();
    await page.locator('[data-workspace-section="sources"]').click();
    await page.locator('[data-license-empty]').waitFor();
    assert.equal(await page.locator('[data-license-reconcile], [data-assignment-history]').count(), 0);
    await switchTo(business, access);
    if (!await page.locator('[data-business-data-licenses]').count()) await page.locator('[data-workspace-section="sources"]').click();
    await page.locator('[data-license-reconcile]').click();
    await page.locator('[data-license-reconcile]').waitFor({ state: 'detached' });
    assert.deepEqual(deliveries, [deliveries[0], deliveries[0]]);
    await page.unroute('**' + endpoint);

    const recipientPage = await browser.newPage({ locale: 'ar-JO', timezoneId: 'UTC' });
    try {
        await login(recipientPage, beneficiary);
        await recipientPage.goto(urlFor(business, read.id));
        const recipientPanel = recipientPage.locator('[data-assigned-licenses]');
        await recipientPanel.getByText(/تراخيص أُسندت إليك/).waitFor();
        const projection = (await send(recipientPage, inventoryPath + read.id)).body.data;
        assert.deepEqual(projection.sources, []);
        assert.deepEqual(projection.licenses, []);
        assert.deepEqual(Object.keys(projection.assigned_licenses[0]).sort(), ['license_id', 'operation', 'purpose', 'valid_until']);
        assert.equal(projection.assigned_licenses[0].license_id, licence.license_id);
        assert.equal(await recipientPage.locator('[data-license-read], [data-assignment-assign]').count(), 0);
        const assignedRead = { ...originalRead, command_id: command(), recipient_ref: beneficiary.public_id,
            authority_selection: direct(path('business', child.id), path('data_use', assignment.body.data.grant_id)) };
        assert.equal((await send(recipientPage, base + '/materials/read', assignedRead)).status, 403);
        assert.equal((await send(recipientPage, endpoint, { ...bounded, command_id: command(), recipient_public_id: stranger.public_id,
            authority_selection: direct(path('business', child.id), path('data_use', assignment.body.data.grant_id)) })).status, 403);

        assert.equal((await send(page, base + '/grants', { ...child.body, command_id: command(), effect: 'revoke',
            parent_grant_id: null, grant_id: Number(child.id), expected_grant: 1 })).status, 200);
        const renewed = await qualifiedGrant(page, base, 'business.data.use', parent, beneficiary.public_id, childExpiry);
        assert.notEqual(renewed.id, child.id);
        assert.deepEqual((await send(recipientPage, inventoryPath + read.id)).body.data.assigned_licenses, []);
        await chooseAssignment();
        await page.locator('[data-assignment-confirm]').check();
        const revoked = replyFor(page, endpoint);
        await page.locator('[data-assignment-revoke]').click();
        assert.equal((await revoked).status(), 200);
        await page.locator('[data-license-reconcile]').waitFor({ state: 'detached' });
        assignedRead.authority_selection.requirements[0] = path('business', renewed.id);
        assert.equal((await send(recipientPage, base + '/materials/read', assignedRead)).status, 403);
        await recipientPage.locator('[data-license-refresh]').click();
        await recipientPanel.waitFor({ state: 'detached' });
    } finally {
        await recipientPage.close();
    }
    return {readRoot:enrolled.body.data.grant_refs['business.context.read'],parent};
}

async function exerciseSummary(browser, page, business, access, team, realm = direct()) {
    const base='/api/businesses/'+business, until=new Date(Date.now()+25*60000).toISOString().slice(0,16);
    await page.goto(urlFor(business,access));await ready(page,'[data-license-source]');
    let inventory=(await send(page,base+'/data-use-journey?access='+access)).body.data;
    const source=inventory.sources[0], use=inventory.business_use_options[0].grant_id, bind=inventory.binding_options[0].grant_id;
    await page.locator('[data-license-source]').selectOption(source.source_id);await choice(page.locator('[data-license-dataset]'));
    await page.locator('[data-license-operation]').selectOption('process');await choice(page.locator('[data-license-purpose]'));
    await page.locator('#license-capacity').selectOption(realm.realm_kind==='agency'?'authorized_representative':'data_holder');
    await page.locator('[data-license-holder]').fill('صاحب البيانات الاصطناعية للملخص');await page.locator('[data-license-reference]').fill('تفويض صريح لمعالجة الإنفاق الاصطناعي');
    await page.locator('[data-license-expiry]').fill(until);await page.locator('[data-license-confirm]').check();
    let response=replyFor(page,base+'/data-use-licenses');await page.locator('[data-license-issue]').click();
    const issued=await response;assert.equal(issued.status(),200);const licence=(await issued.json()).data;
    await page.locator('[data-license-reconcile]').waitFor({state:'detached'});
    const select=async id=>{await page.locator('[data-license-source]').selectOption(source.source_id);await page.locator('[data-license-current]').selectOption(id);};
    for(const [action,endpoint] of [['cover','/api/data-rights/commands'],['bind',base+'/data-use-bindings']]){
        await select(licence.license_id);await page.locator('[data-license-binding]').selectOption(bind);
        response=replyFor(page,endpoint);await page.locator('[data-license-'+action+']').click();assert.equal((await response).status(),200);
        await page.locator('[data-license-reconcile]').waitFor({state:'detached'});
    }
    await select(licence.license_id);await page.locator('[data-license-use]').selectOption(use);await page.locator('[data-license-binding]').selectOption(bind);
    const selectedLicence=(await send(page,base+'/data-use-journey?access='+access)).body.data.licenses.find(row=>row.license_id===licence.license_id);
    const selectedRef=selectedLicence.material_refs[0], selectedMetadata=selectedLicence.record_metadata.find(row=>JSON.stringify(row.ref)===JSON.stringify(selectedRef));
    assert.ok(selectedMetadata?.campaign_id);
    await page.locator('[data-summary-record]').selectOption('0');
    assert.ok((await page.locator('[data-summary-record-review]').innerText()).includes(selectedMetadata.campaign_id));
    response=replyFor(page,base+'/materials/read');await page.locator('[data-summary-process]').click();const processed=await response;assert.equal(processed.status(),200);
    assert.deepEqual(processed.request().postDataJSON().material_refs,[selectedRef]);const inputAmount=(await processed.json()).data.materials[0].fields.spend;
    await page.locator('[data-summary-process-ready]').waitFor();
    await page.locator('[data-summary-expiry]').fill(new Date(Date.now()+15*60000).toISOString().slice(0,16));await page.locator('[data-summary-confirm]').check();
    await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await page.screenshot({path:join(run,'summary-owner-'+realm.realm_kind+'.png'),fullPage:true});
    const commands=[];let captured;let resolveCapture;const capture=new Promise(resolve=>resolveCapture=resolve);
    await page.route('**'+base+'/expenditure-summaries',async route=>{
        commands.push(route.request().postDataJSON());
        if(commands.length!==1)return route.continue();
        const result=await route.fetch();captured=await result.json();resolveCapture(result.status());await route.abort('failed');
    });
    await page.locator('[data-summary-prepare]').click();assert.equal(await capture,200,JSON.stringify(captured));
    await page.locator('[data-license-reconcile]').click();await page.locator('[data-summary-publish]').waitFor();
    assert.deepEqual(commands,[commands[0],commands[0]]);await page.unroute('**'+base+'/expenditure-summaries');
    assert.deepEqual(commands[0].input_ref,selectedRef);
    const summary=captured.data;
    response=replyFor(page,base+'/materials/publish');await page.locator('[data-summary-publish]').click();const publication=await response;assert.equal(publication.status(),200);
    assert.deepEqual(publication.request().postDataJSON().dependencies[0].input,selectedRef);
    await page.locator('[data-summary-publish]').waitFor({state:'detached'});
    await page.setViewportSize({width:1280,height:900});
    const beneficiary=fixture('setup');
    if(realm.realm_kind==='agency'){
        const teamPath='/api/organizations/'+realm.realm_workspace_public_id+'/team';const members=await send(page,teamPath);
        assert.equal(members.status,200);assert.equal(Number.isSafeInteger(members.body.data.revision),true);const added=await send(page,teamPath,{command_id:command(),effect:'add',recipient_public_id:beneficiary.public_id,expected_revision:members.body.data.revision});assert.equal(added.status,200,JSON.stringify(added.body));
    }
    const childExpiry=new Date(Date.now()+10*60000).toISOString();
    const read=await qualifiedGrant(page,base,'business.context.read',team.readRoot,beneficiary.public_id,childExpiry,realm);
    const child=await qualifiedGrant(page,base,'business.data.use',use,beneficiary.public_id,childExpiry,realm);
    // These explicit out-of-band grant writers advance the admitted authority
    // context. Re-enter through a real document, as the existing grant flow does.
    await page.goto(urlFor(business,access));await ready(page,'[data-license-source]');await select(summary.license_id);
    const assignment=page.locator('[data-license-beneficiary-journey]');await assignment.locator('summary').click();
    await page.locator('[data-assignment-authority]').selectOption(use);await page.locator('[data-assignment-beneficiary]').selectOption(beneficiary.public_id);
    await page.locator('[data-assignment-expiry]').fill(new Date(Date.now()+5*60000).toISOString().slice(0,16));await page.locator('[data-assignment-confirm]').check();
    response=replyFor(page,base+'/data-use-beneficiaries');await page.locator('[data-assignment-assign]').click();const assigned=await response;assert.equal(assigned.status(),200);const named=(await assigned.json()).data;
    const recipientPage=await browser.newPage({locale:'ar-JO',timezoneId:'UTC',viewport:{width:390,height:844}});
    recipientPage.on('pageerror',error=>evidence.pageErrors.push('summary beneficiary: '+error.message));
    try{
        await login(recipientPage,beneficiary);await recipientPage.goto(urlFor(business,read.id));await ready(recipientPage,'[data-assigned-summary-read]');
        await recipientPage.locator('[data-assigned-business-use]').selectOption(String(child.id));response=replyFor(recipientPage,base+'/materials/read');await recipientPage.locator('[data-assigned-summary-read]').click();
        const delivered=await response;assert.equal(delivered.status(),200);const actual=await delivered.json();
        assert.deepEqual(actual.data.materials.map(row=>row.ref.namespace),['business.expenditure_summary']);
        assert.doesNotMatch(JSON.stringify(actual),new RegExp(source.source_id+'|provider_observation_entry|DO_NOT_DISCLOSE|C03_DO_NOT_DISCLOSE|campaign_id|record_metadata|agency-campaign-one'));
        await recipientPage.locator('[data-native-amount]').waitFor();assert.equal(await recipientPage.locator('[data-native-amount]').innerText(),inputAmount.major_units);assert.equal(await recipientPage.locator('[data-native-currency]').innerText(),inputAmount.currency);assert.equal(await recipientPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        await recipientPage.screenshot({path:join(run,'summary-recipient-'+realm.realm_kind+'.png'),fullPage:true});
        const native=(await send(page,base+'/data-use-journey?access='+access)).body.data.licenses.find(row=>row.license_id===licence.license_id);
        const forbidden={...delivered.request().postDataJSON(),command_id:command(),material_refs:native.material_refs};
        assert.equal((await send(recipientPage,base+'/materials/read',forbidden)).status,403);
        assert.deepEqual((await send(recipientPage,'/api/data-rights/use-scopes')).body.data.scopes,[]);
        await recipientPage.reload();await ready(recipientPage,'[data-assigned-summary-read]');
        await recipientPage.locator('[data-assigned-business-use]').selectOption(String(child.id));
        response=replyFor(recipientPage,base+'/materials/read');await recipientPage.locator('[data-assigned-summary-read]').click();
        const reloaded=await response;assert.equal(reloaded.status(),200);const afterReload=await reloaded.json();
        assert.deepEqual(afterReload.data.materials.map(row=>row.ref),actual.data.materials.map(row=>row.ref));
        assert.equal(afterReload.data.materials[0].fields.spend.major_units,inputAmount.major_units);
        assert.equal(afterReload.data.materials[0].fields.spend.currency,inputAmount.currency);
        await recipientPage.locator('[data-native-amount]').waitFor();
        assert.equal(await recipientPage.locator('[data-native-amount]').innerText(),inputAmount.major_units);
        assert.equal(await recipientPage.locator('[data-native-currency]').innerText(),inputAmount.currency);
        await select(summary.license_id);if(!await assignment.evaluate(node=>node.open))await assignment.locator('summary').click();await page.locator('[data-assignment-authority]').selectOption(use);await page.locator('[data-assignment-confirm]').check();
        response=replyFor(page,base+'/data-use-beneficiaries');await page.locator('[data-assignment-revoke]').click();assert.equal((await response).status(),200);
        assert.equal((await send(recipientPage,base+'/materials/read',delivered.request().postDataJSON())).status,403);
        assert.equal((await send(recipientPage,base+'/materials/read',reloaded.request().postDataJSON())).status,403);
        assert.equal((await send(recipientPage,base+'/materials/read',{...reloaded.request().postDataJSON(),command_id:command()})).status,403);
        await recipientPage.locator('[data-license-refresh]').click();await recipientPage.locator('[data-assigned-summary-read]').waitFor({state:'detached'});
    }finally{await recipientPage.close();}
    inventory=(await send(page,base+'/data-use-journey?access='+access)).body.data;
    return {command_id:command(),material_refs:[summary.artifact_ref],operation:'display',purpose:'advertising_performance',recipient_ref:inventory.actor_public_id,authority_selection:{...realm,requirements:[path('business',use),path('data_use',summary.use_grant_id)]}};
}
async function exerciseAgency(browser, clientPage) {
    const client = await send(clientPage, '/api/businesses', { command_id: command(), name: 'نشاط عميل مستقل للوكالة' });
    assert.equal(client.status, 201);
    const business = client.body.data.business_public_id;
    const base = '/api/businesses/' + business;
    const identity = fixture('setup', 'agency');
    const actor = await browser.newPage({ locale: 'ar-JO', timezoneId: 'UTC' });
    actor.on('pageerror',error=>evidence.pageErrors.push('agency: '+error.message));
    const rival = await browser.newPage({ locale: 'ar-JO', timezoneId: 'UTC' });
    try {
        await login(actor, identity);
        const slug = 'agency-' + command();
        const workspaceCreate = await send(actor, '/workspaces', { name: 'وكالة اختبار مستقلة', slug });
        assert.equal(workspaceCreate.status, 0);
        const seeds = fixture('agency-prerequisites', business, identity.public_id, slug);
        const link = { command_id: command(), workspace_public_id: seeds.workspace, kind: 'agency', effect: 'add', expected_revision: 0,
            authority_selection: direct(path('business', seeds.link_grant)) };
        assert.equal((await send(actor, base + '/workspace-links', link)).status, 200);
        const { access } = fixture('agency-context', business, identity.public_id, seeds.workspace);
        const agency = { realm_kind: 'agency', realm_workspace_public_id: seeds.workspace, requirements: [path('business', access)] };
        const adopted = await send(actor, '/api/data-rights/commands', { command_id: command(), target_kind: 'source', target_ref: seeds.source,
            effect: 'adopt', payload: {}, expected_revision: 1, authority_selection: direct(path('native_source', seeds.source_grants['source.manage'])) });
        assert.equal(adopted.status, 200, JSON.stringify(adopted.body));

        const mandatePath = base + '/operating-mandate';
        const mandateBody = { command_id: command(), confirmed: true, capacity: 'responsible_business',
            authority_reference: 'Synthetic independent client mandate', valid_until: new Date(expiry + 'Z').toISOString(), previous_grant: '0', authority_selection: agency };
        assert.equal((await send(actor, mandatePath, mandateBody)).status, 403);
        await actor.goto(urlFor(business, access));
        await ready(actor, '[data-operating-mandate]');
        const mandate = actor.locator('[data-operating-mandate]');
        await mandate.locator('summary').click();
        await mandate.locator('#mandate-capacity').selectOption('client_mandate');
        await mandate.locator('[data-mandate-reference]').fill('تفويض صريح من العميل الاصطناعي');
        await mandate.locator('[data-mandate-expiry]').fill(expiry);
        await mandate.locator('[data-mandate-confirm]').check();
        const qualified = replyFor(actor, mandatePath);
        await mandate.locator('[data-license-qualify]').click();
        assert.equal((await qualified).status(), 200);
        await mandate.waitFor({ state: 'detached' });

        await choice(actor.locator('[data-license-source]'));
        await choice(actor.locator('[data-license-dataset]'));
        await choice(actor.locator('[data-license-purpose]'));
        await actor.locator('#license-capacity').selectOption('authorized_representative');
        await actor.locator('[data-license-holder]').fill('العميل الاصطناعي المستقل');
        await actor.locator('[data-license-reference]').fill('تصريح العميل الاصطناعي للإنفاق المحدد');
        await actor.locator('[data-license-expiry]').fill(expiry);
        const issue = actor.locator('[data-license-issue]');
        await actor.locator('[data-license-review-needed]').check();
        await actor.locator('[data-license-review-state]').waitFor();
        assert.equal(await issue.isDisabled(), true);
        assert.equal(fixture('inspect', business).licenses, 0);
        await actor.locator('[data-license-review-needed]').uncheck();
        await actor.locator('[data-license-confirm]').check();
        const issued = replyFor(actor, base + '/data-use-licenses');
        await issue.click();
        assert.equal((await issued).status(), 200);
        for (const [button, endpoint] of [['cover', '/api/data-rights/commands'], ['bind', base + '/data-use-bindings']]) {
            await choice(actor.locator('[data-license-source]'));
            await choice(actor.locator('[data-license-current]'));
            await choice(actor.locator('[data-license-binding]'));
            const effect = replyFor(actor, endpoint);
            await actor.locator('[data-license-' + button + ']').click();
            assert.equal((await effect).status(), 200);
            await actor.locator('[data-license-reconcile]').waitFor({ state: 'detached' });
        }
        await choice(actor.locator('[data-license-source]'));
        await choice(actor.locator('[data-license-current]'));
        await choice(actor.locator('[data-license-use]'));
        const read = replyFor(actor, base + '/materials/read');
        await actor.locator('[data-license-read]').click();
        const response = await read;
        assert.equal(response.status(), 200);
        const original = response.request().postDataJSON();
        assert.doesNotMatch(await response.text(), /C03_DO_NOT_DISCLOSE/);
        await actor.locator('[data-native-amount]').filter({ hasText: /^17$/ }).waitFor();
        const summaryRead=await exerciseSummary(browser,actor,business,access,{readRoot:access},agency);
        assert.equal((await send(actor,base+'/materials/read',summaryRead)).status,200);

        await login(rival, fixture('setup', 'agency'));
        assert.equal((await send(rival, '/workspaces', { name: 'وكالة أخرى', slug: 'agency-' + command() })).status, 0);
        const journey = base + '/data-use-journey?access=' + access;
        assert.equal((await send(rival, journey)).status, 403);
        assert.equal((await send(rival, base + '/materials/read', { ...original, command_id: command() })).status, 403);
        assert.equal((await send(actor, base + '/workspace-links', { ...link, command_id: command(), effect: 'end', expected_revision: 1 })).status, 200);
        assert.equal((await send(actor, base + '/materials/read', original)).status, 403);
        assert.equal((await send(actor,base+'/materials/read',summaryRead)).status,403);
        assert.equal((await send(actor, base + '/workspace-links', { ...link, command_id: command(), expected_revision: 2 })).status, 200);
        assert.equal((await send(actor, journey)).status, 403);
        assert.equal((await send(actor, base + '/materials/read', { ...original, command_id: command() })).status, 403);
        assert.equal((await send(actor,base+'/materials/read',{...summaryRead,command_id:command()})).status,403);
    } finally {
        await rival.close();
        await actor.close();
    }
}
const browser = await chromium.launch({ headless: true, executablePath: 'C:/Users/stoma/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe' });
let page;
try {
    const { email } = fixture('setup');
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ar-JO', timezoneId: 'UTC' });
    page = await context.newPage();
    page.setDefaultTimeout(25000);
    page.on('pageerror', error => evidence.pageErrors.push(error.message));
    await context.route(/^https:\/\/www\.facebook\.com\//, async route => {
        const state = new URL(route.request().url()).searchParams.get('state');
        assert.ok(state);
        await route.fulfill({ status: 302, headers: { Location: `${origin}/providers/meta/callback?${new URLSearchParams({ state, code: 'synthetic-native-code' })}` }, body: '' });
    });
    await page.goto(origin + '/login');
    const logged = await send(page, '/login', { email, password: 'DisposableGoalsProof42!' });
    assert.equal(logged.status, 200);
    await page.goto(origin + '/businesses/new');
    await ready(page, '#bf-create-name');
    await page.locator('#bf-create-name').fill('نشاط اختبار ترخيص البيانات');
    await page.locator('#bf-create-name').locator('xpath=ancestor::form').locator('button[type=submit]').click();
    await ready(page, '[data-business-public]');
    const business = await page.locator('[data-business-public]').getAttribute('data-business-public');
    const base = `/api/businesses/${business}`;
    const choiceResponse = await send(page, '/api/businesses');
    const access = choiceResponse.body.data.items.find(item => item.business_public_id === business).read_grant_id;
    const journeyPath = `${base}/data-use-journey?access=${access}`;
    await page.locator('[data-workspace-section="sources"]').click();
    await ready(page, '[data-license-empty]');
    await page.locator('[data-provider="meta"] button').click();
    const connectAccount = async () => {
        await page.locator('[data-source-connect="meta"]').click();
        await page.waitForURL(new RegExp(`/businesses/${business}.*#meta-flow=`));
        await page.locator('dialog[open] [data-source-select]').first().click();
        await page.locator('dialog[open] [data-source-disconnect]').waitFor();
    };
    await connectAccount();
    assert.equal(fixture('admit', business).admitted, 1);
    // Admission changes the proof, so reverify the credential in the real provider flow.
    await connectAccount();
    const report = page.locator('dialog[open] [data-source-report="meta"]');
    await report.locator('input[type=date]').nth(0).fill('2026-09-01');
    await report.locator('input[type=date]').nth(1).fill('2026-09-01');
    await report.locator('select').selectOption('SYNC');
    const queued = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/report'));
    await report.locator('button[type=submit]').click();
    assert.ok((await queued).ok());
    assert.equal(fixture('jobs').handled, 1);
    await page.locator('.advertising-dialog-heading button').click();
    await page.locator('[data-license-refresh]').click();
    await choice(page.locator('[data-license-source]'));
    await choice(page.locator('[data-license-dataset]'));
    evidence.cases.push({ name: 'Real Business creation, source OAuth callback, account selection and report job', status: 'PASS' });

    const mandate = page.locator('[data-operating-mandate]');
    await mandate.locator('summary').click();
    await mandate.locator('[data-mandate-reference]').fill('إقرار مسؤولية مستقل للنشاط الاصطناعي');
    await mandate.locator('[data-mandate-expiry]').fill(expiry);
    await mandate.locator('[data-mandate-confirm]').check();
    await mandate.locator('[data-license-qualify]').click();
    await mandate.waitFor({ state: 'detached' });
    await choice(page.locator('[data-license-source]'));
    await choice(page.locator('[data-license-dataset]'));
    await choice(page.locator('[data-license-purpose]'));
    await page.locator('[data-license-holder]').fill('مسؤول النشاط الاصطناعي');
    await page.locator('[data-license-reference]').fill('إقرار استعمال محدد لتقرير الإنفاق الاصطناعي');
    await page.locator('[data-license-expiry]').fill(expiry);
    await page.locator('[data-license-confirm]').check();
    let original;
    let firstDeliveryCompleted;
    const firstDelivery = new Promise(resolve => { firstDeliveryCompleted = resolve; });
    const writes = [];
    await page.route(`**${base}/data-use-licenses`, async route => {
        const body = route.request().postDataJSON();
        writes.push(body);
        if (body.effect === 'issue' && !original) {
            original = body;
            const response = await route.fetch();
            if (!response.ok()) {
                evidence.licenceRejection = { status: response.status(), body: await response.json() };
                await route.fulfill({ response });
                firstDeliveryCompleted(response.status());
                return;
            }
            await route.abort('failed');
            firstDeliveryCompleted(response.status());
            return;
        }
        await route.continue();
    });
    await page.locator('[data-license-issue]').click();
    assert.equal(await firstDelivery, 200);
    await page.locator('[data-license-reconcile]').waitFor();
    await page.locator('[data-license-reconcile]').click();
    await page.locator('[data-license-reconcile]').waitFor({ state: 'detached' });
    assert.deepEqual(writes, [original, original]);
    assert.equal(fixture('inspect', business).licenses, 1);
    const chooseLicence = async () => {
        await choice(page.locator('[data-license-source]'));
        await choice(page.locator('[data-license-current]'));
    };
    const refreshedJourney = () => page.waitForResponse(response => new URL(response.url()).pathname === `${base}/data-use-journey` && response.ok());
    const writeReceipt = path => page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === path);
    await chooseLicence();
    await choice(page.locator('[data-license-binding]'));
    const covered = writeReceipt('/api/data-rights/commands');
    const coverage = refreshedJourney();
    await page.locator('[data-license-cover]').click();
    assert.equal((await covered).status(), 200);
    await coverage;
    await chooseLicence();
    await choice(page.locator('[data-license-binding]'));
    const bound = writeReceipt(`${base}/data-use-bindings`);
    const binding = refreshedJourney();
    await page.locator('[data-license-bind]').click();
    assert.equal((await bound).status(), 200);
    await binding;
    await chooseLicence();
    await choice(page.locator('[data-license-use]'));
    const read = page.waitForResponse(response => new URL(response.url()).pathname === `${base}/materials/read` && response.ok());
    await page.locator('[data-license-read]').click();
    const readResponse = await read;
    const originalRead = readResponse.request().postDataJSON();
    await page.locator('[data-license-materials] [data-native-amount]').filter({ hasText: /^12\.5(?:0)?$/ }).waitFor();
    assert.equal(await page.locator('[data-license-materials] [data-native-currency]').innerText(), 'USD');
    evidence.cases.push({ name: 'Explicit mandate, licence with lost-response reconciliation, binding and actual field-filtered read', status: 'PASS' });
    await page.setViewportSize({ width: 390, height: 844 });
    const licencePanel = page.locator('[data-business-data-licenses]');
    assert.equal(await licencePanel.getAttribute('dir'), 'rtl');
    const mobileCopy = await licencePanel.innerText();
    assert.match(mobileCopy, /مراجعة أداء الإعلانات/);
    assert.match(mobileCopy, /عرض البيانات داخل النشاط/);
    assert.doesNotMatch(mobileCopy, /advertising_performance|\bdisplay\b|\d{4}-\d\d-\d\dT\d\d:\d\d/);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: join(run, 'data-licence-rtl-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 900 });
    const summaryTeam=await exerciseBeneficiary(browser, page, business, access, originalRead);
    evidence.cases.push({ name: 'Named beneficiary, private projection, lost receipt across Business switch, replacement and revocation', status: 'PASS' });
    await chooseLicence();
    await page.locator('[data-license-withdraw-confirm]').check();
    const withdrawal = refreshedJourney();
    await page.locator('[data-license-withdraw]').click();
    await page.locator('[data-license-materials]').waitFor({ state: 'detached' });
    await withdrawal;
    assert.equal((await send(page, `${base}/materials/read`, originalRead)).status, 403);
    assert.equal((await send(page, journeyPath)).body.data.licenses[0].status, 'withdrawn');
    assert.equal(fixture('inspect', business).synthetic_roots, 0);
    evidence.cases.push({ name: 'Mobile RTL and withdrawal denies replay of former authorised material read', status: 'PASS' });
    await exerciseSummary(browser,page,business,access,summaryTeam);
    evidence.cases.push({name:'Explicit process, finite summary consent, lost prepare reply, registered publication and source-free recipient read/reload/revocation',status:'PASS'});
    await exerciseAgency(browser, page);
    evidence.cases.push({ name: 'Independent client and qualified agency browser, rival denial and relationship replacement', status: 'PASS' });
    assert.deepEqual(evidence.pageErrors, []);
} catch (error) {
    evidence.failure = String(error.stack ?? error);
    if (page && !page.isClosed()) await page.screenshot({ path: join(run, 'data-licence-browser-failure.png'), fullPage: true }).catch(() => {});
    throw error;
} finally {
    await browser.close();
    await writeFile(join(run, 'browser-results.json'), JSON.stringify(evidence, null, 2));
}
