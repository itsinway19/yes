<!-- DOCUMENTATION-MODERNIZATION-2026-11-15 -->
> **RETAIN_VERIFICATION_EVIDENCE — historical scope only.** This record preserves its original dates, source revisions, results and limits. Instructions, proposals, authorization and sequencing below are historical, not current work orders or design authority. Use the [governing architecture](../../architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md) and [current implementation](../../architecture/CURRENT_IMPLEMENTATION.md). Retired-document links point to their exact committed provenance.

# Business-first ownership consolidation — 2026-11-10

Owner authorization: the current chat accepts Business as the work unit, including an agency Business, and authorizes closing dependencies then incremental implementation in the existing dirty tree. Codex execution was explicitly accepted. No publication, main merge or production migration is authorized.

Baseline: `db229b44c1f9ad2015bfb39edc48ddc0d8355449` plus the pre-existing local registration, timezone, activity and advertiser UI changes. Earlier Foundation evidence does not qualify this dirty tree. Preserve all existing changes. This record extends the current Business execution record; it is not another authority system.

## Governing Owner clarification — clean new product / review handoff

The Owner clarified that the product is new and has no real customer legacy data. The public Workspace and previous-record experience is retired entirely, including its list, detail, team and source navigation. HTML GET `/workspaces/**` redirects to `/businesses`; both former public creation writers return410. Business choices/surface no longer discover or project Workspace relationships. The following dated evidence describes intermediate candidates and must not override this decision.

No database rows or protected historical migration inputs are deleted. Qualified headless historical contracts and original Source/service/audit custody remain technical dependencies, not a second advertiser product. New Workspace relationship creation and new organizational agency declaration grants are denied at their SQL writers, with exact prior command reconciliation retained. Existing `business.link.manage` remains legitimate for independent data-boundary attachment; it is projected as `data_use.binding_options`, without reviving Workspace collaboration or supplying Source/data-use rights.

The final Owner instruction defers comprehensive Foundation to a review session: **NOT_RUN_OWNER_DEFERRED**. Focused integration, UI and browser evidence are recorded separately. This handoff is not full architectural acceptance, deployment readiness or permission to publish/migrate.

Current result: **READY_FOR_REVIEW**. [Final review handoff, exact evidence and remaining boundaries](REVIEW_HANDOFF.md) supersedes the intermediate pending statements below. Current core13/266 and affected UI163 passed; production build and two no-image browser cases passed. The broader focused94-case run retained one corrected stale test expectation; its affected contract was rerun successfully. No Foundation was started.

## Acceptance before implementation

1. A creator adds a named employee to an independent Business without creating any Workspace; the employee has only explicitly assigned actions.
2. An agency is a Business. A client explicitly delegates a finite action to an agency representative. A named current agency employee receives an exact descendant assignment for that client only.
3. Withdrawing the client grant invalidates every descendant, preserving independently granted rights. Ending employee eligibility also invalidates assignments; rejoining never revives them.
4. Source/data rights, original/private history and external effects are not inferred from these grants. Database authority, not visibility, enforces this separation.
5. Populated upgrade preserves identities, old facts, rights, receipts and history. No automatic old-Workspace-to-Business ownership mapping.
6. Public Workspace creation retires only after the required replacement functions work. Existing historical/source obligations retain qualified access until explicitly transferred.
7. One advertiser surface serves every Business. Receiving a mandate is not restricted to an agency account type. An owner, employee and external representative each act through their exact current rights for the selected Business.
8. Commercial entitlements are separate from authority. Future plans may cap Business creation or permit external management, but no plan name, paid status or account label supplies a grant. No undefined quota, unlimited-plan promise, subscription engine or blanket agency exemption is introduced in this consolidation.

### Owner clarification — unified product and future plans

The Owner confirmed that individual/agency packaging may later define creation counts and external-management entitlements. These are future commercial policies, not separate identities or dashboards. Their future writers must check the applicable commercial entitlement **and** current action/resource authority; passing either one alone is insufficient. Missing policy is not evidence of a free or unlimited commercial offering. Current declaration commands keep their existing authorized availability until a concrete plan contract is accepted.

“الفريق والصلاحيات” is the shared Business management surface. Employees may receive only implemented, explicit actions. Sales/marketing job titles do not create sales, provider execution, private data or Source capabilities. The employee-oriented add/edit/remove flow and per-client assignment acceptance remain required; the first finite declaration proof does not establish those as complete.

Technical `agency_*` references in migration750 describe the existing typed mandate basis. They do not classify the account or Business as an agency. Keeping exact pending command identities preserves reconciliation; no parallel generic grant system is added to change terminology.

## Closed dependency dispositions

