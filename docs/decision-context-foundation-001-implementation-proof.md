# Decision Context Foundation 001 — implementation and lock-repair evidence

Date: 2026-10-04. Current status: IMPLEMENTED_AND_PROVEN — all13 approved tasks and bounded lock-order repair complete; fresh independent final review PASS. Ready for Owner patch review. Original STOP and failed candidates below are historical evidence, not current blockers.

## Execution authorization and current state

Execution is NATIVE under the approved spec/13-task plan and the Owner's additional hardening. HEAD at start and at STOP equals `8ebd9a1f1ffd7b23307d277304203c706e67d95d` exactly. Branch is `codex/decision-context-discovery-001`; worktree is `C:\dev\builderad-worktrees\decision-context-discovery-001`.

Tasks 1–13 are complete. All required technical gates passed on their actual final artifacts, and fresh independent final review of the complete uncommitted patch returned Ready for Owner patch review YES with no remaining Critical/Important/Minor findings. The original prerequisite lock characterization was pulled forward before product state, then the approved bounded repair passed its affected gate. Historical checkpoint states below describe their execution time; the final handoff at the end governs the current status. No commit, push, merge or primary connection occurred.

## Exact performed commands and results

Commands were issued through `pwsh -NoProfile -File` with repository-native wrappers:

| Command | Actual result |
| --- | --- |
| `scripts/builderad.ps1 preflight` | Exit 0: PHP 8.5.10, Node 24.21.0, PostgreSQL client 18.6, Composer 2.10.3, npm 11.19.0. |
| `scripts/builderad.ps1 composer install --no-interaction --prefer-dist --no-progress --no-scripts` | Exit 0, locked install, no upgrade/scripts or DB connection. |
| `scripts/builderad.ps1 npm ci` | Exit 0; installation reported 0 vulnerabilities; vue-demi install-script policy warning retained. This is not the final dependency-audit proof. |
| `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Focused -Filter BusinessFoundationTest` | Exit 0, 14 warning-marked tests/169 assertions; missing local `.env` warning. Not credited as clean PASS. Task cluster stopped and data cleaned. |
| `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Focused -Filter 'BusinessFoundationTest\|DeclaredTargetMarketsConcurrencyTest'` | Actual argument uses the literal pipe without the Markdown escape: 14 passed, 1 failed, 175 assertions, errors 0, skips 0, exit 1; warning-free final focused run. Existing Foundation tests pass; real lock characterization fails on 40P01. |
| `scripts/builderad.ps1 php -l tests/TargetMarketsCommandWorker.php` | Exit 0, no syntax errors. |
| `scripts/builderad.ps1 php -l tests/Feature/DeclaredTargetMarketsConcurrencyTest.php` | Exit 0, no syntax errors. |
| Native PowerShell AST parse of `scripts/Test-DeclaredTargetMarkets.ps1` | PASS, no parse errors. |
| `git diff --check` | Exit 0; new-file whitespace is checked separately at handoff. |

Local ignored `.env` is pinned to `testing`, loopback port 5433, `builderad_test_disposable`, existing test roles and a synthetic key. It contains no live primary configuration or persisted isolation token. The task wrapper supplies a fresh isolation token before guarded startup. No primary Builderad server/database was contacted; bootstrap's database named `builderad` exists only inside the new disposable cluster, not the primary instance.

Both lockfiles are unchanged:

- Composer SHA-256: `6553160844FE4E8EB81F5E610F1E0FBC1F85B0DA528759DF8E77F29AD5E2B268`.
- npm SHA-256: `6C3752AA6F26C8F9AF669AABEBA0B95E2FFBF05DDC4E275665E7B5B5EE9C66BF`.

## Reproduction and observed lock graph

The test uses the actual unmodified baseline `builderad_select_workspace_context` and `builderad_business_context_write`; real independent app-role connections; valid existing signed actor/session/Workspace grants; and PostgreSQL lock-state inspection. It does not mock contention, alter functions or weaken the accepted outcome.

1. Create one valid synthetic Workspace and current advertiser/session/grants through the existing Foundation flow. Desired name/type and expected revision are identical in both writers.
2. An independent guarded owner-role transaction locks that fixture's session row, pausing the real existing actor path after both workers have selected the signed Workspace.
3. Confirm both workers emitted their selected transaction IDs and are actually waiting on PostgreSQL locks behind the blocker. Both already hold the canonical Workspace sharing locks. Polling only observes database state; elapsed sleep is not the proof.
4. Release the blocker and let both actual writers attempt the existing Workspace `FOR UPDATE` serialization lock.
5. Compare exact worker outcome, final Workspace revision and event history. One actual write commits revision 1 with one existing name/type event; the other is aborted as deadlock. The test deliberately requires zero deadlocks and both equivalent successful results, so it remains red.

| Participant | Transaction | Observed wait/outcome |
| --- | --- | --- |
| Session-row blocker PID 23072 | 1317 | Both workers were blocked behind it before release; commits to release the real authority-path wait. |
| Worker PID 6488 | 1320 | After release waits on transaction 1318; PostgreSQL aborts it with `40P01`. |
| Worker PID 23028 | 1318 | After release waits on transaction 1320; survives deadlock resolution and returns revision 1. |

Cycle: `PID 6488 / xact 1320 -> xact 1318 / PID 23028 -> xact 1320 / PID 6488`.

PostgreSQL reports: `while locking tuple (0,1) in relation "workspaces"`, at `SELECT * FROM workspaces WHERE id=p_workspace FOR UPDATE`, inside `builderad_business_context_write(bigint,text,bigint,text,bigint)`.

Relevant unchanged boundaries:

- `database/migrations/0001_01_01_000100_create_authority_context.php:152`: canonical selector takes `FOR SHARE OF workspace, membership, grant_record`.
- `database/sql/source_registry.sql:106`: actor proof also takes Workspace `FOR SHARE`.
- `database/sql/business_context.sql:111`: canonical writer upgrades to Workspace `FOR UPDATE`.

Thus two signed transactions can each hold the shared Workspace row before either can obtain the exclusive serialization lock. Merely adding the approved new writer after unchanged WorkspaceContext selection cannot remove that preexisting simultaneous-entry conversion cycle. Automatic retries or accepting 40P01 would change/obscure the approved proof rather than satisfy it.

## Preserved raw evidence

All paths are inside this worktree:

- [PID, transaction, lock and final-state evidence](../storage/framework/testing/dtm-lock-a59a5741-5cef-4086-9f0b-224cdc5ddcf6.json).
- [JUnit results](../storage/framework/testing/dtm-db-66854db9-47dd-4afa-a386-4fe6e9f59064/results.xml).
- [PostgreSQL deadlock log](../storage/framework/testing/dtm-db-66854db9-47dd-4afa-a386-4fe6e9f59064/postgres.log).
- [Focused combined run log](../.superpowers/sdd/2026-10-04-declared-target-markets/task1-lock-proof.log).
- [Execution ledger](../.superpowers/sdd/2026-10-04-declared-target-markets/progress.md).

Raw records contain synthetic test-session identifiers only; fixture files passed to workers were removed after the test. Cluster data directories were cleaned; logs/evidence retained.

## STOP condition and smallest Owner decision

The Owner execution authorization section 6 requires STOP if canonical locking makes the approved contract impossible without changing Foundation behavior. The hardening additionally prohibits weakening simultaneous overlap, mocking the authority wait, treating deadlock as conflict, or silently modifying canonical locking. This exact condition is now evidenced before production DTM changes.

Required decision: authorize a bounded remediation to canonical mutation serialization/lock ordering so competing name/type and DTM mutations serialize before incompatible Workspace sharing-lock upgrades, while preserving existing signed human/session/membership/owner proofs, exact management grants, no-op/version/audit semantics and the unchanged-strength real race tests. This does not authorize redesign of product semantics, geography, Commercial or provider architecture. No Foundation remediation has been performed under the current authorization.

## Actual diff scope and limitations

`DIFF_REVIEW_SCOPE = BOUNDED`. Expected changed surfaces are the plan checkpoints, planned task harness, planned concurrency suite/worker and this proof document. No unexpected tracked-surface file was created. Initial expected preflight/spec/plan artifacts remain; the spec/preflight content was not modified. No production PHP, SQL, migration, route, Vue, Business Type, Source Guidance or Commercial file changed. Ignored dependencies, safe local environment, ledger and run evidence are task setup/evidence only.

New/revised tracked surfaces during execution:

1. `docs/superpowers/plans/2026-10-04-declared-target-markets.md` — only Task 1 checkpoint ticks.
2. `scripts/Test-DeclaredTargetMarkets.ps1` — guarded task-owned setup, phase dispatch and cleanup; Durability/UI proof entrypoints await their later tasks and are not claimed implemented.
3. `tests/TargetMarketsCommandWorker.php` — actual baseline writer participant for lock reproduction; DTM mode is not implemented.
4. `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php` — intentionally failing real prerequisite lock characterization.
5. `docs/decision-context-foundation-001-implementation-proof.md` — this interim STOP report.

All A–Q feature cases remain UNVERIFIED as DTM has not been implemented. The prerequisite concurrency outcome is FAIL, not an A–Q PASS. Country source/provenance, label preservation, fresh/exact-forward/reset/N1 DTM proof, rendered UI and full PHP/JS/build/typecheck/style/audit regression remain UNVERIFIED/NOT_RUN. The Foundation portion of the focused run is not a full regression claim.

