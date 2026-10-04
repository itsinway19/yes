# Engineering Remediation 001 — Owner handoff

Date: 2026-10-04, Europe/Istanbul. Bounded remediation of the governing `ENGINEERING_QUALITY_GATE_001_REVIEW.md`. This is not a repeat architecture review or production acceptance.

## Baseline and findings before implementation

```text
BASELINE = f307f760520f294202b198042573214b4ad6bfe2
HEAD = f307f760520f294202b198042573214b4ad6bfe2
BRANCH = codex/engineering-quality-gate-001
WORKTREE = C:\dev\builderad-worktrees\engineering-quality-gate-001
WORKTREE_CLEAN_AT_START = YES
WORKTREE_CLEAN_AT_HANDOFF = NO — the reviewed uncommitted remediation patch is retained
BASELINE_CONFIRMED = YES
F01_CONFIRMED = YES
F02_CONFIRMED = YES
F03_CONFIRMED = YES
F04_CONFIRMED = YES
F05_CURRENT_STATE = ENVIRONMENT_DEPENDENT
```

F01/F02 were reproduced together before material repairs: the new expired-recovery regression received HTTP 200 instead of 403; the repeated install encountered `builderad_business_context_write`, SQLSTATE 42723. F04 reproduced the exact unresolved `@/Components/ProviderBrand.vue` import before any assertions. F03's six PHP interpreters and five frontend predicates were confirmed in the frozen source. The focused configuration real-wait test passed at baseline; the governing full-run evidence remains 651/653 with two pre-consumer readiness failures.

## Repairs and boundaries

F01 replaces only `builderad_record_commercial_audit_delivery` through forward migration 000320. It consumes the same exact recovery authority before locking, after the delivery row lock, and after UPDATE. The latter check rolls back any protected local effect after a later wait. Acknowledged replay also rechecks current authority. Existing error translation, custody, grant types, Founder policy and function privileges are preserved. The real-wait proof observes the exact consumer PID waiting on the holder transaction and the exact persisted grant expiring by the database clock. Denial leaves the entire delivery row unchanged, while independently committed custody survives; later current authority succeeds, acknowledged replay does not increment attempts, and revocation denies replay.

F02 establishes `Tests\Support\DisposableDatabaseReset` as the one test reset contract. Before destruction it invokes the existing real identity/server/token/role guard, disconnects runtime/authority clients, uses the existing disposable table wipe, then drops an explicitly enumerated set of public `builderad_` functions owned by the validated migration role with `RESTRICT`. No function CASCADE, production reset command, runtime registration or historical migration rewrite was introduced. A non-Builderad scalar function survives repeated resets. The same populated database is reset twice and then reused by a separate PHPUnit process.

F04 compiles the actual ProviderBrand SFC in the existing Vue custom-renderer harness. Product handlers/components remain real. `npm test` includes all seven existing JS suites, and the existing Foundation CI job invokes that command.

F05 was investigated at parent/worker creation, PDO connection, lock readiness, actual PID/transaction wait observation, DB-clock expiry, release and cleanup. Initial evidence was insufficient, so no speculative correction was made. The first complete run then failed one Source recovery revocation test: HTTP 200 after 27.02 seconds, 656/657 passed. Its holder's 25-second observation deadline had ended before the consuming phase; this exposed the same readiness boundary as the historical Commercial failures.

The cause was then proven: Symfony Process `waitUntil` calls `updateStatus(false)` before its local chunk callback, and on Windows its loop's `isRunning` also drains stdout through `updateStatus(false)`. LOCK_READY can therefore already be in the accumulated Process output, with no fresh chunk left for the callback. The deterministic stdin-synchronized probe observes a live worker and buffered LOCK_READY, yet `waitUntil` returns false after release while the signal remains in the buffer. No arbitrary delay is required to reproduce the loss. The old callers ignored `waitUntil`'s false result; Commercial subsequently failed its active-process assertion, while Source could issue a request after the holder had rolled back without revoking the grant. This is a test protocol defect, not a demonstrated recovery-authority bypass.

