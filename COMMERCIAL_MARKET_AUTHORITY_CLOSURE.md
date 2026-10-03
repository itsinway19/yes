# Commercial market authority preparation — Owner review

2026-10-03. Owner explicitly approved active Founder preparation of one exact server-generated market target, without operational Commercial self-grant. HEAD remains `9db511bdd813e49d68deacf2bec110e388094af1`; no commit, push or merge.

```text
NEW_COMMERCIAL_MARKET_END_TO_END_JOURNEY_COMPLETE = YES (authorized disposable journey; real account prerequisites below)
AUTHORITY_BYPASSED = NO
SELF_GRANT_FROM_COMMERCIAL_CREATED = NO
MANUAL_AUTHORITY_UUID_REQUIRED = NO
GENERAL_GRANT_MANAGEMENT_ENGINE_CREATED = NO
COMMERCIAL_DOMAIN_SEMANTICS_CHANGED = NO
TARGET_MARKET_IMPLEMENTED = NO
AUTHORIZED_PREPARATION_TESTED = YES
UNAUTHORIZED_PREPARATION_REJECTED = YES
TARGET_IDENTITY_CANONICAL = YES
EXACT_TARGET_IDENTITY_PROPAGATED_TO_COMMERCIAL = YES
AUTHORIZED_MARKET_RECORDING_TESTED = YES
PREPARED_TARGET_CONSUMED_CORRECTLY = YES
ORPHAN_OR_AMBIGUOUS_AUTHORITY_TARGET_POSSIBLE = NO (atomic approval+administrator grant; failed transaction leaves neither)
PREPARATION_GRANT_FAILURE_RECOVERY_VALIDATED = YES (injected approval insert failure and second operational grant failure, full rollback, retry success)
CONSUMED_TARGET_REUSABLE_AS_NEW_MARKET = NO
CONCURRENT_TARGET_CONSUMPTION_SAFE = YES
TARGET_AUTHORITY_SCOPE_VERIFIED = YES
HISTORICAL_AUTHORITY_EVIDENCE_PRESERVED = YES
COMMERCIAL_VISUAL_HIERARCHY_PRESERVED = YES
PRIMARY_UI_CONTAINS_NO_MANUALLY_EDITABLE_SYSTEM_DERIVED_IDS = YES
ARABIC_VALIDATED = YES
ENGLISH_VALIDATED = YES
DESKTOP_VALIDATED = YES
MOBILE_WEB_VALIDATED = YES
COMMIT_CREATED = NO
PUSHED = NO
MERGED = NO
STATUS = STOPPED FOR OWNER REVIEW
```

## Actual journey and authority scope

The preparation target is a server-owned UUIDv7 for a future Commercial market. A session-issued preparation intent identifies the approval request; GrantAuthority generates the market UUID on the successful transaction, retains it in immutable approval evidence and assigns the same exact target to a distinct eligible administrator. Approved label is authority preparation context; recording remains a separate Commercial fact. Founder authority comes from the explicit Owner decision, not inherited staff.view/grant.manage or an operational Commercial grant.

The named administrator separately issues ConfigureCommercialMarket and ReadCommercialMarketRegistry to another eligible operator. Both operational grants are atomic as a pair, carry the exact target, null workspace/version tuple, current recipient generation and approval expiry. Founder is not operationally granted through this path; issuer cannot grant to itself. Trusted deployment Owner mandate execution remains unchanged and separate. The new runtime approval is not injected into trusted deployment configuration.

The operator selects “تسجيل هوية سوق”, chooses the approved human market label, enters the recorded display name and submits the unchanged Commercial writer. The actual target UUID becomes the market primary key. Recorded targets disappear from new-market choices, while their existing Configure grants continue supporting configuration versions. Independent Registry-read authority keeps the recorded market readable. Grants and approval history are retained rather than deleted on consumption.