No independent final whole-change review was dispatched: the required gate comes after completed implementation/proof, which has not been reached. `REVIEWER_INDEPENDENCE = NOT_VERIFIED`; `MATERIAL_FINDINGS_RE_REVIEWED_AFTER_FIX = NOT_APPLICABLE` (no reviewer finding/fix cycle). No self-review is substituted for that final gate.

```text
IMPLEMENTATION_TASKS_COMPLETED = 1/13
DECLARED_TARGET_MARKETS = BLOCKED
CONCURRENCY_PROOF = FAIL (actual prerequisite canonical lock cycle)
DIFF_REVIEW_SCOPE = BOUNDED
UNEXPECTED_CHANGED_FILES = NONE
PRIMARY_BUILDERAD_CONNECTIONS = 0
PRIMARY_BUILDERAD_WRITES = 0
TASK_PROCESSES_LEFT_RUNNING = 0
GOAL_IMPLEMENTED = NO
AUDIENCE_IMPLEMENTED = NO
MARKET_OPPORTUNITY_IMPLEMENTED = NO
COMMIT = NO
PUSH = NO
MERGE = NO
SAFE_FOR_OWNER_PATCH_REVIEW = YES (diagnostic patch only)
```

Historical stop: Owner subsequently APPROVED bounded lock remediation. The original failure remains FAIL evidence; subsequent repair results are recorded separately below.

## Authorized repair execution — BEFORE map

Owner authorization is already granted in the approved bounded-lock-order prompt. Execution resumed; the earlier STOP marker above describes the original diagnostic only.

Classification: BOUNDED_FOUNDATION_LOCK_ORDER_REPAIR.

Observed baseline: principal activation -> canonical selector locks actor lifecycle SHARE -> Workspace/membership/view-grant SHARE -> responsible-owner/owner membership/lifecycle/identity SHARE -> source_actor locks actor lifecycle, identity, Workspace, memberships, ownership, session and view grant SHARE -> name/type writer requests Workspace UPDATE -> type SHARE -> exact manage grant SHARE -> actor/grant effect-time revalidation -> atomic state/event -> final revalidation. The demonstrated PostgreSQL cycle is the two Workspace SHARE holders mutually waiting for UPDATE (PIDs/xacts retained above).

DTM is not yet installed; its planned writer shares the same Workspace revision and must use the same mutation entry/serialization point.

Relevant overlapping paths inspected: SessionAuthority.revokeSelected locks session UPDATE; revokeAll updates matching sessions. LifecycleAuthority.changeProfile locks lifecycle UPDATE, session SHARE, identity UPDATE then lifecycle invalidation. Business-context grant request validates existing source-owner evidence then serializes grant issuance. Customer owner reassessment locks lifecycle/identity SHARE, session SHARE, Workspace SHARE, ownership/owner membership SHARE, and historical grants SHARE. Existing selector and source actor definitions are preserved. A revocation of an already SHARE-locked session/grant linearizes after the writer; one committed before its acquisition must be observed by post-wait checks using clock_timestamp().

Candidate local sequence: signed principal/runtime plus non-mutating membership scope proof -> one transaction-scoped Business Context serialization lock -> unchanged canonical selector -> unchanged full source-actor/session proof -> Workspace UPDATE -> existing type/exact-grant/effect checks. Both name/type and future DTM share this sequence. A signed transaction-bound preparation marker prevents a caller from entering the writer via the old unsafe SHARE-first protocol; it conveys sequencing evidence only, never permission. No shared actor/selector redefinition or automatic retries.

The concurrent test retains simultaneous transaction entry and two actual PostgreSQL waiters, but selected-context completion naturally serializes after the new entry point. Requiring two completed selectors before that point would recreate the prohibited order. Backend wait graphs, final results, revisions and events remain asserted.

## Candidate repair and first post-repair gate

Implemented only in the new forward migration 000330 and forward payload business_context_lock_order.sql. The existing name/type writer body is byte-equivalent after normalizing CREATE OR REPLACE and removing its sequencing-preparation guard (verified); no historical migration/payload was edited.

AFTER: signed principal + non-mutating member scope -> shared transaction-level `builderad.business.context.<workspace>` serialization point -> unchanged signed canonical selector and responsible-owner checks -> unchanged source_actor/session checks -> signed transaction-bound preparation marker -> existing Workspace UPDATE/type/manage-grant locks -> existing no-op/stale/material-write/audit/final effect-time checks. One prepared writer per Workspace can enter that chain. DTM will reuse exactly this point.

The preparation marker is HMAC over the existing signed context (actor, Workspace, xact and PID bound). Runtime cannot forge/replay it; it proves the sequencing protocol, not authority. Full actor and exact grant checks still determine whether any effect is allowed. Unprepared unsafe direct entry is denied before trying to upgrade a shared Workspace lock. WorkspaceContext.runBusinessMutation is used only by the name/type mutation; its normal read/provider/source run path is unchanged. Atomic creation selects through the same prepared entry while retaining its existing transaction.

First affected gate: 58 tests, 360 assertions, failures/errors/skips 0; JUnit/logs in `storage/framework/testing/dtm-db-28aee44a-f198-4c40-a911-c3cd8fb193d0`. Cluster stopped/data cleaned. Both real equal participants returned revision1 with exactly one event. Different participants returned revision1 and 40001 with one event. Grant revocation, grant expiry, session expiry during confirmed waits returned 42501 for both participants with revision0 and zero events. No 40P01 was observed. Each race retained two backend PIDs, two entered transactions, two actual lock waiters and pg_locks/pg_blocking_pids evidence. Graph now has one worker waiting on the session holder and the other on the Business Context serialization lock; there is no mutual Workspace upgrade cycle.

First gate evidence JSONs: dtm-lock-47e1aad4-5097-4e2a-b7a2-009ec0d8a8f2 (equal), dtm-lock-47e9d38d-e56f-46e2-9bff-36100db85967 (different), dtm-lock-15f9a91e-92d7-4f19-9ac2-c7b4fd817874 (grant revocation), dtm-lock-0090f0d3-1fe8-4e37-acb5-a9d5aaa5297b (grant expiry), dtm-lock-72065edc-e7b6-4f97-bf13-f3ba59b89151 (session expiry), all under storage/framework/testing.

Complete affected matrix with added lifecycle/member/owner/session-revocation waits and session/lifecycle/database-boundary regression remains running. LOCK_ORDER_REPAIR is not yet declared PROVEN. DTM equal/different/mixed tests remain NOT_YET_APPLICABLE until its writer exists.

## Bounded repair gate handoff

Complete affected gate: `Test-DeclaredTargetMarkets.ps1 -Phase Focused -Filter 'BusinessFoundationTest|BusinessContextLockOrderTest|WorkspaceIsolationTest|DeclaredTargetMarketsConcurrencyTest|SessionAuthorityTest|LifecycleActivationTest|WorkspaceCreationTest|DatabaseAuthorityBoundaryTest'`: 78 passed,543 assertions,failures0,errors0,skips0,exit0. JUnit, PostgreSQL and command logs: storage/framework/testing/dtm-db-3cd3829b-3791-41c1-ab0a-226f79a01268; ledger command log repair-complete-gate.log. This includes exact original equal name/type submissions under simultaneous entry and actual lock overlap, different-name 40001, authorized stale identical no-op with byte-equivalent full row/event snapshots, unsigned/foreign-scope denial, forged preparation denial, grant/session expiry and revocation waits, lifecycle generation change, revoked membership and invalid responsible-owner waits. Raw race JSON paths/PIDs/graphs are named in the command log. All task workers exited; cluster stopped/data cleaned.

```text
LOCK_ORDER_BLOCKER = CONFIRMED
ORIGINAL_SQLSTATE = 40P01
ROOT_CAUSE = Two canonical Workspace SHARE holders attempted mutual UPDATE upgrades
REPAIR_SCOPE = Business Context local entry serialization and forward name/type writer sequencing guard
HISTORICAL_MIGRATIONS_EDITED = NO
SHARED_ACTOR_SEMANTICS_CHANGED = NO
BUSINESS_CONTEXT_AUTHORITY_SEMANTICS_CHANGED = NO
BUSINESS_CONTEXT_VERSION_SEMANTICS_CHANGED = NO
AUDIT_SEMANTICS_CHANGED = NO
NAME_TYPE_WRITER_EQUIVALENCE = PASS
NAME_TYPE_CONCURRENCY = PASS
DTM_EQUAL_CONCURRENCY = NOT_YET_APPLICABLE
DTM_DIFFERENT_CONCURRENCY = NOT_YET_APPLICABLE
MIXED_CONTEXT_CONCURRENCY = NOT_YET_APPLICABLE
EFFECT_TIME_AUTHORITY = PASS (existing-writer affected matrix)
DEADLOCK_40P01_PRESENT = NO (post-repair gate)
AFFECTED_FOCUSED_TESTS = 78; ASSERTIONS = 543; FAILURES = 0; ERRORS = 0; SKIPS = 0
PRIMARY_BUILDERAD_CONNECTIONS = 0
PRIMARY_BUILDERAD_WRITES = 0
COMMIT = NO
PUSH = NO
MERGE = NO
LOCK_ORDER_REPAIR = PROVEN
SAFE_TO_RESUME_APPROVED_PLAN = YES
```