The bounded repair is `Tests\Support\RowLockReadiness`: read accumulated output, require LOCK_READY and a still-active holder, and honor the caller's existing Process timeout. It replaces the one Commercial and two Source lock-readiness calls. Worker waiter deadlines, real PostgreSQL PID/transaction observation, DB-clock expiry, grant revocation, custody assertions and cleanup remain unchanged. Unit tests deterministically cover already-drained readiness and rejection of an exited holder. Focused real-wait proofs pass 5/5 with 64 assertions. The complete regression is rerun only because this proven correction directly invalidates the first run's harness evidence, not as an unchanged retry-until-green.

F03 creates one small evidence classification contract and classifier consumed by EffectiveCapability and all five native status classes. The native classes still own local revocation, native credential expiry, renewal and immutable newer denial observations. Strict operator admission retains its additional exact timestamp-shape/artifact/configuration rules, but delegates the common time-validity decision to the same classifier. A backend report prerequisite projection replaces all five frontend material predicates. Missing projection fails closed in the browser. Execution continues to require the existing request/credential/SQL authority gates.

```text
COMMON_EVIDENCE_FACTS = evidence presence, required fields, timestamp parsing, future observation, expiry ordering, expiry, accepted state, observed time
PROVIDER_NATIVE_FACTS = local/native denial, token expiry, renewal/reconnect, permission/rate/continuation/admission/configuration/lifecycle contracts
FOUR_AXIS_FETCH_INPUTS = provider_support, provider_permission, credential, privacy_purpose
SEVEN_AXIS_READINESS_INPUTS = the four fetch axes plus freshness, completeness, product_exposure
CANONICAL_COMMON_DERIVER = App\Providers\Ads\ProviderEvidenceValidity
NATIVE_DERIVERS_AFTER_REFACTOR = GoogleAccessStatus, MetaAccessStatus, TikTokAccessStatus, SnapchatAccessStatus, XAccessStatus
FRONTEND_COMMON_INTERPRETATION = reporting.can_report / reporting.why_not, derived by ProviderReportPrerequisites
BACKEND_EXECUTION_AUTHORITY = existing ProviderRequestGuard, ProviderAccessStore and SQL exact-scope consumers
COMMON_CLASSIFIER_DOES_NOT_GRANT_AUTHORITY = YES
FRONTEND_REPORTABLE_DOES_NOT_GRANT_AUTHORITY = YES
NATIVE_PROVIDER_DENIAL_STILL_WINS = YES
FOUR_AXIS_AND_SEVEN_AXIS_NOT_CONFLATED = YES
PROVIDER_ENUM_REMAINS_CANONICAL_IDENTITY = YES
NO_SECOND_PROVIDER_REGISTRY_CREATED = YES
NO_PROVIDER_DOMAIN_REDESIGN = YES
```

The equivalence matrix exercises all five actual native status consumers against missing, malformed, malformed timestamp/state types, invalid dates, future observations, invalid expiry order, expired, fresh VERIFIED, FAILED, REVOKED and nonaccepted evidence. Existing `STALE` evidence-state spelling remains nonaccepted (`UNKNOWN`); time-expired evidence yields `STALE`, preserving the prior contract. Separate proofs retain Google native denial, Snapchat renewal denial and all-five admission rules. All-five grant revocation proves `can_report=true` cannot grant execution. Local native revocation overrides fresh common evidence. The unit proof distinguishes four-axis fetch eligibility from seven-axis product readiness and verifies missing execution authority wins over valid evidence.

## Exact changed paths

All paths below are relative to the absolute WORKTREE above; this list includes this handoff. No other tracked product surface was modified.

