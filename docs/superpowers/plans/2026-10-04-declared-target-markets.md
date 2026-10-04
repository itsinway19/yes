# Declared Target Markets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement optional Business-level declared country markets through the existing Business Context boundary, with actual semantic, authority, concurrency and installation proof.

**Architecture:** Business Context owns one current relational country set attached to the existing Workspace identity. Neutral ISO identity/reference labels remain separate from Commercial and provider evidence. A protected set-replacement writer shares the current context revision and records bounded DTM transitions; reads derive declaration state from current rows.

**Tech Stack:** Existing Laravel 13 / PHP `>=8.5.0 <8.6.0`, PostgreSQL 18, Vue 3 / Inertia / TypeScript, Node `>=24 <25`, locked Composer/npm dependencies, existing PowerShell disposable-database mechanisms.

**Spec:** [Approved architectural specification](../specs/2026-10-04-declared-target-markets-design.md), all 14 sections including the final identity/localization provenance clarification. Final Owner approval is recorded in the Implementation Plan request. Read the spec and this plan together; the spec governs semantics.

## Global Constraints

- Original planning-only handoff is historical. Owner subsequently approved Native execution and bounded lock-order repair; checked steps record execution, and unchecked steps remain incomplete.
- Baseline `8ebd9a1f1ffd7b23307d277304203c706e67d95d`; branch `codex/decision-context-discovery-001`; worktree `C:\dev\builderad-worktrees\decision-context-discovery-001`. Reuse this worktree; no reset or discarded changes.
- No commit, push or merge, including task checkpoints: the Owner overrides the skill's frequent-commit default.
- `PRIMARY_BUILDERAD_CONNECTIONS = 0`; `PRIMARY_BUILDERAD_WRITES = 0`. No primary connection even for diagnostics. No bare Composer setup or unguarded Artisan migration command.
- All database fixtures, migrations and verification run inside independently guarded task-owned disposable PostgreSQL environments. Track and stop every task-started process; start background helpers hidden on Windows.
- Preserve accepted Business Foundation, Business Type, Source Guidance, WorkspaceContext and authority semantics. Exact existing `business.context.manage`, not owner-only or internal `business.manage`.
- Current truth: `business_target_markets`; physical Business: Workspace; cardinality 0..N. Zero rows = `NOT_DECLARED / UNKNOWN`; no persisted declaration boolean.
- Country identity uses ISO 3166-1 alpha-2 membership from an accepted snapshot. Identity and Arabic/English localization provenance/evidence are independent. No runtime network lookup or Commercial availability filter.
- Preserve atomic set replacement, authorized same-set no-op before stale-change rejection, one shared `business_context_version`, and one DTM event per actual change.
- Country question: "أين تريد الحصول على عملائك؟"; supporting copy: "اختر الدول التي تريد الحصول منها على عملائك أو نتائجك." Arabic/RTL/mobile, 320px no-overflow and accessible interaction are required.
- No Business Location, Goal, Audience, Outcome/Conversion Path, economics, capacity, sales cycle, qualification, seasonality or market opportunity implementation. No scoring/recommendations, country administration, generic framework, EAV, rule DSL, polymorphic scope or new authority model.
- The preflight ran zero tests due to missing Composer dependencies. It is not PASS. Focused green cannot replace failed/skipped full required proof.
- If actual evidence exposes a material contradiction requiring architecture/authority changes, stop and present the smallest exact Owner decision. Do not silently change the approved contracts.

## Review Focus

These five implied failure conditions are pinned to the named task tests below:

1. PostgreSQL array transport containing quotes/braces, NULL elements or multidimensional input must fail safely without changing the set (Task 4, `test_malformed_sql_arrays_are_rejected_atomically`).
2. A saved country filtered out by a later search must remain selected and removable (Task 7, `selected_values_survive_search_filtering`).
3. A locale switch changes labels, never canonical submitted codes or the saved declaration (Tasks 5/7, `test_locale_change_preserves_codes`, `locale_switch_preserves_draft_codes`).
4. Name/type Save and DTM Save from two stale tabs must conflict safely and must not post each other's draft (Tasks 4/7, mixed-writer race and `independent_forms_preserve_unrelated_drafts`).
5. A grant that expires while the transaction waits must not produce an effect even if it was valid on entry (Tasks 4/9, `test_authority_expiry_after_wait_rolls_back`).

## File map and command conventions

Paths below are relative to the fixed worktree above. These are planned surfaces, not changes authorized during planning.

| Surface | Responsibility |
| --- | --- |
| `database/reference/countries/identities.json`, `labels.json`, `provenance.json`, `README.md`, `sources/` | Versioned identity membership, labels, separate provenance, deterministic serialization rules and accepted source artifacts/license evidence. |
| `database/migrations/0001_01_01_000330_create_declared_target_markets.php` | One new additive forward migration, after baseline `000320`; no changes to earlier migrations. |
| `database/sql/declared_target_markets.sql` | DTM/reference DDL, RLS/grants, audit protection and protected writer. |
| `app/Business/BusinessContext.php` | Country options, dedicated replacement adapter and additive canonical projection. |
| `app/Http/Controllers/BusinessContextController.php`, `routes/web.php` | Existing controller action/edit projection and dedicated scoped mutation route. |
| `resources/js/Components/Business/TargetMarketsEditor.vue` | Focused country multi-select/editor and read-only state within current context flow. |
| `resources/js/Pages/Business/Context.vue`, `Sources.vue`, `resources/js/localization/business.ts` | Existing surface integration, additive projection typing/summary, DTM copy. |
| `tests/Feature/DeclaredTargetMarketsTest.php`, `DeclaredTargetMarketsDatabaseTest.php`, `DeclaredTargetMarketsConcurrencyTest.php`, `DeclaredTargetMarketsSecurityTest.php`, `CountryReferenceProvenanceTest.php` | HTTP/domain, SQL integrity, real races, security/non-overwrite, label-preservation proof respectively. |
| `tests/TargetMarketsCommandWorker.php` | Test-only independent runtime participant; never a production command. |
| `tests/country-reference.test.mjs`, `target-markets-interaction.test.mjs`, `package.json` | Dataset reproduction and rendered Vue interaction; add suites to existing `npm test` without removing existing suites/dependency changes. |
| `tests/Support/DeclaredTargetMarketsDurabilityProof.php` | Guarded exact-baseline/HEAD catalog and populated-history proof; preserve existing historical `MigrationDurabilityProof.php`. |
| `scripts/Test-DeclaredTargetMarkets.ps1` | Focused task-specific extension of the existing isolated provisioning pattern, with explicit phase selection, logs and guaranteed cleanup. |
| `docs/decision-context-foundation-001-implementation-proof.md` | Future exact executed evidence/handoff, not an assertion of planned tests passing. |