Same preparation intent with the same payload returns the same target; altered payload conflicts. Approval and its administrator grant roll back together. Configure and Registry-read grants also roll back together if either issuance fails. Revoked authority cannot be revived by replay. Unconfirmed requests retain the exact payload across remount; first definitive denial permits correction, whereas a denial following an uncertain attempt does not pretend to settle that earlier outcome. Matching approval evidence allows explicit review. A consumed operator target can be explicitly retired from follow-up against refreshed server evidence, keeping the original request in tab review evidence without assuming successful grant issuance.

Two independent, exact-authorized operators raced for the same target. The test observed an actual PostgreSQL waiter before releasing the first transaction: one200, one409, one market and one command receipt, retained approval. Same-user sessions additionally serialize through the existing SessionAuthority principal advisory lock; that lock was not weakened.

## UI and current real-account prerequisites

Preparation lives under existing Administration at `/internal/administration/commercial-markets`, independently gated for Founder or exact Commercial administrator. Authorized links appear in Administration/Commercial; no top-level module or generic grant console. Founder sees approval decisions; administrator sees its exact approved markets and eligible operator choices; historical/read-only evidence does not expose an executable form.

Name, reason and expiry are human decisions; UUIDs are server-derived and visible only as read-only review evidence. Reason documents approval; expiry governs personnel authorization, not the market's lifespan. Mandatory expiry was an implementation choice for bounded delegation, acknowledged to the Owner; it does not impose a Target Market or Commercial market expiry policy. Date input is normalized to an explicit ISO instant before submission.

The existing Owner preview currently has only the active Founder as an eligible internal account. Therefore there is no distinct qualified administrator to select; another staff account must complete existing invitation/activation/passkey prerequisites. Recent authentication is also required for effects. Human name/reason/date fields remain editable; only unavailable administrator choice and final execution are disabled. No real-account grant, staff creation, authority bypass or silent Founder self-grant was performed to make the button executable. Missing prerequisites are explained in the UI.

Desktop layout uses two contained columns with minmax(0,1fr), bounded controls, separate action/navigation widths and readable spacing; mobile uses one column. Actual391px mobile and1440/1915px desktop inspection found no horizontal overflow. Arabic and English were checked; the Owner preview language was restored to Arabic. Browser extension contentScript/i18next/location/runtime.lastError messages were not the cause of disabled fields or the missing-table500.

## Preview500 repair

The exact local error was SQLSTATE42P01: security_commercial_market_approvals did not exist in the existing localhost8140 preview's builderad_test database on5432. After identifying its existing protected launcher configuration without printing credentials, only migration000280 was applied with builderad_owner to verified builderad_test:5432. Existing users, workspaces, security grants and Commercial markets counts were unchanged. An authenticated rendered200 page was then verified. No migrate:fresh/reset or broader migration was run on that database. This additive local-preview migration is separate from the disposable runners' zero5432-connections proof.

## Verification

- `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Browser -Filter 'CommercialMarketPreparationTest|CommercialScopeTest|InternalAuthorityTest|OwnerGrantReassessmentTest'`: **45 passed,745 assertions**,185.05s. Log: `storage/framework/testing/market-preparation-final-runtime.log`.
- `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Browser -Filter CommercialMarketPreparationTest`: **4 passed,81 assertions**,73.88s; final browser journey executed on its isolated fixture. Log: `storage/framework/testing/market-preparation-visual-runtime.log`.
- `node --test tests/commercial-operator.test.mjs tests/localization.test.mjs`: **35 passed,0 failed,0 skipped**,2795.5312ms; includes authority decisions, stable uncertain retries, definitive denial recovery, consumed-target follow-up, partial refresh and editable human fields under missing prerequisites. Log: `storage/framework/testing/market-preparation-final-js.log`.
- `npm run build`: **PASS**,including vue-tsc; final Vite build27.56s. Log: `storage/framework/testing/market-preparation-final-build.log`.
- PHP syntax checks for new controller and GrantAuthority: **PASS**. `git diff --check`: **PASS** (line-ending normalization warnings only).
- Final disposable HTTP and PostgreSQL processes: **STOPPED**,confirmed in both runner logs. Existing Owner8140 server predated this task and remains running. Test tab closed; viewport override reset; existing Owner page retained.

## Known unresolved review/test gaps

