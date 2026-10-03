# Commercial Phase A — Owner review

2026-10-03. Current SA-01 operator correction only. Implementation remains uncommitted.

```text
CHECKPOINT_BASELINE = 9db511bdd813e49d68deacf2bec110e388094af1
CURRENT_HEAD = 9db511bdd813e49d68deacf2bec110e388094af1
COMMERCIAL_STATE_SYNC_FIXED = YES
COMMERCIAL_AUTHORITY_BOUND_INPUTS_FIXED = YES
COMMERCIAL_COMMAND_LIFECYCLE_FIXED = YES
BUSINESS_GATE_TESTS_REALIGNED = YES
CONTENTION_TEST_RELIABILITY = PASS in the complete Commercial suite and subsequent combined/runtime runs; exact waiter and DB-clock expiry retained
COMMERCIAL_CATALOG_REFERENCE_PRESENTATION_FIXED = YES
COMMERCIAL_RESULT_RECEIPT_AUDIT_PRESENTATION_FIXED = YES
COMMERCIAL_MARKET_TARGET_MARKET_AMBIGUITY_REMOVED = YES
COMMERCIAL_DOMAIN_SEMANTICS_CHANGED = NO
TARGET_MARKET_IMPLEMENTED = NO
NEW_COMMERCIAL_CAPABILITY_ADDED = NO
AUTHORITY_CHANGED = NO
ARABIC_VALIDATED = YES
ENGLISH_VALIDATED = YES
DESKTOP_VALIDATED = YES — actual CSS viewport 1440 × 1000
MOBILE_WEB_VALIDATED = YES — actual CSS viewport 390 × 844
COMMERCIAL_VALUE_CLASSIFICATION_COMPLETED = YES
PRIMARY_UI_CONTAINS_NO_MANUALLY_EDITABLE_SYSTEM_DERIVED_IDS = YES
AUTHORITY_PRESENTATION_MODEL = EXECUTABLE / VISIBLE_BUT_READ_ONLY / PREREQUISITE_MISSING / AUTHORITY_UNDETERMINED
VISIBLE_BUT_READ_ONLY_VALIDATED = YES — mounted Vue test and authenticated reference workflow after recording the authorized identity
PREREQUISITE_MISSING_VALIDATED = YES — mounted Vue tests; live unassignable Jordan registration and expired fresh authentication
AUTHORITY_UNDETERMINED_VALIDATED = YES — mounted actual Vue component with missing fresh-authentication presentation evidence
UNCERTAIN_COMMAND_RECONCILIATION_IMPLEMENTED = YES
UNCERTAIN_COMMAND_PAYLOAD_IMMUTABILITY_VALIDATED = YES
CAS_CONFLICT_AUTO_RETRY_CREATED = NO
CAS_CONFLICT_OPERATOR_REVIEW_VALIDATED = YES — mounted actual Vue component
CONTENTION_INVARIANT = SUPPORTED by the real PostgreSQL contention/expiry proof
CONTENTION_TEST_HARNESS = RELIABLE in the observed runs after moving expiry initialization after LOCK_READY
STEP_4_STATUS = COMPLETE
STEP_5_STARTED_WITH_INCOMPLETE_STEP_4 = NO
APPROVED_COMMERCIAL_REFERENCE_LANGUAGE_VALIDATED = YES
OUT_OF_OPTION_HIDDEN_VALUES_POSSIBLE = NO — covered state transitions clear invalid selected values; backend remains final
MARKET_SCOPED_STALE_VALUES_FIXED = YES
EXPECTED_REVISION_SYNC_FIXED = YES
REGISTRATION_SNAPSHOT_SYNC_FIXED = YES
CATALOG_AUTHORITY_BOUND_SELECTION_FIXED = YES
COMMAND_RETRY_VS_NEW_OPERATION_CLEAR = YES
COMMERCIAL_CATALOG_REFERENCES_VISIBLE = YES
COMMERCIAL_CONFIGURATION_HISTORY_UNDERSTANDABLE = YES
ASSIGNABILITY_MEANING_CLEAR = YES
OPERATION_EFFECT_DISTINCT_FROM_RECEIPT = YES
RECEIPT_DISTINCT_FROM_AUDIT_DELIVERY = YES
RAW_TECHNICAL_DETAIL_PROGRESSIVELY_DISCLOSED = YES
COMMERCIAL_MARKET_MEANING_CLEAR = YES
AUTHORITY_SEMANTICS_UNCHANGED = YES
COMMERCIAL_NEW_DOMAIN_CAPABILITY_ADDED = NO
COMMERCIAL_VALUE_CONTRACTS_CLASSIFIED = YES
AUTHORITY_PRESENTATION_STATES_IMPLEMENTED = YES
VISIBLE_BUT_READ_ONLY_SUPPORTED = YES
PREREQUISITE_MISSING_SUPPORTED = YES
AUTHORITY_UNDETERMINED_SUPPORTED = YES
UNCERTAIN_COMMAND_PRESERVES_ORIGINAL_IDENTITY = YES
UNCERTAIN_COMMAND_PRESERVES_ORIGINAL_PAYLOAD = YES
SAME_COMMAND_DIFFERENT_PAYLOAD_ALLOWED = NO
CAS_CONFLICT_AUTO_RETRY = NO
CAS_CONFLICT_REQUIRES_OPERATOR_REVIEW = YES
CONTENTION_STEP_4_GATE_RESOLVED = YES
APPROVED_COMMERCIAL_REFERENCE_OPERATOR_LANGUAGE = YES
COMMIT_CREATED = NO
PUSHED = NO
MERGED = NO
STATUS = STOPPED FOR OWNER REVIEW; not release or final acceptance
```