Task 2 resumes automatically. This local repair proof is not DTM implementation/concurrency/durability/UI proof; those remain to be executed. Exact added/changed repair files: new database/migrations/0001_01_01_000330_create_declared_target_markets.php; new database/sql/business_context_lock_order.sql; app/Foundation/Tenancy/WorkspaceContext.php; app/Http/Controllers/BusinessContextController.php; tests/Feature/BusinessContextLockOrderTest.php; tests/Feature/DeclaredTargetMarketsConcurrencyTest.php; tests/TargetMarketsCommandWorker.php; this report and ignored ledger. DTM additions will extend the same 000330 forward migration rather than edit historical SQL.

## Task 2 — verified identity and label artifacts

Accepted identity source: Debian iso-codes ISO 3166-1 database redistributed unchanged by pycountry, pinned repository snapshot `4c6a69927a25326a69bf753671fb571bb437cd7d` (2026-02-18; retrieved 2026-10-04). Pinned source README/copyright establish the unchanged Debian upstream and LGPL data license. This is documented as a maintained standard-derived snapshot, not an ISO-published Arabic translation. English and Arabic labels independently use Unicode CLDR 48.2.3 snapshot `91c267402229a59e3ef2774544f001bf959e8809` (2026-09-30; retrieved 2026-10-04), Unicode-3.0 permission retained.

All nine evidence fields, immutable source URLs, original source hashes, byte-level Git blob hashes, extraction and normalization are in database/reference/countries/provenance.json and README.md. Complete membership is 249 unique officially assigned snapshot entries including territories; no reserved/user-assigned/region shortcuts. Complete en/ar label coverage is exact pinned CLDR[NFC] with no fallback/manual naming. Identity SHA-256 `801ef127f0b3e6b4e971c239c9b8475caedb65c17573d84ca1b57eed72523a0e`; independent localization SHA-256 `264bcc1dfb5267520bc0a423e556e8ed07f976a66a46104905310b353d20557a`. All three raw data files matched the original fetched Git blob bytes.

Four source/digest tests watched missing-artifact RED, then verified GREEN with approved Node v24.21.0 directly, failures/skips0,exit0: ledger task2-node24.log. Initial plan `npm exec -- node` command bound incorrectly through PowerShell; explicit PassThrough fixed binding but npm selected transient node22 outside approved PATH. No repository dependency/lock change occurred. The accepted verification was re-executed with C:/tools/node24/node.exe, avoiding that inference. Future Node invocation uses the explicit approved runtime/PATH.

## Task 3 — DDL, integrity and installation

Four missing-table tests first failed on42P01. Initial candidate returned3passed1failed31assertions because a DELETE RESTRICT oracle incorrectly expected23503; actual23001 denied deletion as designed. Corrected only that test expectation and added an explicit retained-reference assertion. Final run:4passed32assertions,failures/errors/skips0,exit0, storage/framework/testing/dtm-db-2ef82dee-bc1c-4539-8eed-88178388cc5a/results.xml and ledger task3-verified.log. Cluster stopped/data cleaned.

Actual duplicate23505, unknown FK23503, protected reference deletion23001, app direct DML/TRUNCATE42501 and owner audit mutation23514 were proved. Catalog asserts builderad_owner ownership and FORCE RLS on both Business-owned tables. Installation creates neutral references only:zero declarations/events, no old revision mutation. Historical migrations/SQL remain untouched; only000330 plus new payloads install these additions. The audit reuses the established immutable trigger; reference label maintenance remains administrative and outside runtime permissions.

## Task 4 — protected writer and effect-time proof

Actual combined writer/race/Foundation/lock-order command: **43 passed, 548 assertions**, failures/errors/skips 0, exit 0; `storage/framework/testing/dtm-db-409f00f6-8941-4202-8d75-923d641b7ee5/results.xml`. Separate effect-time gate: **4 passed, 59 assertions**, failures/errors/skips 0, exit 0; `storage/framework/testing/dtm-db-563e3ca6-3a38-4cc4-ab6d-86679c66252c/results.xml`. These are two commands, not a claimed single 47-test command. All owned workers and clusters stopped; data cleaned, logs preserved.

Real independent connections prove equal/different DTM sets, mixed name/type and DTM writers with both forced winning orders and synchronized entry, full atomic snapshots, authorized stale same-set no-op, and stale different-set 40001. No 40P01 is accepted. Effect-time tests block after pending audit insertion, observe actual database-clock grant/session expiry and require 42501 plus complete rollback. A prewarmed revoker blocked behind an authorized writer linearizes after its commit. Original 3-second lock timeout is unchanged; failed artificial long-wait fixtures are retained in the ledger and not credited as product proof.

## Task 5 — canonical domain projection

Observed missing-domain behavior RED followed by **17 passed, 182 assertions**, failures/errors/skips 0, exit 0 (three domain tests plus fourteen unchanged Foundation tests): `storage/framework/testing/dtm-db-d88e5516-cf8b-4f21-ad17-ddc1589a0294/results.xml`. Server stopped and data cleaned. Countries remain neutral reference data; projection derives declared state from scoped current rows and returns code-sorted localized records, with the existing shared revision and exact management authority.

## Owner governance directive

Recorded once in `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md` as OWNER-APPROVED PRODUCT GOVERNANCE / NOT CURRENT FEATURE IMPLEMENTATION. The revised directive preserves current DTM execution and defers new integration expansion to a usable Core gate. No new product, integration, speculative framework or DTM semantic change was started because of the directive.
Task 6 HTTP/Foundation gate: 25 passed / 283 assertions; failures, errors and skips 0; exit 0. Exact results: storage/framework/testing/dtm-db-2b5d5532-9380-417d-85a8-f48615b72879/results.xml. Dedicated route, raw JSON list-shape validation, root country_codes errors, version conflict, empty removal/no-op, nonowner explicit manager, owner without grant, revoked membership/session and foreign principal denial, plus name/type preservation are proven. Cluster stopped and data cleaned. Task 7 in progress.

## Task 7 — rendered interaction and browser proof

Final approved Node 24 interaction/localization command: **13 passed**, fail/skip/cancel 0, exit 0. Actual compiled Vue handlers prove search, retained selection/removal, locale-independent draft codes, separate forms/shared revision refresh, skip without POST, read-only state, unknown state and retained conflict review. Production typecheck/build exit 0. Build rejection of unsupported Inertia reload options was fixed before accepted proof.

Real bundled Playwright Chromium, isolated PHP preview at `http://127.0.0.1:8017`, synthetic owner/reader sessions and a new guarded PostgreSQL cluster: **10 browser checks PASS**, runtime/console errors 0; `storage/framework/testing/dtm-db-1bf795d6-c3ef-407e-b788-26286f9a16a4/ui-evidence.json`. Arabic RTL 320×740 and English 1280×900; real native keyboard selection, named removal, saving, actual stale-tab 40001 conflict, reload showing current persisted set while retaining draft, skip without mutation and member read-only summary. Document scroll width does not exceed 320px before/after save or in reader view. Screenshot evidence in the task visualization directory; Arabic mobile image visually inspected. Browser plugin unavailable; existing bundled Playwright used without dependency change. PHP preview, browser and PostgreSQL stopped; task session/data cleaned, proof logs preserved.

## A–Q evidence matrix (current checkpoints)

HTTP = DeclaredTargetMarketsTest; SQL = DeclaredTargetMarketsDatabaseTest; RACE = DeclaredTargetMarketsConcurrencyTest. Combined Task 8 command actually passed **44 tests / 561 assertions**, failures/errors/skips 0, exit 0; `storage/framework/testing/dtm-db-1948cc35-60d3-4fc6-9e28-85bef25a443c/results.xml`. Task 9 result: **63 tests / 509 assertions**, failures/errors/skips 0; final process exit/cleanup recorded on completion. Durability L subsequently passed in Tasks 11–12, recorded below.

| Case | Actual assertion/evidence | Status |
| --- | --- | --- |
| A | HTTP test_zero_markets_business_remains_valid: existing Sources route works after normal classification with no DTM. | PASS |
| B | HTTP test_no_location_required_for_sa: only codes/version supplied. | PASS |
| C | HTTP test_jordan_location_example_keeps_independent_meaning; Workspace full-fact preservation and controller/writer have no location input/dependency. JO is explicitly a test-only independent scenario variable. No persisted Business Location implementation/proof is claimed. | PASS within approved semantic limit |
| D | HTTP test_projection_round_trips_canonical_codes / test_http_replacement_refreshes_canonical_projection_and_preserves_name_type. | PASS |
| E | SQL PK/valid reference test and HTTP duplicate/reordered same-set assertions. | PASS |
| F | HTTP foreign/revoked membership and SEC selected-scope visibility/foreign writer/body spoof denial. | PASS |
| G | SQL unauthorized identical-set, HTTP owner without grant/revoked session and RACE current-authority failures. | PASS |
| H | HTTP test_authorized_nonowner_manager_can_edit, existing Foundation explicit manager assertion. | PASS |
| I | HTTP name/type preservation; real Commercial/provider snapshot preservation before/after final DTM removal. No physical location storage exists. | PASS |
| J | CommercialScopeTest test_commercial_country_never_populates_or_overwrites_declared_markets: actual Jordan market/configuration/registration with empty and populated DTM. | PASS |
| K | ProviderAccessStoreTest test_provider_geography_never_populates_or_overwrites_declared_markets: actual encrypted custody and immutable native observation journal across all five providers, with synthetic JO/AE facts, empty/populated DTM. | PASS |
| L | Tasks 11–12 exact baseline archive, 33 migrations, full snapshots of 58 historical tables, zero fabricated declarations/events; final F/P and independent N1 evidence below. | PASS |
| M | HTTP locale-independent canonical round-trip, SQL malformed arrays/reference integrity, source reproduction. | PASS |
| N | Final actual compiled Vue15 interactions plus15 real browser checks, RTL/320px/keyboard/save/remove/skip/read-only/conflict, both locale directions and retained drafts. | PASS |
| O | SQL authorized stale same-set no-op / stale different-set plus21 real RACE methods, both mixed winning orders and simultaneous entry; actual before-locale competing save preserves both draft revisions, explicit review/manual save and both crossed-form unit cases. | PASS |
| P | SEC raw DML/unsigned/forged/service/internal/current ACL/RLS probes; actual producer-path preservation and executable writer inventory below. | PASS |
| Q | Domain false/empty, HTTP final removal/empty replay, UI server-derived unknown/no defaults/skip no POST. | PASS |