| Dependency and existing owners | Disposition | Final responsibility / cutover condition |
|---|---|---|
| BusinessScope; business create/read/facts/practices; BusinessSurfaceController | KEEP | Independent Business identity and declarations; retain existing authority checks. |
| direct_team.sql; grant_commands.sql; DirectBusinessTeam | EXTEND | Existing finite Business team policy and exact grants remain the single employee authority. Membership eligibility must identify the particular current grant chain, not any incidental read access. |
| organization_team.sql; OrganizationTeam; workspace_memberships | REPLACE for new normal flows | New Business staffing uses canonical Business grants. Old membership remains an explicit prerequisite only for untransferred legacy grants. Do not silently reinterpret it. |
| business_workspace_links agency; scope_authority.sql; BusinessCollaboration | REPLACE | Typed Business-to-Business qualification on the existing qualification/grant graph, with exact client issuer, agency Business and employee eligibility basis. No second ACL evaluator. |
| internal_team.sql / InternalClientFileTeam | RETIRE from new normal flows after replacement | Preserve old explicit custodianship, recovery and original records until individually qualified transfer; do not convert internal file custody to external client representation. |
| authority_footprint, lock_authority_footprint, verify_scope_path, grant writer | EXTEND at original owner | Include exact agency eligibility and all ancestors in sorted authority locks and final requalification; prevent ordinary delegation from stripping agency constraints. |
| Business choices/surface/history, market private consumers, practices, licences/beneficiaries, material planners | ADAPT | All consume the same current chain. Agency assignment does not release creator-private history or data. Requalify expired/revoked prerequisites before returning a receipt or data. |
| source_registry + business_native_sources + NativeSourceContext + five provider route groups | KEEP | Existing source identity and native Business custody; source manager/read/use rights remain independent. Agency-owned sources belong to the agency Business, not a new Workspace. |
| WorkspaceSourceController, native legacy provider routes, source adoption/fences/audit recovery | KEEP TEMPORARILY per actual legacy resource | Original Source rights and obligations stay valid under their existing custody. Transfer needs exact resource/owner/right evidence; no bulk identity rewrite. |
| BusinessSources shared inventory / source visibility | ADAPT | Explain actual qualified source owner and independent use rights; never treat organizational discovery as client data access. |
| ServiceIdentityAuthority / service_workspace_scopes / ServiceWorkspaceContext | KEEP bounded legacy/internal | Service identity remains its own owner. No service Business execution authority is inferred. Adapt actual Business consumer only with explicit action/scope; no speculative automation framework. |
| RegisterCommercialScope / SA-01 | KEEP bounded internal provenance | Business beneficiary is distinct from payer, entitlement, capacity and data authority. Operating subscription policy remains unavailable, not free/unlimited/paid. |
| migration cohorts, legacy mappings/history, commercial/Purpose history and audit | KEEP | Existing original identity/history and qualified mappings; no name or membership-based migration. Add forward SQL only; retain historical input hashes. |
| /organizations/new, POST /organizations, POST /workspaces, /business/start | RETIRE public creation after replacement proof | Retire server writer and SPA together; preserve known existing resources/history, no cosmetic hiding or creation redirect that silently creates a Workspace. |
| /workspaces and advertiser navigation | RETIRE public experience | HTML redirects to Business list; no previous-resources menu or parallel team/source context. Qualified headless historical contracts remain internal technical dependencies only. |
| OpenAPI generators/types and browser/client recovery | ADAPT | Existing generated contracts; freeze exact pending commands, isolate Business switches, retain denied/unknown/expired states and Arabic/RTL. |

## Implementation plan

- [ ] Add failing integration tests for the three first-stage behaviors, revocation, independent-path survival, employee re-enrollment, expiry, cross-client denial and resource separation.
- [ ] Extend the existing qualification/grant model with typed agency/employee basis references only where existing columns cannot express them; implement in additive migration after 740. Do not alter protected historical SQL.
- [ ] Update the existing footprint and path verifier; constrain issuance so no unconstrained child can escape the agency basis. Use the same receipt, fence, permit and RLS owners.
- [ ] Expose the finite commands and qualified read projection through BusinessScope, current controllers and generated OpenAPI.
- [ ] Run focused isolated integration/upgrade/architecture guards; record the actual source state and evidence.
- [ ] Integrate simple Business team/agency tasks, then retire parallel public creation with equivalent history/resource access retained.
- [ ] Verify Arabic/English, RTL/mobile, switching, denied/unknown outcomes and browser journeys. Run applicable mandatory final gates on the final tree.

## Evidence and rulings