## Correctness and contracts

Market and operation changes clear dependent choices. Refreshed props rederive revision and the registration's exact configuration/reference/denomination snapshot. A selection absent from the available options is cleared. Beneficiaries use the exact scope/workspace tuple instead of a list index; authorized workspace names come from the existing independently checked endpoint.

Assignability separates current configuration, selected new configuration and explicit disable. An empty selection cannot become disable. Only complete, readable configurations can be presented as executable for new assignability. Denomination remains independent of market/geography. Creating an already-readable market identity is blocked as a missing new-identity prerequisite; a configuration identity already present in refreshed history is not presented as a new creation.

| Value | Actual contract and presentation |
| --- | --- |
| Market identity | Authority-bound target; select a readable market or an unrecorded Configure grant target |
| Market label | Operator decision for identity creation |
| Approved reference identity | Exact market/version grant identity; one may be auto-selected, multiple appear as actual choices |
| Catalog source identity/version, approval reference, SHA-256 | Operator-supplied approved-source provenance; these are not newly generated Catalog-reference IDs |
| New configuration identity | Server-generated `newConfigurationId`; read-only evidence |
| Configuration/reference/denomination for registration | Canonically derived from current selected market assignability; read-only evidence |
| Configuration and explicit denomination for creation | Operator choice of a recorded reference and independent denomination; incomplete versions retain existing domain semantics |
| Assignability configuration | Operator selection of a recorded configuration, or an explicit disable decision |
| Revision | Canonical CAS evidence; derived, read-only, never automatically resubmitted after conflict |
| Scope/workspace | Authority-bound exact tuple; operator chooses an authorized beneficiary |
| Command identity | Client-generated system identity; preserved with immutable semantic payload during uncertainty |
| Reason | Operator input with existing fingerprint semantics |
| Receipt/context lookup identity | Operator choice for an independently authorized read, not an editable derived mutation identity |
| Obligations, acknowledgments, timestamps, fingerprints, policy and custody payload | Read-only technical/audit evidence under existing authorized reads |

No SQL, migrations, domain service, security service or route contract changed. The controller adds existing fresh-authentication evidence and target/workspace/acknowledgment fields from already RLS-protected obligation rows. The four authority states are presentation evidence, not an authorization decision or new capability.

## Command behavior

In flight blocks duplicate submissions. Uncertain outcome locks the editing workflow and preserves the original command ID and a cloned original semantic payload. Retry sends that same request. A denial on an uncertain retry does not resolve the earlier unknown outcome. Another command's receipt cannot unlock the request. A matching receipt requires an explicit operator reconciliation action before a new command.

Confirmed completion creates a new command for a subsequent semantic operation. New-command reset clears the prior operation effect. Receipt, recorded context and audit recovery have separate result stores and sections.