## Task 9 — security and non-overwrite inventory

The only runtime SQL mutations of DTM are DELETE/INSERT relation rows and INSERT audit within `builderad_business_target_markets_write`. The only application caller is BusinessContext::replaceTargetMarkets invoked by the explicit dedicated controller route under the prepared signed Workspace transaction. Projection joins are read-only; reference seeding is neutral installation data only. Tests contain explicit owner fixtures, negative raw-role probes and ephemeral blockers; none is registered as a production command. No trigger/listener/provider/Commercial producer writes DTM. `task9-writer-inventory.txt` and `task9-privileged-inventory.txt` retain bounded executable/privileged connection inspection, not merely claims based on missing text.

Catalog owner/search path/SECURITY DEFINER, app-only execution, PUBLIC/authority exclusion, private preparation guard and FORCE RLS were executed. Raw runtime INSERT/UPDATE/DELETE/TRUNCATE, unsigned/forged context and foreign signed scope have zero effects. A valid independent signed service with active Workspace scope and view grant cannot prepare or declare. A current internal-passkey staff member holding business.manage plus view scope cannot cross the human advertiser boundary. Privileged installation rights remain administrative capabilities, not advertiser/service authority.

Existing accepted Commercial commands were exercised with actual market/configuration registration for both empty/populated declarations. Existing provider custody/journal accepted ten synthetic geography observations across five providers without changing DTM or shared revision/audit; this is local runtime producer-path proof, not live provider API evidence. Removing DTM preserves actual Commercial rows and provider source/access/history/observation rows. Two initial fixture errors (invalid global scope and human self-grant) were corrected to existing platform scope and the actual Owner authority-request path, with no authority/schema change.
Task 9 final exit 0; all task processes stopped/data cleaned. JUnit: storage/framework/testing/dtm-db-7f24dfe0-7dd2-4390-a4d7-21da32039e83/results.xml. Task 10: 2 PHP tests / 528 assertions, failures/errors/skips 0, exit 0; storage/framework/testing/dtm-db-9cac56d2-1652-430b-835f-03018849ee92/results.xml; independent country Node suite 4/4 PASS. Label-only guarded change preserved full Workspace/DTM/audit and identity digest, changed localization digest and displayed metadata, then restored original labels in finally. Clusters stopped/data cleaned. Tasks 11–13 remain incomplete.

## Tasks 11–12 — exact forward installation and independent durability

Final F/P proof: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Durability -Baseline 8ebd9a1f1ffd7b23307d277304203c706e67d95d`, exit 0. Evidence directory: `storage/framework/testing/dtm-db-e76897e1-0133-4d75-9a6d-48f0f697d43d`. Empty-to-head, two actual resets, exact populated baseline-to-head, final schema/authority catalog equality, identity equivalence and localization equivalence all PASS. Git archive and per-file Git-object verification prove the 33 historical migrations ending at 000320 and their SQL/bootstrap came from the exact baseline. Only new 000330 was applied forward; full deterministic row values across all 58 historical tables were preserved. No DTM relation/audit rows or prior revision changes were fabricated. Representative existing Commercial administrative fixtures remain valid under actual constraints; canonical provider custody/observation and existing Business commands were exercised. No disabled constraints or primary data were used.

Final N1 proof: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Durability -DurabilityReference <F/P durability-reference.json>`, exit 0. Evidence: `storage/framework/testing/dtm-db-0dc6bc1f-2855-4474-9efc-39ba7230ee25`. Independently initialized cluster, distinct data directory, fresh isolation token and PostgreSQL system identifier are asserted. Installation independently reproduces F/P schema/authority, all country identities/digests and localization snapshot. Only comparison evidence is read; no database files are copied.

Both runs additionally execute a deliberately invalid comparison-path denial. Its expected `DTM_DURABILITY=FAIL` is followed by `DURABILITY_FAILURE_EXIT_CLASSIFICATION=PASS`; the main proof is credited only when its own stage markers and process exit pass. Explicit Throwable handling prevents a standalone Laravel error from being misclassified as success. Existing disposable guard tests: 2 passed / 10 assertions in each installation, failures/errors/skips 0. All clusters stopped and their checked data directories cleaned; logs and manifest/snapshot/catalog JSON are preserved. Catalog line endings alone are normalized for cross-platform Git archive equivalence; historical row snapshots are not normalized.

Task 11 initial candidate failed on a fixture variable collision after recording historical preservation; it is not accepted final proof. The fixture now has an isolated variable scope. Final accepted runs above cover all required durability stages. A–Q case L is PASS; all 17 cases are now executed, with C's explicitly approved semantic limit retained.

## Task 13 — historical initial regression checkpoint

Commands use the existing `scripts/builderad.ps1` wrapper and approved PHP 8.5.10 / Node 24.21.0. PowerShell npm arguments are passed as explicit `-PassThrough` arrays; `npm exec node` is not used. Raw per-command logs are retained in `.superpowers/sdd/2026-10-04-declared-target-markets/`.

| Executed command | Accepted result / raw log |
| --- | --- |
| `builderad.ps1 npm test` | 105 passed; failures/skips/cancellations 0; exit 0; task13-npm-test.log. |
| `builderad.ps1 -Action npm -PassThrough @('exec','--','vue-tsc','--noEmit')` | Exit 0; task13-typecheck.log. |
| `builderad.ps1 build` | vue-tsc and production Vite build exit 0; task13-build.log. |
| `builderad.ps1 php vendor/bin/pint --test` | Entire existing style gate passed, exit 0; task13-pint-test.log. Applied formatting only to actual changed PHP surfaces beforehand. |
| `builderad.ps1 php -l <each changed PHP file>` | 17 individual PHP files; all syntax checks pass, exit 0; task13-php-syntax.log. |
| `builderad.ps1 composer validate --strict` | Exit 0; task13-composer-validate.log. |
| `builderad.ps1 composer audit` | No security vulnerability advisories, exit 0; task13-composer-audit.log. |
| `builderad.ps1 -Action npm -PassThrough @('audit','--audit-level=high')` | 0 vulnerabilities, exit 0; task13-npm-audit.log. |

Full PHP regression and final independent review remain pending at this checkpoint. No UI implementation changed after the accepted rendered browser evidence; subsequent PHP formatting and production build do not invalidate that UI proof. Both dependency lock hashes and HEAD rechecked unchanged. New governance documentation records the supplied owner directive without extending DTM scope or implementing deferred Core capabilities.


## Exact review artifact file inventory

- `database/migrations/0001_01_01_000330_create_declared_target_markets.php`
- `database/reference/countries/README.md`
- `database/reference/countries/identities.json`
- `database/reference/countries/labels.json`
- `database/reference/countries/provenance.json`
- `database/reference/countries/sources/cldr-ar-territories.json`
- `database/reference/countries/sources/cldr-en-territories.json`
- `database/reference/countries/sources/iso_3166-1.json`
- `database/reference/countries/sources/pycountry-COPYRIGHT.txt`
- `database/reference/countries/sources/pycountry-LICENSE.txt`
- `database/reference/countries/sources/pycountry-README.rst`
- `database/reference/countries/sources/unicode-LICENSE.txt`
- `database/sql/business_context_lock_order.sql`
- `database/sql/declared_target_markets.sql`
- `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md`
- `docs/decision-context-foundation-001-bounded-lock-order-repair-prompt.md`
- `docs/decision-context-foundation-001-implementation-proof.md`
- `docs/decision-context-foundation-001-preflight.md`
- `docs/superpowers/plans/2026-10-04-declared-target-markets.md`
- `docs/superpowers/specs/2026-10-04-declared-target-markets-design.md`
- `resources/js/Components/Business/TargetMarketsEditor.vue`
- `scripts/Test-DeclaredTargetMarkets.ps1`
- `tests/Feature/BusinessContextLockOrderTest.php`
- `tests/Feature/CountryReferenceProvenanceTest.php`
- `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php`
- `tests/Feature/DeclaredTargetMarketsDatabaseTest.php`
- `tests/Feature/DeclaredTargetMarketsSecurityTest.php`
- `tests/Feature/DeclaredTargetMarketsTest.php`
- `tests/Support/DeclaredTargetMarketsDurabilityProof.php`
- `tests/Support/DeclaredTargetMarketsLegacyFixture.php`
- `tests/Support/DeclaredTargetMarketsUiFixture.php`
- `tests/Support/declared-target-markets-browser.mjs`
- `tests/TargetMarketsCommandWorker.php`
- `tests/country-reference.test.mjs`
- `tests/target-markets-interaction.test.mjs`
- `app/Business/BusinessContext.php`
- `app/Foundation/Tenancy/WorkspaceContext.php`
- `app/Http/Controllers/BusinessContextController.php`
- `package.json`
- `resources/js/Components/LanguageSwitcher.vue`
- `resources/js/Layouts/AdvertiserLayout.vue`
- `resources/js/Pages/Business/Context.vue`
- `resources/js/Pages/Business/Sources.vue`
- `resources/js/localization/business.ts`
- `routes/web.php`
- `tests/Feature/Commercial/CommercialMarketPreparationTest.php`
- `tests/Feature/Commercial/CommercialScopeTest.php`
- `tests/Feature/Providers/ProviderAccessStoreTest.php`

