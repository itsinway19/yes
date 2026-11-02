# Two contention test corrections — 2026-11-02

Scope: Owner requested correction of the two errors in the completed local Foundation attempt, followed by targeted verification only. No comprehensive test rerun, publication or product/database contract change.

Worktree: `C:/dev/builderad-worktrees/ba-commercial-expertise-001`, HEAD `c747f6d8a8f2011fa5dcc368da2aa4e8449e0565` plus existing dirty candidate.
Relevant last passing baseline: hosted Foundation run41287174337 at exact HEAD, as established in BF03_FINAL_INDEPENDENT_REVIEW.md. Its Ubuntu/PHP8.5.12 environment differs from local Windows/PHP8.5.10. The three edited test/worker files had no prior dirty changes at this task start.

## Diagnosis and limits

Failing run: `storage/framework/testing/goals-spa-c4bac31c-89f1-4f4d-92a2-44f06f446c17`. Its source-unchanged=True. Both retained evidence files lack a READY_PID, wait PID and blocker observation and have empty stdout/stderr. Their post-cleanup exit1 is not evidence that a product operation failed; cleanup stopped the child. A readiness observation failure was reported as missing contention before contention had been attempted. The historical logs cannot conclusively distinguish slow bootstrap, missing output, or an early bootstrap failure. No reproducible product authorization defect is established, and the old errors are not called flaky or erased.

The first diagnostic run with explicit BOOT_STAGE/fatal-shutdown instrumentation and the original observation deadlines passed the exact two tests (2/14). This passing retry alone does not resolve historical causality. Inspection of Symfony Process confirms it already buffers getOutput; a no-op callback is not a corrective mechanism and was removed.

## Correction

- The guarded test worker optionally writes its exact backend PID and unique run into a local ready sidecar after kernel bootstrap, disposable-DB validation and connection naming. Existing stdout READY_PID remains available to all other callers.
- These two test paths no longer depend on stdout readiness delivery. Before acquiring a holder lock or release, they require the same child process alive and a fresh pg_stat_activity observation for the exact PID + application_name with backend_xid=NULL.
- BOOT_STAGE entry/autoload/kernel and fatal-shutdown messages plus before-cleanup process state are retained, distinguishing bootstrap from actual lock attempts and cleanup termination.
- Existing actual pg_blocking_pids proof, identity NOWAIT availability, real withdrawal, SQLSTATE42501 denial, expiry/rollback, preserved retained-read meaning and no-effect assertions remain intact.
- Existing 15-second observation, 45-second process,25-second release and all database/security timeouts are unchanged. No sleeps, retry-until-green loop, assertions removed, production source edits, SQL migrations or authority changes.
- Sidecars are unique per random run and removed in finally after workers stop. They do not convey authority or replace current server observations.

## Actual verification

Diagnostic exact two-test selection: PASS 2 tests /14 assertions (84.80s), evidence in `diagnostic/`.
Final affected selection: `BusinessHistoryTest|MarketEvidenceConcurrencyTest`, PASS 7 tests /173 assertions (52.62s). No Foundation was launched. Final evidence in `final/` includes JUnit, logs, source hashes and source-unchanged=True.
Five actual contention cases have readyPID=waitPID and real Lock blockers; initial xid=NULL. They include historical read withdrawal, source withdrawal, qualification expiry, evidence expiry and retained read validity at release. Startup measured0.55–1.28s in this final run; no guarantee of future host scheduling is inferred.
PHP syntax checks passed. Pint initially reported formatting corrections; applied only its whitespace/blank-line changes, then Pint --test passed. git diff --check passed. No behavioral edits after the passing final tests. Full tests were not repeated merely for formatting.
Official runner stopped its task-owned PostgreSQL/Redis/workers; task ports5433/6397/8136/8137 have no listeners.

## Important before Owner's final comprehensive run

The previous Foundation run also aborted with PHP `Allowed memory size of134217728 bytes exhausted` in PHPUnit Metadata/Parser/CachingParser.php. This is a separate outstanding execution-environment blocker. Fixing the two contention tests does not remove that128MB ceiling. This task did not change PHP configuration, phpunit.xml, product timeout/security contracts or Foundation gate status. Owner's final full run must use an appropriate explicit PHPUnit memory configuration (for example512M after confirming host capacity) or an equivalent authoritative hosted gate. No full success claim is made.

Artifacts: `C:/Users/stoma/.codex/visualizations/2026/10/30/01a18cdf-2b2d-7682-ac51-b50f944e5fcc/two-contention-tests`.
Global Foundation status remains FAILED/INCOMPLETE until the Owner's final comprehensive gate completes. Commit/push/PR/merge/deployment not performed.