Inspect/reuse `scripts/builderad.ps1`, `scripts/Test-BusinessFoundation.ps1`, `tests/Support/DisposableDatabaseReset.php`, `tests/TestCase.php`, the disposable guard/bootstrap SQL, existing Business Foundation tests and `tests/SourceCommandWorker.php`/`CommercialMarketRecordingWorker.php`. Change those existing harness/core authority files only if a demonstrated DTM integration need exists; this plan requires no core authority redesign.

All tool commands below run from the fixed worktree using `pwsh -NoProfile -File scripts/builderad.ps1`. The new test wrapper supports `-Phase Focused|Durability|Full|UI`, `-Filter` for Focused, and `-Baseline` fixed to the authoritative SHA for Durability. It creates the guarded environment before booting Laravel and runs commands in that same environment. Full runs `builderad.ps1 test --compact --display-warnings --log-junit=<run-local-results.xml>` without `--filter`. Durability runs `builderad.ps1 php tests/Support/DeclaredTargetMarketsDurabilityProof.php` only after the guard succeeds. UI starts an isolated local preview bound to its disposable fixtures and stops it in `finally`; no primary configuration is loaded.

## Task 1: Execution Environment

**Purpose / invariant:** Restore locked tools/dependencies and establish safe test execution; no primary connection or upgrade.