## Final country provenance fields

- `IDENTITY_SOURCE`: Debian iso-codes ISO 3166-1 database, redistributed unchanged by pycountry (maintained standard-derived dataset, not an ISO-published Arabic naming source)
- `IDENTITY_SNAPSHOT_OR_REVISION`: pycountry/pycountry@4c6a69927a25326a69bf753671fb571bb437cd7d; repository snapshot 2026-02-18; retrieved 2026-10-04
- `IDENTITY_COUNT`: 249
- `IDENTITY_DIGEST`: 801ef127f0b3e6b4e971c239c9b8475caedb65c17573d84ca1b57eed72523a0e
- `ENGLISH_LABEL_SOURCE`: Unicode CLDR cldr-localenames-full main/en/territories.json (distinct from ISO identity naming)
- `ENGLISH_LABEL_REVISION`: unicode-org/cldr-json@91c267402229a59e3ef2774544f001bf959e8809 (48.2.3), 2026-09-30; retrieved 2026-10-04
- `ARABIC_LABEL_SOURCE`: Unicode CLDR cldr-localenames-full main/ar/territories.json; localized display metadata, no claim of ISO naming authority
- `ARABIC_LABEL_REVISION`: unicode-org/cldr-json@91c267402229a59e3ef2774544f001bf959e8809 (48.2.3), 2026-09-30; retrieved 2026-10-04
- `LOCALIZATION_DIGEST_OR_EQUIVALENT`: 264bcc1dfb5267520bc0a423e556e8ed07f976a66a46104905310b353d20557a

## Execution rulings and remaining scope limits

1. Native PowerShell ledger management replaces commit-oriented Bash helpers on Windows. Owner forbids commits; proof stays on disk. Cost if wrong: bookkeeping differences, not product semantics.
2. Preserve the ignored ledger/evidence instead of deleting them at completion because no commit persists the record. Cost if wrong: local ignored disk usage; deletion would lose required proof.
3. Independent final review covers the actual uncommitted tracked/untracked patch, not empty baseline..HEAD; material fixes require independent re-review. Cost if wrong: reviewer setup/time; reviewing an empty commit range would miss the implementation.
4. An ignored synthetic `.env` pinned to testing/localhost:5433 prevents missing-file warnings; a fresh isolation token is supplied only by the guarded task wrapper. Cost if wrong: test configuration/proof invalidity; no primary configuration is used.
5. Pull the real lock characterization forward before country/product installation because the unchanged selector SHARE-to-writer UPDATE sequence could contradict simultaneous-entry success. Cost if wrong: one extra focused prerequisite proof; the actual 40P01 confirmed its necessity.
6. Under explicit bounded-repair approval, serialize Business Context mutation entry before canonical selection, preserve shared actor/selector semantics and use forward SQL with a transaction-bound sequencing guard. Simultaneous entry remains real while selector completion serializes. Cost if wrong: material concurrency/authority regression; the affected 78-test gate and subsequent equal/different/mixed/effect-time proofs address this risk.
7. Use explicit PowerShell `-PassThrough` arrays and approved Node 24 directly instead of ambiguous bare `--`/`npm exec node`. Cost if wrong: command/runtime mismatch; accepted results were rerun on the approved runtime and lockfiles stayed unchanged.
8. Additional security/provenance proofs inspect existing correct behavior; do not artificially weaken production solely to manufacture RED for proof-only tests. Product causal RED/GREEN evidence remains recorded. Cost if wrong: weaker proof of assertion sensitivity; direct catalog, changed-label, negative SQLSTATE and real race/effect snapshots provide the independent checks.
9. The required whole PHP gate exposed an existing Windows Commercial worker-readiness issue. Start both workers first and observe cumulative buffered output with unchanged deadlines, preserving real waits and200/409/exactly-one assertions. Startup alone failed and is not credited. Cost if wrong: remaining harness flakiness; the corrected focused and final whole-suite evidence is required.
10. Record both approved Owner directives in one canonical governance document, verbatim once with the explicit extension relationship. Cost if wrong: ambiguous governing direction; exact source comparisons and independent review establish coherence.
11. Accept explicit declined-to-judge scope/safety limits for absent persisted Location, future capabilities, live provider calls, future reference administration and primary deployment; supply original directive sources to resolve their separate verbatim-review gap. Cost if wrong: those behaviors remain unproven; none is claimed as implemented by this slice.
12. Preserve dirty drafts' original revision through unrelated refreshes; explicit review or a successful local write may advance the relevant revision, with cross-form synchronization allowed only if its original canonical fields are unchanged. Cost if wrong: stale overwrite or needless conflict; actual before-locale browser and crossed-form tests gate this correction. PHP/SQL stayed identical to the716-test final full proof; rerun affected Vue/JS/type/build/browser gates.
13. Record the External Evidence Providers supplement verbatim once in the same canonical source, extending future evidence discipline without implementing providers or expanding DTM. Cost if wrong: future provider-governance ambiguity; no current product behavior changes. All three original directives are verified exactly once.
14. Reconcile successful DTM values/revision together unless a new edit was made in flight; retain that new draft's original revision. The actual name/type success unmounts Context, so the withdrawn analogous candidate does not justify another production change. Cost if wrong: lost new draft or stale overwrite; componentRED/GREEN and actual post-commit/pre-redirect browser proof gate the change. Keep failed resource/probe candidates as FAIL and run build/browser sequentially, without timeout widening or mutation retries.

Business Location is absent and is not implemented by this patch. Case C proves no DTM dependency/overwrite through a test-only independent JO scenario and full existing Workspace snapshots, not persisted Location integration. Provider geography proof uses existing local synthetic custody/observation producer paths, not live external API calls. Country identities and localization are independently pinned snapshots; reference updates remain administrative maintenance outside this feature. No runtime lookup, speculative framework, new integration, Goal, Audience or Market Opportunity is implemented. These are scope boundaries, not missing required DTM behavior.

## Full regression candidate failure and bounded test-harness correction

First entire PHP run: 714 passed / 1 failed / 8485 assertions; errors/skips 0; exit 1. Retained JUnit and PostgreSQL logs: `storage/framework/testing/dtm-db-f461babf-a272-4823-8b59-ec2aa4ca62f9`; cluster stopped/data cleaned. Sole failure was the existing `CommercialMarketPreparationTest::test_two_independent_sessions_cannot_consume_the_same_target_twice`, at the assertion that the first worker remained running before releasing the second. No DTM or Foundation test failed. This run is FAIL, not substituted by focused success.

Unmodified isolated localization passed: 1 test / 13 assertions, exit 0, 13.01s; `storage/framework/testing/dtm-db-f27fb57d-d03d-4bd1-9c6e-ef61dfe2084e`. Inspection found sequential startup lets the second worker's bootstrap consume the first worker's 15-second pre-effect start-barrier budget; the full failed test took 33.796s. Small test-only correction starts both workers before waiting for either PID, preserving the existing INSERT_READY barrier, actual PostgreSQL competing-wait observation, 200/409 results and exact-one-state/event assertions. Added first worker stdout to failure diagnostics. No worker/process/database timeout, SQL/authority rule or product behavior was changed. This is an extra focused test surface required to stabilize the mandated full proof; it is not Commercial product scope expansion. The first failure is retained and final full proof remains required.

The startup-only candidate was insufficient and failed the same assertion: 1 failed / 4 assertions, exit 1, 22.01s; `dtm-db-fc92e758-827e-46a6-b8ef-85420d86ab89`. Added stdout exposed `Start barrier not observed` before any effect. Actual cause is the Windows Symfony Process output-read boundary: `waitUntil` performs `updateStatus`, and Windows `isRunning` also reads pipes, buffering an already-emitted PID before the temporary wait callback can observe it. The existing callback can miss readiness until the worker's start deadline expires. Final local correction polls cumulative `getOutput` with the existing 20-second Process `checkTimeout`, and explicitly asserts each PID and INSERT_READY marker. No vendor or worker code changed.

Final focused result: 1 passed / 16 assertions, exit 0, 6.96s; `storage/framework/testing/dtm-db-f44dc2aa-c08f-455f-99ec-e45ee11b72a5`. The three added assertions check readiness; all original real-lock waiter, 200/409 and exactly-one facts remain intact. Cluster stopped/data cleaned. Modified-file Pint and syntax checks exit 0; total changed PHP files checked is now 18. Entire PHP regression is rerunning on a fresh installation; focused success does not substitute for it.

## Market Evidence governance acknowledgment

