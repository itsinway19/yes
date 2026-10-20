# Test execution rationalization

## Baseline and acceptance

Worktree: `C:\dev\builderad-worktrees\ba-test-execution-001`; branch: `codex/ba-test-execution-001`.
Initial HEAD: `d7997d65980dd1116c6e081c9534e881d77439bc`, clean and correctly registered. Its tree is `af311e75f635d5ac9f26a161150fbc27d0eb96e3`, including merged native Vue SPA cleanup (#44). No unrelated initial modifications or nested AGENTS.md files were found.

Acceptance requires preserving executed JavaScript cases and necessary modes, one production build per comprehensive gate, focused runner/failure evidence, intact PHP/security/isolation coverage, and a local commit. Hosted Foundation remains a subsequent gate. No push, PR, merge, deployment, shared database access, or full PHP rerun was performed.

Authoritative historical passing evidence: [Foundation 39456421148](https://github.com/42Huss/builderad/actions/runs/39456421148). Run metadata names branch head `cc4523ade6328202c244b86e4546b1830c9d8ee0`; checkout logs and GitHub commit API establish actual tested merge `35e5034cd3ee2a1a6c09e444c4c8161dfe19cb56`, parents `5281634071b0a91520255a0b1ee4812b134dc76f` and `cc4523ade6328202c244b86e4546b1830c9d8ee0`. Its tree equals the initial worktree tree above. This establishes the passing source baseline, not candidate acceptance.

## Confirmed waste and implementation

- Baseline `npm test`: 22 selected files, 231 executed cases. SPA subset: 21 selected files, 231 cases. Ten selected filenames overlap; selection union: 33 files.
- Actual case comparison finds **311 required identities**, with **151 repeated case executions** across those two invocations. Filename comparison alone misses 18 Source Scope cases imported by Source Registry.
- `npm test` now explicitly selects the complete 33-file union plus four infrastructure contracts in one new test file. Focused aliases remain unchanged.
- Removed Source Registry's test-to-test side-effect import. Registry stubs its scope component and uses its own renderer/ports; isolated Registry + Scope execution passes. Scope cases now run through their explicit selection. No assertion or independent test was deleted.
- Hosted Foundation invokes only `npm test`, not its SPA subset. Removed the separate two-case runtime unit invocation already included in `npm test`; retained the **post-build `--assets` CLI**, which supplies distinct output validation. Total old hosted JS executions: 464; new: 315 (311 existing + 4 new). **153 redundant old executions eliminated**, with four new checks added.
- `build:goals-spa` is exactly `npm run build`; both use the same Vite config, production mode and SPA entry. Each comprehensive gate now builds once. The alias and distinct `build:goals-spa:proof` remain available. Type checking still precedes Vite through `&&`.
- Windows Foundation previously ran only the old default JS selection, two equivalent builds, and no explicit retirement CLI/asset guard. It now covers the full union and both guards. Its PHP command, filters, database/token checks, source receipts and cleanup remain intact. `scripts/builderad.ps1` is unchanged.
- A direct Node probe exposed a pre-existing Windows flag-placement defect: appending `--test-concurrency=1` after filenames leaves effective concurrency at Node's default (`0` in worker execArgv). Windows now reads the literal canonical selection from package.json, validates its simple command shape, and places the serial flag **before** Node's test arguments. No second selection manifest or new runner was introduced. Effective worker execArgv now reports `--test-concurrency=1`.
- New infrastructure contracts protect focused-selection coverage, duplicate registration, mandatory hosted gates, Windows runner wiring, and build/proof-mode distinctions.

## Coverage equivalence

Baseline commands were executed before edits. A temporary Node reporter retained pass/fail/skip events and summaries. Comparison keys are source file, nesting, case title and per-title occurrence: two account/session variants sharing a title remain two required cases. Imported Scope cases are counted once across equivalent runs. Unchanged file contents, default environment, Node process isolation and test registration order justify occurrence matching; this is not an assertion-equivalence claim about unrelated tests.

| Execution | Cases | Fail/skip/todo | Required identities missing |
|---|---:|---:|---:|
| Baseline default | 231 | 0 | Not applicable |
| Baseline SPA | 231 | 0 | Not applicable |
| Candidate default | 315 | 0 | 0 |
| Candidate genuinely serial | 315 | 0 | 0 |

Both scripts originally used only `node --test` and filenames: no different filters, test-runner modes or script-specific environment assignments. `BUSINESS_EDITOR_BASELINE`, `COMMERCIAL_OPERATOR_BASELINE` and `NODE_OPTIONS` were unset. The PHP query-serialization subprocess still uses approved PHP. Node remains 24.21.0, npm 11.19.0, PHP 8.5.10; locked dependencies were installed with `scripts/builderad.ps1 npm ci` (190 packages; no lock change).

Inertia/N1 source and negative protocol guards remain active. Historical harness names are not evidence of obsolescence. No PHP test, concurrency wait, timeout, migration, RLS/role boundary, fixture identity or cleanup mechanism changed. Browser instrumentation mode remains separate from production mode.

## Performance and bounded PHP investigation

Historical hosted timings are one sequential Ubuntu job, not aggregate parallel CPU time:

| Historical stage | Observed duration |
|---|---:|
| Job start to completion | 613 s |
| Verify Foundation step | 553 s |
| Containers / PHP setup / Node setup | 28 / 4 / 3 s (step resolution) |
| Locked dependency install | 15 s (combined Composer/npm step) |
| PostgreSQL authority bootstrap | Approximately 1 s |
| SPA JS / default JS | 23.402 / 22.383 s (runner durations) |
| PHP, 1,016 cases / 15,083 assertions | 457.54 s |
| API drift / Pint | Approximately 1.49 / 23.8 s from output timestamps |
| Production build / repeated alias build | Approximately 11.38 / 10.28 s from script-start to completion |
| Of those builds, Vite alone | 1.90 / 1.76 s |

Credential generation and disposable-environment checking round below one second in step metadata. Migrations, fixture cleanup, Redis/session operations and child-process startup have no independent hosted timers; they are included in the relevant stage. They cannot be assigned invented subtotals.

Retained Windows JUnit from the earlier cleanup worktree has 1,016 tests / 15,654 assertions and 2,476.944 s, at its recorded older local source state `66c0913a691d6f791db5602e896e8172f3081c22`. It is **diagnostic historical evidence**, not acceptance of this baseline or a Windows/Ubuntu performance comparison. The final-result receipt identifies the archived JUnit; ordinary tests.xml was subsequently incomplete and was not used.

| Highest-cost retained class | Cases | Case-time sum (s) |
|---|---:|---:|
| ProviderAccessStoreTest | 155 | 726.655 |
| CurrentIntentConcurrencyTest | 11 | 195.389 |
| CommercialScopeTest | 32 | 162.361 |
| SourceRegistryTest | 29 | 120.583 |
| DeclaredTargetMarketsConcurrencyTest | 21 | 96.428 |

Bounded source inspection covered the leading Provider/Current Intent classes, shared TestCase/reset and the 49.286 s AccountSecuritySpaTest outlier. Shared installation is already once per PHP process; guarded per-test truncation remains. Provider setup registers all five provider scopes for each test, a plausible repeated fixture cost, but JUnit does not separate setup from behavior. Its 55.910 s Redis case exercises independent worker processes across all five providers; the queue case includes a deliberate six-second retry delay. Current Intent explicitly observes real blockers and independent transactions. Account resource checks spawn Node schema-validation processes. These observations identify profiling targets; they do **not** establish that fixture sharing, reduced provider setup or shorter waits is safe. No speculative PHP/fixture optimization or deletion was made, and no new PHP benchmark was needed.

Local diagnostics: original default + SPA took 18.829 + 9.926 s; final union default 14.933 s and genuine serial 44.770 s. The actual npm wrapper smoke run reported 25.794 s. These are single observations with different workloads/scheduling and overlapping diagnostic activity, **not a controlled before/after performance benchmark**. Serial behavior is now enforced rather than silently ignored. Production build passed (Vite 6.18 s); type-check/startup wall time was not separately instrumented.

**Eliminated work:** one production build per comprehensive gate and the redundant hosted case executions above. **Measured execution improvement:** no controlled percentage claimed. **Projected hosted improvement:** reduced repeated work, magnitude unknown until comparable GitHub runs; do not subtract an entire old SPA duration from the new full-union critical path.

## Canonical verification commands

On Windows use the approved wrapper; PHP integration below creates a dedicated disposable cluster, checks reserved ports/token/roles and cleans task-owned processes. Direct `builderad.ps1 test` requires an already configured approved isolated environment. Never point it at preview/shared databases.

| Change | Official command / entry point |
|---|---|
| Documentation | Inspect affected references/content; `git diff --check` |
| Local Vue UI | `pwsh -NoProfile -File scripts/builderad.ps1 npm run test:goals` (or affected `test:purpose`, `test:commercial-operator`, `test:localization`); direct `C:\tools\node24\node.exe --test tests/<affected>.test.mjs` for a specific existing file |
| Router/transport/presentation | `pwsh -NoProfile -File scripts/builderad.ps1 npm run test:visibility`; broader `npm run test:spa-foundation` when affected contracts warrant it |
| Laravel domain/API | `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Focused -TestFilter 'BusinessFoundationTest'` (replace with affected existing classes); contract changes also `scripts/builderad.ps1 npm run api:check` |
| Session/passkey/authorization | Same isolated Focused runner with `-TestFilter 'SessionAuthorityTest|ProductionPasskeyTest|PasskeyBoundaryTest|IdentityTransitionTest'`, adjusted to the actual affected boundary; client ceremony: `scripts/builderad.ps1 npm run test:passkey-client` |
| PostgreSQL/RLS/migrations | Same isolated Focused runner with `-TestFilter 'DisposableResetTest|DisposableTestDatabaseGuardTest|DatabaseAuthorityBoundaryTest|WorkspaceIsolationTest'`; add actual affected migration/concurrency tests |
| Browser lifecycle | `scripts/builderad.ps1 npm run build:goals-spa:proof`, then `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection foundation` (or `account`, `auth`, `restoration`, etc.); rebuild production after proof instrumentation. Physical-device/native BFCache evidence remains separate |
| Global appearance browser | `scripts/builderad.ps1 npm run build`, then `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Browser -BrowserSelection appearance`; uses production assets and guarded synthetic Founder/advertiser sessions on disposable resources |
| Runner/fixtures | `C:\tools\node24\node.exe --test tests/test-execution.test.mjs`, affected fixture consumers, actual failure-path probes and relevant isolated integration checks when PHP fixtures change |
| Complete JavaScript / production | `scripts/builderad.ps1 npm test`; `scripts/builderad.ps1 build`; `C:\tools\node24\node.exe scripts/runtime-retirement.mjs --assets` |
| Local comprehensive | `pwsh -NoProfile -File scripts/Test-GoalsSpa.ps1 -Phase Foundation` (serial JS, full PHP, audits, drift, Pint, build and retirement guards; separate browser phase) |
| Final PR acceptance | Complete `.github/workflows/foundation.yml` on the actual candidate merge checkout after separately authorized publication |

Commands above map real existing entry points and class names; the execution ledger below distinguishes commands actually run here from inspected, unchanged PHP/browser entry points. Impact and uncertainty determine expansion. Existing AGENTS sections already govern baseline diagnosis, bounded experiments, affected reruns, evidence freshness and prohibition of weakened protections; only two short operational paragraphs were added, linking this mapping and distinguishing local/hosted/browser gates. Two existing command documents were corrected where they still demanded both equivalent builds. Historical evidence documents were preserved.

## Verification ledger and handoff

Local evidence directory: `storage/framework/testing/test-execution-001/` in this worktree (ignored artifacts, retained for independent review). Baseline source plus the final committed diff identifies the tested state. Production sources, build inputs and locks did not change; the intentional temporary TypeScript/test/asset faults were restored byte-for-byte or removed before closure.

| Check | Status / scope |
|---|---|
| Approved-toolchain preflight / locked npm install | PASS |
| Original two selections, then candidate default and real serial via measure.mjs | PASS; reporter only observes events, coverage-equivalence.json retains all case identities |
| Actual `scripts/builderad.ps1 npm test` | PASS, 315 cases |
| Registry + Scope + runner contracts isolated | PASS, 23 cases |
| Actual `scripts/builderad.ps1 build`, post-build asset CLI | PASS, production entry `resources/js/spa/main.ts` |
| Intentional failing password case through official focused npm wrapper | Expected nonzero 1; file restored |
| Intentional TypeScript error through official build wrapper | Expected nonzero 2 before Vite, existing manifest unchanged; probe removed |
| Invalid manifest production entry | Expected nonzero 1; bytes restored, asset CLI PASS afterward |
| Actual Foundation branch control flow, external commands stubbed | PASS: correct ten-command order, each injected failure prevents subsequent gates; this is **not** a full Foundation execution |
| PowerShell parsing / affected reference checks / diff whitespace | PASS |
| Complete PHP, browser/device, proof-mode build, audits/Pint/API drift on candidate | NOT EXECUTED; no production/PHP/schema/lock/contract change requiring their local rerun. Complete mandatory hosted gates retained |
| Candidate GitHub Foundation | **PENDING HOSTED CI**; no publication authorized/performed |

Key retained evidence SHA256:

- Historical CI log: `db3905dc1af3e927049d6f46eaf0a2422a766867fc0efd297b8cb674400fdc31` (`ci-baseline.log`).
- Historical Windows JUnit: `e628e6cc27ef9dafbe3d7e5d1aa5e79c33e70501472e1562315d30fab3f22a51` (`php-historical-junit.xml`). Original: `C:\dev\builderad-worktrees\ba-code-organization-001\storage\framework\testing\goals-spa-bac286ba-3ad5-41a4-8db6-1cf91fe95ffa\7b68433b-2f86-478f-9abc-97b473dc05b6-tests.xml`.
- `evidence-manifest.json` records final artifact and modified-source hashes; `handoff.json` records the final local commit SHA, tree, changed paths and clean Git status after commit. These post-commit receipts avoid embedding a self-referential commit SHA in its own document; resolve the committed candidate directly with `git log -1 --format=%H -- docs/BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md`.

Modified surfaces: package selection; hosted Foundation; Windows Foundation; Source Registry test import; new runner contracts; AGENTS; this report; `docs/execution/spa-openapi/GOALS_API_CONTRACT.md`; `docs/execution/spa-openapi/stabilization/CI_PLAN.md`. No task-started service, watcher or browser server remains; all invoked child commands completed. Initial sandbox npm install failed on network resolution and was stopped before the successful approved install; no application service was contacted.

Independent review should inspect case multiplicity/equivalence artifacts, Windows option placement and fail-fast probes, unchanged database/bootstrap/cleanup boundaries, and the hosted gate diff. Residual work is actual candidate hosted Foundation and, only if later prioritized, instrumented Provider fixture/process cost measurement. Neither historical green CI nor focused local evidence is promoted to final hosted acceptance or production readiness.

READY FOR INDEPENDENT TEST-INFRASTRUCTURE REVIEW