**Dependencies:** Owner plan approval and execution-method selection. **Files:** inspect `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `scripts/builderad.ps1`, `scripts/Test-BusinessFoundation.ps1`, `config/database.php`, disposable guard/bootstrap; create the task-specific wrapper above and run-local evidence directories only.

**Interfaces:** Produces the guarded wrapper phases/parameters specified above; installed locked dependencies; fresh per-run isolation token and explicit test connections. No environment variables are inherited as trusted primary defaults.

- [x] Check worktree/branch/HEAD and expected documentation-only changes; record both lockfile hashes and runtime versions via `builderad.ps1 preflight`. Existing unrelated material changes or wrong baseline require reporting, not reset.
- [x] Run `builderad.ps1 composer install --no-interaction --prefer-dist --no-progress --no-scripts` and `builderad.ps1 npm ci`. Never run update, change dependency constraints or create a replacement lockfile. Require installed `vendor/autoload.php` and unchanged lock hashes.
- [x] Create `scripts/Test-DeclaredTargetMarkets.ps1` by following the existing task-owned cluster/bootstrap/guard pattern. Require free checked port, workspace-contained absolute data path, fresh isolation marker, explicit app/owner/authority test connections, synthetic app key and nonprimary cache/session setup before package discovery or Artisan. Bootstrap may create its existing named databases only inside that new cluster; never connect to an existing primary cluster. Wrap start/run/stop in `try/finally`, capture failures, stop workers first and clean only checked task-owned paths.
- [x] Inside the guarded wrapper run `builderad.ps1 php artisan package:discover --ansi`, then `-Phase Focused -Filter BusinessFoundationTest`; retain actual counts and stop/cleanup evidence. Also expose Full, Durability and UI command phases without running them prematurely.

**Completion:** Locked installs and focused baseline checks actually pass in isolation; guard failures stop before tests/migrations. No ceremonial red test for package installation. Missing tools/network permission is setup failure, never test PASS.

## Task 2: Country Reference Dataset

**Purpose / invariant:** Produce a neutral reference with independently reproducible identity and localization provenance.

**Dependencies:** 1. **Files:** create the reference artifacts and `tests/country-reference.test.mjs`; inspect the spec section 4, existing Commercial catalog only to exclude its ownership, and `package.json` for suite inclusion.

**Interfaces:** `identities.json` is a code-sorted array of uppercase strings; `labels.json` is code-sorted objects with fixed fields `code,name_en,name_ar`; `provenance.json` records the nine required fields below plus raw-source hashes, normalization/digest rules and retrieval/license evidence. These are installation artifacts, never runtime remote lookups.

- [x] Add Node tests `identity_membership_matches_source_snapshot`, `labels_match_accepted_sources`, `identity_digest_excludes_labels`, `noncountry_codes_are_rejected`, asserting exact source-extracted code equality, unique complete label coverage, nonempty labels and independently recomputed digest equality. Run `builderad.ps1 npm exec -- node --test tests/country-reference.test.mjs`. Expected red: required artifacts absent, not arbitrary guessed country counts.
- [x] Select and verify actual accepted source(s) during implementation: the identity source must establish officially assigned ISO membership; Arabic/English names must have traceable accepted localization provenance. Record `IDENTITY_SOURCE`, `IDENTITY_SNAPSHOT_OR_REVISION`, `IDENTITY_COUNT`, `IDENTITY_DIGEST`, `ENGLISH_LABEL_SOURCE`, `ENGLISH_LABEL_REVISION`, `ARABIC_LABEL_SOURCE`, `ARABIC_LABEL_REVISION`, `LOCALIZATION_DIGEST_OR_EQUIVALENT`. Pin revisions/dates and references; explicitly state if English identity/display naming share a source. Do not invent Arabic names or imply unsupported ISO authority. If source use/coverage cannot be verified, stop this task before a dependent migration.
- [x] Make identity digest SHA-256 over sorted unique codes joined by LF with one final LF, UTF-8 without BOM. Membership is source-controlled, with no label-based inclusion. Make localization digest SHA-256 over UTF-8 compact JSON of sorted records, fixed key order `code,name_en,name_ar`, with one final LF; normalize source display labels to Unicode NFC, preserving spelling/diacritics, and document any necessary source mapping. No undocumented fallback. Hash original source bytes separately so normalization does not hide provenance.
- [x] Check in accepted source artifacts where permitted and source-specific deterministic extraction/mapping instructions in README. Source restrictions must still permit reproducible/traceable pinned evidence; no unlicensed redistribution. Run the focused Node suite and record exact codes/count/digests, exclusion of GCC/Gulf/MENA/user-assigned codes and complete label provenance. Add that suite to `npm test`.

**Completion:** Actual sources/revisions/count/hashes replace all source-selection obligations in the future proof report; reproducing identities does not require interpreting label membership. No source is selected or downloaded during the present planning task.

Test oracles for Task 2, using `sourceCodes` extracted from the pinned accepted identity snapshot and `labelRows` parsed from the pinned label artifact:

```js
assert.deepEqual(identityCodes, [...new Set(sourceCodes)].sort());
assert.deepEqual(labelRows.map(row => row.code), identityCodes);
assert.equal(recomputedIdentityDigest, provenance.IDENTITY_DIGEST);
assert.equal(recomputedLocalizationDigest, provenance.LOCALIZATION_DIGEST_OR_EQUIVALENT);
assert.equal(identityCodes.includes('GCC'), false);
```

## Task 3: Additive Migration / Database Model

**Purpose / invariant:** Enforce relational integrity and signed-scope read isolation with no inferred/backfilled truth.

**Dependencies:** 1–2. **Files:** create `0001_01_01_000330_create_declared_target_markets.php`, `database/sql/declared_target_markets.sql`, `DeclaredTargetMarketsDatabaseTest.php`; inspect baseline migration `000270`/`000320`, existing immutable trigger and role conventions, `tests/TestCase.php` reset behavior.

**Interfaces:** Tables/fields exactly as approved spec section 5. Migration reads local accepted artifacts, installs reference rows/SQL and preserves prior migrations; `down(): void` rejects destructive rollback under the established forward-repair convention.

- [x] Add `test_valid_country_fk_and_unique_business_country`, `test_runtime_cannot_write_reference_or_relations`, `test_empty_install_has_no_declarations`, `test_audit_is_immutable`. Focused command: `Test-DeclaredTargetMarkets.ps1 -Phase Focused -Filter DeclaredTargetMarketsDatabaseTest`. Expected red: new tables absent; record that causal failure before applying the new migration.
- [x] Define `country_references(code,name_ar,name_en)` with code PK/uppercase shape and nonempty label checks. Define relation composite PK and restrictive Workspace/country FKs. Define bounded event identity PK, restrictive Workspace/user FKs, positive shared `version`, unique Workspace/version, before/after text arrays, timestamp, nonnull sets/elements and different-set checks. Writer canonicalization enforces sorted unique valid audit codes; relation membership itself is enforced by FK. No rank/boolean/extra owner column. Use the relation PK for scoped lookups; add no redundant index without evidence.
- [x] Establish migration-role ownership; enable/FORCE RLS for Business-owned tables; use established owner policy and signed Workspace-phase SELECT policy. Grant neutral reference SELECT and appropriate scoped app SELECT; revoke PUBLIC/app/authority direct DML/TRUNCATE and unrelated execution. Protect audit UPDATE/DELETE with the existing immutable trigger convention, including privileged application paths; reference labels are not made immutable by this audit trigger.
- [x] Install reference rows only, with zero DTM rows/events and unchanged old revisions. Run the focused suite on an empty disposable install; verify actual constraints, table owners, privileges and FORCE RLS from PostgreSQL catalog, not string matching alone.

**Completion:** DDL and installation pass with duplicate/FK denials and read isolation. No baseline migration/SQL rewrite or fabricated historical declaration. The writer body is completed in Task 4 in the same new SQL/migration payload before final durability runs.

## Task 4: Protected DTM Writer

**Purpose / invariant:** Authorized, atomic, deterministic set replacement with shared revision and real effect-time proof.

**Dependencies:** 3. **Files:** extend only new `declared_target_markets.sql`; create concurrency suite and `tests/TargetMarketsCommandWorker.php`; extend database tests. Inspect existing `builderad_source_actor`, `builderad_business_context_write`, signed selectors and existing real worker barriers, without changing their semantics.

**Interfaces:** `builderad_business_target_markets_write(bigint,text,text[],bigint) RETURNS bigint`; consumes selected signed Workspace, human session hash, code list and expected revision. Worker consumes a run-local fixture file with actor/session/Workspace/operation/input/barrier fields; emits backend PID, resulting revision or exact SQLSTATE and exit status. It asserts disposable safety before any DB work and uses separate real connections/processes, no mocked transactions.

- [x] Add meaningful red SQL tests: unknown/NULL/multidimensional/malformed arrays rejected atomically; duplicates/reordering canonicalized; unauthorized identical no-op denied; same-set stale retry returns current revision with no event; stale different set raises `40001`; final removal yields no rows. Define names `test_malformed_sql_arrays_are_rejected_atomically`, `test_same_set_stale_retry_is_noop`, `test_stale_different_set_rolls_back`, `test_last_removal_returns_unknown`. Run Focused filter `DeclaredTargetMarketsDatabaseTest|DeclaredTargetMarketsConcurrencyTest` and retain the missing-writer red cause.
- [x] Implement the approved sequence: existing signed human/Workspace proof; Workspace serialization lock; exact current management grant with generation/status/revocation/expiry and existing membership/session/owner checks; validate/canonicalize codes; authorized equality no-op; expected-revision check only for different set; atomic replacement; shared revision +1; exactly one canonical before/after audit event; existing actor/grant revalidation after waits and before effect return. No alternate authority resolver, owner-only rule, grant issuance or automatic overwrite retry. No-op does not touch timestamps/revisions/events.
- [x] Implement SQL array transport with parameter binding, never interpolated code literals; explicitly reject malformed dimensions/NULL values and cap submitted cardinality to reference count. PHP transport will reuse this contract in Task 5. Keep SQL validation independently effective for direct protected calls.
- [x] Build real race tests `test_concurrent_equal_replacements`, `test_concurrent_different_replacements`, `test_concurrent_name_type_and_dtm`, and `test_authority_expiry_after_wait_rolls_back`. Parent uses run-specific barriers and an independent blocker; workers publish PIDs; assert PostgreSQL blocking/overlap via `pg_stat_activity`/`pg_blocking_pids` under permitted test roles before releasing the blocker. Use bounded deadlines, never an arbitrary sleep as proof. Clean every worker on timeout/failure.
- [x] For equal same-revision races require one change/event/revision increment and both workers returning that revision. For different races require one success and one `40001`, with one entire winning set/event. For mixed writers require one winning effect, loser stale conflict, no unintended fields changed; test both schedules. Add a synchronized simultaneous-entry schedule, not only a schedule where writer 1 already owns the row lock.
- [x] Exercise revocation committed before the worker's authorization lock is acquired; it must deny effects after release. Exercise grant/session expiry during a confirmed lock wait and effect-time expiry using existing lock/barrier patterns. A revocation blocked behind an already-held authority lock linearizes after that writer; do not falsely demand time travel or report that as unauthorized access. On denial compare set, revision and audit byte-for-byte to before.
- [x] Run Focused concurrency/database suites. Detect `40P01`/lock timeouts explicitly: they are not the approved `40001` conflict or a passing equal-set replay. The current actor helper takes Workspace `FOR SHARE` before the existing writer's `FOR UPDATE`; inspect actual lock conversion in these races. If satisfying the accepted sequence would require changing canonical authority/locking behavior, stop with exact evidence and the smallest Owner decision rather than altering the foundation or hiding failures with retries.

**Completion:** All SQL/race outcomes above actually pass; no partial failure side effects, unsigned/service path or hidden deadlock treated as success. Existing name/type writer remains unchanged.

Test oracles for Task 4: start from revision `$v` and `$before` snapshot; `$after` captures `codes`, `version` and event count after the actual call/race. Each losing/denied operation additionally compares its full state snapshot. Equal-set workers expose both returned revisions:

```php
// Authorized identical/reordered replay of the existing AE, KW, SA set.
$this->assertSame(['AE', 'KW', 'SA'], $after['codes']);
$this->assertSame($before['version'], $after['version']);
$this->assertSame($before['event_count'], $after['event_count']);
// Equal concurrent replacements from an initially different set.
$this->assertSame([$v + 1, $v + 1], $workerRevisions);
$this->assertSame($before['event_count'] + 1, $raceAfter['event_count']);
// Different concurrent replacements: classify actual PostgreSQL results.
$this->assertSame(1, count(array_filter($sqlStates, fn ($s) => $s === '40001')));
$this->assertNotContains('40P01', $sqlStates);
```

## Task 5: Business Context Domain / Read Model

**Purpose / invariant:** Expose one canonical declaration meaning through existing domain boundaries.

**Dependencies:** 4. **Files:** modify `app/Business/BusinessContext.php`; create `DeclaredTargetMarketsTest.php`; inspect existing Workspace projection consumer.

**Interfaces:** Add `countries(): array` returning code-sorted `{code,name_ar,name_en}` references; `replaceTargetMarkets(Request $request, Workspace $workspace, array $codes, int $version): int` calls the protected SQL writer and returns its revision. Existing `projection(Request,Workspace): array` adds `targetMarketsDeclared: bool` and `targetMarkets: array`; existing `type/version/canManage` retain meaning. No country registry class or cached declaration flag.

- [x] Add red domain assertions `test_empty_projection_is_not_declared`, `test_projection_round_trips_canonical_codes`, `test_locale_change_preserves_codes`: false/[] for none; `AE,KW,SA` sorted objects/true for saved three; code membership unchanged across Arabic/English projections. Use the established guarded transaction and signed fixture, not bypassed default connections.
- [x] Implement those three interfaces using FK-backed relation/reference reads in the selected Workspace; derive the boolean only on the server from scoped persisted rows. Add parameter-safe typed array adapter to the Task 4 contract. Preserve original `write`, `guidance`, `types` and authority resolution.
- [x] Run Focused `DeclaredTargetMarketsTest|BusinessFoundationTest`; assert name/type writes neither clear nor regenerate saved markets and inactive-type/guidance semantics remain intact.

**Completion:** One domain projection owns declaration semantics, localized metadata is separate from identity, and original Foundation assertions remain green.

## Task 6: HTTP Mutation Boundary

**Purpose / invariant:** Dedicated explicit advertiser mutation follows existing controller and Workspace scope.

**Dependencies:** 5. **Files:** modify `BusinessContextController.php`, `routes/web.php`; extend `DeclaredTargetMarketsTest.php`; inspect current validation/error/redirect conventions only.

**Interfaces:** Add `updateTargetMarkets(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): RedirectResponse`; route `POST /workspaces/{workspace}/business/target-markets`, name `workspaces.business.target-markets.update`. Edit GET adds canonical `business` projection and `countryOptions`; creation remains unchanged.

- [x] Add red HTTP tests `test_missing_null_and_object_codes_are_rejected`, `test_empty_list_is_an_explicit_removal`, `test_foreign_workspace_and_wrong_grant_are_forbidden`, `test_mutation_refreshes_context_revision`, including same-set denied calls and invalid `version`. Require error fields `country_codes`/`version`, never `business_type_id` for DTM errors.
- [x] Add the action in the existing controller and route group with existing current-principal/session/CSRF/UUID semantics. Validate a present list including `[]` (Laravel `present` + `array` + list validation; do not use `required` semantics that reject empty arrays), string uppercase reference membership, maximum reference cardinality, required integer nonnegative version. Deduplicate valid codes in the adapter/writer, not through a validation rule that contradicts accepted duplicate requests. Ignore no foreign actor/Workspace authority input.
- [x] Resolve via `WorkspaceContext::run`, invoke `replaceTargetMarkets`, and map `42501` to forbidden, invalid-code/shape/integrity failures to `country_codes`, `40001` to localized `version` reload/review error. Keep unrelated unexpected exceptions visible. Success redirects to existing context edit GET and refreshes canonical revision/set. Preserve current name/type redirects and safe destination behavior.
- [x] Run Focused `DeclaredTargetMarketsTest|BusinessFoundationTest`, including active nonowner manager, owner missing grant, revoked membership/session and nonmember foreign Business.

**Completion:** Dedicated operation works without a new controller/authority abstraction, tri-state, onboarding requirement or silent overwrite.

## Task 7: Advertiser UX

**Purpose / invariant:** Optional usable DTM inside existing Business Context, Arabic/RTL/mobile first-class.

**Dependencies:** 6. **Files:** create `TargetMarketsEditor.vue`, `target-markets-interaction.test.mjs`; modify current Context/Sources pages, business copy and `package.json`; inspect existing native compiled Vue interaction tests and advertiser styles/layout.

**Interfaces:** Editor props: `workspaceId: string`, `business: {targetMarketsDeclared:boolean,targetMarkets:Country[],version:number,canManage:boolean}`, `countryOptions: Country[]`, with `Country = {code:string,name_ar:string,name_en:string}`. Uses its own form `{country_codes:string[],version:number}`; submits only the Task 6 route. Names and type remain in their existing separate form. No persisted local draft/semantic boolean.

- [x] Add rendered-component tests for search in both languages/code, multi-select/removal, `selected_values_survive_search_filtering`, `locale_switch_preserves_draft_codes`, `independent_forms_preserve_unrelated_drafts`, `skip_never_posts_or_clears_saved_set`, `readonly_members_cannot_submit`, and server-error draft preservation. Compile/mount the real Vue handlers using existing test patterns; transport/navigation alone are doubles. Assert submitted codes/version and absence of unintended calls, not merely source strings. Run `builderad.ps1 npm exec -- node --test tests/target-markets-interaction.test.mjs`; red is absent editor/interaction behavior.
- [x] Implement the editor within persisted-Business Context flow; search native accessible checkbox/list options, keep selected values visible outside filtering, named remove controls, explicit DTM Save and "ليس الآن" navigation without a POST. Unknown presentation consumes `targetMarketsDeclared`, not frontend-derived Business meaning. Read-only members see saved/unknown context. Do not repurpose existing `skip` localization key (it means skip-to-content); add DTM-specific copy.
- [x] Integrate existing Context and Sources projection typings/summary. Show no DTM creation wizard or mandatory editor. Refresh authoritative version after saves while retaining unrelated unsaved form values; on conflict keep draft and offer reload/review, never auto-repost it. Add the DTM suite to existing `npm test` without losing prior suites.
- [x] Run Node interaction/localization suites, then `Test-DeclaredTargetMarkets.ps1 -Phase UI`. With task-owned preview fixtures, record Arabic/RTL and English keyboard/search/select/remove/save/skip/read-only/error behavior. At 320px assert document scroll width does not exceed viewport width, chips wrap and focus/labels/status alerts work. Browser inspection is required for actual layout; custom-renderer tests alone do not prove overflow. Stop preview/browser helpers in `finally`.

**Completion:** Real rendered flows and 320px evidence pass; no auto-selection, country-card wall, Commercial options, provider targeting language or hidden primary usage.

## Task 8: Semantic Acceptance A–Q

**Purpose / invariant:** Make every approved case independently visible in the evidence matrix.

**Dependencies:** 3–7. **Files:** the named DTM feature/SQL/concurrency/interaction suites; future implementation proof report. Use tests already added; add missing meaningful assertions only.

**Interfaces:** Evidence table fields are CASE, TEST/PROOF, FILES, EXPECTED RESULT, later actual command/count/result. Aliases in this matrix: HTTP = `tests/Feature/DeclaredTargetMarketsTest.php`; SQL = `tests/Feature/DeclaredTargetMarketsDatabaseTest.php`; RACE = `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php`; SEC = `tests/Feature/DeclaredTargetMarketsSecurityTest.php`; UI = `tests/target-markets-interaction.test.mjs`; DUR = `tests/Support/DeclaredTargetMarketsDurabilityProof.php`.

| CASE | TEST/PROOF | FILES | EXPECTED RESULT |
| --- | --- | --- | --- |
| A | `test_zero_markets_business_remains_valid` | HTTP | Valid creation/access, no rows, projection false/empty. |
| B | `test_no_location_required_for_sa` | HTTP | SA round-trips without location input or stored fabrication. |
| C | `test_jordan_location_example_keeps_independent_meaning` plus bounded dependency inspection | HTTP; proof report | Explicitly test-only independent JO scenario and SA declaration; writer has no location dependency. No invented production field or false persisted-location PASS. |
| D | `test_three_markets_round_trip` | HTTP | Exactly AE, KW, SA, unique sorted codes. |
| E | `test_duplicate_business_country_is_impossible` | SQL; HTTP | PK denial for raw duplicate; submitted duplicate codes collapse. |
| F | `test_cross_workspace_mutation_denied` | HTTP; SEC | Foreign scope/forged owner has zero effects. |
| G | `test_unauthorized_identical_set_is_denied` plus authority failure cases | HTTP; SEC | Current exact grant/session/membership required even for no-op. |
| H | `test_authorized_nonowner_manager_can_edit` | HTTP; SEC | Existing explicit manager works; owner-only assumption absent. |
| I | `test_removal_preserves_unrelated_facts` | HTTP; SEC | Only DTM/audit/context metadata change; source/type/name/Commercial snapshots unchanged; location limitation recorded. |
| J | `test_commercial_country_never_populates_or_overwrites` | SEC | Real accepted Commercial operation leaves empty and populated DTM unchanged. |
| K | `test_provider_geography_never_populates_or_overwrites` | SEC | Existing report/import/callback paths with synthetic geography leave DTM unchanged. |
| L | `populated_exact_baseline_forward_preserves_history` | DUR | Zero fabricated rows/events and unchanged prior revisions/historical facts. |
| M | `test_canonical_country_round_trip_and_locale_independence` | HTTP; SQL | Canonical code identity survives reads/locale/removal; invalid regions/codes rejected. |
| N | Arabic/RTL interaction and 320px rendered proof | UI; proof report | Selection/search/save/skip/read-only/keyboard/error and overflow acceptance pass. |
| O | Same-set/reorder/duplicate/retry and equal/different/mixed races | SQL; RACE | Deterministic canonical result, no duplicate or no-op events, actual shared revision behavior. |
| P | `test_no_signal_or_service_can_overwrite_declared_intent` plus reference/grant inventory | SEC; proof report | Only explicit authorized writer can effect changes; current sync paths cannot overwrite. |
| Q | `test_empty_is_not_declared_not_explicit_none` | HTTP; SQL; UI | False/[] before declaration and after final removal; skip creates no declaration. |

- [x] Reconcile this matrix to named runnable assertions and add any absent cases before completion. For C use a test-only scenario variable for independent Jordan truth and demonstrate no API/SQL location dependency; label that as semantic proof, not integration of absent persisted Business Location. Do not infer location from Commercial/provider country or add location storage.
- [x] Run `Test-DeclaredTargetMarkets.ps1 -Phase Focused -Filter 'DeclaredTargetMarketsTest|DeclaredTargetMarketsDatabaseTest|DeclaredTargetMarketsConcurrencyTest'` and the exact Node interaction command. Fill actual evidence references/counts for each case; L awaits Task 11 and P awaits Task 9, with no premature PASS.

**Completion:** All 17 cases mapped and eventually evidenced; C's accepted limit is explicit. No aggregate green substitutes for an unexecuted required case.

## Task 9: Security / Non-Overwrite Proof

**Purpose / invariant:** No current nondeclaration path can own or overwrite intent.

**Dependencies:** 4–8. **Files:** create SEC; inspect only DTM references, actor/session/grant execution boundaries and relevant existing Commercial/provider observation/callback tests; update proof report. No provider/Commercial model change.

**Interfaces:** Signed human paths reuse existing grant fixtures; service/unsigned/raw-role probes use independent guarded connections. Test result includes SQLSTATE, denied effect snapshot and actual catalog ACL/RLS evidence.

- [x] Add red/green `test_direct_runtime_dml_and_unsigned_writer_denied`, `test_service_and_internal_management_cannot_declare`, `test_owner_without_exact_grant_is_denied`, `test_workspace_rls_hides_foreign_rows`, `test_revoked_expired_authority_cannot_commit`. Probe INSERT/UPDATE/DELETE/TRUNCATE and event/reference writes as app/unrelated roles, forged context, service context and an internal staff principal holding only `business.manage`; expect `42501` or relevant existing denial without effects. App execution privilege does not grant human authority.
- [x] Inspect `pg_proc` execution ACLs, owners/search path, PUBLIC grants, policies and FORCE RLS; ensure no unrelated role can execute and audit remains immutable. Test scoped read for authorized members and invisibility/denial outside selected scope. Database-owner install privileges are not application authority.
- [x] Inventory actual references with `rg -n 'business_target_markets|business_target_market_events|builderad_business_target_markets_write' app routes database resources tests scripts`, classify every executable write, and record each legitimate exception as test/migration-only. Inspect privileged application connections too; text absence alone is insufficient.
- [x] Exercise J/K/P with existing synthetic Commercial country/configuration mutation and provider/source/report/observation fixtures; snapshot DTM/revision/events before and after for both empty and populated declarations. Keep no default from Workspace currency/timezone, source account country or locale. Research/discovery implementations are absent: prove no actual callable writer/access path, not a fabricated recommendation service.
- [x] Run Focused `DeclaredTargetMarketsSecurityTest|BusinessFoundationTest|WorkspaceIsolationTest|ProviderCallbackIngressTest`, then existing relevant Commercial/provider tests in Task 13. Record effect-time race evidence from Task 4, including revocation-lock linearization.

**Completion:** Actual ACL/RLS, negative calls and current producer-path preservation support P/J/K/I; no new permission/resolver or sync write.

## Task 10: Provenance / Label Preservation Proof

**Purpose / invariant:** Labels never determine membership or rewrite intent/audit meaning.

**Dependencies:** 2–9. **Files:** create `CountryReferenceProvenanceTest.php`; extend dataset Node test and proof report; reference artifacts read only.

**Interfaces:** Independently recomputed identity/localization SHA-256 from Task 2 serialization; test-only label mutation through guarded existing migration/owner connection, no maintenance route/service.

- [x] Add `test_label_only_change_preserves_identity_and_intent`: save AE/KW/SA through authorized path, snapshot all codes, identity count/digest, DTM rows, audit events, revision and canonical code-based intent. Change only one Arabic/English reference display label in the disposable fixture; assert all snapshots except label metadata/localization digest are identical, UI labels reflect the correction and identity digest is unchanged. DTM audit interpretation remains the same code transition, not old display spelling.
- [x] Reproduce source-extracted identities and trace English/Arabic labels separately using pinned source revisions; assert precise accepted counts and stored/recomputed digests, not a magic expected count selected before sources. Require corrected fixture localization digest differs; restore test-only labels or reset guarded fixtures to prevent later suite contamination.
- [x] Run focused `CountryReferenceProvenanceTest` and `tests/country-reference.test.mjs`; record distinct provenance evidence and label-preservation output.

**Completion:** Identity reproduction, label traceability and controlled preservation actually pass with zero new runtime capabilities.

## Task 11: Exact-Baseline Forward Migration

**Purpose / invariant:** Prove populated authoritative main survives only the new DTM addition.

**Dependencies:** 3–10. **Files:** create DUR and its run-local baseline extraction/fixture artifacts; extend wrapper Durability phase; inspect existing durability proof/bootstrap. Preserve the old `000010` proof.

**Interfaces:** DUR consumes pinned baseline SHA, source-artifact root, guarded connection and output directory; emits catalog/provenance/history comparisons and exact populated-forward outcome. Never registers a runtime command.

- [x] Obtain baseline files read-only using `git archive --format=tar --output=<checked-run-local-path> 8ebd9a1f1ffd7b23307d277304203c706e67d95d database/migrations database/sql database/bootstrap`; extract only inside checked workspace storage. Record archive digest and full baseline-migration inventory/count as observed; verify extraction bytes against Git object evidence. Do not reset working files/create another worktree or accidentally include the new SQL through working-tree paths.
- [x] Install the extracted baseline into a fresh guarded disposable installation. In the test-only proof runner set the Laravel application's database path to the extracted `database` directory using `useDatabasePath`, then run migration with that absolute migration directory and `--realpath`. This makes `database_path('sql/...')` resolve to exact baseline SQL, never working-tree implementation SQL. Restore the original database path before the forward step, including on failure. Assert terminal migration `000320`, DTM tables absent and manifest matching the exact baseline files.
- [x] Populate valid representative current-main fixtures: classified Businesses with context revisions/events, active memberships/owner/grants/sessions, Business Types, Source Guidance, Commercial market/country/scope/configuration and provider source/capability/observation records. Use established valid fixture shapes and canonical operations where appropriate; no disabled constraints or writes to primary. Capture all historical rows with keys in deterministic order, including revisions and provider/Commercial values, plus source manifest.
- [x] Apply only the new `000330` forward migration from implementation files; assert prior migration inventory was not replayed. Compare every preserved historical value, not counts alone; assert zero DTM relation/event rows, unchanged existing context revisions, valid Businesses and false/empty read projection. Record exact preservation counts and nonfabrication outcome.
- [x] Run `Test-DeclaredTargetMarkets.ps1 -Phase Durability -Baseline 8ebd9a1f1ffd7b23307d277304203c706e67d95d`; retain source manifest, before/after snapshots and catalog for Task 12. A failed baseline install/forward proof remains FAIL.

**Completion:** Exact populated authoritative-baseline forward proof passes independently of the old historical proof. No primary connection, migration rewrite or reused later payload.

## Task 12: Fresh / Reset / N1 Durability

**Purpose / invariant:** Independent installation and reset reproduce authority, identity and localization.

**Dependencies:** 11. **Files:** DUR, wrapper, proof report; reuse protected DisposableDatabaseReset and guard without weakening them.

**Interfaces:** Three separately named phases/results: fresh F, populated-forward P and independent N1; each installation identity/marker and catalog captured. Two resets belong to F, never relabeled N1.

- [x] In an empty guarded installation F run all implementation migrations; capture seven catalog groups used by existing durability proof (tables/columns/constraints/indexes/policies/functions/triggers), relevant role/grant signatures, reference identity count/digest and localization digest/source revisions. Compare against P from Task 11, excluding fixture rows/sequence positions only.
- [x] Reset/reinstall F twice through `DisposableDatabaseReset`; verify all catalog/provenance values reproduce and no stale DTM data survives. Run existing disposable safety/reset tests and require non-disposable rejection. Preserve accepted role grants and protection checks.
- [x] Shut down F/P as needed, create N1 using a new independently initialized task-owned cluster/data directory and new isolation token/bootstrap. Install from artifacts independently; compare schema/authority, identity membership/count/digest and localization snapshot/evidence to F/P. Never copy their database/catalog files or call a second reset N1.
- [x] Run the wrapper Durability phase covering these distinct results; require `EMPTY_TO_HEAD`, `POPULATED_EXACT_BASELINE_TO_HEAD`, `REPEATED_RESET`, `N1`, `FINAL_CATALOG_EQUALITY`, identity equivalence and localization equivalence each actually PASS. Stop all clusters/processes in `finally`; report zero primary connections/writes and retained evidence paths.

**Completion:** All distinct durability results pass, historical data survives and source/label evidence agree independently.

## Task 13: Full Regression

**Purpose / invariant:** Required complete matrix passes without substituting focused-only evidence.

**Dependencies:** 1–12. **Files:** all actual changed surfaces for syntax/style, existing PHP/JS suites and new suites, proof report; inspect `.github/workflows/foundation.yml` for matching gates. No unrelated cleanup/dependency upgrades.

**Interfaces:** Consumes all completed DTM suites and the isolated wrapper's Full/UI/Durability evidence. Produces `docs/decision-context-foundation-001-implementation-proof.md` with per-command exit/counts, A–Q result links, provenance fields, authority/concurrency/durability outcomes and Owner final-state fields; unexecuted or failed proof is explicitly unverified/failed.

- [x] Run `Test-DeclaredTargetMarkets.ps1 -Phase Full` with the entire PHP suite (no filter), retaining JUnit executed/pass/fail/skip/warning/assertion counts and process exit. This includes Business Foundation/Type/context authority, Commercial, provider evidence/OAuth/connections, Source Guidance/internal Admin and RLS/database authority plus new tests. If suite infrastructure needs existing Redis/session services, start only task-owned configured services and stop them; do not point at primary defaults.
- [x] Run `builderad.ps1 npm test` including both new Node suites and existing suites; rerun the task-owned UI phase for final changed UI if earlier UI evidence predates the final implementation. Record actual counts and 320px/RTL evidence.
- [x] Run `builderad.ps1 npm exec -- vue-tsc --noEmit`, then `builderad.ps1 build` (existing `vue-tsc && vite build`), and `builderad.ps1 php vendor/bin/pint --test`. Require each exit zero; no silent broad reformat.
- [x] Run `builderad.ps1 php -l` individually on actual changed PHP files: new migration, BusinessContext/controller, new feature suites, worker and durability support. Record exact file count/results; include any additional actual PHP change.
- [x] Run `builderad.ps1 composer validate --strict`, `builderad.ps1 composer audit`, `builderad.ps1 npm audit --audit-level=high`, and `git diff --check`; record raw results, severity/findings and exit status. Missing audit network is unverified, not PASS; no unrelated update to manufacture green.
- [x] Fill the proof report with exact commands, counts, failures/skips, A–Q links, separate provenance/durability/concurrency/authority evidence, current baseline/branch/HEAD, process cleanup and zero primary usage. Reconcile every required spec/Owner final field. No commit/push/merge; leave reviewable changes for Owner review. Failure or missing required proof blocks `IMPLEMENTED_AND_PROVEN`.

**Completion:** All required proofs run and pass, including the complete matrix; reviewable bounded changes and honest exact evidence remain in the existing worktree, with no task process left running.

## Plan self-review and execution handoff

Self-review: **PASS — plan review only; implementation tests not run in this work package**.

| Review requirement | Coverage |
| --- | --- |
| Spec sections 1–3, 13: accepted foundations, product/ownership/non-goals/future compatibility | Global constraints and Tasks 3–9; no deferred capability implementation. |
| Spec sections 4–5: provenance/reference and relational model | Tasks 2–3, 10–12; nine separate provenance fields and deterministic encoding. |
| Spec sections 6–7: write/revision/audit/security | Tasks 4, 6, 9; signed exact authority, no-op/conflict rules, rollback, ACL/RLS and actual lock overlap. |
| Spec sections 8–9: canonical read/UX | Tasks 5–7; exact interfaces, separate forms and rendered RTL/320px evidence. |
| Spec sections 10–12: migration/A–Q/durability/regression | Tasks 8, 10–13; 17 matrix rows; populated exact-baseline and independent N1 are distinct. |
| Spec section 14: honest handoff and gate | Task 13 evidence and present Owner plan-review/method-selection gate. |
| Step/interface consistency | SQL signature, PHP methods, route name, projection fields, dataset encoding and wrapper phases remain identical across tasks. Each step has a testable result; no meaningless red package-install checks. |
| Review focus | Five named failure conditions attached to owning task assertions. |
| Scope/primary safety | Only plan documentation changed now; future all connections guarded and task-owned; no generic framework or primary use. |

Remaining implementation-time decisions are accepted dataset sources/revisions/count/digests and any source-specific extraction mapping that must be verified in Task 2. They are deliberate verified inputs, not architectural choices or permission for undocumented data. Migration/SQL/controller/editor/test/harness placement is resolved above; no known unresolved placement decision. A newly discovered file conflict or material lock/authority contradiction must be reported, not silently redesigned.

Highest-risk code change: the protected SQL writer's effect-time authority validation, shared revision and atomic event/state commit. Highest-risk proof: simultaneous equal/different/mixed writer entry with actual PostgreSQL lock overlap and expiry/revocation during waits, especially the existing actor helper's share-lock-to-update-lock interaction. No deadlock outcome is predeclared acceptable.

Execution options after Owner plan review:

- **Subagent-driven:** a fresh implementer and reviewer per task, then whole-branch review; thorough but higher context/review cost. Use `superpowers:subagent-driven-development` only if selected.
- **Native:** the current agent implements all tasks sequentially using `superpowers:executing-plans`, followed by one fresh whole-change reviewer; lower cost and preserves shared transaction/harness context. Recommended here because the tasks share one SQL/HTTP/read/UX contract and the same tightly guarded proof environment. Selection is the Owner's; no agents are dispatched during planning.

STOP FOR OWNER PLAN REVIEW / EXECUTION METHOD SELECTION. Product implementation, dependency restoration, database provisioning and writing product tests have not started during this planning task.