The refined Owner Market Evidence and Opportunity Intelligence directive is recorded verbatim once in the same canonical `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md`. It explicitly extends, and does not replace, the approved Core Execution governance. The meaningful Decision Delta rule, shared-evidence ownership, separation from declared intent, nondecorative confidence, source rights/access/reproducibility gates and separately authorized future implementation gate are preserved. The current PHP regression continued uninterrupted; no product architecture or DTM behavior changed.

```text
MARKET_GOVERNANCE_RECORDED = YES
CANONICAL_GOVERNANCE_LOCATION = C:\dev\builderad-worktrees\decision-context-discovery-001\docs\BUILDERAD_CORE_EXECUTION_GOVERNANCE.md
CORE_GOVERNANCE_RELATIONSHIP = EXTENDS_EXISTING_CORE_GOVERNANCE
CURRENT_DTM_SCOPE_CHANGED = NO
MARKET_IMPLEMENTATION_STARTED = NO
GENERIC_MARKET_FRAMEWORK_CREATED = NO
GENERIC_EVIDENCE_FRAMEWORK_CREATED = NO
NEW_DATA_SOURCE_INGESTION_STARTED = NO
DECISION_DELTA_RULE_RECORDED = YES
CURRENT_EXECUTION_RESUMED = YES
NEXT_CURRENT_ACTION = Complete Task 13 full PHP regression and independent final patch review
```

## Final full-regression result — review gate pending

Final command `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Full` ran the entire PHP suite without filtering: **715 passed / 8497 assertions**, failures 0, errors 0, skips 0, warning lines 0; exit 0. Actual duration 1159.87s. JUnit and PostgreSQL/command logs: `storage/framework/testing/dtm-db-5326d49e-511c-4e84-b7bd-6d3163d802ff`; ledger `task13-full-php-final.log`. This includes the previously failed Commercial two-session race, now with all original concurrency outcome assertions plus three readiness checks. All Foundation/DTM/security/provider/Commercial/session/authority suites ran. The earlier full run remains recorded as FAIL and is superseded only by this complete successful command.

Final changed-PHP syntax coverage is 18 files; original 17-file gate plus the additional Commercial test's final `php -l` both pass. Entire Pint gate passed before that test-only change and the additional changed file passes final Pint; no remaining untested formatting delta. JavaScript 105/105, standalone typecheck, production build, Composer strict validation, Composer security audit and npm security audit all exit 0. No advisories or npm vulnerabilities. Final tracked diff whitespace check exit 0; new-file validation retained separately. The independent final review is still pending; `SAFE_FOR_OWNER_PATCH_REVIEW` is not yet asserted for the completed product patch.

All task PostgreSQL clusters and both Commercial participants exited; task DB data cleaned with raw logs preserved. Browser/PHP preview were already stopped after accepted UI proof. Primary connections/writes remain zero. HEAD and both lockfiles remain unchanged; no commit, push, merge or reset.

## Independent final review 1 — accepted findings and bounded fixes

Fresh read-only reviewer: GPT-6 Astra, separate context, no implementation history or mutations/database calls. Reviewed the complete uncommitted tracked/untracked patch and retained raw race, installation, historical snapshot, reference, UI and regression evidence. Verdict: **Not ready — two Important/P2 findings**, no Critical and no deferred Minor findings.

1. Actual `LanguageSwitcher` POST used `preserveState:false`, remounting Context and losing unsaved DTM additions/removals. Mock locale changes and prior saved-state browser check did not exercise this path. Accepted; real browser RED proved draft AE/JO/KW became saved AE/KW/SA. The bounded fix adds an opt-in preserveState prop passed through AdvertiserLayout and enabled by persisted Business Context only. Default false remains for all other pages. Existing version watchers still refresh shared revision, and forms remain independent. New actual browser checks exercise both language directions with unsaved country addition/removal and name/type drafts, no Business POST, and subsequent canonical reload proving nothing was persisted by locale change.
2. Required quoted/braced malformed-value proof did not cover the PHP adapter, and the raw SQL test omitted those values. Accepted as explicit coverage gap, not demonstrated injection vulnerability. Added actual bound JSON-to-array adapter probes plus direct protected-SQL quote/brace cases against an already populated KW/SA declaration; require22023 and unchanged complete Workspace/current set/audit snapshots. NULL, nested/object array shapes, backslashes and injection-shaped text are included in adapter probes. No production SQL/PHP validation was weakened or changed.

These findings remain material review history even after correction. Final proof and independent re-review are required before acceptance. No self-review substitutes for re-review.

Reviewer declined to judge: persisted Business Location (absent; approved semantic limit), deferred future products/frameworks (Owner excludes), live external-provider behavior (local synthetic path proof only), future country administration (outside slice), independent runtime/primary deployment (reviewer prohibited; author retained guarded raw proof), original-message verbatim equality (not in reviewer context). Executor accepts the first five bounds under the explicit approved scope. Original Core and Market directive source paths are supplied to the re-review package; executor independently checks their verbatim recording. Cost if these bounds are wrong: integration/future/live behavior would remain unproven; none is claimed by this patch.

## Post-review affected proof

Real locale-loss RED: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase UI`, exit1, `dtm-db-908fc4a9-28c9-4585-902e-d93b11013290`; actual draft AE/JO/KW was replaced by saved AE/KW/SA upon real English switch. Final corrected full UI phase: **13 checks PASS, runtime/console errors0, exit0**, `storage/framework/testing/dtm-db-550626f6-a8e0-452a-9ec2-f364cfcc6ad2/ui-evidence.json`. Includes original RTL320/keyboard/search/save/skip/readonly/real stale-tab review plus both locale directions retaining additions/removals and independent name/type drafts. A competing tab advances the revision after locale switching; the retained draft is still rejected, not automatically reposted, and complete original persisted countries/name/type remain verified. Explicit successful restore-response JSON asserts the expected next revision and exact canonical set. All preview/browser/cluster processes stopped and task sessions/data cleaned.

Protected SQL plus actual PHP adapter proof: `-Phase Focused -Filter 'DeclaredTargetMarketsTest|DeclaredTargetMarketsDatabaseTest'`, **24 passed /239 assertions**, failure/error/skip0,exit0; `storage/framework/testing/dtm-db-b521ffcc-ba0b-40cb-9cbe-a0704b3ed4a4/results.xml`. All new malformed inputs deny22023 with full populated Workspace/set/audit unchanged. Cluster stopped/data cleaned. No production PHP or SQL changed for this coverage correction.

Latest full JavaScript: **107 passed**, fail/skip/cancel0,exit0; review-full-js.log. Actual compiled interaction suite9/9; review-js-interaction.log. New actual LanguageSwitcher handler tests verify explicit preserveState and unchanged false default on unrelated pages. Latest standalone vue-tsc, production build, entire Pint style gate and syntax checks of both changed PHP test files exit0; review-typecheck.log,review-build.log,review-pint-test.log,review-php-syntax.log. Full changed PHP syntax coverage remains18. Dependency artifacts unchanged, so earlier actual zero-vulnerability audits still apply.

The added PHP test increases the full suite to716; entire final PHP rerun is in progress and must complete before fresh independent re-review. Previous715/8497 is retained as the earlier actual whole-suite result, not claimed as execution of the new test.

## Final post-fix complete regression — independent re-review pending

Final complete PHP command after both material findings were corrected: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase Full`, **716 passed /8527 assertions**, failures0,errors0,skips0,warning lines0,exit0; duration1152.95s. Exact JUnit and retained logs: `storage/framework/testing/dtm-db-53860fc8-142e-40b8-9f6a-d3133a0981f0/results.xml`; `review-full-php-final.log`. This actually executes the added adapter test and expanded protected-SQL malformed-value test, alongside all original Foundation/authority/concurrency/Commercial/provider suites. Earlier715/8497 remains an honest earlier checkpoint. Cluster stopped/data cleaned; all task processes exited; primary connections/writes0.

Final JS107/107, UI13/13, typecheck/build/fullPint/syntax18 and unchanged-lock dependency audits all PASS as recorded above. Tracked and all35 new-file whitespace checks PASS. No new product, provider integration, Market framework or evidence framework was created; only bounded review fixes were made. A fresh independent re-review of the complete final uncommitted artifact is the remaining acceptance gate. No final readiness claim precedes its verdict.

## Independent review 2 — locale revision bypass

Fresh read-only GPT-6 Astra review of the whole actual patch closed both initial P2 findings, independently verified original Core/Market directives verbatim once, F/P/N1 equality, populated historical byte equality and raw716/8527 +107JS +13UI evidence. It found one new Important/P2, no Critical/Minor: if a competing tab saves BEFORE a locale switch, retained drafts receive the new canonical revision from unconditional watchers. Their next Save could overwrite the competitor without conflict. The previous browser schedule put the competitor AFTER locale switching and did not prove this ordering. Accepted; readiness remains blocked until correction and fresh independent re-review.