```text
.github/workflows/foundation.yml
app/Providers/Ads/Google/GoogleAccessStatus.php
app/Providers/Ads/Meta/MetaAccessStatus.php
app/Providers/Ads/ProviderEvidenceClassification.php
app/Providers/Ads/ProviderEvidenceValidity.php
app/Providers/Ads/ProviderReportingEvidence.php
app/Providers/Ads/ProviderReportPrerequisites.php
app/Providers/Ads/Snapchat/SnapchatAccessStatus.php
app/Providers/Ads/TikTok/TikTokAccessStatus.php
app/Providers/Ads/X/XAccessStatus.php
app/Sources/EffectiveCapability.php
database/migrations/0001_01_01_000320_commercial_delivery_effect_time.php
database/sql/commercial_delivery_effect_time.sql
docs/ENGINEERING_REMEDIATION_001_HANDOFF.md
package.json
resources/js/Pages/Workspaces/GoogleConnectionPanel.vue
resources/js/Pages/Workspaces/MetaConnectionPanel.vue
resources/js/Pages/Workspaces/SnapchatConnectionPanel.vue
resources/js/Pages/Workspaces/TikTokConnectionPanel.vue
resources/js/Pages/Workspaces/XConnectionPanel.vue
tests/Feature/Commercial/CommercialScopeTest.php
tests/Feature/DisposableResetTest.php
tests/Feature/Providers/ProviderAccessStoreTest.php
tests/Feature/Sources/SourceRegistryTest.php
tests/SA01Proof.ps1
tests/Support/DisposableDatabaseReset.php
tests/Support/MigrationDurabilityProof.php
tests/Support/RowLockReadiness.php
tests/TestCase.php
tests/Unit/ProviderEvidenceValidityTest.php
tests/Unit/RowLockReadinessTest.php
tests/source-registry-interaction.test.mjs
```

```text
NEW_MIGRATIONS = C:\dev\builderad-worktrees\engineering-quality-gate-001\database\migrations\0001_01_01_000320_commercial_delivery_effect_time.php
```

## Executable evidence

Commands run from WORKTREE. PostgreSQL commands use only task-owned PostgreSQL 18 at 127.0.0.1:5433. Real 5432 services and primary builderad were never connected to or modified. The runner's similarly named bootstrap databases on 5433 are synthetic fixtures, not primary databases. Task-owned clusters are stopped in the runner's finally block; logs/data are retained as evidence. No server/watcher was left for use.

