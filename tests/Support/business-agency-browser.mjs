import { readFile } from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

/** Uses the existing guarded Business browser runner and its case/evidence owner. */
export async function businessAgencyBrowserProof({ browser, run, origin, fixture, check, sendFrom, activate, restore, errors }) {
    await check('Business-first agency browser proof: direct staff, named client assignment and selective withdrawal', async () => {
        fixture('business-agency-setup');
        const f = JSON.parse(await readFile(path.join(run, 'business-agency-browser-fixture.json'), 'utf8'));
        const contexts = [];
        const inspectedStates = [];
        const selection = grant => ({ realm_kind: 'direct', realm_workspace_public_id: null,
            requirements: [{ requirement_kind: 'business', leaf_grant_id: Number(grant) }] });
        const expiry = new Date(Date.now() + 2 * 60 * 60 * 1000).toISOString();
        const localExpiry = (() => { const date = new Date(expiry); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); })();
        const waitReady = async tab => tab.waitForFunction(() => document.querySelector('[data-application-spa]')?.dataset.state === 'ready'
            && !!document.querySelector('[data-business-public]') && document.querySelector('[data-business-public]')?.getAttribute('aria-busy') === 'false');
        const capture = async (tab, name) => {
            assert.equal(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'The agency task must fit its viewport');
            inspectedStates.push({ state: name.replace(/\.png$/, ''), viewport: tab.viewportSize(), direction: await tab.locator('html').getAttribute('dir') });
        };
        async function actorPage(role, width = 1280) {
            const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, locale: 'ar-JO' });
            contexts.push(context);
            const tab = await context.newPage(); tab.setDefaultTimeout(20000);
            tab.on('pageerror', error => errors.push(error.message)); activate(tab);
            await tab.goto(origin + '/login');
            assert.equal((await sendFrom(tab, '/login', 'POST', { email: f.actors[role].email, password: 'DisposableGoalsProof42!' })).status, 200);
            return tab;
        }
        async function openAccess(tab, business, read) {
            activate(tab);
            await tab.goto(`${origin}/businesses/${business}?access=${read}&section=settings&settings=access`);
            await waitReady(tab);
            await tab.locator('[data-workspace-panel="access"]').waitFor({ state: 'visible' });
        }
        async function directEmployee(tab, business, originalRead, email) {
            await openAccess(tab, business, originalRead);
            const team = tab.locator('[data-business-direct-team]');
            await team.locator('[data-direct-team-confirm]').check();
            const [enrolled] = await Promise.all([
                tab.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${business}/direct-team` && response.request().method() === 'POST'),
                team.locator('[data-direct-team-submit]').click(),
            ]);
            assert.equal(enrolled.status(), 200);
            const roots = (await enrolled.json()).data.grant_refs;
            await waitReady(tab);
            const members = tab.locator('[data-business-team-members]');
            await members.locator('[data-team-add]').click();
            await members.locator('[data-team-email]').fill(email);
            const [resolved] = await Promise.all([
                tab.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${business}/team/recipient`),
                members.locator('[data-team-lookup]').click(),
            ]);
            assert.equal(resolved.status(), 200);
            await members.locator('[data-team-confirm]').check();
            const [issued] = await Promise.all([
                tab.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${business}/team` && response.request().method() === 'POST'),
                members.locator('[data-team-save]').click(),
            ]);
            assert.equal(issued.status(), 200); await waitReady(tab);
            return { roots, grant: (await issued.json()).data.grant_refs['business.context.read'] };
        }
        async function agencyTask(tab, effect) {
            activate(tab);
            if (effect === 'assign') await openEmployeeClient(tab);
            else {
                const task = tab.locator('[data-business-task="business-agency"]');
                if (await task.isVisible()) await task.click();
            }
            const panel = tab.locator('[data-business-agency-team]'); await panel.waitFor({ state: 'visible' });
            assert.equal(await panel.locator('[data-agency-mode]').count(), 0, 'Only the server-admitted task is offered');
            if (effect === 'mandate') await panel.locator('[data-agency-business]').fill(f.agency.business_public_id);
            else {
                await panel.locator('[data-agency-known-business]').waitFor({ state: 'visible' });
                assert.ok((await panel.locator('[data-agency-known-business]').textContent()).trim());
                assert.equal(await panel.locator('[data-agency-business]').count(), 0, 'The roster fixes the current agency Business');
                assert.ok((await panel.locator('details').allTextContents()).join(' ').includes(f.agency.business_public_id));
            }
            if (effect === 'assign') {
                await panel.locator('[data-agency-known-recipient]').waitFor({ state: 'visible' });
                assert.ok((await panel.locator('[data-agency-known-recipient]').textContent()).trim());
                assert.ok((await panel.locator('details').allTextContents()).join(' ').includes(f.actors.employee.public_id));
            }
            const [discovered] = await Promise.all([
                tab.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/agency-options`),
                panel.locator('[data-agency-inspect]').click(),
            ]);
            assert.equal(discovered.status(), 200);
            const options = (await discovered.json()).data;
            assert.equal(options.effect, effect); assert.equal(options.scope, 'current_declaration_agency_only');
            assert.equal(options.agency_business_public_id, f.agency.business_public_id);
            if (effect === 'assign') assert.equal(options.recipient.public_id, f.actors.employee.public_id);
            const read = options.options.find(option => option.action === 'business.context.read'); assert.ok(read);
            await panel.locator('[data-agency-action]').selectOption(String(read.parent_grant_id));
            await panel.locator('[data-agency-expiry]').fill(localExpiry);
            assert.equal(await panel.locator('input[name="membership_grant_id"], input[name="parent_grant_id"]').count(), 0);
            await panel.locator('[data-agency-confirm]').check();
            return panel;
        }
        async function issueAgency(tab, panel) {
            const [response] = await Promise.all([
                tab.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/agency-grants` && response.request().method() === 'POST'),
                panel.locator('[data-agency-submit]').click(),
            ]);
            assert.equal(response.status(), 200); const receipt = (await response.json()).data;
            await waitReady(tab); return receipt;
        }
        let mandateRead;
        async function openEmployeeClient(tab) {
            await openAccess(tab, f.agency.business_public_id, f.agency.grant_refs['business.context.read']);
            const member = tab.locator('[data-team-list] > li').filter({ hasText: f.actors.employee.email });
            await member.locator('[data-team-assign-clients]').click();
            const helper = tab.locator('[data-business-employee-assignments]');
            await helper.locator('[data-employee-client]').selectOption(JSON.stringify([f.client.business_public_id, String(mandateRead)]));
            await helper.locator('[data-business-agency-team]').waitFor({ state: 'visible' });
        }
        try {
            const client = await actorPage('client');
            const direct = await directEmployee(client, f.client.business_public_id, f.client.grant_refs['business.context.read'], f.actors.employee.email);
            const staff = await actorPage('employee', 390);
            await openAccess(staff, f.client.business_public_id, direct.grant);
            assert.equal(await staff.locator('[data-business-public]').getAttribute('data-business-public'), f.client.business_public_id);
            await capture(staff, 'business-agency-direct-staff-ar-390.png');

            const agency = await actorPage('agency');
            const membership = await directEmployee(agency, f.agency.business_public_id, f.agency.grant_refs['business.context.read'], f.actors.employee.email);
            const mandatePanel = await agencyTask(client, 'mandate');
            assert.equal(await client.locator('html').getAttribute('dir'), 'rtl');
            await capture(client, 'business-agency-mandate-ar-1280.png');
            const mandate = await issueAgency(client, mandatePanel);
            mandateRead = mandate.grant_id;
            const assignmentPanel = await agencyTask(agency, 'assign');
            await agency.setViewportSize({ width: 390, height: 844 });
            await capture(agency, 'business-agency-assignment-ar-390.png');
            const assignment = await issueAgency(agency, assignmentPanel);
            await openAccess(staff, f.client.business_public_id, assignment.grant_id);
            assert.match(await staff.locator('[data-business-switch]').innerText(), /بتفويض بين الأنشطة/);
            const qualified = await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/surface?access=${assignment.grant_id}`);
            assert.equal(qualified.status, 200); assert.equal(qualified.body.data.agency_business_public_id, f.agency.business_public_id);
            assert.equal(qualified.body.data.private_history_available, false);
            const choices = await sendFrom(staff, '/api/businesses'); assert.equal(choices.status, 200);
            assert.equal(choices.body.data.items.some(item => item.business_public_id === f.other_client.business_public_id), false);
            const wrongClient = await sendFrom(staff, `/api/businesses/${f.other_client.business_public_id}/read`, 'POST', { authority_selection: selection(assignment.grant_id) });
            assert.equal(wrongClient.status, 403);
            const sources = await sendFrom(staff, '/api/source-rights'); assert.equal(sources.status, 200); assert.deepEqual(sources.body.data.sources, []);
            await capture(staff, 'business-agency-employee-ar-390.png');

            activate(agency);
            const menu = agency.locator('.advertiser-account-menu');
            if (await menu.getAttribute('open') === null) await menu.locator(':scope > summary').click();
            await agency.locator('[data-application="locale"]').click();
            await agency.waitForFunction(() => document.documentElement.lang === 'en'); await waitReady(agency);
            assert.equal(await agency.locator('html').getAttribute('dir'), 'ltr');
            if (await menu.getAttribute('open') !== null) await menu.locator(':scope > summary').click();
            await openEmployeeClient(agency);
            await agency.locator('[data-business-agency-team]').getByRole('heading', { name: 'Business mandates and employee assignments', exact: true }).waitFor();
            await capture(agency, 'business-agency-en-390.png');

            activate(client);
            // Refresh uses the current original creator read path. The command
            // being revoked is the exact issued mandate, never the direct grant.
            await openAccess(client, f.client.business_public_id, f.client.grant_refs['business.context.read']);
            await client.locator('[data-business-task="business-mandates"]').click();
            const grantedMandates = client.locator('[data-business-mandates]');
            await grantedMandates.locator('[data-mandate-review]').click();
            await grantedMandates.locator('[data-mandate-confirm]').check();
            const [withdrawn] = await Promise.all([
                client.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/agency-mandates` && response.request().method() === 'POST'),
                grantedMandates.locator('[data-mandate-withdraw]').click(),
            ]);
            assert.equal(withdrawn.status(), 200); await waitReady(client);
            const revoked = await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/read`, 'POST', { authority_selection: selection(assignment.grant_id) });
            assert.equal(revoked.status, 403, 'Client mandate withdrawal blocks its employee descendant');
            const surviving = await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/read`, 'POST', { authority_selection: selection(direct.grant) });
            assert.equal(surviving.status, 200, 'Independent direct client permission survives agency withdrawal');
            await openAccess(staff, f.client.business_public_id, direct.grant);
            const remaining = await sendFrom(staff, '/api/businesses'); assert.equal(remaining.status, 200);
            assert.equal(remaining.body.data.items.some(item => String(item.read_grant_id) === String(assignment.grant_id)), false);
            assert.equal(remaining.body.data.items.some(item => String(item.read_grant_id) === String(direct.grant)), true);
            await capture(staff, 'business-agency-independent-right-after-revocation-ar-390.png');
            fixture('business-agency-audit');
            const audit = JSON.parse(await readFile(path.join(run, 'business-agency-browser-audit.json'), 'utf8'));
            // Extend the already-proved agency journey only after its original
            // withdrawal/isolation checks: custody is an independent Business
            // operation and must never be inferred from that agency mandate.
            await openAccess(client, f.client.business_public_id, f.client.grant_refs['business.context.read']);
            const custody = client.locator('[data-business-file-custody]');
            await custody.locator('[data-custody-recipient]').selectOption(f.actors.employee.public_id);
            await custody.locator('[data-custody-confirm]').check();
            const [appointed] = await Promise.all([
                client.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/internal-team` && response.request().method() === 'POST'),
                custody.locator('[data-custody-save]').click(),
            ]);
            assert.equal(appointed.status(), 200);
            const custodyCommand = appointed.request().postDataJSON(), custodyReceipt = (await appointed.json()).data;
            assert.equal(custodyCommand.workspace_public_id, null); assert.equal(custodyCommand.effect, 'appoint');
            assert.equal(custodyCommand.recipient_public_id, f.actors.employee.public_id);
            assert.equal(custodyReceipt.workspace_public_id, null); assert.equal(custodyReceipt.link_ref, null);
            assert.ok(Number.isSafeInteger(custodyReceipt.versions.authority_epoch));
            const custodyRead = custodyReceipt.grant_refs['business.context.read']; assert.ok(custodyRead);
            await waitReady(client);
            const surrendered = await sendFrom(client, `/api/businesses/${f.client.business_public_id}/grants`, 'POST', {
                command_id: crypto.randomUUID(), authority_selection: selection(f.client.grant_refs['business.context.read']),
                effect: 'revoke', grantee_public_id: f.actors.client.public_id, action: 'business.context.read',
                parent_grant_id: null, grant_id: Number(f.client.grant_refs['business.context.read']), expected_grant: 1,
                expires_at: null, delegable: false, realm_workspace_public_id: null,
            });
            assert.equal(surrendered.status, 200);
            assert.equal((await sendFrom(client, `/api/businesses/${f.client.business_public_id}/surface?access=${f.client.grant_refs['business.context.read']}`)).status, 403);
            await openAccess(staff, f.client.business_public_id, custodyRead);
            await staff.goto(`${origin}/businesses/${f.client.business_public_id}?access=${custodyRead}&section=settings&settings=details`);
            await waitReady(staff);
            await staff.locator('[data-business-task="business-profile"]').click();
            await staff.locator('#bf-name').fill('Business preserved by its explicit custodian');
            const [custodyEdited] = await Promise.all([
                staff.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/context` && response.request().method() === 'PUT'),
                staff.locator('form').filter({ has: staff.locator('#bf-name') }).locator('button.bf-primary').click(),
            ]);
            assert.equal(custodyEdited.status(), 200); await staff.locator('[data-business-saved]').waitFor();
            const continued = await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/surface?access=${custodyRead}`);
            assert.equal(continued.status, 200); assert.equal(continued.body.data.business.name, 'Business preserved by its explicit custodian');
            assert.equal(continued.body.data.private_history_available, false); assert.equal(continued.body.data.agency_task.can_mandate, false);
            await openAccess(staff, f.client.business_public_id, custodyRead);
            const ownCustody = staff.locator('[data-business-file-custody] li').filter({ hasText: f.actors.employee.email });
            await ownCustody.locator('[data-custody-remove]').click();
            await staff.locator('[data-business-file-custody] [data-custody-confirm]').check();
            const [custodyRemoved] = await Promise.all([
                staff.waitForResponse(response => new URL(response.url()).pathname === `/api/businesses/${f.client.business_public_id}/internal-team` && response.request().method() === 'POST'),
                staff.locator('[data-business-file-custody] [data-custody-save]').click(),
            ]);
            assert.equal(custodyRemoved.status(), 200); assert.equal(custodyRemoved.request().postDataJSON().effect, 'remove');
            assert.equal((await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/surface?access=${custodyRead}`)).status, 403);
            assert.equal((await sendFrom(staff, `/api/businesses/${f.client.business_public_id}/surface?access=${direct.grant}`)).status, 200);
            await openAccess(staff, f.client.business_public_id, direct.grant);
            assert.deepEqual((await sendFrom(staff, '/api/source-rights')).body.data.sources, []);
            fixture('business-agency-audit');
            const custodyAudit = JSON.parse(await readFile(path.join(run, 'business-agency-browser-audit.json'), 'utf8'));
            return { runtimeBusinessCreation: true, runtimeDirectTeamAndStaffGrant: true, workspaceCreated: false,
                agencyBusiness: f.agency.business_public_id, clientBusiness: f.client.business_public_id,
                directGrant: direct.grant, membershipGrant: membership.grant, mandateGrant: mandate.grant_id, assignmentGrant: assignment.grant_id,
                exactNamedClientOnly: true, privateHistoryUnavailable: true, sourceOrDataRightsInferred: false,
                clientMandateWithdrawalDeniesDescendant: true, groupedWithdrawal: true, assignmentFromOwnEmployeeRoster: true, independentDirectRightSurvives: true,
                locales: ['ar', 'en'], inspectedStates, imagesCaptured: false, audit,
                custody: { namedRosterAppointmentUI: true, workspace: null, read: custodyRead, creatorOriginalReadSurrendered: true,
                    actualCustodianEditUI: true, continuedAfterCreatorSurrender: true, ownCustodyWithdrawalUI: true,
                    withdrawnCustodyRead: 403, independentDirectRead: 200, noInferredSourceRights: true, audit: custodyAudit } };
        } finally { restore(); await Promise.all(contexts.map(context => context.close())); }
    });
}