- Initial browser observation at `http://localhost:8027/workspaces`: advertiser sees a separate organization/team context and a creation CTA to `/organizations/new`. This is confirmed overlap; no preview data was written.
- Ruling: reuse finite direct Business staffing and the canonical qualification/grant graph. A current staff grant can supply eligibility only when issued under that exact Business team policy; general context.read, agency access or a historical Workspace membership cannot substitute.
- Ruling: a first agency mandate covers only explicitly selected current-declaration actions already delegable by the client. It is not legal verification, provider authority, private inquiry access, source access or data-use consent.
- BACKEND_VERIFIED: pending. UI_INTEGRATED: pending. BROWSER_VERIFIED: pending. Foundation: pending. Current task is not accepted or complete.

### First executable proof and review

- Added forward migration750: two nullable, foreign-keyed agency/employee-basis references on the existing qualification receipt. Existing rows remain unassigned. Grants retain their parent chain and selection contract; the agency designation is a typed qualification basis, not a Workspace or another ACL.
- Extended the original footprint/path verifier and extracted its sorted row-lock executor for server-selected recipient bases. Ordinary issuance rejects agency-bearing paths; exact existing revocation remains available. Replacement employee grants cannot revive assignments pinned to earlier grant identities.
- `POST /api/businesses/{business}/agency-grants` admits only the five existing finite current-declaration actions. The original client creator appoints the agency Business's original representative; that representative assigns a current direct employee. It issues no Source, private-history, client-data or external-effect rights.
- `GET /api/businesses/{business}/agency-options` prepares exact action/parent/employee references without exposing an agency directory or private names. Preparation is not a grant or success acknowledgement.
- RED `storage/ownership-red-unsandboxed.log`: two tests fail404 before the endpoint. A prior sandbox initialization never reached tests and was stopped.
- First focused PASS `storage/ownership-green-02.log`: **15 tests /223 assertions**, including existing direct-team/delegation regressions. A preceding run caught a new SQL alias collision; it was corrected at the writer. The existing upgrade test preserves all old fields and separately asserts both new basis fields remain NULL.
- Projection RED `storage/ownership-options-red.log`; subsequent PASS `storage/ownership-options-green.log`: **59 tests /139 assertions**, including migration-input and Core guards.
- Expanded first run `storage/ownership-boundaries-01.log`: **9 passed /5 failed /425 assertions**. New agency final-receipt contention and populated750 upgrade passed. Failures localized to older upgrade snapshots lacking the two new NULL fields and final-schema provenance still pointing at the replaced historical verifier. Preserve all original-field assertions, assert absence of inferred agency references, and pin the new final definitions rather than editing historical SQL.
- Independent static review found one concrete missing dependency: agency cohort recovery mode/lock. Regression `storage/ownership-recovery-red.log` reproduced client access200 while the agency was held. The correction adds agency cohort identity/epoch/mode to the footprint, locks its cohort before principal/grant locks, and requires V2_ACTIVE in eligibility. Recheck pending.
- Frontend integration adds a Business agency task, exact prepared parent selection, finite expiry, confirmation and immutable unknown-result recovery. Affected API/advertiser selection passed162 cases; the subsequently improved exact-parent panel passed8 cases. These are UI tests, not browser acceptance.
- These results qualify their tested file states only. Expanded correction checks, remaining consumer migration, public creation retirement, browser and mandatory final gates remain pending. The overall consolidation is not complete.

### Subsequent qualified evidence and remaining acceptance

- `storage/ownership-surface-green.log`: **9 tests /853 assertions PASS**, including populated forward upgrade and final composition. The recovery regression is corrected: an agency Business in recovery mode denies the derived client path while independently granted client access survives.
- `storage/ownership-browser-01.log`: first finite declaration browser proof passed. Evidence directory `storage/framework/testing/goals-spa-7988d8a8-cd08-493a-aafb-3bc67fd75d92` records Arabic1280/390 and English390, direct employee addition, exact client assignment and selective withdrawal. Workspace count stayed4, actor Workspace memberships0 and Source/client-data/data-use grants0. Subsequent affordance/copy changes require new browser evidence; do not apply that result to the newer served build.
- Public creation is retired at both old POST endpoints with410 and a Business creation next action; old GET entry points redirect to Business creation. Previous-resource readers retain their original qualification. `/workspaces` now presents qualified historical resources/records, never a new organization/team creation CTA. The main Business list exposes this link only when qualified previous resources or reassessment exist. Original resource permissions remain in the previous-record detail, outside the ordinary Business team task.
- `storage/ownership-retirement-php-01.log` retained **74 passed /4 failed /534 assertions**. Three failures were the retired creation expectation and a projection outside its required transaction; corrected. The identity-sensitive concurrency setup failed with array sessions in the older helper. The canonical Redis runner below qualifies the correction and the actual identity/session environment; the earlier failure is retained, not reclassified as a product pass.
- `storage/ownership-retirement-official-02.log`: canonical isolated runner **59 tests /318 assertions PASS**, source-unchanged `True`, processes `STOPPED`. Includes the first agency proof, migration-input protection, legacy Workspace reads/public creation retirement and the real in-flight lifecycle invalidation case.
- `storage/ownership-retirement-js-04.log`: **157 tests PASS** before the neutral Business delegation copy. New Arabic/English unified-task regression fails in `storage/ownership-unified-business-red.log` before correction; `storage/ownership-unified-business-green.log`: **158 tests PASS** after correction. These cover UI/API contracts and command recovery; they do not establish commercial entitlement enforcement or browser acceptance.
- Overall acceptance remains **INCOMPLETE**: the unified employee-oriented email/add/edit/remove experience, broader consumer cutovers, explicit legacy ownership transfers, latest browser verification and final mandatory Foundation coverage are not established. The existing finite declaration proof is not an advertising-execution, CRM, private-data or paid-plan capability.