| Exact command | Result | Evidence under storage/framework/testing |
| --- | --- | --- |
| `git rev-parse HEAD`; `git status --short` | Baseline exact, initial clean | Frozen baseline above |
| `node --test tests/source-registry-interaction.test.mjs` before repair | FAIL before assertions; exact alias error | Tool output |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_r2_configuration_expiry_after_wait_rolls_back_fact_and_obligation\|test_r2_grant_expiry_after_actual_wait_denies_all_local_effects'` | 1 pass, 13 assertions; baseline focused only; second filter name matched no test | remediation-baseline-waits.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_recovery_expiry_after_delivery_wait_rolls_back_local_state_but_retains_custody\|test_populated_disposable_install_can_be_reset_repeatedly'` before repair | 2 expected failures; HTTP 200 and SQLSTATE 42723 | remediation-baseline-defects.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_recovery_expiry_after_delivery_wait_rolls_back_local_state_but_retains_custody\|test_custody_failure_lost_acknowledgment_idempotent_recovery_and_fingerprint_conflict'` | 2 pass, 40 assertions | remediation-f01.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -ResetReuseProof` | 1 pass/10 assertions, then independent process 16 pass/180 assertions; catalog proofs pass | remediation-f02.log |
| `node --test tests/source-registry-interaction.test.mjs` after repair | 18 pass; assertions execute | remediation-f04-source.log |
| `node --test tests/provider-report-interaction.test.mjs tests/source-capability-ui.test.mjs tests/localization.test.mjs tests/commercial-operator.test.mjs` | 69 pass | remediation-f04-related.log |
| `pwsh -NoProfile -File scripts/builderad.ps1 build` after F04 | vue-tsc and Vite pass | remediation-f04-build.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_common_evidence_equivalence_and_report_projection_across_all_five_native_statuses'` before native refactor | Expected failure at absent reporting projection | remediation-f03-native-red.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'ProviderEvidenceValidityTest\|EffectiveCapabilityTest\|test_common_evidence_equivalence_and_report_projection_across_all_five_native_statuses\|test_google_status_surfaces_native_denial\|test_snapchat_renew_denial\|test_reporting_evidence_admission_fences_all_five'` | 6 pass, 763 assertions | remediation-f03.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -MigrationDurabilityProof` | Empty HEAD, populated 000010→HEAD, 4 users/2 workspaces/5 memberships/1 reset token preserved, seven catalog groups equal, repeated catalog equal; 33 migrations | remediation-migrations.log; remediation-fresh-catalog.json; remediation-forward-catalog.json |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -CompleteRegression` before F05 correction | FAIL: 656 pass, 1 fail, 7271 assertions; 811.70s; Source revocation holder readiness | remediation-final-php.log; remediation-full-first.xml |
| `php storage/framework/testing/remediation-readiness-probe.php` | PASS: buffered READY/live worker, callback false, buffered READY retained, worker exit 0; deterministic lost-signal reproduction | remediation-readiness-cause.log |
| `php vendor/bin/phpunit tests/Unit/RowLockReadinessTest.php` | Before helper: missing-class failures; after helper: 2 pass, 4 assertions | remediation-readiness-red.log; remediation-readiness-green.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_r2_grant_expiry_during_real_assignability_contention_denies_material_effect\|test_r2_configuration_expiry_after_wait_rolls_back_fact_and_obligation\|test_recovery_expiry_after_delivery_wait_rolls_back_local_state_but_retains_custody\|test_recovery_revocation_while_waiting_on_actual_grant_denies_before_custody\|test_recovery_expiry_after_delivery_wait_keeps_independent_custody_and_local_state_separate'` | 5 pass, 64 assertions after proved readiness correction | remediation-f05-focused.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -CompleteRegression` after F05 correction | PASS: 659 tests, 7279 assertions, 0 failures/errors/skips; 899.98s; both final catalog proofs pass; runner exit 0 | remediation-final-php-after-readiness.log; remediation-full.xml |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter 'test_r2_grant_expiry_during_real_assignability_contention_denies_material_effect\|test_r2_configuration_expiry_after_wait_rolls_back_fact_and_obligation\|test_recovery_revocation_while_waiting_on_actual_grant_denies_before_custody'` after complete run | PASS: 3 tests, 31 assertions, 49.76s; runner exit 0 | remediation-final-focused-waits.log |
| `pwsh -NoProfile -File tests/SA01Proof.ps1 -TestOwnedMigration -Filter test_n1_different_history_durability -N1DurabilityPhase source -N1PreservationFile 'C:\dev\builderad-worktrees\engineering-quality-gate-001\storage\framework\testing\remediation-final-n1-preserved.json'`, then the same command with `-N1DurabilityPhase target` | PASS: source 1 test/6 assertions, target 1 test/13 assertions; distinct cluster identities 7692713608385290232 / 7692713718747095132; source XID 2090, target XID 851 | remediation-final-n1-source.log; remediation-final-n1-target.log |
| `pwsh -NoProfile -File scripts/builderad.ps1 npm test` | 94 pass; 0 fail/skip | remediation-final-js.log |
| `pwsh -NoProfile -File scripts/builderad.ps1 build` | vue-tsc + Vite PASS; 771 modules | remediation-final-build.log |
| `php vendor/bin/pint --test` | PASS, exit 0 | remediation-final-pint.log |
| `php C:\composer\composer.phar validate --strict` | PASS, exit 0 | remediation-final-composer-validate.log |
| `php -l <each changed/new PHP path>` | All pass, exit 0 | remediation-final-syntax.log |
| `pwsh -NoProfile -File scripts/builderad.ps1 composer audit --format=json` | PASS, exit 0; no advisories/abandoned packages | remediation-final-composer-audit.json |
| `pwsh -NoProfile -File scripts/builderad.ps1 npm audit --json` | PASS, exit 0; 0 vulnerabilities at every severity | remediation-final-npm-audit.json |
| `git diff --check`; exact changed/new file inspection | PASS; bounded changes only | Final tool output |