409 requests canonical refresh without retry. It shows original/current revision and the original request for comparison. A confirmed first-attempt rejection may start a new command only after successful state refresh and explicit operator review. A 409 received while retrying a previously uncertain command still requires receipt reconciliation. Retry stays blocked during conflict review. Revision refresh never becomes last-write-wins.

## Tests and prior failures

Commands were run from `C:\dev\builderad-worktrees\admin-experience-foundation-001` using PowerShell 7 for SA01Proof. PostgreSQL ran on disposable port 5433; the real disposable guard and final SQL catalog proofs passed. Port 5432 was not used or written.

```powershell
pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter CommercialScopeTest
# 28 passed, 418 assertions, 132.39 seconds. Step 4 gate before Step 5.

pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Browser -Filter 'CommercialScopeTest|BusinessFoundationTest|WorkspaceIsolationTest|DatabaseAuthorityBoundaryTest|InternalAuthorityTest|OwnerGrantReassessmentTest'
# 98 passed, 1011 assertions, 135.84 seconds.

pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Browser -Filter 'r2_commercial_admin_expiry_after_grantee_contention_rolls_back_issuance'
# Additional final browser run: 1 passed, 7 assertions, 13.76 seconds.
# Contention test itself: 12.66 seconds. Actual catalog evidence passed on shutdown.

node --test tests/commercial-operator.test.mjs tests/localization.test.mjs
# Final: 27 passed, 0 failed, 0 skipped; 21 operator tests plus 6 localization tests.

npx vue-tsc --noEmit
# PASS, exit 0.

npm run build
# PASS, exit 0; final build 4.48 seconds. Plugin timing notices only.

git diff --check
# PASS.

git diff --exit-code HEAD -- app/Commercial app/Security database routes/web.php
# No differences.
```

The Vue tests compile and mount the actual SFC using Vue's renderer; HTTP, Inertia navigation and host elements are test doubles. They are not authenticated browser proof of HTTP delivery uncertainty or a concurrent browser conflict. Tests cover stale choices, grant-bound single/multiple reference choices, revision refresh, independent denomination, exact scope tuple, uncertainty and receipt reconciliation, post-completion command identity, explicit disable, read-only/missing/unknown authority evidence, derived readonly fields, English primary copy, existing identity prerequisites and explicit review after CAS conflict.

Earlier failures remain recorded in ignored testing logs: duplicate `business_context_write` on premature migrate followed by TestCase's migrate:fresh; two outdated `Workspaces/Index` expectations and a missing business prerequisite in reassessment fixtures; failed disposable initdb when disk was full. `-TestOwnedMigration` delegates the initial migration to the existing TestCase; original runner default is retained. It was validated for these single-process filtered runs, not `-FullRegression` multi-process execution. No product migration was changed to accommodate the harness.

Commercial fixtures now include a seeded Business Type. Reassessment fixtures reach their original authority assertions under the actual Business prerequisite and `Business/Index`. `EnsureBusinessContext` was not bypassed. The expiry clock starts after the independent holder reports LOCK_READY; exact consumer PID wait observation and persisted DB-clock expiry are retained. No sleeps/mocks replace contention proof.

Logs under `storage/framework/testing`: `phase-a-commercial-gate-2.log`, `phase-a-final-runtime-4.log`, `phase-a-browser-final.log`, `phase-a-ui-tests.log`, `phase-a-build.log`. The red tests were observed before the corresponding state/lifecycle corrections.

## Operator presentation and authenticated runtime evidence

The accepted Admin shell contains one main landmark. The workflow is markets → selected market/current assignability → approved references → immutable configuration history → action/prerequisites → operation effect → receipt → recorded context → audit delivery → technical disclosure. Primary copy is Arabic/English, including the explicit separation from advertiser target markets. No product-upload flow or Target Market UI was added.

Authenticated disposable application checks used real SessionAuthority-issued sessions and actual application routes/SQL/RLS. The synthetic eligibility credential is a fixture, not a real WebAuthn ceremony proof.