Actual browser RED on original compiled implementation: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase UI`, exit1; `storage/framework/testing/dtm-db-4f954c01-d258-4c27-bcce-95deb5921693/ui-evidence.json`; `review-locale-revision-red.log`. Before-locale competing revision4 was submitted by an older revision3 draft. All preview/browser/cluster processes stopped/data cleaned. New actual compiled unit tests independently failed on8 versus7 revision and on clean refresh retaining old countries with new revision; the old explicit-review handler also had no completion callback.

Bounded correction in the two existing forms: dirty drafts keep their original expected revision across unrelated refreshed props; clean forms refresh actual fields and revision together. DTM's explicit Reload and review updates its revision on successful completion even if locale already fetched the same canonical revision, retaining the draft and saved-set conflict summary without automatic repost. Successful local writes may synchronize the other form's shared revision only if its underlying canonical fields still equal its original baseline; reviewing one form does not authorize overwriting competing changes to the other. No SQL, PHP, authority or database timeout changes, new dependencies or generic framework. Actual browser covers competing save BEFORE both locale directions, both independent stale submissions, and explicit review/manual DTM save. Unit proofs include clean fields, normal independent saves and both crossed competing-field cases.

Ruling: preserve original dirty-draft revisions and permit only the explicit review/justified local-success paths above. Cost if wrong: stale overwrite or needless conflict; fresh actual browser and compiled interaction tests gate acceptance. The complete716/8527 PHP proof covers the unchanged final PHP/SQL artifact; this Vue/browser/unit-only correction requires fresh affected UI/JS/type/build proof, not a redundant unrelated PHP rerun. Final affected results and independent verdict are pending.

Browser candidate history after this bounded correction: `dtm-db-1dbf26cb-a649-4153-9b76-e706394452ba` failed because the probe expected DTM's localized conflict text for the pre-existing name/type error. `dtm-db-982edc0f-f4d3-4ad8-9246-69ff2f08b739` and diagnostic `dtm-db-5c1abfee-6434-4152-9020-cd1a707e0622` failed because the general alert selector matched both the visible field span and hidden generic duplicate. The diagnostic actual request/response proves original revision3 and the existing business_type_id error at canonical4; no production change was needed for either probe issue. Corrected selector is scoped to the visible Business Type field. These commands remain FAIL, not credited as final UI proof; all task processes/clusters/data were stopped/cleaned.

## Final affected locale-revision proof — review 3 pending

Final actual guarded UI command: `scripts/Test-DeclaredTargetMarkets.ps1 -Phase UI`, **15 checks PASS, console/runtime errors0, exit0**; `storage/framework/testing/dtm-db-ac01b8c9-484d-47cc-a55a-86fb40813ddf/ui-evidence.json`; ledger `review-locale-revision-ui-accepted.log`. It proves both stale submissions retain original shared revision3 after competing save4 BEFORE the actual en/ar locale actions. Name/type failure returns the existing field error and exact unchanged canonical name/type/revision4 and AE/KW/QA/SA. DTM conflict retains AE/JO/KW, no automatic repost. Explicit review retrieves already-fetched4, retains visible current QA summary and performs zero POST; subsequent manual Save submits4 and succeeds. Controlled restoration returns canonical AE/KW/SA at6, original name/type unchanged. All original narrow320pxRTL/keyboard/search/chips/skip/unknown/readonly and English checks reran. Every preview/browser/database process stopped; synthetic sessions/data cleaned; primary connections/writes0.

Final complete JavaScript command: **113 passed, failures/skips/cancellations0, exit0**, `review-locale-revision-js-final.log`; actual compiled interactions15/15. Standalone vue-tsc and production build both exit0, `review-locale-revision-type-final.log` and `review-locale-revision-build-final.log`. No PHP/SQL or dependency artifact changed since the recorded exact full716tests/8527assertions, fullPint/syntax18 and zero-vulnerability audit gates. Those gates remain applicable to their identical artifacts. Fresh independent third review of the actual whole patch is the remaining gate; acceptance is not inferred from technical passes.

## External Evidence Providers governance supplement

Owner source `C:\Users\stoma\.codex\attachments\728fbda5-9c7e-4222-834a-3f297e74c1c5\Pasted text.txt` is recorded verbatim once in the same canonical `docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md` as a supplement. It distinguishes fact-relative authority from substitutability, provider meaning from Builderad meaning, decision-level replaceability from schema identity, and source failure/evidence absence/fact absence. Valuable capabilities may be used deeply and selectively when legally/economically/technically justified; each meaningful group must change a decision. Degradation must reduce claims when evidence weakens. No ingestion, adapter, dependency, provider framework or future Market implementation is authorized or created by recording it. All three original governance texts were verified by exact ordinal containment, each once; Market is now an interior verbatim section rather than the document's ending.

Ruling: record this supplement in the existing canonical authority and preserve the current approved implementation boundaries. Cost if wrong: future provider governance ambiguity; no product behavior change. Current execution remains resumed; final independent review follows the passed affected proofs.

## Independent review 3 — partial verdict and DTM redirect interleaving correction

The fresh reviewer independently closed the prior before-locale revision issue in both forms and verified all three original governance directives exactly once. It identified a new P2 in the actual DTM same-component success redirect: another tab can commit between this successful POST and its redirect GET. The success callback assigned the newer response revision but kept the older submitted countries, allowing a subsequent save to erase the later commit. The reviewer's initial analogous name/type concern was withdrawn after verifying that its actual successful controller redirect unmounts Context into Sources or the approved foundation destination; no live name/type issue is claimed. The reviewer reached an account usage limit before completing the whole-review verdict; this partial review is not PASS.

Actual compiled component RED: `node --test tests/target-markets-interaction.test.mjs`,15passed2failed,exit1; `review-redirect-unit-red.log`. CanonicalKW/revision9 resulted in oldSA/AE/revision9; an edit made during the request also incorrectly advanced from7to9. Bounded DTM correction captures submitted values/version, suppresses passive canonical refresh while saving, reconciles unchanged submitted fields to actual canonical values and revision together on success, and retains a newly edited in-flight draft with its original revision. The existing conflict/review and cross-form baseline checks remain. Component GREEN17/17,exit0; `review-redirect-unit-green.log`. NoPHP/SQL/authority change. Full affected proof and completed fresh independent re-review remain required; readiness stays NO.

Entire final JS115passedfail/skip/cancel0, standalone vue-tsc and production build exit0 (`review-redirect-js-final.log`, `review-redirect-type-final.log`, `review-redirect-build-final.log`). Browser candidate `dtm-db-ceed38fd-3d19-4cb4-bcd5-ab8956eb5e06` failed before the new interleaving probe, with testserver500 and disconnected PostgreSQL. Raw PostgreSQL log reports out-of-memory, exception0xC000012D and recovery; a concurrent shell launch reports Windows paging-file-too-small1455. Build and browser overlapped in error; no browserRED or product-success claim is made from this candidate. All task processes stopped/data cleaned. Final browser proof is rerun sequentially after completed build. Existing actual componentRED remains the causal reproduction; no timeout, database retry or product semantics change is used to mask host failure.

Sequential browser candidate `dtm-db-95c88cc2-a180-4d70-ac49-9d5f5f180190` failed its explicit interception guard: Playwright does not route the followed redirect GET. Corrected fixture instead executes the actual POST once through `route.fetch({maxRedirects:0})`, withholds its original redirect response while B verifies A's actual committed set and makes a protected competing write, then fulfills the original response. Candidate `dtm-db-9671f388-cbed-4423-8fc4-cf185bc82412` failed an incorrect303 expectation; the established POST returns302 and existing InertiaMiddleware converts onlyPUT/PATCH/DELETE302 to303. The exact302 oracle is now used. Neither probe correction changes product behavior or repeats the mutation. These runs remain FAIL with full cleanup; final sequential evidence follows separately.

Final302 browser candidate `dtm-db-574391c0-6716-4eb9-9c2e-9262558f9f6f` executed the new actual interleaving successfully: A committed, B verified A's actualJO and changedJOtoQA before the original302 response was delivered, A received canonicalAE/KW/QA/SA with the later revision, and its next manual save preserved the competitor as a same-set no-op. All functional checks reached PASS, but the complete run was **FAIL** at console/runtime health because `net::ERR_CONNECTION_TIMED_OUT` was recorded. It is not credited as final UI acceptance. Browser diagnostics now retain exact console resource location and failed-request URL/error without filtering or ignoring errors. New wholeUI health gate is pending; all prior task processes stopped/data cleaned.

## Final accepted complete UI after redirect correction

`scripts/Test-DeclaredTargetMarkets.ps1 -Phase UI`: **16 checksPASS,errors0,exit0**; raw `storage/framework/testing/dtm-db-e12d92a7-3ccb-490b-81c4-f41b68ab364c/ui-evidence.json` and `ui-browser.log`; ledger `review-redirect-ui-health-final.log`. No resource suppression, font replacement, network-error filtering or retry was introduced; the earlier unlocalized timeout remains a failed candidate, not attributed to a specific resource without evidence. The new actual protected-write ordering completed and verifies canonical response/displayed countries plus next manual same-set no-op preserving B's revision/set. Both stale forms before locale, explicit review/manual save, original RTL320/keyboard/search/removal/skip/reader and English checks also pass. Actual browser console/runtime errors are empty. Failed-request diagnostics retain four net::ERR_ABORTED local Sources integration-logo images (snapchat/tiktok/meta/google) during immediate scripted navigation back from Sources. These are navigation cancellations, not the earlier timeout; no diagnostic entries are discarded. All preview/browser/PostgreSQL processes stopped; synthetic sessions/data cleaned; primary0. Final fullJS115passed, actualcompiled17passed, standalone typecheck/build exit0; unchanged fullPHP716/8527 remains applicable. Final independent verdict is pending, with no further product changes planned.

## Final independent whole-patch review — PASS

Fresh independent GPT-6 Astra reviewer `/root/final_locale_revision_review`, read-only context without author implementation history. Reviewed all48 actual changed files (13tracked,35new), unchanged baseline/HEAD, approved spec/plan/bounded repair, retained raw PHP/JS/browser/race/durability/source/governance artifacts. Account usage interrupted its initial partial review; the completed continuation explicitly re-reviewed the final redirect correction and accepted raw16-check browser proof. The partial verdict was never credited as PASS.

Final assessment: **Ready for Owner patch review YES**; remainingCritical0,Important0,Minor0. No merge authorized. All four material findings closed: actual locale-remount draft loss; missing quote/brace adapter and protected-SQL proof; competitor-before-locale revision bypass in both forms; successful DTM post-commit/pre-redirect interleaving combining stale values/newer revision. The withdrawn analogous name/type candidate is not reported as a defect. Applicable causalRED/GREEN and retained final proof substantiate closure; no self-review substituted for the independent gate.

All five Review Focus conditions satisfied: malformed arrays fail atomically over populated snapshots; filtered saved countries remain removable; actual locale switches preserve codes/drafts/revisions without declaration mutation; independent stale name/type/DTM payloads conflict and cross-form/redirect-success paths preserve competing facts; actual grant/session expiry after waits denies42501 with complete effect rollback. Reviewer independently verified all21 race records, original writer equivalence except preparation guard, full716PHP/8527assertions, JS115, browser16, exact57 baseline Git blobs/33migrations, full58-table historical byte equality, F/P/N1 catalog/reference equality with distinct N1,249identities and label mappings/digests/sourcehashes, and all3 governance originals verbatim exactly once. Commercial test retains real wait/200/409/exactly-one assertions and original timeouts.

Reviewer limitations accepted within authorized scope: no persisted Business Location (caseC semantic only); deferred Goal/Audience/Market/Opportunity/ingestion/provider expansion/frameworks; no live provider availability/API calls; future reference administration/updates/freshness; independent runtime/primary deployment prohibited to reviewer; fresh upstream network authentication prohibited to reviewer (local source bytes/mappings/hashes reviewed); unrelated pre-existing architecture outside affected boundaries. No required DTM behavior was silently dropped. No minor findings deferred. The four aborted local logo requests remain honestly retained in final diagnostics; earlier failed candidates remainFAIL.

## Current final Owner handoff

```text
BASELINE = 8ebd9a1f1ffd7b23307d277304203c706e67d95d
HEAD = 8ebd9a1f1ffd7b23307d277304203c706e67d95d
BRANCH = codex/decision-context-discovery-001
WORKTREE = C:\dev\builderad-worktrees\decision-context-discovery-001
EXECUTION_METHOD = NATIVE
SPEC = APPROVED
PLAN = APPROVED
IMPLEMENTATION_TASKS_COMPLETED = 13/13
DECLARED_TARGET_MARKETS = IMPLEMENTED_AND_PROVEN
BUSINESS_TYPE_FOUNDATION = UNCHANGED
SOURCE_GUIDANCE = UNCHANGED
DTM_SEMANTIC_OWNER = BUSINESS_CONTEXT
PHYSICAL_BUSINESS_IDENTITY = WORKSPACE
COUNTRY_REFERENCE = NEUTRAL_ISO_REFERENCE
IDENTITY_SOURCE = Debian iso-codes ISO3166-1, redistributed unchanged by pycountry
IDENTITY_SNAPSHOT_OR_REVISION = pycountry@4c6a69927a25326a69bf753671fb571bb437cd7d,2026-02-18,retrieved2026-10-04
IDENTITY_COUNT = 249
IDENTITY_DIGEST = 801ef127f0b3e6b4e971c239c9b8475caedb65c17573d84ca1b57eed72523a0e
ENGLISH_LABEL_SOURCE = Unicode CLDR cldr-localenames-full main/en/territories.json
ENGLISH_LABEL_REVISION = cldr-json@91c267402229a59e3ef2774544f001bf959e8809,48.2.3,2026-09-30,retrieved2026-10-04
ARABIC_LABEL_SOURCE = Unicode CLDR cldr-localenames-full main/ar/territories.json
ARABIC_LABEL_REVISION = cldr-json@91c267402229a59e3ef2774544f001bf959e8809,48.2.3,2026-09-30,retrieved2026-10-04
LOCALIZATION_DIGEST_OR_EQUIVALENT = 264bcc1dfb5267520bc0a423e556e8ed07f976a66a46104905310b353d20557a
TARGET_MARKET_AUTHORITY = existing exact current business.context.manage plus signed session/membership/Workspace actor checks
EMPTY_SET_SEMANTICS = NOT_DECLARED
PERSISTED_DECLARED_BOOLEAN = NO
MULTI_MARKET = PASS
NO_LOCATION_REQUIRED = PASS
NO_FABRICATED_DEFAULTS = PASS
NO_DECLARED_INTENT_OVERWRITE = PASS
NO_COMMERCIAL_COUPLING = PASS
NO_PROVIDER_CAMPAIGN_GEO_COUPLING = PASS
NO_PARALLEL_BUSINESS_PROFILE = PASS
NO_NEW_AUTHORITY_MODEL = PASS
NO_GENERIC_CONTEXT_FRAMEWORK = PASS
A_Q = COMPLETE,caseC within approved semantic limitation
AUTHORITY_PROOF = PASS
CONCURRENCY_PROOF = PASS
LABEL_ONLY_PRESERVATION = PASS
EMPTY_TO_HEAD = PASS
EXACT_POPULATED_BASELINE_TO_HEAD = PASS
REPEATED_RESET = PASS,two guarded resets
N1 = PASS,independently initialized cluster/token/system identifier
ARABIC_RTL_MOBILE = PASS,320px
FULL_PHP_REGRESSION = 716passed,8527assertions,failures0,errors0,skips0,warninglines0,exit0
FULL_JS_REGRESSION = 115passed,failures0,skips0,cancellations0,exit0
ACTUAL_COMPILED_DTM_INTERACTIONS = 17passed,exit0
FINAL_REAL_BROWSER = 16checksPASS,console/runtimeerrors0,exit0
FINAL_BROWSER_NAVIGATION_CANCELLATIONS = 4localSourceslogoERR_ABORTED,retained
BUILD = PASS
TYPECHECK = PASS
PHP_SYNTAX = PASS,18actualchangedfiles
PINT = PASS,entiregate
DEPENDENCY_AUDITS = PASS,Composerstrictvalidate/noadvisories,npm0vulnerabilities,unchangedlockfiles
INDEPENDENT_FINAL_REVIEW = PASS
MATERIAL_REVIEW_FINDINGS = 4ImportantP2findingsFIXED_AND_REVIEWED,remaining0
PRIMARY_BUILDERAD_CONNECTIONS = 0
PRIMARY_BUILDERAD_WRITES = 0
TASK_PROCESSES_LEFT_RUNNING = 0
GOAL_IMPLEMENTED = NO
AUDIENCE_IMPLEMENTED = NO
MARKET_OPPORTUNITY_IMPLEMENTED = NO
GENERIC_MARKET_FRAMEWORK_CREATED = NO
GENERIC_EVIDENCE_FRAMEWORK_CREATED = NO
NEW_DATA_SOURCE_INGESTION_STARTED = NO
MARKET_GOVERNANCE_RECORDED = YES
EXTERNAL_PROVIDER_GOVERNANCE_RECORDED = YES
CORE_GOVERNANCE_RELATIONSHIP = EXTENDS_EXISTING_CORE_GOVERNANCE
CANONICAL_GOVERNANCE_LOCATION = C:\dev\builderad-worktrees\decision-context-discovery-001\docs\BUILDERAD_CORE_EXECUTION_GOVERNANCE.md
CURRENT_DTM_SCOPE_CHANGED = NO
LOCK_ORDER_REPAIR = PROVEN
SAFE_TO_RESUME_APPROVED_PLAN = YES,already resumed and completed
HISTORICAL_MIGRATIONS_EDITED = NO
SHARED_ACTOR_SEMANTICS_CHANGED = NO
BUSINESS_CONTEXT_AUTHORITY_SEMANTICS_CHANGED = NO
BUSINESS_CONTEXT_VERSION_SEMANTICS_CHANGED = NO
AUDIT_SEMANTICS_CHANGED = NO
NAME_TYPE_WRITER_EQUIVALENCE = PASS
DEADLOCK_40P01_PRESENT = NO,in repaired required proofs
AFFECTED_LOCK_REPAIR_GATE = 78passed,543assertions,failures0,errors0,skips0,exit0
COMMIT = NO
PUSH = NO
MERGE = NO
SAFE_FOR_OWNER_PATCH_REVIEW = YES
CURRENT_STATE = COMPLETE_PENDING_OWNER_PATCH_REVIEW
```

The exact48-file review inventory, per-command/raw-path results, fullA–Q matrix, distinct provenance/durability proof, failed candidates, bounded deviations and all14 execution rulings/costs are recorded above and in the retained ledger. The only production repair beyond DTM is explicitly approved bounded Foundation lock ordering; the Commercial readiness correction is test-only and the UI corrections fix actual reviewed DTM behavior. Final PHP/SQL artifacts did not change after the accepted full716-test gate; latest Vue changes have fresh115JS/type/build/16-browser evidence. All3 governance directives are future-only and recorded once in one canonical authority. No integration or publish action is taken; the complete uncommitted patch remains in the authorized worktree for Owner review.