The initial sandboxed initdb could not progress through resource probing; its exact task-owned initialization processes were identified/stopped and database proofs ran with approved host execution. This was an execution-environment restriction, not a database test result. A sandboxed standalone PHP unit command could not open vendor/autoload.php and is not used as proof. The first F03 focused run exposed a synthetic fixture CHECK violation; the fixture was corrected to call the existing real revocation writer before the successful rerun. No product constraints were relaxed. Both complete regression results are retained, with the proved harness change explaining the necessary second run.

Independent read-only bounded review of the exact patch and direct callers found no actionable proven issue. No broad audit or reviewer write/database operation occurred.

## No-new-debt acceptance

```text
NEW_CANONICAL_FACT_OWNER = NO
NEW_AUTHORITY_MODEL = NO
NEW_PARALLEL_RESOLVER = NO
NEW_CROSS_DOMAIN_WRITE = NO
NEW_PROVIDER_REGISTRY = NO
NEW_GENERIC_FRAMEWORK = NO
DOMAIN_SEMANTICS_CHANGED = NO
AUTHORITY_SEMANTICS_CHANGED = exact F01 effect-time enforcement correction only
COMMERCIAL_PRODUCT_MEANING_CHANGED = NO
BUSINESS_PRODUCT_MEANING_CHANGED = NO
TARGET_MARKET_IMPLEMENTED = NO
```

## Completion matrix and quality gate