### Unified Business task — latest bounded verification

- `storage/ownership-unified-business-build.log`: production typecheck/build PASS; `storage/ownership-unified-api-check.log`: generated application contract/types match.
- `storage/ownership-unified-browser.log`: **2 browser cases PASS**, the required real login/create/list/open reference plus direct staff/named client assignment/selective withdrawal. Final run `storage/framework/testing/goals-spa-7e2d5e30-b1a8-4a43-a59b-a901c629e69b`; source-unchanged `True`; all runner processes `STOPPED`, exit0. Inspected the Arabic390 direct-staff and assignment captures. Workspace count4→4, actor Workspace memberships0, external Source/client-data/data-use grants0.
- This verifies the bounded finite declaration journey on the neutral Business delegation UI. It does not complete the employee-oriented CRUD acceptance, broader legacy transfers or final Foundation. All tested creation and mutation happened in the marked disposable database, not the Owner preview or production.

### Employee management — additive migration760

Acceptance: one registered, verified employee can be found by exact email, assigned explicit supported Business actions, reviewed by name, edited with an exact current grant/version set and removed from that Business team. No mail is sent. Removal affects only children of that Business's existing direct-team administrator roots; independent rights and external resources retain their own authority. Nonempty action sets require reading. Legacy mixed expiries require an explicit common duration choice. Invalid old grant identities are never revived; explicitly reconfirmed rights receive new identities.

The implementation adds projections and an atomic coordinator over `builderad_v2_business_grant_command`, not another membership table or policy store. It freezes root/recipient/target rows in the existing sorted lock order, compares all recorded active staff grants and requalifies owner roots. Each canonical child receipt remains durable. A completed child transaction slot is released only for the exact actor/command after all permits are consumed; its complete authority proof and digests are preserved in the outer invocation. The final receipt covers retained staff validity and requested expiry, while removed expired records stay outside live prerequisites. Private executor access adds only `users.name/email`, with qualified roster and exact-email projections; the runtime role receives no new direct table privilege.

- Endpoint RED `storage/ownership-team-red.log`:2 failures404.
- First implementation `storage/ownership-team-green-01.log`:45 passed/10 failed. Failures identified the migration down-reader literal contract, unavailable executor name/email columns, completed invocation transaction-slot reuse and the old public Workspace setup in `NativeMaterialFixture`. Historical input hashes and original fixture assertions are retained; legacy material setup now uses the already guarded explicit historical provisioner.
- Second correction `storage/ownership-team-green-02.log`:48 passed/3 failed. The only remaining implementation error was the required canonical receipt `returned_versions`; result now records the existing Business authority-fence epoch. No new team revision store is introduced.
- `storage/ownership-team-green-03.log`:1 passed/3 failed. These failures were test JSON traversal of dotted action keys, not missing grants; assertions now read the actual action-keyed map.
- `storage/ownership-team-green-04.log`:4 tests/60 assertions PASS, including exact staff CRUD/CAS, unqualified recipient denial, re-confirmation after qualification withdrawal, expired cleanup and a real observed final-receipt lock wait followed by expiry/rollback. Later input-validation additions and final combined gates remain to run. Sources changed outside the running tests, so this development evidence is not a frozen final-candidate acceptance.
- UI `storage/ownership-team-ui.log`:166 tests PASS before the final employee-to-client integration. Team drafts participate in the existing archive/departure guards; other grants and withdrawal history remain mounted in a collapsed section. Current access label is «الفريق والصلاحيات».

No Owner preview schema or data was migrated. Final acceptance remains open until current employee/client UI, legacy cutovers and mandatory gates are qualified on the resulting tree.