- Full repository regression was not run; focused relevant cone only.
- Source/target fresh-installation N1 durability proof was not rerun. Prior normal N1 source assertions do not replace that proof.
- Synthetic sessions use the real SessionAuthority and Founder factor prerequisites; they are not proof of a fresh real-device WebAuthn ceremony.
- Existing SA01 authority expiry/contention tests ran in the cone; no claim of exhaustive new-preparation expiry-under-every-wait matrix.
- Independent reviewer produced two actionable recovery findings, both fixed with failing/passing tests, then hit its usage limit before completing a formal whole-patch verdict. Author follow-up review is complete; independent final acceptance remains unavailable. This is not release or merge approval.
- Real Owner account still needs a distinct eligible administrator and operator under the Owner-approved separation of duties; browser proof used isolated synthetic personnel and did not enroll real people.

## Changed paths

New request product paths:

- `app/Http/Controllers/CommercialMarketPreparationController.php`
- `app/Http/Controllers/CommercialController.php` (also contains prior Phase A changes)
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Security/GrantAuthority.php`
- `database/migrations/0001_01_01_000280_create_commercial_market_approvals.php`
- `database/sql/commercial_market_approvals.sql`
- `resources/js/Layouts/InternalLayout.vue`
- `resources/js/Pages/Internal/Administration.vue`
- `resources/js/Pages/Internal/Commercial.vue` (also contains prior Phase A changes)
- `resources/js/Pages/Internal/CommercialMarketPreparation.vue`
- `resources/js/types/index.d.ts`
- `routes/web.php`

New request tests/reports:

- `tests/CommercialMarketRecordingWorker.php`
- `tests/Feature/Commercial/CommercialMarketPreparationTest.php`
- `tests/SA01BrowserFixture.php` (also contains prior changes)
- `tests/commercial-operator.test.mjs` (also contains prior tests)
- `COMMERCIAL_MARKET_AUTHORITY_PREWRITE.md`
- `COMMERCIAL_MARKET_AUTHORITY_CLOSURE.md`
- `docs/superpowers/plans/2026-10-03-commercial-market-authority.md`

Preserved prior uncommitted Phase A paths, not newly modified for this authority request:

- `package.json`
- `resources/js/localization/messages.ts`
- `tests/Feature/Commercial/CommercialScopeTest.php`
- `tests/Feature/Security/OwnerGrantReassessmentTest.php`
- `tests/SA01Proof.ps1`
- `COMMERCIAL_PHASE_A_REVIEW.md`
- `COMMERCIAL_VISUAL_MICRO_REVIEW.md`

## Screenshots

All under absolute directory `C:/dev/builderad-worktrees/admin-experience-foundation-001/storage/framework/testing/commercial-market-preparation-visual/`:

1. `01-owner-desktop-ar.jpg` — initial contained Arabic desktop.
2. `02-owner-desktop-en.jpg` — English desktop.
3. `03-owner-mobile-en.jpg` — English mobile, no overflow.
4. `04-owner-mobile-ar.jpg` — Arabic mobile.
5. `05-founder-approved.jpg` — actual synthetic Founder approval success.
6. `06-operator-authorized.jpg` — separate administrator operational grant success.
7. `07-owner-fields-enabled-desktop.jpg` — final editable human fields, bounded desktop and explained missing administrator.
8. `08-consumed-choice-removed.jpg` — recorded identity absent from new-market choices.
9. `09-recorded-market-readable.jpg` — registry remains readable with independent permission.
10. `10-historical-consumed-approval.jpg` — consumed historical approval preserved, no executable new-market form.
11. `11-final-mobile-ar.jpg` — final editable mobile fields within viewport.

Screenshots, temporary migration helper, synthetic credentials, test databases and build outputs are ignored artifacts; none staged/committed. No task process remains running.
# Subsequent Owner decision — 2026-10-03

The Owner superseded Founder-preparation-only authority: the Founder now operates Commercial directly without self-grants or another employee; employee delegation is optional and separate. Earlier assertions below describe the previous checkpoint. Current policy, verification and remaining gaps are recorded in `FOUNDER_DIRECT_COMMERCIAL_REVIEW.md`.