- Registration was server-confirmed; receipt and context were read independently. First runtime command: `14d43eba-6b70-4793-82ed-1937a4dcb63a`.
- Final runtime recorded `synthetic:phasea:review`, version 2, with the exact grant identity. Command: `5eadf054-1c27-4801-9169-6bc7386f4fda`. The recorded reference appeared beside version 1; no unused reference identity remained, producing a read-only action presentation.
- Created configuration version 2 using that reference and SAR. Selected it for assignability; current configuration became version 2 while version 1 remained readable history.
- Authorized recovery returned `acknowledged`, attempts 1. The UI reports semantic acknowledgment, not merely HTTP success.
- Switching to Jordan showed no assignable registration configuration; no Saudi configuration carried across. Switching actions updated visible primary fields.
- Expired fresh authentication disabled mutation presentation; backend checks remain final.
- Arabic desktop: actual CSS viewport 1440×1000. Arabic and English mobile: actual CSS viewport 390×844. English desktop rendered the translated workflow. The in-app browser capability initially applied physical scaling; sizes were calibrated and measured from the rendered DOM rather than assumed from the requested dimensions.
- No horizontal overflow at measured desktop/mobile sizes. One main landmark, valid label targets and aria-describedby references, visible keyboard focus styles, result focus after success, collapsed technical disclosures. Final browser console error/warning list was empty. This is focused accessibility verification, not a complete assistive-technology audit.

Screenshot files, all under the ignored test artifact directory, are not committed:

```text
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\arabic-desktop.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\arabic-mobile.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\english-desktop.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\english-mobile.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\arabic-desktop-result.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\catalog-readonly-result.jpg
C:\dev\builderad-worktrees\admin-experience-foundation-001\storage\framework\testing\phase-a-visual\assignability-audit-result.jpg
```

Desktop/mobile screenshots were captured during the authenticated runtime checks. The final subsequent alert-only addition exposes original/current revision and original request on conflict; its validation is the actual mounted Vue test, typecheck and build, not a live conflict screenshot.

## Exact changed files

```text
C:\dev\builderad-worktrees\admin-experience-foundation-001\app\Http\Controllers\CommercialController.php
C:\dev\builderad-worktrees\admin-experience-foundation-001\resources\js\Pages\Internal\Commercial.vue
C:\dev\builderad-worktrees\admin-experience-foundation-001\package.json
C:\dev\builderad-worktrees\admin-experience-foundation-001\tests\Feature\Commercial\CommercialScopeTest.php
C:\dev\builderad-worktrees\admin-experience-foundation-001\tests\Feature\Security\OwnerGrantReassessmentTest.php
C:\dev\builderad-worktrees\admin-experience-foundation-001\tests\SA01BrowserFixture.php
C:\dev\builderad-worktrees\admin-experience-foundation-001\tests\SA01Proof.ps1
C:\dev\builderad-worktrees\admin-experience-foundation-001\tests\commercial-operator.test.mjs
C:\dev\builderad-worktrees\admin-experience-foundation-001\COMMERCIAL_PHASE_A_REVIEW.md
```

## Deferred work and verification limits

Target Market Phase B, schema/migrations, authority/grant writers, RLS policies and new Commercial capabilities were deliberately excluded. This is not final acceptance, release approval, merge approval or proof of every repository test.

The full repository regression suite was not run. The inherited N1 `different_history` test passed in the ordinary Commercial suite, but fresh source/target installation durability proof was not rerun. Uncertain network delivery and CAS/reconciliation UI states were exercised with the actual mounted Vue component and controlled HTTP responses, not browser-level network interruption/concurrent-tab fault injection. Real passkey authentication, screen-reader testing and production deployment were not validated here.

Owner follow-up during the final visual pass identified an operator journey gap: recording a new market requires a pre-existing exact ConfigureCommercialMarket grant target; its preparation has backend APIs but no current Internal grant-preparation UI. Full self-service market creation from scratch is therefore not proven or complete. The visual micro pass did not add authority-management behavior or bypass the prerequisite. See `COMMERCIAL_VISUAL_MICRO_REVIEW.md`.

All task-started HTTP and disposable PostgreSQL processes were stopped by the runner and confirmed in the logs. No preview process remains. Generated build/screenshot/test-database artifacts are ignored and excluded from the intended change list. Owner review is the next step; no commit, push or merge was performed.