```text
F01_EFFECT_TIME_AUTHORITY = FIXED_AND_PROVEN
F02_DISPOSABLE_RESET = FIXED_AND_PROVEN
F03_COMMON_PROVIDER_DERIVATION = FIXED_AND_PROVEN
F03_FRONTEND_DUPLICATION = REMOVED
F04_SOURCE_REGISTRY_TEST = EXECUTES_AND_PASSES
F05_FULL_REGRESSION = GREEN_AND_PROVEN

SOURCE_REGISTRY_TEST_FILE_LOADS = YES
SOURCE_REGISTRY_ASSERTIONS_EXECUTE = YES
SOURCE_REGISTRY_ASSERTIONS_PASS = YES
COMMON_PROVIDER_EVIDENCE_DERIVATION = ONE CANONICAL IMPLEMENTATION
ALL_FIVE_PROVIDERS_CONSUME_IT = YES
PROVIDER_NATIVE_DIFFERENCES_REMAIN_SEPARATE = YES
EQUIVALENCE_TESTS_PASS = YES
NO_PARALLEL_COMMON_APPLICATION_INTERPRETERS_REMAIN = YES

ENGINEERING_REMEDIATION_001 = PASS
CODE_CORRECTNESS_DEBT = NONE_PROVEN
ARCHITECTURE_DEBT = NONE_PROVEN
AUTHORITY_MODEL = MOSTLY_CENTRALIZED
CANONICAL_FACT_OWNERSHIP = VERIFIED
CANONICAL_DERIVATION_OWNERSHIP = VERIFIED
CONCURRENCY_MODEL = STRONG
DATABASE_COUPLING = CONTROLLED
TEST_ARCHITECTURE = STRONG
MIGRATION_DURABILITY = VERIFIED
PROVIDER_ABSTRACTION = COHERENT
HIGH_RISK_DUPLICATED_TRUTH = NO
MATERIAL_DUPLICATED_DERIVATION = NO
HIDDEN_PARALLEL_AUTHORITY_MODEL = NO
FULL_PHP_REGRESSION_GREEN = YES
FULL_RELEVANT_JS_REGRESSION_GREEN = YES
REPEATED_DISPOSABLE_RESET_GREEN = YES
N1_DURABILITY = PASS
FRESH_INSTALL = PASS
POPULATED_FORWARD_MIGRATION = PASS
BUILD = PASS
TYPECHECK = PASS
DEPENDENCY_AUDITS = PASS
PRIMARY_BUILDERAD_MODIFIED = NO

FOUNDATION_CODE_READY = YES
FOUNDATION_ARCHITECTURE_READY = YES
FOUNDATION_VERIFICATION_READY = YES
REMAINING_DEBT_IS_RELEASE_ENVIRONMENT_OR_FUTURE_SCALE_ONLY = YES
ARCHITECTURE_REMEDIATION_REQUIRED_BEFORE_DECLARED_TARGET_MARKETS = NO
SAFE_TO_BEGIN_DECLARED_TARGET_MARKETS = YES
PRODUCTION_RELEASE_READY = NO

EMPTY_TO_HEAD = PASS
POPULATED_000010_TO_HEAD = PASS
N1_INDEPENDENT_SOURCE_TARGET = PASS
REPEATED_DISPOSABLE_RESET = PASS
FINAL_CATALOG_CONSISTENCY = PASS
PRIMARY_BUILDERAD_CONNECTIONS = 0
PRIMARY_BUILDERAD_WRITES = 0
TASK_OWNED_POSTGRESQL_PROCESSES = STOPPED
COMMIT = NOT_PERFORMED
PUSH = NOT_PERFORMED
MERGE = NOT_PERFORMED
```

These quality assessments apply to the implemented foundation and bounded remediation, with unchanged ownership/authority findings carried from the governing review. They do not attest physical WebAuthn, live provider contracts, production migration operations or primary environment reconciliation. Authority remains the existing model with its accepted bounded Founder policy; it was not replaced or newly generalized.

## Owner answers

1. F01's proven expired-grant local effect, F02's nondeterministic disposable function lifecycle and F04's unreachable interaction assertions were repaired and proved. F05's lost readiness signal was deterministically reproduced and corrected in the bounded row-holder harness.
2. F03's repeated common evidence interpretation and five material frontend fetch predicates were removed. Provider-native behavior and aggregate meanings remain separate.
3. Common application evidence interpretation now has one canonical classifier; all five native statuses, EffectiveCapability and common admission time validity consume it. The database retains its accepted independent integrity/execution fences.
4. No authority architecture or Commercial/Business product boundary changed. F01 restores the accepted effect-time authority requirement. Reportability remains presentation and never permission.
5. Yes. The final single unfiltered PHP invocation passes 659/659, 7279 assertions, no failures/errors/skips; the three affected real-wait tests also pass independently afterward. All seven JS suites pass 94/94. The failed pre-correction complete run is retained and is not described as green.
6. Empty/forward/repeated/populated reset, seven-group catalog equality and N1 independent-installation durability all pass through the protected disposable contract. This does not claim production rehearsal or make raw Laravel table-only migrate:fresh the Builderad reset contract.
7. F06 physical WebAuthn, F07 external provider activation, F08 production migration rehearsal, F09 primary environment reconciliation and F10 future-scale performance remain untouched. They are the remaining release/environment/future-scale debt; F01–F05 are closed.
8. Yes. The foundation is stronger in correctness, reproducible verification and canonical semantic ownership. It is safe to begin Declared Target Markets after Owner review; no Target Market or Goal capability was implemented. Production release remains unaccepted.

**STOP FOR OWNER REVIEW. No commit, push or merge.**
